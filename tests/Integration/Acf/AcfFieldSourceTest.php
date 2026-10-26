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
		$source = new AcfFieldSource( 'group_znikla' );
		$this->assertFalse( $source->groupExists() );
		$this->assertSame( array(), $source->fields() );
	}
}
