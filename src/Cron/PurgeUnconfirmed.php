<?php
/**
 * Cron usuwający konta, których adres e-mail nigdy nie został potwierdzony.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Cron;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\PurgeSelection;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;

/**
 * Konto WordPressa powstaje już przy wysłaniu formularza, więc adres jest zajęty od tej chwili.
 * Bez czyszczenia porzucona rejestracja blokowałaby adres na zawsze.
 *
 * To jedyna operacja wtyczki, która nieodwracalnie usuwa konta. Decyzję podejmuje wyłącznie
 * PurgeSelection; run() przegląda konta paczkami, pyta domenę o każde i kasuje. Kandydatów NIE
 * zawęża się w zapytaniu: stan czyta repozytorium i rozstrzyga domena, więc konto bez stanu
 * albo ze stanem nierozpoznanym nie ma jak się prześlizgnąć.
 */
final class PurgeUnconfirmed {

	public const HOOK = 'pusers_purge_unconfirmed';

	/**
	 * Zdarzenie w logu: konto usunięte. To jedyny ślad po nim.
	 */
	public const LOG_ACTION = 'account_purged';

	private const DEFAULT_BATCH = 200;

	/**
	 * Podpina przebieg pod hook crona. Harmonogram zakładają aktywacja i jednorazowa migracja
	 * (Migrations::run), NIE to wywołanie: biegnie przy każdym żądaniu, a administrator, który
	 * zdjął zdarzenie (np. WP Crontrol), nie może go odzyskiwać przy najbliższym wejściu na stronę.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Zakłada dzienny harmonogram, o ile jeszcze go nie ma. Pierwszy przebieg za dobę, nie od razu:
	 * na instalacji, która chodziła bez crona, czyszczenie jest nieodwracalne i administrator
	 * musi zdążyć zobaczyć ustawienie okna, zanim usunie się zaległy stos.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Zdejmuje harmonogram; po usunięciu wtyczki zostawiony cron wołałby nieistniejącą funkcję.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Czy wtyczka działa w sieci witryn. Stała MULTISITE jest zdefiniowana w całym procesie, więc
	 * testu pojedynczej witryny nie da się przełączyć na sieć; filtr jest szwem testowym tej jednej decyzji.
	 */
	public static function is_network(): bool {
		return (bool) apply_filters( 'pusers_purge_is_multisite', is_multisite() );
	}

	/**
	 * Usuwa konta zakwalifikowane przez domenę i zapisuje każde usunięcie w logu.
	 */
	public static function run(): void {
		// Na sieci wp_delete_user() tylko odpina konto od witryny i zwraca true, więc konto zostaje,
		// adres nadal jest zajęty, a log kłamałby o usunięciu. Multisite jest poza zakresem wtyczki.
		if ( self::is_network() ) {
			return;
		}

		// W kontekście crona wp_delete_user() nie jest załadowane.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$now       = time();
		$selection = new PurgeSelection( SettingsRepository::get()->unconfirmedPurgeDays );
		$batch     = max( 1, (int) apply_filters( 'pusers_purge_batch_size', self::DEFAULT_BATCH ) );
		$kept      = 0; // Konta, które zostały: o tyle przesuwamy offset, bo usunięte znikają z wyników.

		do {
			$users = get_users(
				array(
					'fields'  => array( 'ID', 'user_registered', 'user_email' ),
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => $batch,
					'offset'  => $kept,
				)
			);

			// Bez tego każdy get() w pętli byłby osobnym zapytaniem o metadane.
			update_meta_cache( 'user', array_map( static fn ( $user ): int => (int) $user->ID, $users ) );

			foreach ( $users as $user ) {
				$id           = (int) $user->ID;
				$registeredAt = (int) strtotime( $user->user_registered . ' UTC' );

				if ( ! $selection->qualifies( AccountStatusRepository::get( $id ), $registeredAt, $now ) ) {
					++$kept;
					continue;
				}
				if ( wp_delete_user( $id ) ) {
					EventLog::write(
						self::LOG_ACTION,
						$id,
						0,
						array(
							'registered_at' => $user->user_registered,
							'email_hash'    => hash( 'sha256', strtolower( $user->user_email ) ),
						)
					);
				} else {
					++$kept;
				}
			}
			$fetched = count( $users );
		} while ( $fetched === $batch );
	}
}
