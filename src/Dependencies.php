<?php
/**
 * Strażnik zależności: wtyczka wymaga ACF Pro.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers;

defined( 'ABSPATH' ) || exit;

/**
 * Sprawdza obecność ACF Pro i pokazuje komunikat zamiast wywalać witrynę.
 */
final class Dependencies {

	private const ACF_FREE = 'advanced-custom-fields/acf.php';

	/**
	 * Kody braków. Pusta lista = zależności spełnione.
	 *
	 * Czysta funkcja: stan podawany argumentami, żeby dała się testować bez WordPressa.
	 *
	 * @param string[] $activePlugins      Aktywne wtyczki (ścieżki `katalog/plik.php`).
	 * @param bool     $acfProClassExists  Czy ACF Pro jest załadowany.
	 * @return string[] `acf_pro`, a przy aktywnym darmowym ACF dodatkowo `acf_free_active`.
	 */
	public static function missing( array $activePlugins, bool $acfProClassExists ): array {
		if ( $acfProClassExists ) {
			return array();
		}

		$codes = array( 'acf_pro' );
		if ( in_array( self::ACF_FREE, $activePlugins, true ) ) {
			$codes[] = 'acf_free_active';
		}
		return $codes;
	}

	/**
	 * Czy ACF Pro jest załadowany (klasa `acf_pro` lub stała `ACF_PRO`).
	 */
	private static function acf_pro_loaded(): bool {
		return class_exists( 'acf_pro', false ) || defined( 'ACF_PRO' );
	}

	/**
	 * Zbiera aktywne wtyczki witryny i sieci.
	 *
	 * @return string[]
	 */
	private static function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_filter( $active, 'is_string' ) );
	}

	/**
	 * Lista braków w bieżącym stanie WordPressa.
	 *
	 * @return string[]
	 */
	private static function current_missing(): array {
		return self::missing( self::active_plugins(), self::acf_pro_loaded() );
	}

	/**
	 * Czy zależności są spełnione.
	 */
	public static function satisfied(): bool {
		return array() === self::current_missing();
	}

	/**
	 * Podpina komunikat w panelu administratora.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Wypisuje komunikat o brakującym ACF Pro.
	 */
	public static function render_notice(): void {
		$missing = self::current_missing();
		if ( array() === $missing ) {
			return;
		}

		if ( in_array( 'acf_free_active', $missing, true ) ) {
			$message = __( 'Wtyczka Portal Users wymaga ACF Pro. Aktywny jest darmowy ACF, który nie wystarcza — zainstaluj i aktywuj ACF Pro.', 'portal-users' );
		} else {
			$message = __( 'Wtyczka Portal Users wymaga ACF Pro. Zainstaluj i aktywuj ACF Pro — do tego czasu Portal Users pozostaje nieaktywny.', 'portal-users' );
		}

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
