<?php
/**
 * Testy spięcia: że wtyczka podpina ekrany pod hook funkcji i że każdy formularz ma
 * handler pod swoją akcją admin-post. Suity ekranów wołają handlery bezpośrednio,
 * więc bez tych testów brakująca linia add_action() zostawiłaby wszystko zielone.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Account\Backfill;
use PortalUsers\Admin\ApplicationsScreen;
use PortalUsers\Admin\BackfillNotice;
use PortalUsers\Admin\ExportHandler;
use PortalUsers\Admin\HelpScreen;
use PortalUsers\Admin\ImportScreen;
use PortalUsers\Privacy\PrivacyProvider;
use PortalUsers\Admin\MailQueueNotice;
use PortalUsers\Admin\MatrixScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Admin\ProfessionsScreen;
use PortalUsers\Admin\ProfileSync;
use PortalUsers\Admin\SettingsScreen;
use PortalUsers\Cron\DispatchMail;
use PortalUsers\Cron\PurgeUnconfirmed;
use PortalUsers\Dependencies;
use PortalUsers\Domain\Config\Settings;
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
use PortalUsers\Mail\Dispatcher;
use PortalUsers\Mail\MailQueue;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;
use PortalUsers\Update\GitHubUpdater;
use WP_UnitTestCase;

final class WiringTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_action( 'plugins_loaded', array( Plugin::class, 'load_features' ) );
		remove_all_actions( 'pusers_register_features' );
		remove_all_actions( 'admin_menu' );
		foreach ( $this->adminPostActions() as $action ) {
			remove_all_actions( 'admin_post_' . $action );
			remove_all_actions( 'admin_post_nopriv_' . $action );
		}
	}

	/**
	 * @return list<string> Wszystkie akcje admin-post wtyczki.
	 */
	private function adminPostActions(): array {
		return array(
			SettingsScreen::ACTION,
			ProfessionsScreen::ACTION,
			ProfessionsScreen::ACTION_DELETE,
			MatrixScreen::ACTION_SAVE,
			MatrixScreen::ACTION_DELETE_FIELD,
			ImportScreen::ACTION_SAVE,
			ImportScreen::ACTION_PREVIEW,
			ImportScreen::ACTION_APPLY,
			ImportScreen::ACTION_BACKFILL,
			RegistrationHandler::ACTION,
			LoginHandler::ACTION,
			Confirmation::ACTION_RESEND,
			PasswordSetup::ACTION,
			PasswordReset::ACTION_REQUEST,
			PasswordReset::ACTION_SET,
		);
	}

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public static function features(): array {
		return array(
			'menu'        => array( Menu::class ),
			'settings'    => array( SettingsScreen::class ),
			'professions' => array( ProfessionsScreen::class ),
			'matrix'      => array( MatrixScreen::class ),
			'import'      => array( ImportScreen::class ),
			'mail queue notice' => array( MailQueueNotice::class ),
			'backfill notice' => array( BackfillNotice::class ),
			'registration' => array( RegistrationForm::class ),
			'registration handler' => array( RegistrationHandler::class ),
			'login form' => array( LoginForm::class ),
			'login handler' => array( LoginHandler::class ),
			'redirects' => array( Redirects::class ),
			'confirmation' => array( Confirmation::class ),
			'password setup' => array( PasswordSetup::class ),
			'password reset' => array( PasswordReset::class ),
			'terms binding' => array( TermsBinding::class ),
			'purge unconfirmed' => array( PurgeUnconfirmed::class ),
			'profile sync' => array( ProfileSync::class ),
		);
	}

	/**
	 * @dataProvider features
	 *
	 * @param class-string $screen Klasa ekranu.
	 */
	public function test_boot_hooks_each_screen_into_the_features_hook( string $screen ): void {
		Plugin::boot( Plugin::plugin_file() );
		$this->assertNotFalse(
			has_action( 'pusers_register_features', array( $screen, 'register' ) ),
			$screen . '::register() nie jest podpięte pod pusers_register_features.'
		);
	}

	public function test_znacznik_wdrozenia_jest_zapisywany_takze_przy_aktualizacji_w_miejscu(): void {
		// Aktualizacja nie odpala hooka aktywacji, więc znacznik musi wejść przez plugins_loaded.
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Backfill::class, 'record_activation' ) ) );
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Migrations::class, 'maybe_upgrade' ) ) );
	}

	public function test_settings_register_hooks_its_save_handler(): void {
		SettingsScreen::register();
		$this->assertNotFalse( has_action( 'admin_post_' . SettingsScreen::ACTION, array( SettingsScreen::class, 'handle_save' ) ) );
	}

	public function test_professions_register_hooks_page_and_both_handlers(): void {
		ProfessionsScreen::register();
		$this->assertNotFalse( has_action( 'admin_menu', array( ProfessionsScreen::class, 'add_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . ProfessionsScreen::ACTION, array( ProfessionsScreen::class, 'handle_save' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . ProfessionsScreen::ACTION_DELETE, array( ProfessionsScreen::class, 'handle_delete' ) ) );
	}

	public function test_applications_screen_hooks_its_page_outside_the_features_hook(): void {
		// Ekran wniosków jest ostatnią drogą ratunku, gdy ACF Pro zniknie: brama logowania dalej
		// trzyma oczekujących na zewnątrz, więc ekran nie może zniknąć razem z zależnością.
		ApplicationsScreen::register();
		$this->assertNotFalse( has_action( 'admin_menu', array( ApplicationsScreen::class, 'add_page' ) ) );

		remove_all_actions( 'admin_menu' );
		Plugin::boot( Plugin::plugin_file() );
		$this->assertFalse( has_action( 'pusers_register_features', array( ApplicationsScreen::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'admin_menu', array( ApplicationsScreen::class, 'add_page' ) ) );
	}

	public function test_help_screen_hooks_its_page_outside_the_features_hook(): void {
		// Bez ACF Pro Menu::register() nie odpala i pod Użytkownikami nie ma ŻADNEGO ekranu wtyczki.
		// To jest dokładnie moment, w którym administrator potrzebuje instrukcji, więc ekran pomocy
		// nie może zniknąć razem z zależnością.
		HelpScreen::register();
		$this->assertNotFalse( has_action( 'admin_menu', array( HelpScreen::class, 'add_page' ) ) );

		remove_all_actions( 'admin_menu' );
		Plugin::boot( Plugin::plugin_file() );
		$this->assertFalse( has_action( 'pusers_register_features', array( HelpScreen::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'admin_menu', array( HelpScreen::class, 'add_page' ) ) );
	}

	public function test_export_handler_hooks_its_action_outside_the_features_hook(): void {
		// Eksport jest potrzebny także wtedy, gdy ACF Pro leży: ekran wniosków działa bez niego, więc jego przycisk musi mieć uchwyt.
		ExportHandler::register();
		$this->assertNotFalse( has_action( 'admin_post_' . ExportHandler::ACTION, array( ExportHandler::class, 'handle' ) ) );

		remove_all_actions( 'admin_post_' . ExportHandler::ACTION );
		Plugin::boot( Plugin::plugin_file() );
		$this->assertFalse( has_action( 'pusers_register_features', array( ExportHandler::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . ExportHandler::ACTION, array( ExportHandler::class, 'handle' ) ) );
	}

	public function test_privacy_exporter_is_hooked_outside_the_features_hook(): void {
		// Żądanie RODO ma termin ustawowy: eksporter nie może czekać na naprawę wtyczki pól.
		remove_all_filters( 'wp_privacy_personal_data_exporters' );
		remove_all_filters( 'wp_privacy_personal_data_erasers' );
		Plugin::boot( Plugin::plugin_file() );
		$this->assertFalse( has_action( 'pusers_register_features', array( PrivacyProvider::class, 'register' ) ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_exporters', array( PrivacyProvider::class, 'register_exporter' ) ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', array( PrivacyProvider::class, 'register_eraser' ) ) );
	}

	public function test_matrix_register_hooks_page_and_both_handlers(): void {
		MatrixScreen::register();
		$this->assertNotFalse( has_action( 'admin_menu', array( MatrixScreen::class, 'add_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . MatrixScreen::ACTION_SAVE, array( MatrixScreen::class, 'handle_save' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . MatrixScreen::ACTION_DELETE_FIELD, array( MatrixScreen::class, 'handle_delete_field' ) ) );
	}

	public function test_every_rendered_form_action_has_a_handler_behind_it(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'PWZ',
				),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'             => 'lekarz',
						'label'           => 'Lekarz',
						'credential_type' => 'physician_pwz',
					),
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		SettingsScreen::register();
		ProfessionsScreen::register();
		MatrixScreen::register();

		$seen = array();
		foreach ( array( SettingsScreen::class, ProfessionsScreen::class, MatrixScreen::class ) as $screen ) {
			ob_start();
			$screen::render();
			$html = (string) ob_get_clean();
			preg_match_all( '/<input type="hidden" name="action" value="([^"]+)"/', $html, $m );
			$this->assertNotEmpty( $m[1], $screen . ' nie wyrenderował żadnego formularza z akcją.' );
			foreach ( $m[1] as $action ) {
				$seen[] = $action;
				$this->assertNotFalse( has_action( 'admin_post_' . $action ), 'Brak handlera dla akcji formularza: ' . $action );
			}
		}
		// Formularze zapisu i usuwania profesji oraz zapisu macierzy i ustawień faktycznie się pojawiły.
		foreach ( array( SettingsScreen::ACTION, ProfessionsScreen::ACTION, ProfessionsScreen::ACTION_DELETE, MatrixScreen::ACTION_SAVE ) as $expected ) {
			$this->assertContains( $expected, $seen );
		}
	}

	public function test_confirmation_register_hooks_the_registered_action_the_link_and_the_resend_handler(): void {
		Confirmation::register();
		$this->assertNotFalse( has_action( RegistrationHandler::REGISTERED_HOOK, array( Confirmation::class, 'on_registered' ) ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( Confirmation::class, 'handle' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . Confirmation::ACTION_RESEND, array( Confirmation::class, 'handle_resend' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Confirmation::ACTION_RESEND, array( Confirmation::class, 'handle_resend' ) ) );
	}

	public function test_password_setup_register_hooks_its_handler_for_logged_out_visitors_too(): void {
		// Konto po potwierdzeniu adresu NIE jest zalogowane (hasła jeszcze nie ma), więc bez
		// wariantu `nopriv` formularz ustawienia hasła nie miałby kto obsłużyć.
		PasswordSetup::register();
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . PasswordSetup::ACTION, array( PasswordSetup::class, 'handle' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . PasswordSetup::ACTION, array( PasswordSetup::class, 'handle' ) ) );
	}

	public function test_password_reset_register_hooks_its_handler_the_shortcode_and_the_mail_filters(): void {
		// Niezalogowany wysyła ten formularz najczęściej, ale wariant `admin_post_` jest tu równie
		// potrzebny: `Redirects` odcięło lekarzowi `wp-admin`, więc hasło zmienia on zalogowany,
		// właśnie tą drogą. Filtry treści maila podmieniają odnośnik rdzenia na adres strony
		// wtyczki — bez nich mail prowadziłby na `wp-login.php?action=rp`.
		PasswordReset::register();
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . PasswordReset::ACTION_REQUEST, array( PasswordReset::class, 'handle_request' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . PasswordReset::ACTION_REQUEST, array( PasswordReset::class, 'handle_request' ) ) );
		// Drugi formularz tej strony: ustawienie nowego hasła z klucza z maila.
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . PasswordReset::ACTION_SET, array( PasswordReset::class, 'handle_set' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . PasswordReset::ACTION_SET, array( PasswordReset::class, 'handle_set' ) ) );
		// Zakaz cache musi pojechać PRZED renderem: z `the_content` nagłówki wychodzą za późno.
		$this->assertNotFalse( has_action( 'template_redirect', array( PasswordReset::class, 'on_template_redirect' ) ) );
		$this->assertTrue( shortcode_exists( PasswordReset::SHORTCODE ) );
		$this->assertNotFalse( has_filter( 'retrieve_password_title', array( PasswordReset::class, 'mail_title' ) ) );
		$this->assertNotFalse( has_filter( 'retrieve_password_message', array( PasswordReset::class, 'mail_message' ) ) );
	}

	public function test_login_gateway_register_hooks_the_gate_behind_the_core_password_check(): void {
		// Rdzeń trzyma swoje weryfikatory hasła na priorytecie 20 (wp-includes/default-filters.php),
		// więc brama MUSI stać za nimi. Na filtrze `wp_authenticate_user` dostawałaby konto jeszcze
		// PRZED `wp_check_password()` i sama zdradzałaby stan konta temu, kto zna sam adres.
		//
		// Za samym hasłem to jednak NIE WSZYSTKO: `wp_signon()` dokłada z wnętrza swojego ciała
		// PIĄTY callback — `wp_authenticate_cookie` na priorytecie 30 (`wp-includes/user.php:107`)
		// — który przy pustym loginie I pustym haśle podnosi rdzeniowy błąd do `WP_User` wziętego
		// z ważnego ciasteczka sesji. Brama rejestrowana przy ładowaniu wtyczki szłaby wewnątrz
		// priorytetu 30 pierwsza, więc tego `WP_User` nigdy by nie zobaczyła. Dlatego liczy się
		// nie „za 20”, a „za uwierzytelniaczem ciasteczka”.
		LoginGateway::register();
		$this->assertSame( LoginGateway::PRIORITY, has_filter( 'authenticate', array( LoginGateway::class, 'gate' ) ) );
		$this->assertGreaterThan( 30, LoginGateway::PRIORITY, 'Brama musi stać za uwierzytelniaczem ciasteczka (priorytet 30), nie tylko za weryfikatorami hasła (20).' );
		$this->assertLessThan( 99, LoginGateway::PRIORITY, 'Brama musi stać przed wp_authenticate_spam_check (priorytet 99).' );
		$this->assertFalse( has_filter( 'wp_authenticate_user', array( LoginGateway::class, 'gate' ) ) );

		// Druga droga wejścia: REST. Rdzeń trzyma tam swoje weryfikatory na 90 i 100
		// (`rest_application_password_check_errors`, `rest_cookie_check_errors`), więc brama
		// musi stać za nimi, czyli wyżej niż 100.
		$this->assertSame( LoginGateway::REST_PRIORITY, has_filter( 'rest_authentication_errors', array( LoginGateway::class, 'gate_rest' ) ) );
		$this->assertGreaterThan( 100, LoginGateway::REST_PRIORITY );
	}

	public function test_boot_registers_the_login_gate_even_when_acf_pro_is_missing(): void {
		// Zamek na drzwiach wejściowych nie może zależeć od wtyczki od pól. Gdyby brama jechała
		// przez `pusers_register_features` (hook odpala się tylko przy spełnionych zależnościach),
		// wyłączone albo zepsute aktualizacją ACF Pro wpuszczałoby każde konto `email_unconfirmed`,
		// `pending`, `rejected` i z nierozpoznanym stanem.
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		remove_filter( 'authenticate', array( LoginGateway::class, 'gate' ), LoginGateway::PRIORITY );
		remove_filter( 'rest_authentication_errors', array( LoginGateway::class, 'gate_rest' ), LoginGateway::REST_PRIORITY );
		$this->assertFalse( Dependencies::satisfied(), 'Test ma działać przy NIEspełnionych zależnościach.' );
		$this->assertFalse( has_filter( 'authenticate', array( LoginGateway::class, 'gate' ) ), 'Brama miała być zdjęta przed próbą.' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertFalse(
			has_action( 'pusers_register_features', array( LoginGateway::class, 'register' ) ),
			'Brama nie może jechać przez hook zależny od ACF Pro.'
		);
		$this->assertSame( LoginGateway::PRIORITY, has_filter( 'authenticate', array( LoginGateway::class, 'gate' ) ) );
		$this->assertSame( LoginGateway::REST_PRIORITY, has_filter( 'rest_authentication_errors', array( LoginGateway::class, 'gate_rest' ) ) );
	}

	public function test_load_features_hooks_the_import_screen_save_action(): void {
		add_filter( 'pusers_acf_pro_loaded', '__return_true' );
		$this->assertTrue( Dependencies::satisfied(), 'Test ma działać przy spełnionych zależnościach.' );
		$this->assertFalse( has_action( 'admin_post_' . ImportScreen::ACTION_SAVE ), 'Uchwyt miał być zdjęty przed próbą.' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertNotFalse( has_action( 'admin_post_' . ImportScreen::ACTION_SAVE, array( ImportScreen::class, 'handle_save' ) ) );
		$this->assertNotFalse( has_action( 'admin_menu', array( ImportScreen::class, 'add_page' ) ) );
	}

	public function test_ekran_importu_jest_podpiety(): void {
		add_filter( 'pusers_acf_pro_loaded', '__return_true' );
		$this->assertTrue( Dependencies::satisfied(), 'Test ma działać przy spełnionych zależnościach.' );
		$handlers = array(
			ImportScreen::ACTION_SAVE    => 'handle_save',
			ImportScreen::ACTION_PREVIEW => 'handle_preview',
			ImportScreen::ACTION_APPLY   => 'handle_apply',
		);
		foreach ( array_keys( $handlers ) as $action ) {
			$this->assertFalse( has_action( 'admin_post_' . $action ), 'Uchwyt miał być zdjęty przed próbą.' );
		}

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		foreach ( $handlers as $action => $method ) {
			$this->assertNotFalse( has_action( 'admin_post_' . $action, array( ImportScreen::class, $method ) ), $action );
		}
	}

	public function test_boot_registers_the_session_guard_even_when_acf_pro_is_missing(): void {
		// Repozytorium stanu odpala akcję niezależnie od ACF Pro. Strażnik za sprawdzeniem zależności
		// przestawałby niszczyć sesje odrzuconych kont w chwili, gdy ACF Pro zniknie.
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		remove_action( 'pusers_account_status_changed', array( SessionGuard::class, 'on_status_changed' ), 10 );
		$this->assertFalse( Dependencies::satisfied(), 'Test ma działać przy NIEspełnionych zależnościach.' );
		$this->assertFalse( has_action( 'pusers_account_status_changed', array( SessionGuard::class, 'on_status_changed' ) ), 'Strażnik miał być zdjęty przed próbą.' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertFalse(
			has_action( 'pusers_register_features', array( SessionGuard::class, 'register' ) ),
			'Strażnik nie może jechać przez hook zależny od ACF Pro.'
		);
		$this->assertSame( 10, has_action( 'pusers_account_status_changed', array( SessionGuard::class, 'on_status_changed' ) ) );
	}

	public function test_boot_registers_the_users_route_closure_even_when_acf_pro_is_missing(): void {
		// To kontrola ujawniania (czy konto istnieje). Za sprawdzeniem zależności znikałaby razem
		// z ACF Pro i otwierała furtkę, którą brama logowania właśnie zamknęła.
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		remove_filter( 'rest_endpoints', array( RestUsers::class, 'restrict' ) );
		$this->assertFalse( Dependencies::satisfied(), 'Test ma działać przy NIEspełnionych zależnościach.' );
		$this->assertFalse( has_filter( 'rest_endpoints', array( RestUsers::class, 'restrict' ) ), 'Zamknięcie miało być zdjęte przed próbą.' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertFalse(
			has_action( 'pusers_register_features', array( RestUsers::class, 'register' ) ),
			'Zamknięcie tras nie może jechać przez hook zależny od ACF Pro.'
		);
		$this->assertNotFalse( has_filter( 'rest_endpoints', array( RestUsers::class, 'restrict' ) ) );
	}

	public function test_the_dispatcher_is_registered_without_acf(): void {
		// Kolejka musi się opróżniać także wtedy, gdy ACF Pro jest wyłączone — inaczej link
		// potwierdzający zostaje w bazie, a lekarz nie ma jak wejść na konto. Dispatcher nie
		// potrzebuje ACF do niczego: czyta wiersz i woła wp_mail().
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		remove_action( DispatchMail::HOOK, array( Dispatcher::class, 'run' ) );
		remove_action( MailQueue::DISPATCH_HOOK, array( Dispatcher::class, 'run' ) );
		$this->assertFalse( Dependencies::satisfied(), 'Test ma działać przy NIEspełnionych zależnościach.' );
		$this->assertFalse( has_action( DispatchMail::HOOK, array( Dispatcher::class, 'run' ) ), 'Dispatcher miał być zdjęty przed próbą.' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertFalse(
			has_action( 'pusers_register_features', array( DispatchMail::class, 'register' ) ),
			'Dispatcher nie może jechać przez hook zależny od ACF Pro.'
		);
		$this->assertNotFalse( has_action( DispatchMail::HOOK, array( Dispatcher::class, 'run' ) ) );
		$this->assertNotFalse( has_action( MailQueue::DISPATCH_HOOK, array( Dispatcher::class, 'run' ) ) );
	}

	/**
	 * Czy pod hookiem wisi wywołanie zwrotne na instancji danej klasy.
	 *
	 * `register()` podpina metody instancji, więc `has_filter()` z tablicą nie dopasuje
	 * innej instancji niż ta zapisana w rejestrze.
	 */
	private function filter_has_instance_of( string $hook, string $class ): bool {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ] as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && $fn[0] instanceof $class ) {
					return true;
				}
			}
		}

		return false;
	}

	public function test_boot_registers_the_updater_on_init_even_when_acf_pro_is_missing(): void {
		// Aktualizacja jest drogą naprawy portalu, więc nie może zależeć od wtyczki, której
		// zepsute wydanie właśnie naprawiamy. Na `init`, nie `admin_init`: inaczej cron
		// i WP-CLI nie widzą aktualizacji.
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		remove_action( 'init', array( GitHubUpdater::class, 'register' ) );
		$this->assertFalse( Dependencies::satisfied(), 'Test ma działać przy NIEspełnionych zależnościach.' );
		$this->assertFalse( has_action( 'init', array( GitHubUpdater::class, 'register' ) ), 'Aktualizator miał być zdjęty przed próbą.' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertFalse(
			has_action( 'pusers_register_features', array( GitHubUpdater::class, 'register' ) ),
			'Aktualizator nie może jechać przez hook zależny od ACF Pro.'
		);
		$this->assertNotFalse( has_action( 'init', array( GitHubUpdater::class, 'register' ) ) );
		$this->assertFalse(
			has_action( 'admin_init', array( GitHubUpdater::class, 'register' ) ),
			'Rejestracja na admin_init ukryłaby aktualizacje przed cronem i WP-CLI.'
		);
	}

	public function test_updater_register_hooks_the_update_transient_and_the_details_window(): void {
		GitHubUpdater::register();

		$this->assertTrue(
			$this->filter_has_instance_of( 'pre_set_site_transient_update_plugins', GitHubUpdater::class ),
			'Bez tego filtra wtyczka nigdy nie zgłosi się do mechanizmu aktualizacji.'
		);
		$this->assertTrue(
			$this->filter_has_instance_of( 'plugins_api', GitHubUpdater::class ),
			'Bez tego filtra okno „Szczegóły wtyczki" nie pokaże opisu wydania.'
		);
	}

	public function test_the_resend_form_posts_to_the_registered_action(): void {
		Confirmation::register();
		preg_match( '/<input type="hidden" name="action" value="([^"]+)"/', Confirmation::resend_form(), $m );
		$this->assertSame( Confirmation::ACTION_RESEND, $m[1] ?? '' );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . ( $m[1] ?? '' ) ) );
	}
}
