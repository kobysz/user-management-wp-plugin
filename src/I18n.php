<?php
/**
 * Ładowanie tłumaczeń wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers;

defined( 'ABSPATH' ) || exit;

/**
 * Językiem źródłowym jest polski; pliki .mo innych języków leżą w `languages/`.
 */
final class I18n {

	public const TEXT_DOMAIN = 'portal-users';

	/**
	 * Podpina ładowanie tłumaczeń na `init`.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'load' ) );
	}

	/**
	 * Ładuje text domain z katalogu `languages/` wtyczki.
	 */
	public static function load(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, self::relative_languages_dir() );
	}

	/**
	 * Katalog tłumaczeń względem `wp-content/plugins` (zgodnie z `Domain Path`).
	 */
	public static function relative_languages_dir(): string {
		return dirname( plugin_basename( Plugin::plugin_file() ) ) . '/languages';
	}
}
