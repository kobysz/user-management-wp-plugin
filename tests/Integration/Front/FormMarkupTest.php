<?php
/**
 * Testy znacznika formularzy frontu: klasy Bootstrapa na polach, które rysuje sama wtyczka.
 *
 * Pola profilu z ACF mają własny test (`AcfFieldsTest`) — tu chodzi o to, co wtyczka wypisuje
 * sama: etykiety, kontrolki, przyciski i pułapka na boty, która klas dostać NIE MOŻE.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\Confirmation;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\PasswordReset;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class FormMarkupTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array( array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ) )
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		$_GET = array();
		parent::tear_down();
	}

	public function test_rejestracja_ma_klasy_na_polach_konta_i_przycisku(): void {
		$html = RegistrationForm::render();

		$this->assertStringContainsString( 'class="pusers-registration pusers-form"', $html );
		$this->assertMatchesRegularExpression( '/<label class="form-label" for="pusers_profession"/', $html );
		$this->assertMatchesRegularExpression( '/<select class="form-select" id="pusers_profession"/', $html );
		$this->assertMatchesRegularExpression( '/<label class="form-label" for="pusers_email"/', $html );
		$this->assertMatchesRegularExpression( '/<input class="form-control" type="email" id="pusers_email"/', $html );
		$this->assertStringContainsString( 'class="btn btn-primary"', $html );
	}

	public function test_logowanie_ma_klasy_na_polach_i_na_zapamietaj_mnie(): void {
		$html = LoginForm::render();

		$this->assertStringContainsString( 'class="pusers-login pusers-form"', $html );
		$this->assertMatchesRegularExpression( '/<input class="form-control" type="text" id="pusers_login_identity"/', $html );
		$this->assertMatchesRegularExpression( '/<input class="form-control" type="password" id="pusers_login_password"/', $html );
		$this->assertStringContainsString( 'class="pusers-field-remember mb-3 form-check"', $html );
		$this->assertStringContainsString( 'class="form-check-input"', $html );
		$this->assertStringContainsString( 'class="form-check-label"', $html );
		$this->assertStringContainsString( 'class="btn btn-primary"', $html );
	}

	public function test_prosba_o_reset_ma_klasy(): void {
		$html = PasswordReset::render();

		$this->assertStringContainsString( 'class="pusers-password-reset pusers-form"', $html );
		$this->assertMatchesRegularExpression( '/<label class="form-label" for="pusers_reset_email"/', $html );
		$this->assertStringContainsString( 'class="btn btn-primary"', $html );
	}

	public function test_formularz_nowego_hasla_ma_klasy(): void {
		$user  = self::factory()->user->create_and_get();
		$_GET  = array(
			PasswordReset::USER_ARG => $user->user_login,
			PasswordReset::KEY_ARG  => get_password_reset_key( $user ),
		);
		$html  = PasswordReset::render();

		$this->assertStringContainsString( 'class="pusers-password-new pusers-form"', $html );
		$this->assertMatchesRegularExpression( '/<input class="form-control" type="password" id="pusers_reset_password"/', $html );
		$this->assertStringContainsString( 'class="btn btn-primary"', $html );
	}

	public function test_formularz_ponownej_wysylki_ma_klasy(): void {
		$html = Confirmation::resend_form();

		$this->assertStringContainsString( 'class="pusers-resend pusers-form"', $html );
		$this->assertStringContainsString( 'class="form-control"', $html );
		$this->assertStringContainsString( 'class="btn btn-primary"', $html );
	}

	public function test_pulapka_na_boty_nie_dostaje_zadnej_klasy(): void {
		// Pułapka ma zostać niewidoczna. Klasa Bootstrapa na jej polu albo etykiecie pokazałaby
		// ją człowiekowi, a wypełniona pułapka to odmowa zgłoszenia.
		foreach ( array( RegistrationForm::render(), LoginForm::render(), PasswordReset::render() ) as $html ) {
			$this->assertMatchesRegularExpression( '/position:absolute;left:-9999px/', $html );
			$this->assertDoesNotMatchRegularExpression( '/<input class="[^"]*" type="text" id="pusers_pole_(kontrolne|zwrotne)"/', $html );
			$this->assertDoesNotMatchRegularExpression( '/<label class="[^"]*" for="pusers_pole_(kontrolne|zwrotne)"/', $html );
		}
	}
}
