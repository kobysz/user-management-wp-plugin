<?php
/**
 * Testy pól ACF w formularzu rejestracji (typ pola czytany ze źródła, biała lista żądania).
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Front\AcfFields;
use PortalUsers\Tests\Integration\Support\AcfRenderRecorder;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class AcfFieldsTest extends WP_UnitTestCase {

	/**
	 * Klucze ACF pól harnessu. Definicja z `acf_get_fields()` zawsze nosi klucz, a
	 * `acf_prepare_field()` nazywa nim input, więc to on jest indeksem w żądaniu.
	 */
	private const KEY_PWZ        = 'field_6731a2c4de';
	private const KEY_ZGODA      = 'field_8f20b5d1ac';
	private const KEY_NEWSLETTER = 'field_1c94e7f308';
	private const KEY_LATA       = 'field_4a5db28e96';

	public function set_up(): void {
		parent::set_up();
		AcfRenderRecorder::$fields = array();
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text', 'key' => self::KEY_PWZ ),
				array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false', 'key' => self::KEY_ZGODA ),
				array( 'name' => 'newsletter', 'label' => 'Newsletter', 'type' => 'true_false', 'key' => self::KEY_NEWSLETTER ),
				array( 'name' => 'lata', 'label' => 'Lata praktyki', 'type' => 'number', 'key' => self::KEY_LATA ),
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
		$this->assertSame( array( 'zgoda', 'newsletter' ), ( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->booleanKeys() );
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
		$this->assertSame( array( 'zgoda', 'newsletter', 'regulamin_v2' ), ( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->booleanKeys() );
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
		$fields = new AcfFields( new AcfFieldSource( 'group_abc' ) );
		// Ta asercja przechodzi także bez wykluczenia kontenerów (`sub_fields` nigdy nie są czytane),
		// więc NIE chroni przed kontenerem. Dowodem jest asercja `fromRequest()` poniżej i testy źródła.
		$this->assertSame( array( 'zgoda', 'newsletter' ), $fields->booleanKeys() );
		$this->assertSame( array(), $fields->fromRequest( array( AcfFields::INPUT => array( 'dane' => array( 'zgoda2' => '0' ) ) ) ) );
	}

	public function test_boolean_field_with_an_integer_like_name_is_not_a_field_at_all(): void {
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => '123', 'label' => 'Numeryczne', 'type' => 'true_false' );
				return $raw;
			},
			20
		);
		$fields = new AcfFields( new AcfFieldSource( 'group_abc' ) );
		$this->assertSame( array( 'zgoda', 'newsletter' ), $fields->booleanKeys() );
		$this->assertSame( array(), $fields->fromRequest( array( AcfFields::INPUT => array( '123' => '1' ) ) ) );
	}

	public function test_boolean_keys_are_empty_when_the_group_disappeared(): void {
		remove_all_filters( 'pusers_acf_groups' );
		add_filter( 'pusers_acf_groups', static fn (): array => array() );
		$this->assertSame( array(), ( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->booleanKeys() );
	}

	public function test_values_are_read_under_the_field_key_and_returned_under_the_field_name(): void {
		// Żądanie niesie KLUCZE (tak nazywa inputy ACF), a wynik jest po NAZWACH: po nazwach
		// pracują macierz, walidator i zapis metadanych.
		$raw = array(
			AcfFields::INPUT => array(
				self::KEY_PWZ   => '5425740',
				self::KEY_ZGODA => '1',
				'pwz'           => 'nie-ta-nazwa',
				'is_admin'      => '1',
				'role'          => 'administrator',
			),
			'role'           => 'administrator',
		);
		$this->assertSame(
			array(
				'pwz'   => '5425740',
				'zgoda' => '1',
			),
			( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->fromRequest( $raw )
		);
	}

	public function test_a_definition_without_a_key_is_read_under_its_name(): void {
		// Definicja bez klucza to legalny wariant: tak wyglądają pola podane filtrem
		// `pusers_acf_fields`. ACF owija wtedy prefiksem samą nazwę, raz.
		remove_all_filters( 'pusers_acf_fields' );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array( array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ) )
		);
		$fields = new AcfFields( new AcfFieldSource( 'group_abc' ) );

		$this->assertSame( array( 'pwz' => '5425740' ), $fields->fromRequest( array( AcfFields::INPUT => array( 'pwz' => '5425740' ) ) ) );

		ob_start();
		$fields->render( 'pwz', true, null );
		ob_end_clean();
		$this->assertSame( AcfFields::INPUT . '[pwz]', AcfRenderRecorder::$fields[0]['name'] );
	}

	public function test_two_definitions_sharing_one_request_index_are_both_rejected(): void {
		// Klucz jednego pola równy nazwie drugiego: wejście jest nierozstrzygalne, więc żadnego
		// z nich nie czytamy. Cicha wygrana jednego byłaby tym samym błędem, co czytanie pola
		// pod nazwą, której ACF nie wysyła.
		remove_all_filters( 'pusers_acf_fields' );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text', 'key' => 'numer' ),
				array( 'name' => 'numer', 'label' => 'Numer', 'type' => 'text' ),
				array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false', 'key' => self::KEY_ZGODA ),
			)
		);

		$read = ( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->fromRequest(
			array( AcfFields::INPUT => array( 'numer' => '5425740', self::KEY_ZGODA => '1' ) )
		);

		$this->assertSame( array( 'zgoda' => '1' ), $read, 'Pole o spornym indeksie nie jest czytane; reszta grupy tak.' );
	}

	public function test_request_without_the_fields_container_yields_nothing(): void {
		$this->assertSame( array(), ( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->fromRequest( array( 'pwz' => '5425740' ) ) );
		$this->assertSame( array(), ( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->fromRequest( array( AcfFields::INPUT => 'bzdura' ) ) );
	}

	public function test_unchecked_consent_from_the_request_is_read_as_the_zero_that_acf_sends(): void {
		// Odznaczony `true_false` wysyła ukryte `0`; odczyt nie może go gubić ani zmieniać
		// (normalizacja to osobny krok, który widzi tę wartość).
		$read = ( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->fromRequest( array( AcfFields::INPUT => array( self::KEY_ZGODA => '0' ) ) );
		$this->assertSame( array( 'zgoda' => '0' ), $read );
	}

	public function test_render_hands_the_field_to_acf_with_requiredness_value_and_the_plugin_prefix(): void {
		ob_start();
		( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->render( 'zgoda', true, '1' );
		$html = (string) ob_get_clean();

		$this->assertCount( 1, AcfRenderRecorder::$fields );
		$field = AcfRenderRecorder::$fields[0];
		$this->assertSame( 'true_false', $field['type'] );
		$this->assertSame( 1, $field['required'] );
		$this->assertSame( '1', $field['value'] );
		$this->assertSame( AcfFields::INPUT, $field['prefix'] );
		// Nazwę nadaje ACF: klucz pola owinięty prefiksem, RAZ. Wtyczka jej nie ustawia.
		$this->assertSame( AcfFields::INPUT . '[' . self::KEY_ZGODA . ']', $field['name'] );
		$this->assertStringContainsString( 'acf-stub', $html );
	}

	public function test_render_marks_an_optional_field_as_not_required(): void {
		ob_start();
		( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->render( 'pwz', false, null );
		ob_end_clean();

		$this->assertSame( 0, AcfRenderRecorder::$fields[0]['required'] );
	}

	/**
	 * Renderuje pole i oddaje definicję, którą wtyczka podała ACF-owi.
	 *
	 * @param string $fieldKey Nazwa pola.
	 * @param bool   $invalid  Czy pole ma błąd.
	 * @return array<string, mixed>
	 */
	private function definition( string $fieldKey, bool $invalid = false ): array {
		AcfRenderRecorder::$fields = array();
		ob_start();
		( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->render( $fieldKey, false, null, $invalid );
		ob_end_clean();

		return AcfRenderRecorder::$fields[0];
	}

	public function test_pole_tekstowe_dostaje_form_control(): void {
		$this->assertSame( 'form-control', $this->definition( 'pwz' )['class'] );
	}

	public function test_pole_liczbowe_tez_dostaje_form_control(): void {
		$this->assertSame( 'form-control', $this->definition( 'lata' )['class'] );
	}

	public function test_select_dostaje_form_select(): void {
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => 'specjalizacja', 'label' => 'Specjalizacja', 'type' => 'select', 'key' => 'field_select01' );
				return $raw;
			},
			20
		);

		$this->assertSame( 'form-select', $this->definition( 'specjalizacja' )['class'] );
	}

	public function test_true_false_dostaje_form_check_input(): void {
		// Pole zgody na regulamin jest właśnie takim polem.
		$this->assertSame( 'form-check-input', $this->definition( 'zgoda' )['class'] );
	}

	public function test_typ_spoza_listy_nie_dostaje_klasy_kontrolki(): void {
		// Galeria, WYSIWYG, listy opcji: klasa Bootstrapa nie ma na czym usiąść, a render ma
		// przejść. Kontenery (repeater, group) odpadają wcześniej, w AcfFieldSource.
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => 'galeria', 'label' => 'Galeria', 'type' => 'gallery', 'key' => 'field_gal001' );
				$raw[] = array( 'name' => 'tryb', 'label' => 'Tryb pracy', 'type' => 'radio', 'key' => 'field_rad001' );
				return $raw;
			},
			20
		);

		foreach ( array( 'galeria', 'tryb' ) as $fieldKey ) {
			$definition = $this->definition( $fieldKey );
			$this->assertSame( '', $definition['class'], 'Pole „' . $fieldKey . '” nie ma dostać klasy kontrolki.' );
			$this->assertSame( 'mb-3', $definition['wrapper']['class'] );
		}
	}

	public function test_opakowanie_zawsze_dostaje_mb_3(): void {
		$this->assertSame( 'mb-3', $this->definition( 'pwz' )['wrapper']['class'] );
		$this->assertSame( 'mb-3', $this->definition( 'zgoda' )['wrapper']['class'] );
	}

	public function test_bledne_pole_doklada_is_invalid_do_klasy_kontrolki(): void {
		$this->assertSame( 'form-control is-invalid', $this->definition( 'pwz', true )['class'] );
		$this->assertSame( 'form-check-input is-invalid', $this->definition( 'zgoda', true )['class'] );
	}

	public function test_render_ignores_a_field_outside_the_group(): void {
		ob_start();
		( new AcfFields( new AcfFieldSource( 'group_abc' ) ) )->render( 'user_pass', true, null );
		$html = (string) ob_get_clean();

		$this->assertSame( array(), AcfRenderRecorder::$fields );
		$this->assertSame( '', $html );
	}
}
