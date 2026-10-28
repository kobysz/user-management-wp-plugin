<?php
/**
 * Testy widoku formularza logowania.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

final class LoginFormTest extends WP_UnitTestCase {

	/**
	 * Ile razy odpalił się hook zakazu cache (patrz `countNoCache()`).
	 *
	 * @var int
	 */
	private int $noCacheFired = 0;

	public function tear_down(): void {
		unset( $_GET[ LoginForm::REDIRECT ] );
		remove_all_actions( 'litespeed_control_set_nocache' );
		remove_all_actions( LoginForm::EXTRA_FIELDS_ACTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function countNoCache(): void {
		RegistrationHandler::reset_no_cache();
		$this->noCacheFired = 0;
		add_action(
			'litespeed_control_set_nocache',
			function (): void {
				++$this->noCacheFired;
			}
		);
	}

	public function test_a_logged_in_visitor_sees_a_note_and_a_link_forward_not_the_form(): void {
		// Formularz logowania dla zalogowanego to albo zagadka, albo pętla.
		$after = self::factory()->post->create( array( 'post_type' => 'page' ) );
		SettingsRepository::save( Settings::fromArray( array( 'after_login_page_id' => $after ) ) );
		$user = self::factory()->user->create( array( 'display_name' => 'Anna Kowalska' ) );
		wp_set_current_user( $user );

		$html = LoginForm::render();

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( LoginForm::PASSWORD, $html );
		$this->assertStringContainsString( 'Anna Kowalska', $html );
		$this->assertStringContainsString( esc_url( (string) get_permalink( $after ) ), $html );
		$this->assertStringContainsString( esc_url( wp_logout_url() ), $html );
	}

	public function test_the_form_carries_a_nonce_a_honeypot_and_no_time_trap(): void {
		// Obecność pułapki czasowej jest tu BŁĘDEM, nie brakiem: logowanie wypełnia menedżer haseł
		// w ułamku sekundy, a pułapka trzech sekund odcięłaby właśnie jego użytkowników.
		$html = LoginForm::render();

		$this->assertStringContainsString( 'name="' . LoginForm::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( 'name="' . LoginForm::HONEYPOT . '"', $html );
		$this->assertStringContainsString( 'name="' . LoginForm::IDENTITY . '"', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'name="' . LoginForm::REMEMBER . '"', $html );

		// Brak jakiegokolwiek ukrytego pola ze znacznikiem czasu: żadne pole poza nonce'em, akcją
		// i (opcjonalnym) adresem docelowym nie może być ukrytym polem formularza.
		preg_match_all( '/<input[^>]*type="hidden"[^>]*name="([^"]+)"/', $html, $found );
		$allowed = array( 'action', LoginForm::NONCE_FIELD, '_wp_http_referer', LoginForm::REDIRECT );
		$this->assertSame( array(), array_values( array_diff( $found[1], $allowed ) ), 'Ukryte pole spoza nonce\'a i akcji to możliwa pułapka czasowa.' );
		$this->assertDoesNotMatchRegularExpression( '/time|stamp|_t"/i', implode( ' ', $found[1] ) );
	}

	public function test_the_honeypot_name_is_not_one_that_password_managers_fill(): void {
		foreach ( array( 'website', 'url', 'email', 'name', 'phone', 'address' ) as $magnet ) {
			$this->assertStringNotContainsString( $magnet, LoginForm::HONEYPOT );
		}
	}

	public function test_a_redirect_from_the_query_pointing_at_another_host_is_dropped(): void {
		$_GET[ LoginForm::REDIRECT ] = 'https://zly.example/przejecie';

		$html = LoginForm::render();

		$this->assertStringNotContainsString( 'zly.example', $html );
		$this->assertStringNotContainsString( 'name="' . LoginForm::REDIRECT . '"', $html );
	}

	public function test_a_relative_redirect_from_the_query_survives(): void {
		$_GET[ LoginForm::REDIRECT ] = '/artykul/';

		$html = LoginForm::render();

		$this->assertMatchesRegularExpression( '/name="' . LoginForm::REDIRECT . '"[^>]*value="[^"]*\/artykul\/"/', $html );
	}

	public function test_the_page_sends_no_cache_headers(): void {
		// Wywołujemy tylko to, co robi strona: hook przed renderem, potem render. Samo wołanie
		// `RegistrationHandler::no_cache()` w teście niczego by nie dowodziło.
		$this->countNoCache();
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[' . LoginForm::SHORTCODE . ']',
			)
		);
		LoginForm::register();

		$this->go_to( (string) get_permalink( $page ) );
		do_action( 'template_redirect' );

		$this->assertSame( 1, $this->noCacheFired, 'Zestaw zakazu cache jedzie z hooka, przed renderem.' );

		// Druga warstwa: formularz poza treścią wpisu (widżet, szablon) też wyłącza cache.
		remove_all_actions( 'litespeed_control_set_nocache' );
		$this->countNoCache();
		LoginForm::render();
		$this->assertSame( 1, $this->noCacheFired, 'Render sam wyłącza stronę z cache.' );
	}

	public function test_the_extension_point_fires_inside_the_form(): void {
		add_action(
			LoginForm::EXTRA_FIELDS_ACTION,
			static function (): void {
				echo '<input type="hidden" name="dodatek_testowy" value="1" />';
			}
		);

		$html = LoginForm::render();

		$form = strpos( $html, '<form' );
		$add  = strpos( $html, 'dodatek_testowy' );
		$end  = strpos( $html, '</form>' );
		$this->assertNotFalse( $add );
		$this->assertTrue( $form < $add && $add < $end, 'Pole dodatku stoi wewnątrz formularza.' );
	}

	public function test_base_url_is_the_login_page_from_the_settings(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		SettingsRepository::save( Settings::fromArray( array( 'login_page_id' => $page ) ) );

		$this->assertSame( get_permalink( $page ), LoginForm::base_url() );
	}

	public function test_the_form_registers_under_the_features_hook_not_outside_the_dependency_check(): void {
		// Formularz logowania nie jest zamkiem: gdy wtyczka bezczynnie czeka na ACF Pro, nie ma po
		// co renderować formularza, który nie zadziała. Wyjątek bramy i strażnika sesji nie dotyczy widoku.
		$this->assertNotFalse( has_action( 'pusers_register_features', array( LoginForm::class, 'register' ) ) );
	}
}
