<?php
/**
 * Repozytorium ustawień ogólnych.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Config\Settings;

/**
 * Repozytorium ustawień ogólnych.
 */
final class SettingsRepository {

	/**
	 * Wczytuje ustawienia; braki i śmieci normalizuje domena.
	 */
	public static function get(): Settings {
		return Settings::fromArray( OptionStore::read( OptionStore::OPTION_SETTINGS ) );
	}

	/**
	 * Zapisuje ustawienia.
	 *
	 * @param Settings $settings Wartość do zapisu.
	 */
	public static function save( Settings $settings ): bool {
		return OptionStore::write( OptionStore::OPTION_SETTINGS, $settings->toArray() );
	}
}
