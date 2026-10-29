<?php
/**
 * Znacznik wdrożenia wtyczki i nadanie stanu kontom, które istniały przed nią.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Account;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\UserMeta;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Konta założone przed wdrożeniem wtyczki nie mają stanu, więc żadna reguła bramy logowania ich nie obejmuje.
 * Aktywacja nie potrafi ich rozróżnić (mapowanie stanów starej wtyczki powstaje dopiero w narzędziu importu),
 * dlatego niczego nie nadaje: zapisuje tylko moment wdrożenia. Nadanie `approved` jest świadomą decyzją
 * administratora, przez import albo przez przycisk, który woła `grant_approved()`.
 *
 * Zakres to konta BEZ metadanej stanu zarejestrowane PRZED momentem wdrożenia. Konto bez stanu założone
 * później to konto dodane ręcznie w panelu: brama celowo je wpuszcza, a backfill go nie rusza.
 */
final class Backfill {

	/**
	 * Rozmiar partii przy obchodzie kont.
	 */
	public const BATCH = 200;

	/**
	 * Nazwa zdarzenia w logu: jeden wiersz na przebieg nadania.
	 */
	public const LOG_GRANTED = 'backfill_approved';

	/**
	 * Klucz w opcji znacznika, pod którym leży moment wdrożenia jako znacznik czasu UNIX.
	 * `OptionStore` zna tylko tablice, więc znacznik potrzebuje klucza.
	 */
	public const KEY_AT = 'at';

	/**
	 * Przejściowa kopia liczby kont w zakresie: komunikat liczy je na każdym wyświetleniu ekranu wtyczki,
	 * a zapytanie łączy `users` z `usermeta` i porównuje daty.
	 */
	public const TRANSIENT_COUNT = 'pusers_stateless_count';

	/**
	 * Jak długo liczba z `TRANSIENT_COUNT` bywa nieaktualna, jeśli nic jej nie unieważni.
	 */
	public const COUNT_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Zapisuje moment wdrożenia, ale tylko gdy go jeszcze nie ma. Aktywacja po deaktywacji nie może
	 * przesunąć granicy: konta zarejestrowane przez samą wtyczkę w międzyczasie wpadłyby w zakres backfillu.
	 * Znacznik uszkodzony (bez poprawnego czasu) liczy się jak brak i jest zapisywany od nowa.
	 */
	public static function record_activation(): void {
		if ( self::deployed_at() > 0 ) {
			return;
		}

		OptionStore::write( OptionStore::OPTION_DEPLOYED_AT, array( self::KEY_AT => time() ) );
	}

	/**
	 * Moment wdrożenia jako znacznik czasu UNIX albo 0, gdy go nie zapisano.
	 */
	public static function deployed_at(): int {
		$at = OptionStore::read( OptionStore::OPTION_DEPLOYED_AT )[ self::KEY_AT ] ?? 0;

		return is_int( $at ) && $at > 0 ? $at : 0;
	}

	/**
	 * Ile kont leży w zakresie backfillu. Bez znacznika wdrożenia nie ma granicy, więc zakres jest pusty.
	 *
	 * Liczba jest trzymana w krótkim transiencie (`COUNT_TTL`); nadanie stanu i zapis importu go kasują
	 * (`forget_count()`), więc nie kłamie o tym, co właśnie zmieniły. Zero z braku znacznika nie jest zapamiętywane.
	 */
	public static function stateless_count(): int {
		$deployedAt = self::deployed_at();
		if ( 0 === $deployedAt ) {
			return 0;
		}

		$cached = get_transient( self::TRANSIENT_COUNT );
		if ( is_int( $cached ) && $cached >= 0 ) {
			return $cached;
		}

		// Liczenie bez hydracji: jedno konto na stronie wystarcza, bo liczbę daje get_total().
		$query = self::query( $deployedAt, 1, 0, true );
		$count = (int) $query->get_total();

		set_transient( self::TRANSIENT_COUNT, $count, self::COUNT_TTL );

		return $count;
	}

	/**
	 * Kasuje zapamiętaną liczbę kont w zakresie, żeby następne wyświetlenie policzyło je od nowa.
	 */
	public static function forget_count(): void {
		delete_transient( self::TRANSIENT_COUNT );
	}

	/**
	 * Nadaje `approved` kontom z zakresu backfillu i zostawia jeden wiersz w logu, o ile ktoś je dostał.
	 *
	 * Konta idą partiami po `BATCH`, rosnąco po ID. Nadanie usuwa konto z zakresu, więc zakres kurczy się
	 * w trakcie obchodu; konto, któremu stanu nie udało się zapisać, zostaje w zakresie, dlatego przesunięcie
	 * strony rośnie o liczbę takich kont i obchód zawsze się kończy.
	 *
	 * @param int $actorUserId Administrator, który uruchomił nadanie.
	 * @return array{granted: int, not_granted: int, log_failed: bool} Liczba kont, które dostały stan; liczba kont,
	 *         którym zapis stanu się nie udał (zostają w zakresie); informacja, że wiersza w logu nie udało się
	 *         zapisać (dane i tak zostały zapisane).
	 */
	public static function grant_approved( int $actorUserId ): array {
		$deployedAt = self::deployed_at();
		if ( 0 === $deployedAt ) {
			return array(
				'granted'     => 0,
				'not_granted' => 0,
				'log_failed'  => false,
			);
		}

		$granted = 0;
		$skipped = 0;

		do {
			$query = self::query( $deployedAt, self::BATCH, $skipped, false );
			$ids   = array_map( 'intval', $query->get_results() );
			$count = count( $ids );

			foreach ( $ids as $userId ) {
				AccountStatusRepository::set( $userId, AccountStatus::Approved );

				// Powodzenie poznajemy po odczycie zwrotnym, tak jak robi to samo repozytorium.
				if ( AccountStatus::Approved === AccountStatusRepository::get( $userId ) ) {
					++$granted;
				} else {
					++$skipped;
				}
			}
		} while ( self::BATCH === $count );

		$logFailed = false;
		if ( $granted > 0 ) {
			self::forget_count();

			$logFailed = ! EventLog::write(
				self::LOG_GRANTED,
				0,
				$actorUserId,
				array(
					'granted'     => $granted,
					'not_granted' => $skipped,
					'deployed_at' => $deployedAt,
				)
			);
		}

		return array(
			'granted'     => $granted,
			'not_granted' => $skipped,
			'log_failed'  => $logFailed,
		);
	}

	/**
	 * Zapytanie o identyfikatory kont z zakresu: bez stanu i zarejestrowane przed wdrożeniem. „Bez stanu” to metadana
	 * nieobecna albo pusta, tak jak rozumie to `AccountStatusRepository` (pusta wartość nie jest „nierozpoznana”).
	 * Wartość niepusta, której wtyczka nie rozpoznaje, to czyjeś dane i nie wchodzi w zakres: nadpisanie jej
	 * mogłoby dać dostęp kontu, które ktoś oznaczył inaczej.
	 * `WP_User_Query` buduje `date_query` na kolumnie `user_registered`; granica jest wyłączna.
	 * `number` nigdy nie jest zerem, bo to oznacza „bez limitu” i hydrację wszystkich kont.
	 *
	 * @param int  $deployedAt Moment wdrożenia.
	 * @param int  $number     Rozmiar strony (dodatni).
	 * @param int  $offset     Przesunięcie strony.
	 * @param bool $countTotal Czy policzyć wszystkie pasujące konta (dla `get_total()`).
	 */
	private static function query( int $deployedAt, int $number, int $offset, bool $countTotal ): WP_User_Query {
		return new WP_User_Query(
			array(
				'fields'      => 'ID',
				'number'      => max( 1, $number ),
				'offset'      => $offset,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => $countTotal,
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => UserMeta::STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => UserMeta::STATUS,
						'value'   => '',
						'compare' => '=',
					),
				),
				'date_query'  => array(
					array(
						'before'    => gmdate( 'Y-m-d H:i:s', $deployedAt ),
						'inclusive' => false,
						'column'    => 'user_registered',
					),
				),
			)
		);
	}
}
