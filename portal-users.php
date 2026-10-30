<?php
/**
 * Plugin Name:       Portal Users
 * Description:       Zarządzanie użytkownikami portali medycznych: rejestracja z weryfikacją uprawnień zawodowych, akceptacja kont, własne strony logowania.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Eorta.pl
 * License:           GPL-2.0-or-later
 * Text Domain:       portal-users
 * Domain Path:       /languages
 * Update URI:        https://github.com/kobysz/portal-users-wp-plugin
 *
 * Nagłówek „Update URI" nie jest ozdobą: od WordPressa 5.8 powstrzymuje rdzeń od podstawiania
 * pod nasz katalog aktualizacji wtyczki o tym samym slugu z wordpress.org. Slug „portal-users"
 * jest generyczny, a podmiana wtyczki zarządzającej kontami lekarzy na obcą wtyczkę o tej samej
 * nazwie katalogu byłaby awarią, z której nie ma drogi powrotu z panelu. Aktualizacje podstawia
 * `PortalUsers\Update\GitHubUpdater`; zgodność tego adresu ze stałą `REPO` aktualizatora trzyma
 * test spójności wydania.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	// Bez autoloadera żadna klasa wtyczki nie istnieje. Zamiast fatala mówimy, co zrobić, i nic nie podpinamy.
	$pusers_autoload_notice = static function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Wtyczka Portal Users nie może wystartować: brakuje katalogu vendor (autoloadera). Uruchom „composer install” w katalogu wtyczki albo zainstaluj wtyczkę z paczki wydania.', 'portal-users' )
		);
	};
	add_action( 'admin_notices', $pusers_autoload_notice );
	add_action( 'network_admin_notices', $pusers_autoload_notice );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\PortalUsers\Plugin::boot( __FILE__ );

// Hook aktywacji musi stać w zasięgu pliku głównego, nie wewnątrz innego hooka.
register_activation_hook( __FILE__, array( \PortalUsers\Persistence\Migrations::class, 'run' ) );

// Aktualizacja wtyczki nie odpala hooka aktywacji. Bezwarunkowo, bez zależności od ACF Pro.
add_action( 'plugins_loaded', array( \PortalUsers\Persistence\Migrations::class, 'maybe_upgrade' ) );

// Znacznik wdrożenia: aktywacja niczego nie nadaje, tylko zapisuje moment, od którego liczy się „konto sprzed wtyczki”.
// Zapisuje się raz; ponowna aktywacja nie przesuwa granicy. Aktualizacja w miejscu też nie odpala hooka aktywacji,
// więc na portalu, który już działał na wcześniejszej wersji, znacznik zapisuje także plugins_loaded (bez efektu, gdy już jest).
// Konta założone przez samą wtyczkę zawsze mają stan, więc późne zapisanie znacznika nie wciąga ich w zakres backfillu.
register_activation_hook( __FILE__, array( \PortalUsers\Account\Backfill::class, 'record_activation' ) );
add_action( 'plugins_loaded', array( \PortalUsers\Account\Backfill::class, 'record_activation' ) );

// Cron czyszczący niepotwierdzone konta: harmonogram zakładany przy aktywacji, zdejmowany przy deaktywacji.
register_activation_hook( __FILE__, array( \PortalUsers\Cron\PurgeUnconfirmed::class, 'schedule' ) );
register_deactivation_hook( __FILE__, array( \PortalUsers\Cron\PurgeUnconfirmed::class, 'unschedule' ) );

// Cron opróżniający kolejkę maili: ten sam układ. Deaktywacja zdejmuje też zdarzenia natychmiastowe.
register_activation_hook( __FILE__, array( \PortalUsers\Cron\DispatchMail::class, 'schedule' ) );
register_deactivation_hook( __FILE__, array( \PortalUsers\Cron\DispatchMail::class, 'unschedule' ) );

// Cron retencji logu wysyłek: ten sam układ.
register_activation_hook( __FILE__, array( \PortalUsers\Cron\PurgeMailLog::class, 'schedule' ) );
register_deactivation_hook( __FILE__, array( \PortalUsers\Cron\PurgeMailLog::class, 'unschedule' ) );
