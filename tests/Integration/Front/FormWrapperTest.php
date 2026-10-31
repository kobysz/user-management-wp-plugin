<?php
/**
 * Testy wspólnego opakowania wyjścia shortcode'ów frontu.
 *
 * Opakowanie niesie trzy rzeczy naraz: zasięg arkusza zapasowego, tryb karty i to, że widok,
 * który oddał samą notkę (rejestracja niedostępna, wygasły link), też jest ubrany. Dlatego jego
 * liczba w wyjściu jest asercją: dwa opakowania to dwie karty jedna w drugiej.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\FormWrapper;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\PasswordReset;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class FormWrapperTest extends WP_UnitTestCase {

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
		parent::tear_down();
	}

	/**
	 * Przestawia układ, zachowując resztę ustawień.
	 *
	 * @param string $layout Wartość ustawienia układu.
	 */
	private function layout( string $layout ): void {
		$raw                = SettingsRepository::get()->toArray();
		$raw['form_layout'] = $layout;
		SettingsRepository::save( Settings::fromArray( $raw ) );
	}

	public function test_puste_wyjscie_nie_dostaje_opakowania(): void {
		$this->assertSame( '', FormWrapper::wrap( '' ) );
	}

	public function test_tryb_goly_daje_jeden_div_bez_karty(): void {
		$html = FormWrapper::wrap( '<p>treść</p>' );

		$this->assertStringContainsString( 'class="pusers-front"', $html );
		$this->assertStringNotContainsString( 'card', $html );
		$this->assertStringContainsString( '<p>treść</p>', $html );
	}

	public function test_tryb_karta_doklada_card_i_card_body(): void {
		$this->layout( 'karta' );
		$html = FormWrapper::wrap( '<p>treść</p>' );

		$this->assertStringContainsString( 'class="pusers-front card"', $html );
		$this->assertStringContainsString( 'class="card-body"', $html );
		$this->assertStringContainsString( '<p>treść</p>', $html );
	}

	public function test_wyjscie_bez_formularza_tez_jest_opakowane(): void {
		// Rejestracja niedostępna: żadnej profesji nie da się zarejestrować, zostaje sama notka.
		ProfessionRepository::save( ProfessionCollection::fromArray( array() ) );

		$html = RegistrationForm::render();

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'pusers-front', $html );
	}

	public function test_kazdy_widok_ma_dokladnie_jedno_opakowanie(): void {
		$views = array(
			'rejestracja' => RegistrationForm::render(),
			'logowanie'   => LoginForm::render(),
			'reset'       => PasswordReset::render(),
		);

		foreach ( $views as $name => $html ) {
			$this->assertSame( 1, substr_count( $html, 'pusers-front' ), 'Widok „' . $name . '” musi mieć dokładnie jedno opakowanie.' );
		}
	}

	public function test_karta_nie_zaglada_do_srodka_widoku(): void {
		// Jedna karta na widok, nawet gdy widok oddaje notkę i formularz naraz.
		$this->layout( 'karta' );

		$this->assertSame( 1, substr_count( RegistrationForm::render(), 'card-body' ) );
		$this->assertSame( 1, substr_count( PasswordReset::render(), 'card-body' ) );
	}
}
