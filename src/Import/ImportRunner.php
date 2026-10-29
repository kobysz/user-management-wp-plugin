<?php
/**
 * Przebieg importu po wszystkich kontach portalu: tryb próbny i zapis.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Import;

use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Import\AccountChange;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Import\ImportPlanner;
use PortalUsers\Domain\Import\ImportReport;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\UserMeta;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Obchodzi konta partiami po `BATCH`, każdemu daje do zaplanowania jego metadane źródłowe i składa raport.
 *
 * Tryb próbny i zapis przechodzą tę samą drogę, więc ich liczby się zgadzają: obie porównują plan z tym,
 * co konto już ma, i do `changed` liczą tylko konta, w których coś by się zmieniło (zapis: zmieniło). Różnią się
 * tylko tym, że zapis wykonuje zmiany, sprawdza je odczytem zwrotnym i zostawia ślad w logu zdarzeń.
 */
final class ImportRunner {

	/**
	 * Tyle kont naraz czyta jedno zapytanie o identyfikatory.
	 */
	public const BATCH = 200;

	/**
	 * Wiersz logu: przebieg z zapisem (jeden na przebieg).
	 */
	public const LOG_APPLIED = 'import_applied';

	/**
	 * Wiersz logu: przebieg miał nieudane zapisy.
	 */
	public const LOG_WRITE_FAILED = 'import_write_failed';

	/**
	 * Liczy, co zapis by zmienił, niczego nie zapisując: ani metadanych, ani stanu, ani wiersza logu.
	 *
	 * @param ImportMapping $mapping Mapowanie kluczy źródłowych.
	 * @return ImportReport
	 */
	public function dry_run( ImportMapping $mapping ): ImportReport {
		return $this->walk( $mapping, false );
	}

	/**
	 * Zapisuje import i zostawia w logu jeden wiersz zbiorczy z aktorem (oraz drugi, gdy zapis się nie udał).
	 *
	 * Porażka któregokolwiek zapisu do logu nie cofa danych, ale raport ją niesie (`logFailed()`): jedyny ślad tego,
	 * kto zaimportował co, to właśnie ten wiersz, więc ekran nie może pokazać sukcesu bez ostrzeżenia.
	 *
	 * Puste mapowanie nie czyta żadnego klucza, więc nie rusza kont i nie tworzy wiersza logu.
	 *
	 * @param ImportMapping $mapping     Mapowanie kluczy źródłowych.
	 * @param int           $actorUserId Administrator uruchamiający import.
	 * @return ImportReport
	 */
	public function apply( ImportMapping $mapping, int $actorUserId ): ImportReport {
		$report = $this->walk( $mapping, true );

		if ( $mapping->isEmpty() ) {
			return $report;
		}

		$counters = $report->toArray();
		unset( $counters['samples'], $counters['write_failure_sample'], $counters['log_failed'] );
		$counters['source_keys'] = $mapping->sourceKeys();

		$logged = EventLog::write( self::LOG_APPLIED, 0, $actorUserId, $counters );

		if ( $report->writeFailures() > 0 ) {
			$failedLogged = EventLog::write(
				self::LOG_WRITE_FAILED,
				0,
				$actorUserId,
				array(
					'write_failures' => $report->writeFailures(),
					'source_keys'    => $mapping->sourceKeys(),
				)
			);
			$logged       = $logged && $failedLogged;
		}

		return $logged ? $report : $report->withLogFailure();
	}

	/**
	 * Obchodzi wszystkie konta i składa raport.
	 *
	 * Konta idą stronami po `BATCH`, rosnąco po ID. ID jest unikalne, a import go nie zmienia, więc strony
	 * się nie przesuwają. `number` nigdy nie jest zerem, bo to oznacza „bez limitu”.
	 *
	 * @param ImportMapping $mapping Mapowanie kluczy źródłowych.
	 * @param bool          $write   Czy wykonywać zapis (prawda) czy tylko liczyć (fałsz).
	 * @return ImportReport
	 */
	private function walk( ImportMapping $mapping, bool $write ): ImportReport {
		$report = ImportReport::empty();

		if ( $mapping->isEmpty() ) {
			return $report;
		}

		$planner = new ImportPlanner( $mapping, ProfessionRepository::get(), FieldMatrixRepository::get(), new ValidatorRegistry() );
		$keys    = $mapping->sourceKeys();
		$page    = 1;

		do {
			$query = new WP_User_Query(
				array(
					'fields'      => 'ID',
					'number'      => self::BATCH,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'paged'       => $page,
					'count_total' => false,
				)
			);
			$ids   = array_map( 'intval', $query->get_results() );
			$count = count( $ids );

			foreach ( $ids as $userId ) {
				$planned = $this->pending( $planner->plan( $userId, $this->sourceMeta( $userId, $keys ) ) );

				if ( ! $write || ! $planned->hasWrites() ) {
					$report = $report->with( $planned );
					continue;
				}

				if ( $this->store( $planned ) ) {
					$report = $report->with( $planned );
					continue;
				}

				// Konto jest policzone i ma swoje zastrzeżenia, ale nie jako zmienione.
				$report = $report->with( new AccountChange( $userId, array(), null, null, $planned->issues ) )->withWriteFailure( $userId );
			}

			++$page;
		} while ( self::BATCH === $count );

		return $report;
	}

	/**
	 * Wybiera z metadanych konta tylko klucze źródłowe, z pierwszą wartością każdego.
	 *
	 * `get_user_meta()` bez klucza oddaje wartości w postaci surowej z bazy, więc przed przekazaniem planerowi
	 * przechodzą przez `maybe_unserialize()`, tak jak przy odczycie z kluczem; tablica staje się tablicą i planer ją zgłasza.
	 * Bez tego serializowana tablica ze starej wtyczki wyglądałaby dla planera na zwykły tekst, nie zostałaby zgłoszona
	 * jako nieużyteczna i trafiłaby do naszych metadanych jako serializowany łańcuch.
	 *
	 * @param int      $userId Identyfikator konta.
	 * @param string[] $keys   Klucze źródłowe z mapowania.
	 * @return array<string, mixed>
	 */
	private function sourceMeta( int $userId, array $keys ): array {
		$all    = get_user_meta( $userId );
		$source = array();

		if ( ! is_array( $all ) ) {
			return $source;
		}

		foreach ( $keys as $key ) {
			if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) && array_key_exists( 0, $all[ $key ] ) ) {
				$source[ $key ] = maybe_unserialize( $all[ $key ][0] );
			}
		}

		return $source;
	}

	/**
	 * Zostawia z planu tylko to, co konto ma jeszcze inaczej: wartość identyczna z zapisaną nie jest zmianą.
	 *
	 * `update_user_meta()` zwraca fałsz także przy wartości identycznej, więc porównanie musi być przed zapisem.
	 *
	 * @param AccountChange $change Pełny plan dla konta.
	 * @return AccountChange Plan bez pozycji, które konto już ma.
	 */
	private function pending( AccountChange $change ): AccountChange {
		$fields = array();

		foreach ( $change->fields as $fieldKey => $value ) {
			if ( get_user_meta( $change->userId, UserMeta::ACF_PREFIX . $fieldKey, true ) !== $value ) {
				$fields[ $fieldKey ] = $value;
			}
		}

		$profession = $change->professionKey;

		if ( null !== $profession && get_user_meta( $change->userId, UserMeta::PROFESSION, true ) === $profession ) {
			$profession = null;
		}

		$status = $change->status;

		if ( null !== $status && AccountStatusRepository::get( $change->userId ) === $status ) {
			$status = null;
		}

		return new AccountChange( $change->userId, $fields, $profession, $status, $change->issues );
	}

	/**
	 * Wykonuje zapis planu i każdą pozycję potwierdza odczytem zwrotnym.
	 *
	 * Próbuje wszystkich pozycji nawet po pierwszej porażce, bo żadna transakcja nie spina kilku `update_user_meta()`.
	 * Konto policzone jako porażka zapisu mogło więc dostać część wartości, dlatego po usunięciu przyczyny należy
	 * uruchomić import ponownie; jest to bezpieczne, bo porównanie przed zapisem pomija to, co już się zgadza.
	 *
	 * @param AccountChange $change Plan z samymi pozycjami do zapisu.
	 * @return bool Prawda, gdy każda pozycja po zapisie ma oczekiwaną wartość.
	 */
	private function store( AccountChange $change ): bool {
		$ok = true;

		foreach ( $change->fields as $fieldKey => $value ) {
			$ok = $this->storeMeta( $change->userId, UserMeta::ACF_PREFIX . $fieldKey, $value ) && $ok;
		}

		if ( null !== $change->professionKey ) {
			$ok = $this->storeMeta( $change->userId, UserMeta::PROFESSION, $change->professionKey ) && $ok;
		}

		if ( null !== $change->status ) {
			AccountStatusRepository::set( $change->userId, $change->status );
			$ok = AccountStatusRepository::get( $change->userId ) === $change->status && $ok;
		}

		return $ok;
	}

	/**
	 * Zapisuje jedną metadaną i czyta ją z powrotem.
	 *
	 * @param int    $userId Identyfikator konta.
	 * @param string $key    Klucz metadanej.
	 * @param string $value  Wartość do zapisu.
	 * @return bool Prawda, gdy po zapisie odczyt daje dokładnie tę wartość.
	 */
	private function storeMeta( int $userId, string $key, string $value ): bool {
		update_user_meta( $userId, $key, $value );

		return get_user_meta( $userId, $key, true ) === $value;
	}
}
