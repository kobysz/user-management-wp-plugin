<?php
/**
 * Testy magazynu opcji wtyczki.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Persistence\OptionStore;
use WP_UnitTestCase;

final class OptionStoreTest extends WP_UnitTestCase {

	public function test_reads_missing_option_as_empty_array(): void {
		delete_option( OptionStore::OPTION_SETTINGS );
		$this->assertSame( array(), OptionStore::read( OptionStore::OPTION_SETTINGS ) );
	}

	public function test_reads_non_array_option_as_empty_array(): void {
		update_option( OptionStore::OPTION_SETTINGS, 'bzdura' );
		$this->assertSame( array(), OptionStore::read( OptionStore::OPTION_SETTINGS ) );
	}

	public function test_writes_with_autoload_disabled(): void {
		delete_option( OptionStore::OPTION_PROFESSIONS );
		OptionStore::write( OptionStore::OPTION_PROFESSIONS, array( 'a' => 1 ) );
		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				OptionStore::OPTION_PROFESSIONS
			)
		);
		// Nie 'yes'; WordPress 6.6+ zapisuje też 'off' i 'auto', więc nie porównujemy z 'no'.
		$this->assertNotSame( 'yes', $autoload );
	}

	public function test_round_trips_nested_values(): void {
		$value = array( 'lekarz' => array( 'pwz' => 'required' ) );
		OptionStore::write( OptionStore::OPTION_MATRIX, $value );
		$this->assertSame( $value, OptionStore::read( OptionStore::OPTION_MATRIX ) );
	}
}
