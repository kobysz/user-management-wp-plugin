<?php
/**
 * Testy: ProfessionCollectionTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Profession;

use PortalUsers\Domain\Profession\ProfessionCollection;
use PHPUnit\Framework\TestCase;

final class ProfessionCollectionTest extends TestCase {

	public function test_reads_profession_by_key(): void {
		$c = ProfessionCollection::fromArray(
			array(
				array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
				array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
			)
		);
		$this->assertSame( 'Lekarz', $c->get( 'lekarz' )->label );
		$this->assertNull( $c->get( 'nie-ma' ) );
		$this->assertSame( array( 'lekarz', 'student' ), $c->keys() );
	}

	public function test_skips_entries_without_key(): void {
		$c = ProfessionCollection::fromArray( array( array( 'label' => 'Bez klucza' ) ) );
		$this->assertSame( array(), $c->keys() );
	}

	public function test_profession_with_unusable_label_is_kept_under_key_as_label(): void {
		$c = ProfessionCollection::fromArray( array( array( 'key' => 'lekarz', 'label' => array( 'x' ) ) ) );
		$this->assertTrue( $c->has( 'lekarz' ) );
		$this->assertSame( 'lekarz', $c->get( 'lekarz' )->label );
	}

	public function test_skips_entries_with_non_string_key(): void {
		$c = ProfessionCollection::fromArray( array( array( 'key' => 123, 'label' => 'Liczba' ) ) );
		$this->assertSame( array(), $c->keys() );
	}

	public function test_skips_non_array_entries(): void {
		$c = ProfessionCollection::fromArray( array( 'tekst', 5, null, array( 'key' => 'student', 'label' => 'Student' ) ) );
		$this->assertSame( array( 'student' ), $c->keys() );
	}

	public function test_to_array_returns_a_list_that_round_trips(): void {
		$c = ProfessionCollection::fromArray(
			array(
				array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
				array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
			)
		);
		$this->assertSame( array( 0, 1 ), array_keys( $c->toArray() ) );
		$this->assertSame( $c->keys(), ProfessionCollection::fromArray( $c->toArray() )->keys() );
	}
}
