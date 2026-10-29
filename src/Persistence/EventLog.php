<?php
/**
 * Log zdarzeń wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

use PortalUsers\Domain\Privacy\ContextFilter;

defined( 'ABSPATH' ) || exit;

/**
 * Zapis do tabeli `event_log`. Nieudany zapis logu nie może zatrzymać obsługiwanego żądania,
 * więc metoda nie rzuca; porażkę zgłasza wartością zwracaną, żeby wywołujący, któremu log jest
 * dowodem (rozstrzygnięcie wniosku), mógł ją zobaczyć. Pozostali mogą wynik zignorować.
 */
final class EventLog {

	private const ACTION_MAX_LENGTH = 40;

	/**
	 * Dopisuje zdarzenie.
	 *
	 * @param string               $action        Nazwa zdarzenia (do 40 znaków; dłuższa jest obcinana).
	 * @param int                  $subjectUserId Użytkownik, którego zdarzenie dotyczy (0 = brak).
	 * @param int                  $actorUserId   Użytkownik wykonujący (0 = brak, np. anonimowy odwiedzający).
	 * @param array<string, mixed> $context       Dane dodatkowe, zapisywane jako JSON.
	 * @return bool True, gdy wiersz powstał.
	 */
	public static function write( string $action, int $subjectUserId, int $actorUserId, array $context ): bool {
		global $wpdb;

		$encoded = wp_json_encode( $context );

		$result = $wpdb->insert(
			Migrations::table( 'event_log' ),
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'actor_user_id'   => max( 0, $actorUserId ),
				'subject_user_id' => max( 0, $subjectUserId ),
				'action'          => substr( $action, 0, self::ACTION_MAX_LENGTH ),
				'context'         => false === $encoded ? '{}' : $encoded,
				'anonymized'      => 0,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Wiersze logu jednego podmiotu, od najnowszego.
	 *
	 * @param int $userId Podmiot zdarzeń.
	 * @param int $limit  Maksymalna liczba wierszy.
	 * @param int $offset Przesunięcie.
	 * @return list<array{id: int, created_at: string, action: string, actor_user_id: int, context: array<string, mixed>, anonymized: bool}>
	 */
	public static function for_subject( int $userId, int $limit, int $offset ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, action, actor_user_id, context, anonymized FROM %i WHERE subject_user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
				Migrations::table( 'event_log' ),
				$userId,
				max( 0, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'id'            => (int) $row['id'],
				'created_at'    => (string) $row['created_at'],
				'action'        => (string) $row['action'],
				'actor_user_id' => (int) $row['actor_user_id'],
				'context'       => self::decode_context( $row['context'] ),
				'anonymized'    => '1' === (string) $row['anonymized'],
			);
		}

		return $result;
	}

	/**
	 * Anonimizuje log podmiotu: czyści `context`, ustawia `anonymized`, resztę wiersza zostawia.
	 *
	 * Wiersz musi przeżyć, bo wykazuje, kto i kiedy zatwierdził konto; identyfikator podmiotu też zostaje,
	 * bo bez niego wiersz nie dowodziłby nic o konkretnym koncie, a po usunięciu konta nie wskazuje już osoby.
	 * Operacja jest idempotentna: rusza tylko wiersze z `anonymized = 0`, bo rdzeń woła eraser w pętli,
	 * a filtr zastosowany drugi raz dołożyłby listę usuniętych kluczy do kontekstu, który już ją ma.
	 *
	 * @param int $userId Podmiot zdarzeń.
	 * @return int Liczba wierszy faktycznie zmienionych.
	 */
	public static function anonymize_subject( int $userId ): int {
		global $wpdb;

		$table = Migrations::table( 'event_log' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, context FROM %i WHERE subject_user_id = %d AND anonymized = 0', $table, $userId ),
			ARRAY_A
		);

		$changed = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Reguła „co zostaje" żyje w ContextFilter, więc filtrujemy w PHP, a nie w SQL.
			$filtered = wp_json_encode( ContextFilter::anonymize( self::decode_context( $row['context'] ) ) );

			// Warunek anonymized = 0 powtórzony przy zapisie: równoległe wywołanie nie policzy wiersza drugi raz.
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET context = %s, anonymized = 1 WHERE id = %d AND anonymized = 0',
					$table,
					false === $filtered ? '{}' : $filtered,
					(int) $row['id']
				)
			);
			if ( is_int( $updated ) ) {
				$changed += $updated;
			}
		}

		return $changed;
	}

	/**
	 * Odserializowuje kolumnę `context`; wartość pusta albo uszkodzona daje pustą tablicę.
	 *
	 * @param mixed $raw Zawartość kolumny.
	 * @return array<string, mixed>
	 */
	private static function decode_context( mixed $raw ): array {
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}
}
