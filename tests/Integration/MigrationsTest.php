<?php
/**
 * Testy migracji tabel w prawdziwej bazie WordPressa.
 *
 * Tabele wtyczki powstają już przy bootstrapie (plugins_loaded -> maybe_upgrade),
 * a WP_UnitTestCase zamienia CREATE TABLE na tymczasowe. Dlatego testy zdejmują
 * te filtry i odtwarzają stan "świeżej instalacji", żeby asertować realne run().
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Persistence\Migrations;
use WP_UnitTestCase;

final class MigrationsTest extends WP_UnitTestCase {

	private const TABLES = array( 'event_log', 'terms_versions', 'mail_queue' );

	public function tear_down(): void {
		// DDL nie cofa się z transakcją testu: baza musi zostać z tabelami dla innych testów.
		try {
			$this->remove_temporary_table_filters();
			remove_filter( 'query', array( $this, 'block_create_table' ) );
			Migrations::run();
		} finally {
			parent::tear_down();
		}
	}

	public function test_creates_every_table(): void {
		$this->fresh_install();
		foreach ( self::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( Migrations::table( $name ) ), 'Stan wyjściowy: tabeli nie ma.' );
		}

		Migrations::run();

		foreach ( self::TABLES as $name ) {
			$this->assertTrue( $this->table_exists( Migrations::table( $name ) ) );
		}
	}

	public function test_stores_db_version(): void {
		$this->fresh_install();
		$this->assertFalse( get_option( 'pusers_db_version' ), 'Stan wyjściowy: brak opcji.' );

		Migrations::run();

		$this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
	}

	public function test_run_is_idempotent(): void {
		$this->fresh_install();
		Migrations::run();
		$before = $this->create_statements();

		Migrations::run();

		$this->assertSame( $before, $this->create_statements() );
		$this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
	}

	public function test_maybe_upgrade_runs_when_version_is_missing(): void {
		$this->fresh_install();

		Migrations::maybe_upgrade();

		$this->assertTrue( $this->table_exists( Migrations::table( 'event_log' ) ) );
		$this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
	}

	public function test_failed_create_does_not_store_db_version(): void {
		$this->fresh_install();
		// Symulacja nieudanego CREATE (brak uprawnień, pełny dysk): dbDelta() nic nie zgłosi.
		add_filter( 'query', array( $this, 'block_create_table' ) );

		Migrations::run();

		$this->assertFalse( get_option( 'pusers_db_version' ), 'Bez tabel wersja nie może zostać zapisana.' );
		foreach ( self::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( Migrations::table( $name ) ) );
		}
	}

	public function test_maybe_upgrade_does_not_run_when_version_is_current(): void {
		$this->fresh_install();
		update_option( 'pusers_db_version', Migrations::DB_VERSION );

		Migrations::maybe_upgrade();

		$this->assertFalse( $this->table_exists( Migrations::table( 'event_log' ) ), 'run() nie powinno być wołane przy aktualnej wersji.' );
	}

	/**
	 * Filtr zapytań: zamienia CREATE TABLE na no-op.
	 *
	 * @param string $query Zapytanie SQL.
	 */
	public function block_create_table( $query ) {
		return 0 === stripos( ltrim( (string) $query ), 'CREATE TABLE' ) ? 'SELECT 1' : $query;
	}

	public function test_event_log_schema(): void {
		global $wpdb;
		$this->fresh_install();
		Migrations::run();
		$table = Migrations::table( 'event_log' );

		$this->assertNotEmpty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'anonymized'" ) );
		$this->assertSame( 'InnoDB', $this->engine( $table ) );
		$indexes = $this->index_names( $table );
		$this->assertContains( 'subject_user_id', $indexes );
		$this->assertContains( 'created_at', $indexes );
	}

	public function test_terms_versions_schema(): void {
		global $wpdb;
		$this->fresh_install();
		Migrations::run();
		$table = Migrations::table( 'terms_versions' );

		$this->assertSame( 'InnoDB', $this->engine( $table ) );
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'version_hash'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '0', (string) $rows[0]->Non_unique );
	}

	public function test_mail_queue_schema(): void {
		global $wpdb;
		$this->fresh_install();
		Migrations::run();
		$table = Migrations::table( 'mail_queue' );

		$this->assertSame( 'InnoDB', $this->engine( $table ) );
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'dedupe_key'" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '0', (string) $rows[0]->Non_unique );
		$indexes = $this->index_names( $table );
		$this->assertContains( 'dispatch', $indexes );
		$this->assertContains( 'user_id', $indexes );
		$this->assertNotEmpty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'anonymized'" ) );
	}

	public function test_the_mail_queue_table_exists_after_migration(): void {
		// Aktualizacja wtyczki nie odpala aktywacji: tabela musi powstać z maybe_upgrade() na starej wersji.
		$this->fresh_install();
		update_option( 'pusers_db_version', 2 );

		Migrations::maybe_upgrade();

		$this->assertTrue( $this->table_exists( Migrations::table( 'mail_queue' ) ) );
		$this->assertSame( 3, Migrations::DB_VERSION );
		$this->assertSame( 3, (int) get_option( 'pusers_db_version' ) );
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

	private function remove_temporary_table_filters(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Stan "świeżej instalacji": prawdziwe tabele i opcja usunięte.
	 */
	private function fresh_install(): void {
		global $wpdb;
		$this->remove_temporary_table_filters();
		foreach ( self::TABLES as $name ) {
			$table = Migrations::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( 'pusers_db_version' );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function create_statements(): array {
		global $wpdb;
		$out = array();
		foreach ( self::TABLES as $name ) {
			$table         = Migrations::table( $name );
			$row           = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N );
			$out[ $table ] = (string) $row[1];
		}
		return $out;
	}

	private function engine( string $table ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function index_names( string $table ): array {
		global $wpdb;
		return array_map( static fn( $r ) => (string) $r->Key_name, $wpdb->get_results( "SHOW INDEX FROM {$table}" ) );
	}
}
