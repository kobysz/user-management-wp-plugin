<?php
/**
 * Punkt wejścia wtyczki: ścieżki i wersja.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers;

use PortalUsers\Admin\ApplicationsScreen;
use PortalUsers\Admin\ExportHandler;
use PortalUsers\Admin\MailLogScreen;
use PortalUsers\Admin\MailQueueNotice;
use PortalUsers\Admin\MailTemplatesScreen;
use PortalUsers\Admin\MatrixScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Admin\ProfessionsScreen;
use PortalUsers\Admin\ProfileSync;
use PortalUsers\Admin\SettingsScreen;
use PortalUsers\Cron\DispatchMail;
use PortalUsers\Cron\PurgeMailLog;
use PortalUsers\Cron\PurgeUnconfirmed;
use PortalUsers\Front\Confirmation;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\LoginGateway;
use PortalUsers\Front\LoginHandler;
use PortalUsers\Front\PasswordReset;
use PortalUsers\Front\PasswordSetup;
use PortalUsers\Front\Redirects;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Front\RestUsers;
use PortalUsers\Front\SessionGuard;
use PortalUsers\Front\TermsBinding;
use PortalUsers\Mail\CoreCapture;

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

		// Z tego samego powodu poza hookiem zależnym od ACF Pro stoi strażnik sesji: repozytorium
		// stanu odpala akcję zmiany stanu niezależnie od ACF, więc strażnik za sprawdzeniem
		// zależności przestawałby niszczyć sesje odrzuconych kont, gdy tylko ACF Pro zniknie.
		SessionGuard::register();

		// Zamknięcie tras użytkowników REST dla obcych też stoi poza hookiem zależnym od ACF Pro:
		// to kontrola ujawniania (czy konto istnieje), więc znikając razem z zależnością otwierałaby
		// dokładnie tę furtkę, którą brama logowania zamknęła. Nie potrzebuje ACF do niczego.
		RestUsers::register();

		// Z tego samego powodu poza hookiem zależnym od ACF Pro stoi cron opróżniający kolejkę maili:
		// wyłączone (albo zepsute aktualizacją) ACF Pro zatrzymałoby wysyłkę maili, które JUŻ SĄ
		// w kolejce — w tym linku potwierdzającego, bez którego lekarz nie wejdzie na konto.
		// Dispatcher nie potrzebuje ACF do niczego: czyta wiersz i woła wp_mail(). Ekrany panelu
		// z logiem wysyłek zostają za zależnością.
		DispatchMail::register();

		// Retencja logu wysyłek też nie zależy od ACF Pro: kolejka rośnie, kiedy dispatcher chodzi,
		// więc czyszczenie musi chodzić razem z nim.
		PurgeMailLog::register();

		// I z tego samego powodu poza hookiem zależnym od ACF Pro stoi przechwytywanie maila
		// rdzeniowego: odzyskiwanie hasła stoi na mechanizmie rdzenia i musi działać także wtedy,
		// gdy ACF Pro jest wyłączone albo zepsute aktualizacją. Przechwytywanie nie potrzebuje ACF
		// do niczego: rozbrojone przepuszcza każdą wiadomość nietkniętą, a uzbraja je filtr treści
		// z `PasswordReset`, który sam jedzie już za sprawdzeniem zależności.
		CoreCapture::register();

		// Ekran wniosków też stoi poza hookiem zależnym od ACF Pro, choć reszta ekranów panelu jedzie za nim.
		// Brama logowania działa bez ACF Pro i dalej trzyma ludzi na zewnątrz, maile do redakcji już wyszły,
		// a konta czekają w stanie `pending`: gdyby ekran znikał razem z ACF, awaria ACF zamykałaby portal
		// dla każdego oczekującego lekarza bez żadnej drogi ratunku z panelu. Egzekwowanie zostaje, funkcje
		// się bramkuje, a rozstrzyganie wniosków należy do egzekwowania. Jedynie panel szczegółów potrzebuje
		// ACF i sam mówi, gdy go nie ma.
		ApplicationsScreen::register();

		// Eksport wniosków z tego samego powodu: jego przycisk stoi na ekranie, który działa bez ACF Pro.
		// Przy leżącym ACF plik po prostu nie ma kolumn pól.
		ExportHandler::register();

		// Gating dopiero na plugins_loaded: wtedy wszystkie wtyczki (w tym ACF Pro)
		// są już załadowane, niezależnie od kolejności katalogów.
		add_action( 'plugins_loaded', array( self::class, 'load_features' ) );

		// Ekrany panelu rejestrują się dopiero przy spełnionych zależnościach (hook odpala load_features()).
		add_action( 'pusers_register_features', array( Menu::class, 'register' ) );
		add_action( 'pusers_register_features', array( SettingsScreen::class, 'register' ) );
		add_action( 'pusers_register_features', array( ProfessionsScreen::class, 'register' ) );
		add_action( 'pusers_register_features', array( MatrixScreen::class, 'register' ) );
		add_action( 'pusers_register_features', array( MailTemplatesScreen::class, 'register' ) );
		add_action( 'pusers_register_features', array( MailLogScreen::class, 'register' ) );
		// Komunikat o niedrenowanej kolejce stoi po stronie ekranów panelu, a nie przy dispatcherze:
		// to powiadomienie dla człowieka w panelu, a nie część wysyłki. Kolejka opróżnia się dalej
		// bez ACF Pro; komunikat o tym, że przestała, jest wtedy jedną z rzeczy, które milczą.
		add_action( 'pusers_register_features', array( MailQueueNotice::class, 'register' ) );
		add_action( 'pusers_register_features', array( RegistrationForm::class, 'register' ) );
		add_action( 'pusers_register_features', array( LoginForm::class, 'register' ) );
		add_action( 'pusers_register_features', array( LoginHandler::class, 'register' ) );
		add_action( 'pusers_register_features', array( Redirects::class, 'register' ) );
		add_action( 'pusers_register_features', array( RegistrationHandler::class, 'register' ) );
		add_action( 'pusers_register_features', array( Confirmation::class, 'register' ) );
		add_action( 'pusers_register_features', array( PasswordSetup::class, 'register' ) );
		add_action( 'pusers_register_features', array( PasswordReset::class, 'register' ) );
		add_action( 'pusers_register_features', array( TermsBinding::class, 'register' ) );
		add_action( 'pusers_register_features', array( PurgeUnconfirmed::class, 'register' ) );
		// Most ACF do metadanych wtyczki stoi ZA bramką (w przeciwieństwie do ekranu wniosków): bez ACF Pro
		// nie ma czego synchronizować, a hook `acf/save_post` i tak by nie odpalił.
		add_action( 'pusers_register_features', array( ProfileSync::class, 'register' ) );
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
