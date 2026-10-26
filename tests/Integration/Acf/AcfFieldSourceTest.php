<?php
/**
 * Testy adaptera ACF (grupy i pola) przez szwy testowe w postaci filtrów.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Acf;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Acf\AcfGroupList;
use WP_UnitTestCase;

final class AcfFieldSourceTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_field_types' );
		parent::tear_down();
	}

	public function test_returns_empty_list_when_acf_is_unavailable(): void {
		$this->assertSame( array(), ( new AcfFieldSource( 'group_abc' ) )->fields() );
		$this->assertFalse( ( new AcfFieldSource( 'group_abc' ) )->groupExists() );
		$this->assertSame( array(), AcfGroupList::groups() );
	}

	public function test_maps_acf_fields_to_key_label_pairs_preserving_order(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array(
			array( 'name' => 'pwz', 'label' => 'Numer PWZ' ),
			array( 'name' => 'miasto', 'label' => 'Miasto' ),
		) );
		$this->assertSame( array( 'pwz' => 'Numer PWZ', 'miasto' => 'Miasto' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
	}

	public function test_field_without_a_name_is_skipped(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array(
			array( 'label' => 'Bez nazwy' ),
			array( 'name' => 'zgoda', 'label' => 'Regulamin' ),
		) );
		$this->assertSame( array( 'zgoda' => 'Regulamin' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
	}

	public function test_field_without_a_label_falls_back_to_its_name(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'pwz' ) ) );
		$this->assertSame( array( 'pwz' => 'pwz' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
	}

	public function test_group_that_disappeared_reports_absent_and_yields_no_fields(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array() );
		add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'pwz', 'label' => 'Numer PWZ' ) ) );
		$source = new AcfFieldSource( 'group_znikla' );
		$this->assertFalse( $source->groupExists() );
		$this->assertSame( array(), $source->fields() );
	}

	public function test_fields_filter_returning_non_array_yields_no_fields(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): string => 'bzdura' );
		$this->assertSame( array(), ( new AcfFieldSource( 'group_abc' ) )->fields() );

		remove_all_filters( 'pusers_acf_fields' );
		add_filter( 'pusers_acf_fields', static fn () => null );
		$this->assertSame( array(), ( new AcfFieldSource( 'group_abc' ) )->fields() );
	}

	public function test_groups_filter_returning_non_array_yields_no_groups(): void {
		add_filter( 'pusers_acf_groups', static fn (): string => 'bzdura' );
		$this->assertSame( array(), AcfGroupList::groups() );
		$this->assertFalse( ( new AcfFieldSource( 'group_abc' ) )->groupExists() );
	}

	public function test_field_types_map_field_names_to_their_acf_type(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array(
			array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
			array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ),
			array( 'label' => 'Bez nazwy', 'type' => 'text' ),
			array( 'name' => 'bez_typu', 'label' => 'Bez typu' ),
		) );
		$this->assertSame( array( 'pwz' => 'text', 'zgoda' => 'true_false' ), ( new AcfFieldSource( 'group_abc' ) )->fieldTypes() );
	}

	public function test_field_types_are_empty_when_the_group_disappeared(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array() );
		add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'zgoda', 'type' => 'true_false' ) ) );
		$this->assertSame( array(), ( new AcfFieldSource( 'group_znikla' ) )->fieldTypes() );
	}

	public function test_field_types_filter_can_override_the_map_and_survives_garbage(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'zgoda', 'type' => 'text' ) ) );
		add_filter( 'pusers_acf_field_types', static fn ( array $types ): array => array( 'zgoda' => 'true_false' ) + $types );
		$this->assertSame( array( 'zgoda' => 'true_false' ), ( new AcfFieldSource( 'group_abc' ) )->fieldTypes() );

		remove_all_filters( 'pusers_acf_field_types' );
		add_filter( 'pusers_acf_field_types', static fn (): string => 'bzdura' );
		$this->assertSame( array(), ( new AcfFieldSource( 'group_abc' ) )->fieldTypes() );
	}

	public function test_container_and_layout_types_are_invisible_to_the_source(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array(
			array( 'name' => 'dane', 'label' => 'Dane', 'type' => 'group' ),
			array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ),
			array( 'name' => 'lista', 'label' => 'Lista', 'type' => 'repeater' ),
			array( 'name' => 'elastyczne', 'label' => 'Elastyczne', 'type' => 'flexible_content' ),
			array( 'name' => 'klon', 'label' => 'Klon', 'type' => 'clone' ),
			array( 'name' => 'zakladka', 'label' => 'Zakladka', 'type' => 'tab' ),
			array( 'name' => 'opis', 'label' => 'Opis', 'type' => 'message' ),
			array( 'name' => 'akordeon', 'label' => 'Akordeon', 'type' => 'accordion' ),
		) );
		$source = new AcfFieldSource( 'group_abc' );
		$this->assertSame( array( 'zgoda' => 'Regulamin' ), $source->fields() );
		$this->assertSame( array( 'zgoda' => 'true_false' ), $source->fieldTypes() );
		$this->assertNull( $source->definition( 'dane' ) );
	}

	public function test_every_type_allowed_by_the_specification_stays_in_the_source(): void {
		// Specyfikacja: tekst, e-mail, liczba, select, radio, checkbox, pole wielokrotne.
		$types = array(
			'tekst'      => 'text',
			'email'      => 'email',
			'liczba'     => 'number',
			'wybor'      => 'select',
			'opcja'      => 'radio',
			'pole'       => 'checkbox',
			'wielokrotne' => 'select',
		);
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static function () use ( $types ): array {
			$raw = array();
			foreach ( $types as $name => $type ) {
				$raw[] = array( 'name' => $name, 'label' => $name, 'type' => $type, 'multiple' => 'wielokrotne' === $name ? 1 : 0 );
			}
			return $raw;
		} );
		$source = new AcfFieldSource( 'group_abc' );
		$this->assertSame( array_keys( $types ), array_keys( $source->fields() ) );
		$this->assertSame( $types, $source->fieldTypes() );
	}

	public function test_definitions_are_loaded_once_and_shared_by_fields_and_types(): void {
		$calls = 0;
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static function () use ( &$calls ): array {
			++$calls;
			return array( array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ) );
		} );
		$source = new AcfFieldSource( 'group_abc' );
		$source->fields();
		$source->fieldTypes();
		$source->definition( 'zgoda' );
		$source->fields();
		$this->assertSame( 1, $calls );
		$this->assertSame( array_keys( $source->fields() ), array_keys( $source->fieldTypes() ) );
	}

	public function test_field_with_an_integer_like_name_never_enters_the_source(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array(
			array( 'name' => '123', 'label' => 'Numeryczne', 'type' => 'true_false' ),
			array( 'name' => '0', 'label' => 'Zero', 'type' => 'text' ),
			array( 'name' => '007', 'label' => 'Z zerami', 'type' => 'text' ),
			array( 'name' => 'pwz', 'label' => 'PWZ', 'type' => 'text' ),
		) );
		$source = new AcfFieldSource( 'group_abc' );
		// Klucz int zostałby w PHP zapisany jako int; źródło obiecuje klucze tekstowe, więc takie pole jest pomijane.
		// '007' nie jest liczbą całkowitą w sensie klucza tablicy (zostaje stringiem), więc zostaje w źródle.
		$this->assertSame( array( '007' => 'Z zerami', 'pwz' => 'PWZ' ), $source->fields() );
		$this->assertSame( array( '007' => 'text', 'pwz' => 'text' ), $source->fieldTypes() );
		$this->assertNull( $source->definition( '123' ) );
		$this->assertNull( $source->definition( '0' ) );
	}

	public function test_snapshot_is_stale_within_an_instance_and_a_new_instance_sees_new_filters(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'pwz', 'label' => 'PWZ', 'type' => 'text' ) ) );
		$source = new AcfFieldSource( 'group_abc' );
		$this->assertSame( array( 'pwz' => 'PWZ' ), $source->fields() );

		add_filter( 'pusers_acf_fields', static fn ( array $raw ): array => array_merge( $raw, array( array( 'name' => 'zgoda', 'label' => 'Zgoda', 'type' => 'true_false' ) ) ), 20 );
		// Zamierzone: jedna instancja widzi jeden spójny zestaw pól.
		$this->assertSame( array( 'pwz' => 'PWZ' ), $source->fields() );
		$this->assertSame( array( 'pwz' => 'text' ), $source->fieldTypes() );
		$this->assertSame( array( 'pwz' => 'PWZ', 'zgoda' => 'Zgoda' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
	}

	public function test_a_filter_that_calls_back_into_the_source_gets_an_empty_list_and_does_not_poison_the_snapshot(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		$source = new AcfFieldSource( 'group_abc' );
		$seen   = null;
		add_filter( 'pusers_acf_fields', static function () use ( $source, &$seen ): array {
			$seen = $source->fields();
			return array( array( 'name' => 'pwz', 'label' => 'PWZ', 'type' => 'text' ) );
		} );
		$this->assertSame( array( 'pwz' => 'PWZ' ), $source->fields() );
		$this->assertSame( array(), $seen );
		$this->assertSame( array( 'pwz' => 'PWZ' ), $source->fields() );
	}

	public function test_an_exception_in_the_filter_does_not_leave_the_snapshot_empty_for_good(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		$attempt = 0;
		add_filter( 'pusers_acf_fields', static function () use ( &$attempt ): array {
			++$attempt;
			if ( 1 === $attempt ) {
				throw new \RuntimeException( 'chwilowy blad' );
			}
			return array( array( 'name' => 'pwz', 'label' => 'PWZ', 'type' => 'text' ) );
		} );
		$source = new AcfFieldSource( 'group_abc' );
		try {
			$source->fields();
			$this->fail( 'Wyjatek filtra powinien wyjsc na zewnatrz.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'chwilowy blad', $e->getMessage() );
		}
		$this->assertSame( array( 'pwz' => 'PWZ' ), $source->fields() );
	}

	public function test_group_exists_and_fields_read_the_same_state_within_an_instance(): void {
		$present = true;
		add_filter( 'pusers_acf_groups', static function () use ( &$present ): array {
			return $present ? array( 'group_abc' => 'Rejestracja' ) : array();
		} );
		add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'pwz', 'label' => 'PWZ', 'type' => 'text' ) ) );
		$source = new AcfFieldSource( 'group_abc' );
		$this->assertSame( array( 'pwz' => 'PWZ' ), $source->fields() );

		$present = false;
		// Grupa zniknęła po wczytaniu migawki: obie odpowiedzi nadal pochodzą z jednego stanu.
		$this->assertTrue( $source->groupExists() );
		$this->assertSame( array( 'pwz' => 'PWZ' ), $source->fields() );

		// Nowa instancja widzi nowy stan w obu miejscach.
		$fresh = new AcfFieldSource( 'group_abc' );
		$this->assertFalse( $fresh->groupExists() );
		$this->assertSame( array(), $fresh->fields() );
	}

	public function test_adding_types_does_not_change_what_fields_returns(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array(
			array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
			array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ),
		) );
		$this->assertSame( array( 'pwz' => 'Numer PWZ', 'zgoda' => 'Regulamin' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
	}
}
