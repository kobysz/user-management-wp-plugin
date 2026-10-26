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
