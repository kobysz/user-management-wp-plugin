<?php
/**
 * Testy: spis opcji wtyczki. Odinstalowanie iteruje po OptionStore::all(),
 * więc zapomniana tam stała OPTION_* zostawiłaby po wtyczce śmieci.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Persistence;

use PortalUsers\Persistence\OptionStore;
use PHPUnit\Framework\TestCase;

final class OptionStoreTest extends TestCase {

	public function test_all_lists_every_option_constant_and_nothing_else(): void {
		$declared = array();
		foreach ( ( new \ReflectionClass( OptionStore::class ) )->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'OPTION_' ) ) {
				$declared[] = $value;
			}
		}
		$this->assertNotEmpty( $declared );

		$listed = OptionStore::all();
		$this->assertSame( array(), array_values( array_diff( $declared, $listed ) ), 'Stałe OPTION_* nieobecne w all().' );
		$this->assertSame( array(), array_values( array_diff( $listed, $declared ) ), 'Wpisy all() bez stałej OPTION_*.' );
		$this->assertSame( count( $listed ), count( array_unique( $listed ) ), 'Duplikaty w all().' );
	}
}
