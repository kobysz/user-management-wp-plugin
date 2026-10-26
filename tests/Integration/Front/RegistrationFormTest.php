<?php
/**
 * Testy widoku formularza rejestracji.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Tests\Integration\Support\AcfRenderRecorder;
use ReflectionProperty;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class RegistrationFormTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		AcfRenderRecorder::$fields = array();
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
				array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ),
				array( 'name' => 'newsletter', 'label' => 'Newsletter', 'type' => 'true_false' ),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
		$this->professions(
			array(
				array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka' ),
			)
		);
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz'       => array( 'pwz' => 'required', 'zgoda' => 'required', 'newsletter' => 'visible' ),
					'pielegniarka' => array( 'pwz' => 'hidden', 'zgoda' => 'required', 'newsletter' => 'visible' ),
				)
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( RegistrationForm::OLD_INPUT_FILTER );
		wp_dequeue_script( RegistrationForm::SCRIPT_HANDLE );
		wp_deregister_script( RegistrationForm::SCRIPT_HANDLE );
		parent::tear_down();
	}

	/**
	 * @param list<array<string, string>> $items Profesje.
	 */
	private function professions( array $items ): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( $items ) );
	}

	/**
	 * @return array<string, array<string, string>> Mapa przekazana skryptowi.
	 */
	private function localisedMap(): array {
		$data = wp_scripts()->get_data( RegistrationForm::SCRIPT_HANDLE, 'data' );
		$this->assertIsString( $data );
		$this->assertSame( 1, preg_match( '/pusersRegistration = (\{.*\});/s', $data, $m ) );
		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded );
		return $decoded['map'];
	}

	public function test_profession_select_is_rendered_before_any_acf_field(): void {
		$html = RegistrationForm::render();

		$select = strpos( $html, 'name="pusers_profession"' );
		$field  = strpos( $html, 'class="acf-stub"' );
		$this->assertNotFalse( $select );
		$this->assertNotFalse( $field );
		$this->assertLessThan( $field, $select );
	}

	public function test_select_offers_every_configured_profession_and_nothing_else(): void {
		$html = RegistrationForm::render();

		preg_match_all( '/<option value="([^"]*)"/', $html, $m );
		$this->assertSame( array( '', 'lekarz', 'pielegniarka' ), $m[1] );
	}

	public function test_form_carries_a_nonce_a_honeypot_and_a_load_timestamp(): void {
		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match( '/name="pusers_nonce" value="([^"]+)"/', $html, $m ) );
		$this->assertNotFalse( wp_verify_nonce( $m[1], RegistrationForm::NONCE_ACTION ) );
		$this->assertStringContainsString( 'name="' . RegistrationForm::HONEYPOT . '"', $html );
		$this->assertSame( 1, preg_match( '/name="' . RegistrationForm::TIMESTAMP . '" value="(\d+)"/', $html, $t ) );
		$this->assertEqualsWithDelta( time(), (int) $t[1], 5 );
	}

	public function test_honeypot_is_not_visible_to_a_human(): void {
		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match( '/<input type="([a-z]+)" id="pusers_website"/', $html, $m ) );
		$this->assertSame( 'text', $m[1], 'Honeypot ma być zwykłym polem tekstowym, nie type="hidden".' );
		$this->assertMatchesRegularExpression( '/<div style="[^"]*left:-9999px[^"]*"[^>]*>\s*<label for="pusers_website">/', $html );
	}

	public function test_profession_labels_are_escaped(): void {
		$this->professions( array( array( 'key' => 'xss', 'label' => '<script>alert(1)</script>' ) ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'xss' => array( 'pwz' => 'visible' ) ) ) );

		$html = RegistrationForm::render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_hidden_field_of_a_profession_is_absent_from_the_localised_map(): void {
		RegistrationForm::render();

		$map = $this->localisedMap();
		$this->assertSame( array( 'pwz' => 'required', 'zgoda' => 'required', 'newsletter' => 'visible' ), $map['lekarz'] );
		$this->assertSame( array( 'zgoda' => 'required', 'newsletter' => 'visible' ), $map['pielegniarka'] );
	}

	public function test_submitted_values_are_repopulated_after_a_failed_submission(): void {
		add_filter(
			RegistrationForm::OLD_INPUT_FILTER,
			static fn (): array => array(
				'profession' => 'pielegniarka',
				'fields'     => array( 'zgoda' => '1', 'newsletter' => '0' ),
			)
		);

		$html = RegistrationForm::render();

		$this->assertMatchesRegularExpression( '/<option value="pielegniarka" selected=\'selected\'>/', $html );
		$values = array();
		foreach ( AcfRenderRecorder::$fields as $field ) {
			$values[ $field['name'] ] = $field['value'];
		}
		$this->assertSame( '1', $values['pusers_fields[zgoda]'] );
		$this->assertSame( '0', $values['pusers_fields[newsletter]'] );
		$this->assertNull( $values['pusers_fields[pwz]'] );
	}

	public function test_password_is_never_repopulated(): void {
		add_filter(
			RegistrationForm::OLD_INPUT_FILTER,
			static fn (): array => array(
				'profession'      => 'lekarz',
				'password'        => 'Sekret-123!',
				'password_repeat' => 'Sekret-123!',
				'pusers_password' => 'Sekret-123!',
				'fields'          => array( 'zgoda' => '1' ),
			)
		);

		$html = RegistrationForm::render();

		$this->assertStringNotContainsString( 'Sekret-123!', $html );
		$this->assertSame( 1, preg_match( '/<input type="password" id="pusers_password"[^>]*>/', $html, $m ) );
		$this->assertStringNotContainsString( 'value=', $m[0] );
	}

	public function test_shortcode_renders_nothing_but_a_notice_when_no_profession_is_configured(): void {
		$this->professions( array() );

		$html = RegistrationForm::render();

		$this->assertStringContainsString( 'pusers-notice', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_registration_is_closed_when_the_field_group_is_unreadable(): void {
		// Matryca wymaga PWZ lekarza, ale grupa zniknęła: przycięcie pustą listą zniosłoby ten wymóg,
		// więc formularz nie może się pojawić.
		remove_all_filters( 'pusers_acf_groups' );
		add_filter( 'pusers_acf_groups', static fn (): array => array() );

		$html = RegistrationForm::render();

		$this->assertStringContainsString( 'pusers-notice', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'pusers_profession', $html );
		$this->assertStringNotContainsString( 'group_abc', $html, 'Komunikat publiczny nie ujawnia konfiguracji.' );
		$this->assertFalse( RegistrationForm::is_available() );
	}

	public function test_registration_is_closed_when_no_group_is_selected(): void {
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => '' ) ) );

		$this->assertStringNotContainsString( '<form', RegistrationForm::render() );
		$this->assertFalse( RegistrationForm::is_available() );
	}

	public function test_plan_keeps_the_credential_requirement_for_a_readable_source(): void {
		$this->assertTrue( RegistrationForm::is_available() );
		$this->assertSame( array( 'pwz', 'zgoda' ), RegistrationForm::plan()->requiredFor( 'lekarz' ) );
		$this->assertSame( array( 'zgoda' ), RegistrationForm::plan()->requiredFor( 'pielegniarka' ) );
	}

	public function test_plan_prunes_the_matrix_to_the_fields_of_the_source(): void {
		FieldMatrixRepository::save(
			FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'duch' => 'required' ) ) )
		);

		$plan = RegistrationForm::plan();

		// RegistrationPlan iteruje po polach źródła, więc wynik fieldsFor()/requiredFor() jest ten sam
		// z przycięciem i bez niego. Przycięcie widać wyłącznie w macierzy trzymanej przez plan.
		$pruned = ( new ReflectionProperty( $plan, 'pruned' ) )->getValue( $plan );
		$this->assertSame( array( 'lekarz' => array( 'pwz' => 'required' ) ), $pruned->toArray() );
		$this->assertSame( array( 'pwz' ), $plan->requiredFor( 'lekarz' ) );
	}

	public function test_browser_required_is_set_only_for_a_field_every_profession_requires(): void {
		RegistrationForm::render();

		$required = array();
		foreach ( AcfRenderRecorder::$fields as $field ) {
			$required[ $field['name'] ] = $field['required'];
		}
		// zgoda: wymagana u obu profesji. pwz: wymagana tylko u lekarza, więc nie może blokować pielęgniarki.
		$this->assertSame( 1, $required['pusers_fields[zgoda]'] );
		$this->assertSame( 0, $required['pusers_fields[pwz]'] );
		$this->assertSame( 0, $required['pusers_fields[newsletter]'] );
	}

	public function test_script_is_loaded_only_when_the_form_is_actually_rendered(): void {
		$this->assertFalse( wp_script_is( RegistrationForm::SCRIPT_HANDLE, 'enqueued' ) );

		$this->professions( array() );
		RegistrationForm::render();
		$this->assertFalse( wp_script_is( RegistrationForm::SCRIPT_HANDLE, 'enqueued' ), 'Komunikat zamiast formularza nie ładuje skryptu.' );

		$this->professions( array( array( 'key' => 'lekarz', 'label' => 'Lekarz' ) ) );
		RegistrationForm::render();
		$this->assertTrue( wp_script_is( RegistrationForm::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_register_exposes_the_shortcode(): void {
		RegistrationForm::register();

		$this->assertTrue( shortcode_exists( RegistrationForm::SHORTCODE ) );
		$this->assertStringContainsString( 'name="pusers_profession"', do_shortcode( '[' . RegistrationForm::SHORTCODE . ']' ) );
	}
}
