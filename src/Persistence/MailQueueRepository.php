<?php
/**
 * Kolejka maili i log wysyłek w jednej tabeli.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\QueueStatus;

/**
 * Jedyna klasa dotykająca SQL-em tabeli `mail_queue`. Nie decyduje, co i kiedy wysłać.
 *
 * Wiersz zostaje po wysłaniu: kolejka jest też logiem wysyłek, który specyfikacja wymaga w panelu.
 * Wszystkie znaczniki czasu są w UTC. Zmiany stanu, o które mogą się ścigać dwa przebiegi crona,
 * są warunkowymi UPDATE-ami (`WHERE status = …`), nigdy odczytem i zapisem.
 */
final class MailQueueRepository {

	/**
	 * Wstawia wiersz w stanie queued. Duplikat `dedupe_key` jest po cichu pomijany.
	 *
	 * @param array{created_at:string,scheduled_at:string,kind:string,dedupe_key:string,user_id:int,recipient:string,subject:string,body:string} $row Dane wiersza.
	 * @return bool True tylko gdy wiersz POWSTAŁ.
	 */
	public static function insert( array $row ): bool {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (created_at, scheduled_at, kind, dedupe_key, user_id, recipient, subject, body, status, attempts, anonymized) VALUES (%s, %s, %s, %s, %d, %s, %s, %s, %s, 0, 0)',
				Migrations::table( 'mail_queue' ),
				$row['created_at'],
				$row['scheduled_at'],
				$row['kind'],
				$row['dedupe_key'],
				$row['user_id'],
				$row['recipient'],
				$row['subject'],
				$row['body'],
				QueueStatus::Queued->value
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Zapis do własnej tabeli kolejki, nazwa z Migrations::table().

		// Nie insert_id: przy pominiętym duplikacie zostaje w nim wartość z poprzedniego zapisu na tym połączeniu.
		return 1 === $wpdb->rows_affected;
	}

	/**
	 * Zwraca wiersz albo null.
	 *
	 * @param int $id Identyfikator wiersza.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Migrations::table( 'mail_queue' ), $id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stan kolejki musi być świeży, cache pokazałby nieaktualny status.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Wiersze gotowe do wysyłki, najstarszy naprzód.
	 *
	 * @param string $nowUtc Moment odniesienia (UTC).
	 * @param int    $limit  Maksymalna liczba wierszy.
	 * @return list<array<string, mixed>>
	 */
	public static function due( string $nowUtc, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND scheduled_at <= %s ORDER BY id ASC LIMIT %d',
				Migrations::table( 'mail_queue' ),
				QueueStatus::Queued->value,
				$nowUtc,
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dyspozytor musi widzieć aktualną kolejkę, cache zgubiłby świeże wiersze.

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Przejmuje wiersz do wysyłki. Zapis `scheduled_at` jest celowy: to znacznik zajęcia,
	 * względem którego `recover_stale()` rozpoznaje porzucone wiersze (stąd brak osobnej kolumny).
	 *
	 * @param int    $id     Identyfikator wiersza.
	 * @param string $nowUtc Moment zajęcia (UTC).
	 * @return bool True, gdy to wywołanie zajęło wiersz i ma do niego wyłączność.
	 */
	public static function claim( int $id, string $nowUtc ): bool {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, attempts = attempts + 1, scheduled_at = %s WHERE id = %d AND status = %s',
				Migrations::table( 'mail_queue' ),
				QueueStatus::Sending->value,
				$nowUtc,
				$id,
				QueueStatus::Queued->value
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Warunkowy UPDATE jest bramką współbieżności, nie ma tu czego buforować.

		return 1 === $wpdb->rows_affected;
	}

	/**
	 * Oznacza wiersz jako wysłany.
	 *
	 * @param int    $id     Identyfikator wiersza.
	 * @param string $nowUtc Moment wysyłki (UTC).
	 */
	public static function mark_sent( int $id, string $nowUtc ): void {
		global $wpdb;

		$wpdb->update(
			Migrations::table( 'mail_queue' ),
			array(
				'status'  => QueueStatus::Sent->value,
				'sent_at' => $nowUtc,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Zapis do własnej tabeli kolejki.
	}

	/**
	 * Zwraca wiersz do kolejki z nowym terminem i zapisanym błędem.
	 *
	 * @param int    $id             Identyfikator wiersza.
	 * @param string $scheduledAtUtc Termin następnej próby (UTC).
	 * @param string $error          Komunikat błędu.
	 */
	public static function reschedule( int $id, string $scheduledAtUtc, string $error ): void {
		global $wpdb;

		$wpdb->update(
			Migrations::table( 'mail_queue' ),
			array(
				'status'       => QueueStatus::Queued->value,
				'scheduled_at' => $scheduledAtUtc,
				'last_error'   => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Zapis do własnej tabeli kolejki.
	}

	/**
	 * Oznacza wiersz jako definitywnie nieudany.
	 *
	 * @param int    $id    Identyfikator wiersza.
	 * @param string $error Komunikat błędu.
	 */
	public static function mark_failed( int $id, string $error ): void {
		global $wpdb;

		$wpdb->update(
			Migrations::table( 'mail_queue' ),
			array(
				'status'     => QueueStatus::Failed->value,
				'last_error' => $error,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Zapis do własnej tabeli kolejki.
	}

	/**
	 * Zwraca do kolejki wiersze porzucone w stanie sending (przebieg przerwany np. timeoutem PHP).
	 *
	 * @param string $thresholdUtc Wiersze zajęte wcześniej niż ten moment (UTC).
	 * @return int Liczba wierszy zwróconych do kolejki.
	 */
	public static function recover_stale( string $thresholdUtc ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s WHERE status = %s AND scheduled_at < %s',
				Migrations::table( 'mail_queue' ),
				QueueStatus::Queued->value,
				QueueStatus::Sending->value,
				$thresholdUtc
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Warunkowy UPDATE na własnej tabeli kolejki.

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Kasuje wysłane i nieudane wiersze starsze niż próg. Wiersze queued i sending zostają bez względu
	 * na wiek: to maile, które jeszcze nie wyszły, a skasowanie zgubiłoby czyjś link potwierdzający.
	 *
	 * @param string $beforeUtc Granica retencji względem `created_at` (UTC).
	 * @return int Liczba skasowanych wierszy.
	 */
	public static function purge_old( string $beforeUtc ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status IN (%s, %s) AND created_at < %s',
				Migrations::table( 'mail_queue' ),
				QueueStatus::Sent->value,
				QueueStatus::Failed->value,
				$beforeUtc
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Czyszczenie retencyjne własnej tabeli kolejki.

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Strona wierszy, najnowsze naprzód.
	 *
	 * @param string $status  Wartość QueueStatus albo '' dla wszystkich. Nieznana wartość nie daje wyników.
	 * @param int    $perPage Liczba wierszy na stronę.
	 * @param int    $offset  Przesunięcie.
	 * @return list<array<string, mixed>>
	 */
	public static function paginate( string $status, int $perPage, int $offset ): array {
		global $wpdb;

		$table = Migrations::table( 'mail_queue' );
		if ( '' === $status ) {
			$sql = $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $perPage, $offset );
		} elseif ( null !== QueueStatus::tryFrom( $status ) ) {
			$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $status, $perPage, $offset );
		} else {
			return array();
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Zapytanie przygotowane powyżej; lista w panelu ma pokazywać aktualny stan.

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Liczba wierszy w danym stanie.
	 *
	 * @param string $status Wartość QueueStatus albo '' dla wszystkich. Nieznana wartość daje 0.
	 */
	public static function count_by_status( string $status ): int {
		global $wpdb;

		$table = Migrations::table( 'mail_queue' );
		if ( '' === $status ) {
			$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table );
		} elseif ( null !== QueueStatus::tryFrom( $status ) ) {
			$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status );
		} else {
			return 0;
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Zapytanie przygotowane powyżej; licznik w panelu ma być aktualny.
	}

	/**
	 * Wznawia nieudany wiersz: zeruje próby i wraca do kolejki. Wiersz w innym stanie zostaje nietknięty.
	 *
	 * @param int $id Identyfikator wiersza.
	 * @return bool True, gdy wiersz był failed i wrócił do kolejki.
	 */
	public static function requeue_failed( int $id ): bool {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, attempts = 0, scheduled_at = %s, last_error = NULL WHERE id = %d AND status = %s',
				Migrations::table( 'mail_queue' ),
				QueueStatus::Queued->value,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				QueueStatus::Failed->value
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Warunkowy UPDATE na własnej tabeli kolejki.

		return 1 === $wpdb->rows_affected;
	}
}
