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

	public function test_adding_types_does_not_change_what_fields_returns(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array(
			array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
			array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ),
		) );
		$this->assertSame( array( 'pwz' => 'Numer PWZ', 'zgoda' => 'Regulamin' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
	}
}
