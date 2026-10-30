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

use PortalUsers\Cron\DispatchMail;
use PortalUsers\Cron\PurgeMailLog;
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
		$this->assertGreaterThan( 2, Migrations::DB_VERSION );
		$this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
	}

	public function test_the_upgrade_from_version_three_schedules_the_dispatcher_before_init(): void {
		// Instalacja, która przeszła migrację tabeli kolejki, nie przechodzi hooka aktywacji: bez tego
		// kroku cron nigdy by nie powstał, a zakolejkowane maile nie wyszłyby nigdy.
		//
		// Test idzie całą drogą aktualizacji i trzyma dwie rzeczy, które ją niosą:
		// 1. `wp_schedule_event()` oddaje false, gdy harmonogramu nie ma w `wp_get_schedules()`, a
		//    `run()` zapisuje DB_VERSION PRZED tym wywołaniem. Krok wykonany za wcześnie (przed
		//    `DispatchMail::register()`) byłby cichym no-opem, wersja i tak by się zapisała,
		//    `maybe_upgrade()` nigdy by nie ponowił, a kolejka nie miałaby konsumenta.
		// 2. Na prawdziwym żądaniu ta droga biegnie na `plugins_loaded`, czyli PRZED
		//    `after_setup_theme` i `init`, na którym I18n ładuje tłumaczenia. Gdyby `__()` w
		//    `add_schedule()` wymusiło ładowanie „just in time”, rdzeń podniósłby _doing_it_wrong,
		//    a suita testowa zamienia je w pad.
		unset( $GLOBALS['wp_actions']['after_setup_theme'], $GLOBALS['wp_actions']['init'] );
		$this->assertSame( 0, did_action( 'after_setup_theme' ), 'Stan wyjściowy ma odwzorowywać plugins_loaded.' );
		$this->fresh_install();
		update_option( 'pusers_db_version', 3 );
		wp_clear_scheduled_hook( DispatchMail::HOOK );

		Migrations::maybe_upgrade();

		$this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
		$this->assertNotFalse( wp_next_scheduled( DispatchMail::HOOK ), 'Krok jednorazowy musi założyć zdarzenie cykliczne.' );
		$this->assertSame( DispatchMail::SCHEDULE, wp_get_schedule( DispatchMail::HOOK ) );
	}

	public function test_the_upgrade_from_version_four_schedules_the_mail_log_retention(): void {
		// Portal, który przeszedł już migrację 4, bez podbicia wersji nigdy nie dostałby crona retencji.
		$this->fresh_install();
		update_option( 'pusers_db_version', 4 );
		wp_clear_scheduled_hook( PurgeMailLog::HOOK );

		Migrations::maybe_upgrade();

		$this->assertSame( 5, Migrations::DB_VERSION );
		$this->assertSame( 5, (int) get_option( 'pusers_db_version' ) );
		$this->assertNotFalse( wp_next_scheduled( PurgeMailLog::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( PurgeMailLog::HOOK ) );
	}

	/**
	 * Klasy crona odkryte z katalogu, nie wypisane z pamięci.
	 *
	 * Lista wypisana ręcznie przepuściłaby nową klasę crona dokładnie tak, jak przepuszcza ją
	 * aktualizacja w miejscu: hook aktywacji się nie odpala, a harmonogram zakłada wyłącznie
	 * `run()`. Odkrywanie sprawia, że nowa klasa bez dopisania do `run()` zapala ten test.
	 *
	 * @return array<class-string, string>
	 */
	private function cron_hooks(): array {
		$out = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/src/Cron/*.php' ) as $file ) {
			$class = 'PortalUsers\\Cron\\' . basename( (string) $file, '.php' );
			if ( ! class_exists( $class ) || ! defined( $class . '::HOOK' ) || ! method_exists( $class, 'schedule' ) ) {
				continue;
			}
			$out[ $class ] = (string) constant( $class . '::HOOK' );
		}

		return $out;
	}

	public function test_every_cron_class_is_scheduled_by_run(): void {
		$hooks = $this->cron_hooks();
		$this->assertNotEmpty( $hooks, 'Nie odkryto żadnej klasy crona — zmiana układu katalogów wyłączyłaby tę bramkę po cichu.' );

		$this->fresh_install();
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		Migrations::run();

		foreach ( $hooks as $class => $hook ) {
			$this->assertNotFalse(
				wp_next_scheduled( $hook ),
				sprintf( '%s nie jest planowana przez Migrations::run(), więc hook „%s” nie powstanie na portalu zaktualizowanym w miejscu — dopisz wywołanie schedule() i podnieś DB_VERSION.', $class, $hook )
			);
		}
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
