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
 * PurgeSelection; run() wybiera kandydatów po dacie rejestracji, pyta domenę o każdego i kasuje.
 * Kandydatów NIE zawęża się po stanie w zapytaniu: stan czyta repozytorium i rozstrzyga domena,
 * więc konto bez stanu albo ze stanem nierozpoznanym nie ma jak się prześlizgnąć.
 */
final class PurgeUnconfirmed {

	public const HOOK = 'pusers_purge_unconfirmed';

	/**
	 * Zdarzenie w logu: konto usunięte. To jedyny ślad po nim.
	 */
	public const LOG_ACTION = 'account_purged';

	/**
	 * Podpina przebieg pod hook crona i dba, by harmonogram istniał także po aktualizacji
	 * wtyczki zainstalowanej przed wprowadzeniem crona (aktualizacja nie odpala aktywacji).
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		self::schedule();
	}

	/**
	 * Zakłada dzienny harmonogram, o ile jeszcze go nie ma.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Zdejmuje harmonogram; po usunięciu wtyczki zostawiony cron wołałby nieistniejącą funkcję.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Usuwa konta zakwalifikowane przez domenę i zapisuje każde usunięcie w logu.
	 */
	public static function run(): void {
		// W kontekście crona wp_delete_user() nie jest załadowane.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$now       = time();
		$selection = new PurgeSelection( SettingsRepository::get()->unconfirmedPurgeDays );

		$users = get_users(
			array(
				'fields'     => array( 'ID', 'user_registered' ),
				'date_query' => array(
					array(
						'column' => 'user_registered',
						'before' => gmdate( 'Y-m-d H:i:s', $now ),
					),
				),
			)
		);

		foreach ( $users as $user ) {
			$id           = (int) $user->ID;
			$registeredAt = (int) strtotime( $user->user_registered . ' UTC' );

			if ( ! $selection->qualifies( AccountStatusRepository::get( $id ), $registeredAt, $now ) ) {
				continue;
			}
			if ( wp_delete_user( $id ) ) {
				EventLog::write( self::LOG_ACTION, $id, 0, array( 'registered_at' => $user->user_registered ) );
			}
		}
	}
}
