<?php
/**
 * Menu panelu: stałe uprawnienia i slugów oraz podstrona ustawień.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Właściciel stałych panelu i adresów jego ekranów. Podstrona ustawień rejestruje się tutaj;
 * ekrany profesji i macierzy rejestrują swoje podstrony same, każdy w swoim register().
 */
final class Menu {

	public const CAPABILITY = 'manage_options';
	public const PARENT     = 'users.php';

	public const SLUG_SETTINGS       = 'pusers-settings';
	public const SLUG_PROFESSIONS    = 'pusers-professions';
	public const SLUG_MATRIX         = 'pusers-matrix';
	public const SLUG_MAIL_TEMPLATES = 'pusers-mail-templates';
	public const SLUG_MAIL_LOG       = 'pusers-mail-log';
	public const SLUG_APPLICATIONS   = 'pusers-applications';

	/**
	 * Podpina rejestrację podstrony ustawień pod `admin_menu`.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_pages' ) );
	}

	/**
	 * Dodaje podstronę ustawień pod menu Użytkownicy.
	 */
	public static function add_pages(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Portal: ustawienia', 'portal-users' ),
			__( 'Portal: ustawienia', 'portal-users' ),
			self::CAPABILITY,
			self::SLUG_SETTINGS,
			array( SettingsScreen::class, 'render' )
		);
	}

	/**
	 * Adres ekranu panelu. Wartość nie jest eskejpowana: robi to miejsce użycia.
	 *
	 * @param string               $slug Slug podstrony.
	 * @param array<string, mixed> $args Dodatkowe argumenty zapytania.
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( self::PARENT ) );
	}
}
