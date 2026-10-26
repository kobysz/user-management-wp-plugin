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
}
