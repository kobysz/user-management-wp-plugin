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
 * są warunkowymi UPDATE-ami, nigdy odczytem i zapisem. Zajęcie wiersza podnosi licznik `attempts`
 * i oddaje jego wartość; zapisy wyniku (`mark_sent`, `reschedule`, `mark_failed`) przechodzą tylko z tą
 * wartością. Sam status nie wystarczy: wiersz odzyskany przez `recover_stale()` i zajęty ponownie też
 * jest `sending`, a spóźniony zapis pierwszego przebiegu nie może odebrać go drugiemu.
 */
final class MailQueueRepository {

	/**
	 * Nazwa tabeli bez prefiksu; pełną daje Migrations::table().
	 */
	private const TABLE = 'mail_queue';

	/**
	 * Szerokość kolumny `dedupe_key`. Publiczna, bo wołający musi odmówić PRZED zapisem: wpdb zdejmuje
	 * tryby ścisłe SQL, więc dłuższa wartość nie daje błędu, tylko ciche obcięcie (patrz `insert()`).
	 */
	public const DEDUPE_KEY_MAX = 191;

	/**
	 * Szerokość kolumny `recipient`. Publiczna z tego samego powodu: `is_email()` przyjmuje adres do
	 * 254 znaków, więc legalny długi adres zapisałby się obcięty i mail poszedłby na adres, którego nikt nie ma.
	 */
	public const RECIPIENT_MAX = 191;

	/**
	 * Wartość, którą anonimizacja wpisuje w `recipient`, `subject` i `body`. Kolumny są NOT NULL, więc
	 * znacznik zastępuje treść, zamiast ją zerować; ten sam napis w trzech kolumnach mówi czytającemu log, że dane usunięto, a nie że ich nie było.
	 */
	public const ANONYMIZED_PLACEHOLDER = '(dane usunięte na żądanie)';

	/**
	 * Wpis w `last_error` wiersza, który nie wyszedł i już nie wyjdzie, bo anonimizacja odebrała mu adresata i treść.
	 * Stały i bezosobowy, żeby log wysyłek mówił, dlaczego wiersz stoi w `failed`, nie niosąc żadnych danych.
	 */
	public const CANCELLED_BY_ERASURE = 'Wysyłkę anulowało żądanie usunięcia danych.';

	/**
	 * Wstawia wiersz w stanie queued. Duplikat `dedupe_key` jest po cichu pomijany.
	 *
	 * Klucz `dedupe_key` ma co najwyżej DEDUPE_KEY_MAX znaków. wpdb zdejmuje tryby ścisłe SQL, więc dłuższy
	 * MySQL obcina bez błędu: dwa różne klucze o wspólnym przedrostku zderzyłyby się na indeksie,
	 * insert() zwróciłby false, a wywołujący odczytałby to jako "już w kolejce" i mail by nie wyszedł.
	 * To samo dotyczy `recipient` i RECIPIENT_MAX, tylko skutkiem jest mail na obcięty adres.
	 *
	 * Wynik jest TRÓJSTANOWY, bo trzeciego stanu nie da się wyczytać z `rows_affected`: pominięty
	 * duplikat zostawia tam 0 i padnięte zapytanie zostawia tam 0 również, a to dwie różne rzeczy.
	 * Duplikat znaczy „ten sam mail już czeka w kolejce" i jest niegroźny; awaria bazy znaczy mail
	 * porzucony bez wiersza, więc log wysyłek nie odpowiada na pytanie „czy ten mail wyszedł".
	 * Rozróżnienie jedzie wartością zwrotną, a nie osobnym pytaniem o `$wpdb->last_error`:
	 * `wpdb::query()` zeruje tamto pole przy każdym następnym zapytaniu, więc drugi odczyt globalnego
	 * stanu dałby odpowiedź o cudzym zapytaniu. Wołający, który pyta tylko „czy wiersz powstał",
	 * czyta wynik jak dotąd (null jest fałszywy); o powód pyta porównaniem identycznościowym.
	 *
	 * @param array{created_at:string,scheduled_at:string,kind:string,dedupe_key:string,user_id:int,recipient:string,subject:string,body:string} $row Dane wiersza.
	 * @return bool|null True gdy wiersz POWSTAŁ, false gdy pominięto duplikat klucza, null gdy zapis padł na bazie.
	 */
	public static function insert( array $row ): ?bool {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (created_at, scheduled_at, kind, dedupe_key, user_id, recipient, subject, body, status, attempts, anonymized) VALUES (%s, %s, %s, %s, %d, %s, %s, %s, %s, 0, 0)',
				Migrations::table( self::TABLE ),
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
		);

		if ( false === $result ) {
			// `wpdb::query()` oddaje false WYŁĄCZNIE wtedy, gdy zapytanie padło; pominięty duplikat
			// jest dla niej udanym zapytaniem o zerze wierszy (int 0). Porównanie identycznościowe,
			// bo 0 jest tu czymś innym niż false.
			return null;
		}

		// Nie insert_id: przy pominiętym duplikacie zostaje w nim wartość z poprzedniego zapisu na tym połączeniu.
		return 1 === $wpdb->rows_affected;
	}

	/**
	 * NAJWCZEŚNIEJSZY termin wysyłki wśród wierszy czekających w kolejce albo null, gdy żaden nie czeka.
	 *
	 * Podkładka pod komunikat o kolejce, która przestała się opróżniać (patrz MailQueueNotice).
	 * Zdrowa kolejka nie zostawia wiersza po terminie dłużej niż jeden przebieg crona, więc termin
	 * sprzed kwadransa znaczy, że wysyłki nie ma.
	 *
	 * Mierzymy `scheduled_at`, nie `created_at`, i to z dwóch powodów. Pierwszy: `created_at` kłamie
	 * o wierszu, który czeka na swój termin ZGODNIE z planem — wiersz wznowiony ręcznie z ekranu logu
	 * (`requeue_failed()`) ma `created_at` sprzed tygodni i natychmiast podnosiłby alarm o zdrowej
	 * kolejce, a komunikat, który raz skłamał, przestaje być czytany. Drugi: `MIN(scheduled_at)` przy
	 * równości na `status` jest całym przedrostkiem indeksu `dispatch (status, scheduled_at)`, więc
	 * baza oddaje go z indeksu BEZ CZYTANIA WIERSZY (sprawdzone na MariaDB w kontenerze: EXPLAIN daje
	 * „Select tables optimized away", podczas gdy `MIN(created_at)` daje `ref` po wszystkich wierszach
	 * `queued`). Przy zatkanej kolejce tych wierszy są tysiące, a zapytanie biegnie przy każdym wejściu
	 * do panelu — czyli dokładnie tam, gdzie cena nie może rosnąć z rozmiarem kolejki.
	 *
	 * @return string|null Znacznik `Y-m-d H:i:s` w UTC.
	 */
	public static function oldest_due_at(): ?string {
		global $wpdb;

		$oldest = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(scheduled_at) FROM %i WHERE status = %s',
				Migrations::table( self::TABLE ),
				QueueStatus::Queued->value
			)
		);

		// MIN() na pustym zbiorze oddaje NULL, nie brak wiersza.
		return is_string( $oldest ) && '' !== $oldest ? $oldest : null;
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
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Migrations::table( self::TABLE ), $id ),
			ARRAY_A
		);

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
				Migrations::table( self::TABLE ),
				QueueStatus::Queued->value,
				$nowUtc,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Przejmuje wiersz do wysyłki. Zapis `scheduled_at` jest celowy: to znacznik zajęcia,
	 * względem którego `recover_stale()` rozpoznaje porzucone wiersze (stąd brak osobnej kolumny).
	 *
	 * @param int    $id     Identyfikator wiersza.
	 * @param string $nowUtc Moment zajęcia (UTC).
	 * @return int|null Numer próby, który identyfikuje właściciela wiersza (do `mark_*` i `reschedule`),
	 *                  albo null, gdy zajęcie przegrano albo nie udało się odczytać numeru próby.
	 */
	public static function claim( int $id, string $nowUtc ): ?int {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, attempts = attempts + 1, scheduled_at = %s WHERE id = %d AND status = %s',
				Migrations::table( self::TABLE ),
				QueueStatus::Sending->value,
				$nowUtc,
				$id,
				QueueStatus::Queued->value
			)
		);

		if ( 1 !== $wpdb->rows_affected ) {
			return null;
		}

		// Odczyt jest bezpieczny, bo wiersz jest już nasz. Nie liczymy attempts + 1 z wcześniejszego odczytu:
		// między due() a claim() inny przebieg mógł wiersz zająć, oblać i przełożyć.
		$attempts = $wpdb->get_var(
			$wpdb->prepare( 'SELECT attempts FROM %i WHERE id = %d', Migrations::table( self::TABLE ), $id )
		);

		// Zajęcie jest wygrane tylko wtedy, gdy WIEMY, którą próbą władamy. Rzutowanie nieudanego
		// albo pustego odczytu dałoby 0: wartość nieodróżnialną dla wołającego od wygranego zajęcia,
		// a przy tym taką, której straż w `finish()` nigdy nie przepuści. Wiersz zostałby w `sending`
		// do odzysku i mail wyszedłby drugi raz.
		if ( ! is_numeric( $attempts ) || (int) $attempts < 1 ) {
			return null;
		}

		return (int) $attempts;
	}

	/**
	 * Oznacza wiersz jako wysłany, o ile nadal należy do tej próby.
	 *
	 * @param int    $id      Identyfikator wiersza.
	 * @param string $nowUtc  Moment wysyłki (UTC).
	 * @param int    $attempt Numer próby zwrócony przez claim().
	 * @return bool False, gdy wiersz już do tej próby nie należy (zapis pominięty).
	 */
	public static function mark_sent( int $id, string $nowUtc, int $attempt ): bool {
		return self::finish(
			$id,
			$attempt,
			array(
				'status'  => QueueStatus::Sent->value,
				'sent_at' => $nowUtc,
			)
		);
	}

	/**
	 * Zwraca wiersz do kolejki z nowym terminem i zapisanym błędem, o ile nadal należy do tej próby.
	 *
	 * @param int    $id             Identyfikator wiersza.
	 * @param string $scheduledAtUtc Termin następnej próby (UTC).
	 * @param string $error          Komunikat błędu.
	 * @param int    $attempt        Numer próby zwrócony przez claim().
	 * @return bool False, gdy wiersz już do tej próby nie należy (zapis pominięty).
	 */
	public static function reschedule( int $id, string $scheduledAtUtc, string $error, int $attempt ): bool {
		return self::finish(
			$id,
			$attempt,
			array(
				'status'       => QueueStatus::Queued->value,
				'scheduled_at' => $scheduledAtUtc,
				'last_error'   => $error,
			)
		);
	}

	/**
	 * Oznacza wiersz jako definitywnie nieudany, o ile nadal należy do tej próby.
	 *
	 * @param int    $id      Identyfikator wiersza.
	 * @param string $error   Komunikat błędu.
	 * @param int    $attempt Numer próby zwrócony przez claim().
	 * @return bool False, gdy wiersz już do tej próby nie należy (zapis pominięty).
	 */
	public static function mark_failed( int $id, string $error, int $attempt ): bool {
		return self::finish(
			$id,
			$attempt,
			array(
				'status'     => QueueStatus::Failed->value,
				'last_error' => $error,
			)
		);
	}

	/**
	 * Zapisuje wynik próby, ale tylko właścicielowi: status `sending` i ten sam numer próby.
	 * Status sam nie identyfikuje właściciela, bo odzyskany i ponownie zajęty wiersz też jest `sending`.
	 *
	 * @param int                  $id      Identyfikator wiersza.
	 * @param int                  $attempt Numer próby zwrócony przez claim().
	 * @param array<string,string> $values  Kolumny do zapisu.
	 */
	private static function finish( int $id, int $attempt, array $values ): bool {
		global $wpdb;

		$wpdb->update(
			Migrations::table( self::TABLE ),
			$values,
			array(
				'id'       => $id,
				'status'   => QueueStatus::Sending->value,
				'attempts' => $attempt,
			),
			array_fill( 0, count( $values ), '%s' ),
			array( '%d', '%s', '%d' )
		);

		return 1 === $wpdb->rows_affected;
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
				Migrations::table( self::TABLE ),
				QueueStatus::Queued->value,
				QueueStatus::Sending->value,
				$thresholdUtc
			)
		);

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
				Migrations::table( self::TABLE ),
				QueueStatus::Sent->value,
				QueueStatus::Failed->value,
				$beforeUtc
			)
		);

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

		$table = Migrations::table( self::TABLE );
		if ( '' === $status ) {
			$sql = $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $perPage, $offset );
		} elseif ( null !== QueueStatus::tryFrom( $status ) ) {
			$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $status, $perPage, $offset );
		} else {
			return array();
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Zapytanie przygotowane powyżej; lista w panelu ma pokazywać aktualny stan.

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Liczba wierszy w danym stanie.
	 *
	 * @param string $status Wartość QueueStatus albo '' dla wszystkich. Nieznana wartość daje 0.
	 */
	public static function count_by_status( string $status ): int {
		global $wpdb;

		$table = Migrations::table( self::TABLE );
		if ( '' === $status ) {
			$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table );
		} elseif ( null !== QueueStatus::tryFrom( $status ) ) {
			$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status );
		} else {
			return 0;
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Zapytanie przygotowane powyżej; licznik w panelu ma być aktualny.
	}

	/**
	 * Wznawia nieudany wiersz: zeruje próby i wraca do kolejki; `last_error` zostaje jako ślad poprzedniej próby. Wiersz w innym stanie zostaje nietknięty, a zanonimizowany też: żądanie usunięcia danych jest nieodwracalne, a taki wiersz nie ma już adresata ani treści, więc wysyłka nie miałaby komu i czego dostarczyć.
	 *
	 * @param int $id Identyfikator wiersza.
	 * @return bool True, gdy wiersz był failed i wrócił do kolejki.
	 */
	public static function requeue_failed( int $id ): bool {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, attempts = 0, scheduled_at = %s WHERE id = %d AND status = %s AND anonymized = 0',
				Migrations::table( self::TABLE ),
				QueueStatus::Queued->value,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				QueueStatus::Failed->value
			)
		);

		return 1 === $wpdb->rows_affected;
	}

	/**
	 * Wiersze kolejki jednego konta, od najnowszego, BEZ kolumny `body`.
	 *
	 * Metoda zasila eksport danych osobowych, a treść maila w eksporcie nie wychodzi: snapshot rodzaju
	 * `confirmation` niesie żywy token potwierdzenia, a `password_reset` klucz resetu hasła. Kolumny,
	 * której nikt nie czyta, nie ma w zapytaniu, więc nieuważny wywołujący nie ma jak wydać tokenu przejęcia konta.
	 *
	 * @param int $userId Konto.
	 * @param int $limit  Rozmiar strony.
	 * @param int $offset Przesunięcie.
	 * @return array<int, array{id: int, kind: string, recipient: string, subject: string, status: string, created_at: string, sent_at: ?string, anonymized: bool}>
	 */
	public static function for_user( int $userId, int $limit, int $offset ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, kind, recipient, subject, status, created_at, sent_at, anonymized FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
				Migrations::table( self::TABLE ),
				$userId,
				max( 0, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'id'         => (int) $row['id'],
				'kind'       => (string) $row['kind'],
				'recipient'  => (string) $row['recipient'],
				'subject'    => (string) $row['subject'],
				'status'     => (string) $row['status'],
				'created_at' => (string) $row['created_at'],
				'sent_at'    => null === $row['sent_at'] ? null : (string) $row['sent_at'],
				'anonymized' => '1' === (string) $row['anonymized'],
			);
		}

		return $result;
	}

	/**
	 * Anonimizuje wiersze konta: nadpisuje adresata, temat i treść znacznikiem, ustawia `anonymized`.
	 *
	 * Sama flaga nie anonimizowałaby niczego, bo dane zostają w wierszu. Wiersz musi przeżyć, bo tabela
	 * jest logiem wysyłek: wartością jest to, że mail wyszedł (`kind`, `created_at`, `sent_at`, `status`,
	 * `attempts` zostają). `last_error` jest zerowany (wysłany) albo ustawiany na `CANCELLED_BY_ERASURE`. Wiersz, który NIE wyszedł (status inny niż `sent`), dostaje też `failed`. Wiersz, który nie wyszedł i już nie wyjdzie, ma to mówić, zamiast udawać,
	 * że czeka: `queued` trafiałby do `due()` i dispatcher słałby na nie-adres, a ostrzeżenie o zaległej
	 * kolejce paliłoby się w panelu na zawsze. `dedupe_key` też zostaje: jego nadpisanie pozwoliłoby wysłać ten sam mail
	 * drugi raz, a unikalny indeks jest jedyną odpornością kolejki na duplikaty.
	 * Operacja jest idempotentna: rusza tylko wiersze z `anonymized = 0`, bo rdzeń woła eraser w pętli.
	 *
	 * @param int $userId Konto.
	 * @return int Liczba wierszy faktycznie zmienionych.
	 */
	public static function anonymize_user( int $userId ): int {
		global $wpdb;

		$table = Migrations::table( self::TABLE );
		$ids   = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND anonymized = 0', $table, $userId )
		);

		$changed = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			// Warunek anonymized = 0 powtórzony przy zapisie: równoległe wywołanie nie policzy wiersza drugi raz.
			$updated = $wpdb->query(
				$wpdb->prepare(
					// Kolejność przypisań ma znaczenie: MySQL liczy je od lewej i `status` czytany po zmianie dałby już `failed`.
					// `last_error` czyścimy też wysłanym wierszom: komunikat serwera pocztowego z wcześniejszej próby potrafi nieść adres odbiorcy.
					'UPDATE %i SET recipient = %s, subject = %s, body = %s, last_error = IF(status = %s, NULL, %s), status = IF(status = %s, status, %s), anonymized = 1 WHERE id = %d AND anonymized = 0',
					$table,
					self::ANONYMIZED_PLACEHOLDER,
					self::ANONYMIZED_PLACEHOLDER,
					self::ANONYMIZED_PLACEHOLDER,
					QueueStatus::Sent->value,
					self::CANCELLED_BY_ERASURE,
					QueueStatus::Sent->value,
					QueueStatus::Failed->value,
					(int) $id
				)
			);
			if ( is_int( $updated ) ) {
				$changed += $updated;
			}
		}

		return $changed;
	}
}
