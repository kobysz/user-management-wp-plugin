<?php
/**
 * Punkt wejścia wtyczki: ścieżki i wersja.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers;

defined( 'ABSPATH' ) || exit;

/**
 * Rejestr podstawowych faktów o wtyczce. Wersja ma jedno źródło: nagłówek `Version:`.
 */
final class Plugin {

	/**
	 * Ścieżka pliku głównego.
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Cache wersji.
	 *
	 * @var string|null
	 */
	private static ?string $version = null;

	/**
	 * Zapamiętuje plik główny. Kolejne zadania podepną tu hooki.
	 *
	 * @param string $pluginFile Ścieżka pliku głównego wtyczki.
	 */
	public static function boot( string $pluginFile ): void {
		self::$plugin_file = $pluginFile;
		self::$version     = null;
	}

	/**
	 * Wersja z nagłówka pliku głównego.
	 *
	 * @param bool $fresh Pomija cache (żądanie aktualizacji: pliki na dysku są już nowe).
	 */
	public static function version( bool $fresh = false ): string {
		if ( ! $fresh && null !== self::$version ) {
			return self::$version;
		}

		if ( function_exists( 'get_file_data' ) ) {
			$data    = get_file_data( self::plugin_file(), array( 'Version' => 'Version' ) );
			$version = $data['Version'];
		} else {
			$version = self::parse_version( self::plugin_file() );
		}

		self::$version = $version;
		return $version;
	}

	/**
	 * Czyta `Version:` z nagłówka pliku, bez WordPressa.
	 *
	 * @param string $file Ścieżka pliku z nagłówkiem wtyczki.
	 */
	public static function parse_version( string $file ): string {
		// Nagłówek WP mieści się w pierwszych 8 KB pliku.
		$head = file_get_contents( $file, false, null, 0, 8192 );
		if ( false !== $head && 1 === preg_match( '/^[ \t\/*#@]*Version:[ \t]*(\S+)/mi', $head, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Ścieżka pliku głównego wtyczki.
	 */
	public static function plugin_file(): string {
		return self::$plugin_file;
	}

	/**
	 * Ścieżka bezwzględna w katalogu wtyczki.
	 *
	 * @param string $relative Ścieżka względna.
	 */
	public static function path( string $relative = '' ): string {
		$base = rtrim( dirname( self::$plugin_file ), '/\\' ) . '/';
		return $base . ltrim( $relative, '/\\' );
	}
}
