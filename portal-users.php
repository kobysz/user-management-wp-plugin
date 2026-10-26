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

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

\PortalUsers\Plugin::boot( __FILE__ );

// Hook aktywacji musi stać w zasięgu pliku głównego, nie wewnątrz innego hooka.
register_activation_hook( __FILE__, array( \PortalUsers\Persistence\Migrations::class, 'run' ) );

// Aktualizacja wtyczki nie odpala hooka aktywacji. Bezwarunkowo, bez zależności od ACF Pro.
add_action( 'plugins_loaded', array( \PortalUsers\Persistence\Migrations::class, 'maybe_upgrade' ) );
