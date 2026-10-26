<?php
/**
 * Testy migracji tabel w prawdziwej bazie WordPressa.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsTest extends WP_UnitTestCase {

	public function test_creates_both_tables(): void {
		global $wpdb;
		Migrations::run();
		foreach ( array( 'event_log', 'terms_versions' ) as $name ) {
			$table = Migrations::table( $name );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	public function test_stores_db_version(): void {
		Migrations::run();
		$this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
	}

	public function test_run_is_idempotent(): void {
		Migrations::run();
		Migrations::run();
		$this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
	}

	public function test_terms_version_hash_is_unique(): void {
		global $wpdb;
		Migrations::run();
		$table = Migrations::table( 'terms_versions' );
		$wpdb->insert(
			$table,
			array(
				'version_hash' => str_repeat( 'a', 40 ),
				'content'      => 'x',
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		$wpdb->suppress_errors( true );
		$second = $wpdb->insert(
			$table,
			array(
				'version_hash' => str_repeat( 'a', 40 ),
				'content'      => 'y',
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		$wpdb->suppress_errors( false );
		$this->assertFalse( (bool) $second );
	}
}
