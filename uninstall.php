<?php
/**
 * Odinstalowanie wtyczki Portal Users.
 *
 * Cienki punkt wejścia; cała decyzja i logika jest w Uninstaller (domyślnie nic nie kasuje).
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	// Bez autoloadera nie ma czego wywołać; usunięcie wtyczki ma się powieść, dane zostają.
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\PortalUsers\Persistence\Uninstaller::run();
