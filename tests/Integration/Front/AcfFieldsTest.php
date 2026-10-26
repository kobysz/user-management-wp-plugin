<?php
/**
 * Testy pól ACF w formularzu rejestracji (typ pola czytany ze źródła, biała lista żądania).
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Front\AcfFields;
use PortalUsers\Tests\Integration\Support\AcfRenderRecorder;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class AcfFieldsTest extends WP_UnitTestCase {

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
				array( 'name' => 'lata', 'label' => 'Lata praktyki', 'type' => 'number' ),
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_field_types' );
		parent::tear_down();
	}

	public function test_boolean_keys_come_from_the_acf_field_definitions(): void {
		$this->assertSame( array( 'zgoda', 'newsletter' ), ( new AcfFields( 'group_abc' ) )->booleanKeys() );
	}

	public function test_a_new_boolean_field_is_picked_up_without_touching_any_list(): void {
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => 'regulamin_v2', 'label' => 'Regulamin 2', 'type' => 'true_false' );
				return $raw;
			},
			20
		);
		$this->assertSame( array( 'zgoda', 'newsletter', 'regulamin_v2' ), ( new AcfFields( 'group_abc' ) )->booleanKeys() );
	}

	public function test_boolean_field_beside_a_container_is_still_detected_and_the_container_is_not_a_field(): void {
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => 'dane', 'label' => 'Dane', 'type' => 'group', 'sub_fields' => array( array( 'name' => 'zgoda2', 'type' => 'true_false' ) ) );
				return $raw;
			},
			20
		);
		$fields = new AcfFields( 'group_abc' );
		// Ta asercja przechodzi także bez wykluczenia kontenerów (`sub_fields` nigdy nie są czytane),
		// więc NIE chroni przed kontenerem. Dowodem jest asercja `fromRequest()` poniżej i testy źródła.
		$this->assertSame( array( 'zgoda', 'newsletter' ), $fields->booleanKeys() );
		$this->assertSame( array(), $fields->fromRequest( array( AcfFields::INPUT => array( 'dane' => array( 'zgoda2' => '0' ) ) ) ) );
	}

	public function test_numeric_field_name_is_a_string_in_boolean_keys_and_still_read_from_the_request(): void {
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => '123', 'label' => 'Numeryczne', 'type' => 'true_false' );
				return $raw;
			},
			20
		);
		$fields = new AcfFields( 'group_abc' );
		// Rzutowanie w booleanKeys() działa, bo wartości trafiają do listy (w źródle klucz jest int).
		$this->assertSame( array( 'zgoda', 'newsletter', '123' ), $fields->booleanKeys() );
		$this->assertSame( array( 123 => '1' ), $fields->fromRequest( array( AcfFields::INPUT => array( '123' => '1' ) ) ) );
	}

	public function test_boolean_keys_are_empty_when_the_group_disappeared(): void {
		remove_all_filters( 'pusers_acf_groups' );
		add_filter( 'pusers_acf_groups', static fn (): array => array() );
		$this->assertSame( array(), ( new AcfFields( 'group_abc' ) )->booleanKeys() );
	}

	public function test_values_are_read_only_for_known_fields(): void {
		$raw = array(
			AcfFields::INPUT => array(
				'pwz'      => '5425740',
				'zgoda'    => '1',
				'is_admin' => '1',
				'role'     => 'administrator',
			),
			'role'           => 'administrator',
		);
		$this->assertSame(
			array(
				'pwz'   => '5425740',
				'zgoda' => '1',
			),
			( new AcfFields( 'group_abc' ) )->fromRequest( $raw )
		);
	}

	public function test_request_without_the_fields_container_yields_nothing(): void {
		$this->assertSame( array(), ( new AcfFields( 'group_abc' ) )->fromRequest( array( 'pwz' => '5425740' ) ) );
		$this->assertSame( array(), ( new AcfFields( 'group_abc' ) )->fromRequest( array( AcfFields::INPUT => 'bzdura' ) ) );
	}

	public function test_unchecked_consent_from_the_request_is_read_as_the_zero_that_acf_sends(): void {
		// Odznaczony `true_false` wysyła ukryte `0`; odczyt nie może go gubić ani zmieniać
		// (normalizacja to osobny krok, który widzi tę wartość).
		$read = ( new AcfFields( 'group_abc' ) )->fromRequest( array( AcfFields::INPUT => array( 'zgoda' => '0' ) ) );
		$this->assertSame( array( 'zgoda' => '0' ), $read );
	}

	public function test_render_hands_the_field_to_acf_with_requiredness_value_and_plugin_input_name(): void {
		ob_start();
		( new AcfFields( 'group_abc' ) )->render( 'zgoda', true, '1' );
		$html = (string) ob_get_clean();

		$this->assertCount( 1, AcfRenderRecorder::$fields );
		$field = AcfRenderRecorder::$fields[0];
		$this->assertSame( 'true_false', $field['type'] );
		$this->assertSame( 1, $field['required'] );
		$this->assertSame( '1', $field['value'] );
		$this->assertSame( AcfFields::INPUT, $field['prefix'] );
		$this->assertSame( AcfFields::INPUT . '[zgoda]', $field['name'] );
		$this->assertStringContainsString( 'acf-stub', $html );
	}

	public function test_render_marks_an_optional_field_as_not_required(): void {
		ob_start();
		( new AcfFields( 'group_abc' ) )->render( 'pwz', false, null );
		ob_end_clean();

		$this->assertSame( 0, AcfRenderRecorder::$fields[0]['required'] );
	}

	public function test_render_ignores_a_field_outside_the_group(): void {
		ob_start();
		( new AcfFields( 'group_abc' ) )->render( 'user_pass', true, null );
		$html = (string) ob_get_clean();

		$this->assertSame( array(), AcfRenderRecorder::$fields );
		$this->assertSame( '', $html );
	}
}
