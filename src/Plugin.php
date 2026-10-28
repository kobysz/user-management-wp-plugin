<?php
/**
 * Punkt wejścia wtyczki: ścieżki i wersja.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers;

use PortalUsers\Admin\MatrixScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Admin\ProfessionsScreen;
use PortalUsers\Admin\SettingsScreen;
use PortalUsers\Cron\PurgeUnconfirmed;
use PortalUsers\Front\Confirmation;
use PortalUsers\Front\LoginGateway;
use PortalUsers\Front\PasswordSetup;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Front\TermsBinding;

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
	 * Zapamiętuje plik główny i podpina hooki. Komunikat o zależnościach, tłumaczenia oraz
	 * brama logowania działają zawsze; reszta wtyczki tylko przy spełnionych zależnościach.
	 *
	 * @param string $pluginFile Ścieżka pliku głównego wtyczki.
	 */
	public static function boot( string $pluginFile ): void {
		self::$plugin_file = $pluginFile;
		self::$version     = null;

		Dependencies::register();
		I18n::register();

		// Brama logowania NIE jedzie przez `pusers_register_features`: ten hook odpala się tylko
		// przy spełnionych zależnościach, więc wyłączone (albo zepsute aktualizacją) ACF Pro
		// zdejmowałoby zamek z drzwi wejściowych i każde konto `email_unconfirmed`, `pending`,
		// `rejected` oraz z nierozpoznanym stanem logowałoby się normalnie. „Wtyczka bezczynna,
		// nie fatal" jest dobrą polityką dla ekranów panelu i złą dla bramy. Brama nie potrzebuje
		// ACF do niczego: czyta metadaną stanu i pisze do logu zdarzeń.
		LoginGateway::register();

		// Gating dopiero na plugins_loaded: wtedy wszystkie wtyczki (w tym ACF Pro)
		// są już załadowane, niezależnie od kolejności katalogów.
		add_action( 'plugins_loaded', array( self::class, 'load_features' ) );

		// Ekrany panelu rejestrują się dopiero przy spełnionych zależnościach (hook odpala load_features()).
		add_action( 'pusers_register_features', array( Menu::class, 'register' ) );
		add_action( 'pusers_register_features', array( SettingsScreen::class, 'register' ) );
		add_action( 'pusers_register_features', array( ProfessionsScreen::class, 'register' ) );
		add_action( 'pusers_register_features', array( MatrixScreen::class, 'register' ) );
		add_action( 'pusers_register_features', array( RegistrationForm::class, 'register' ) );
		add_action( 'pusers_register_features', array( RegistrationHandler::class, 'register' ) );
		add_action( 'pusers_register_features', array( Confirmation::class, 'register' ) );
		add_action( 'pusers_register_features', array( PasswordSetup::class, 'register' ) );
		add_action( 'pusers_register_features', array( TermsBinding::class, 'register' ) );
		add_action( 'pusers_register_features', array( PurgeUnconfirmed::class, 'register' ) );
	}

	/**
	 * Rejestruje funkcjonalną część wtyczki, o ile zależności są spełnione.
	 */
	public static function load_features(): void {
		if ( ! Dependencies::satisfied() ) {
			return; // Brak ACF Pro: widoczny komunikat i bezczynna wtyczka, nie fatal.
		}

		/**
		 * Zależności spełnione: kolejne części wtyczki rejestrują się pod tym hookiem.
		 */
		do_action( 'pusers_register_features' );
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
		// Odczyt lokalnego pliku wtyczki, nie adresu zdalnego (reguła celuje w URL-e); get_file_data() jest niedostępne bez WordPressa.
		$head = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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
