<?php
/**
 * Testy: błąd widoczny PRZY POLU, nie tylko na liście nad formularzem.
 *
 * Pola oznaczamy wyłącznie tam, gdzie wtyczka wie, którego pola błąd dotyczy. Logowanie jest
 * świadomym wyjątkiem i ma tu własny test — patrz jego komentarz.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Front\LoginForm;
use PortalUsers\Front\LoginHandler;
use PortalUsers\Front\PasswordReset;
use WP_UnitTestCase;

final class FieldErrorsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_nieudane_logowanie_nie_oznacza_zadnego_pola(): void {
		// TEST BEZPIECZEŃSTWA, nie wyglądu. `RESULT_BAD` jest świadomie nieokreślony: nie mówi,
		// czy zły był login, czy hasło. Oznaczenie któregokolwiek pola na czerwono ujawniłoby,
		// która połowa poświadczeń pasuje, czyli zamieniłoby formularz logowania w wyrocznię
		// o istnieniu kont. Czerwony alert nad formularzem zostaje; pola zostają czyste.
		$_GET = array( LoginForm::RESULT_ARG => LoginHandler::RESULT_BAD );

		$html = LoginForm::render();

		$this->assertStringContainsString( 'alert alert-danger', $html );
		$this->assertStringNotContainsString( 'is-invalid', $html );
		$this->assertStringNotContainsString( 'invalid-feedback', $html );
	}

	public function test_za_krotkie_haslo_oznacza_pole_hasla(): void {
		$user = self::factory()->user->create_and_get();
		$_GET = array(
			PasswordReset::USER_ARG   => $user->user_login,
			PasswordReset::KEY_ARG    => get_password_reset_key( $user ),
			PasswordReset::RESULT_ARG => PasswordReset::RESULT_SHORT,
		);

		$html = PasswordReset::render();

		$this->assertMatchesRegularExpression( '/<input class="form-control is-invalid" type="password" id="pusers_reset_password"/', $html );
		$this->assertStringContainsString( 'invalid-feedback', $html );
	}

	public function test_zly_adres_oznacza_pole_adresu(): void {
		$_GET = array( PasswordReset::RESULT_ARG => PasswordReset::RESULT_EMAIL );

		$html = PasswordReset::render();

		$this->assertMatchesRegularExpression( '/<input class="form-control is-invalid" type="email" id="pusers_reset_email"/', $html );
		$this->assertStringContainsString( 'invalid-feedback', $html );
	}

	public function test_udana_wysylka_linku_nie_oznacza_pola_adresu(): void {
		$_GET = array( PasswordReset::RESULT_ARG => PasswordReset::RESULT_SENT );

		$html = PasswordReset::render();

		$this->assertStringNotContainsString( 'is-invalid', $html );
	}
}
