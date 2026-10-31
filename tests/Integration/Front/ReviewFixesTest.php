<?php
/**
 * Testy poprawek z recenzji całej gałęzi.
 *
 * Każdy z nich pilnuje czegoś, co wyglądało dobrze w kodzie, a kłamało człowiekowi na ekranie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\LoginGateway;
use PortalUsers\Front\LoginHandler;
use PortalUsers\Front\PasswordReset;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class ReviewFixesTest extends WP_UnitTestCase {

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

	public function test_komunikat_o_hasle_nie_staje_pod_polem_adresu(): void {
		// `RESULT_EMAIL` znaczy „hasło nie może być takie samo jak adres”. Formularz PROŚBY
		// o link nie ma pola hasła, więc nie ma tu czego oznaczać — a na pewno nie pola adresu.
		$_GET = array( PasswordReset::RESULT_ARG => PasswordReset::RESULT_EMAIL );

		$html = PasswordReset::render();

		$this->assertStringNotContainsString( 'is-invalid', $html, 'Formularz adresu nie ma pola, którego ten kod dotyczy.' );
	}

	public function test_haslo_rowne_adresowi_oznacza_pole_hasla(): void {
		$user = self::factory()->user->create_and_get();
		$_GET = array(
			PasswordReset::USER_ARG   => $user->user_login,
			PasswordReset::KEY_ARG    => get_password_reset_key( $user ),
			PasswordReset::RESULT_ARG => PasswordReset::RESULT_EMAIL,
		);

		$html = PasswordReset::render();

		$this->assertMatchesRegularExpression( '/<input class="form-control is-invalid" type="password" id="pusers_reset_password"/', $html );
	}

	public function test_komunikat_bledu_pola_profilu_stoi_w_odstepie_pola(): void {
		// Odstęp `mb-3` musi być na opakowaniu, które obejmuje POLE I JEGO KOMUNIKAT. Gdy siedzi
		// na opakowaniu ACF-a, czerwony tekst odkleja się od swojego pola i przykleja do
		// następnej etykiety — czyta się jak błąd pola poniżej.
		$html = RegistrationForm::render();

		$this->assertMatchesRegularExpression( '/<div class="pusers-field mb-3" data-pusers-field="pwz">/', $html );
	}

	public function test_konto_czekajace_na_decyzje_nie_jest_czerwone(): void {
		// Człowiek, który zrobił wszystko dobrze i czeka na redakcję, nie dostaje komunikatu
		// o błędzie — ani czerwonego pudełka, ani `role="alert"` w uszach czytnika ekranu.
		$_GET = array( LoginForm::RESULT_ARG => LoginGateway::CODE_PENDING );

		$html = LoginForm::render();

		$this->assertStringContainsString( 'alert alert-info', $html );
		$this->assertStringNotContainsString( 'alert-danger', $html );
		$this->assertStringContainsString( 'role="status"', $html );
	}

	public function test_nieudane_logowanie_dalej_jest_czerwone(): void {
		$_GET = array( LoginForm::RESULT_ARG => LoginHandler::RESULT_BAD );

		$html = LoginForm::render();

		$this->assertStringContainsString( 'alert alert-danger', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
	}

	public function test_klasa_z_filtra_jedzie_do_acf_zescapowana(): void {
		// Escapowanie wartości z obcego filtra należy do wtyczki, nie do ACF-a: ACF-a nie ma
		// w środowisku testowym, więc nic by tego nie sprawdziło.
		add_filter(
			'pusers_form_classes',
			static function ( array $roles ): array {
				$roles['control'] = 'a" onclick="zlo()';
				return $roles;
			}
		);

		RegistrationForm::render();

		$classes = '';
		foreach ( \PortalUsers\Tests\Integration\Support\AcfRenderRecorder::$fields as $field ) {
			$classes .= (string) ( $field['class'] ?? '' );
		}
		remove_all_filters( 'pusers_form_classes' );

		$this->assertStringNotContainsString( '"', $classes );
	}
}
