<?php
/**
 * Testy spięcia: że wtyczka podpina ekrany pod hook funkcji i że każdy formularz ma
 * handler pod swoją akcją admin-post. Suity ekranów wołają handlery bezpośrednio,
 * więc bez tych testów brakująca linia add_action() zostawiłaby wszystko zielone.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Admin\MatrixScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Admin\ProfessionsScreen;
use PortalUsers\Admin\SettingsScreen;
use PortalUsers\Cron\PurgeUnconfirmed;
use PortalUsers\Dependencies;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\Confirmation;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\LoginGateway;
use PortalUsers\Front\LoginHandler;
use PortalUsers\Front\PasswordSetup;
use PortalUsers\Front\Redirects;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Front\SessionGuard;
use PortalUsers\Front\TermsBinding;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;
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
			RegistrationHandler::ACTION,
			LoginHandler::ACTION,
			Confirmation::ACTION_RESEND,
			PasswordSetup::ACTION,
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
			'registration' => array( RegistrationForm::class ),
			'registration handler' => array( RegistrationHandler::class ),
			'login form' => array( LoginForm::class ),
			'login handler' => array( LoginHandler::class ),
			'redirects' => array( Redirects::class ),
			'confirmation' => array( Confirmation::class ),
			'password setup' => array( PasswordSetup::class ),
			'terms binding' => array( TermsBinding::class ),
			'purge unconfirmed' => array( PurgeUnconfirmed::class ),
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

	public function test_login_gateway_register_hooks_the_gate_behind_the_core_password_check(): void {
		// Rdzeń trzyma swoje weryfikatory hasła na priorytecie 20 (wp-includes/default-filters.php),
		// więc brama MUSI stać za nimi. Na filtrze `wp_authenticate_user` dostawałaby konto jeszcze
		// PRZED `wp_check_password()` i sama zdradzałaby stan konta temu, kto zna sam adres.
		LoginGateway::register();
		$this->assertSame( LoginGateway::PRIORITY, has_filter( 'authenticate', array( LoginGateway::class, 'gate' ) ) );
		$this->assertGreaterThan( 20, LoginGateway::PRIORITY );
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

	public function test_the_resend_form_posts_to_the_registered_action(): void {
		Confirmation::register();
		preg_match( '/<input type="hidden" name="action" value="([^"]+)"/', Confirmation::resend_form(), $m );
		$this->assertSame( Confirmation::ACTION_RESEND, $m[1] ?? '' );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . ( $m[1] ?? '' ) ) );
	}
}
