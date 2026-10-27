<?php
/**
 * Testy odinstalowania w prawdziwej bazie WordPressa.
 *
 * Testy kasują prawdziwe tabele. WP_UnitTestCase zamienia CREATE TABLE na tymczasowe,
 * a tabele tymczasowe są niewidoczne dla SHOW TABLES, więc testy zdejmują te filtry
 * (tak jak MigrationsTest) i w tear_down odtwarzają bazę.
 *
 * DDL (DROP/CREATE) zatwierdza transakcję testu, więc opcje zapisane po nim nie zostałyby
 * wycofane; tear_down sprząta je jawnie, a wersję schematu odtwarza i zatwierdza.
 *
 * UWAGA o @runInSeparateProcess (dwa testy stałej): stała nie wycieka, ale proces potomny
 * powtarza bootstrap suity WP, w tym includes/install.php, a WP_TESTS_SKIP_INSTALL nie jest
 * ustawione. Potomek usuwa i odtwarza tabele rdzenia WordPressa w bazie dzieloną z procesem
 * rodzica. Stąd ok. 20 s na test. Dziś to działa, bo tabele wtyczki nie należą do $wpdb->tables()
 * i każdy test buduje fixtury od nowa; pierwszy test, który oprze się na zatwierdzonym stanie
 * bazy, pęknie bez widocznej przyczyny.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\RateLimiter;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\Uninstaller;
use WP_UnitTestCase;

final class UninstallerTest extends WP_UnitTestCase {

	public function tear_down(): void {
		try {
			$this->remove_temporary_table_filters();
			foreach ( OptionStore::all() as $option ) {
				OptionStore::delete( $option );
			}
			Migrations::run();
			// Rollback parent::tear_down() cofnąłby zapis po DDL, więc zapis zatwierdzamy.
			update_option( Migrations::OPTION_DB_VERSION, Migrations::DB_VERSION );
			$GLOBALS['wpdb']->query( 'COMMIT' );
		} finally {
			parent::tear_down();
		}
	}

	public function test_gate_is_closed_on_fresh_install(): void {
		// Nikt niczego nie ustawiał: brak opcji ustawień i brak stałej.
		$this->assertFalse( defined( Uninstaller::CONSTANT_GATE ), 'Stan wyjściowy: stała niezdefiniowana.' );
		$this->assertFalse( get_option( OptionStore::OPTION_SETTINGS ), 'Stan wyjściowy: brak zapisanych ustawień.' );

		$this->assertFalse( Uninstaller::should_delete_data() );
	}

	public function test_default_settings_do_not_enable_deletion(): void {
		SettingsRepository::save( Settings::defaults() );

		$this->assertFalse( Uninstaller::should_delete_data() );
	}

	public function test_should_delete_data_follows_the_setting(): void {
		$this->save_gate( false );
		$this->assertFalse( Uninstaller::should_delete_data() );

		$this->save_gate( true );
		$this->assertTrue( Uninstaller::should_delete_data() );
	}

	public function test_does_nothing_by_default(): void {
		$this->install_with_data();
		delete_option( OptionStore::OPTION_SETTINGS );

		Uninstaller::run();

		$this->assert_data_intact();
	}

	public function test_does_nothing_when_setting_is_explicitly_off(): void {
		$this->install_with_data();
		$this->save_gate( false );

		Uninstaller::run();

		$this->assert_data_intact();
	}

	public function test_setting_enables_deletion(): void {
		$this->install_with_data();
		$this->save_gate( true );

		Uninstaller::run();

		$this->assert_data_gone();
	}

	public function test_deletion_removes_every_plugin_option(): void {
		$this->install_with_data();
		foreach ( OptionStore::all() as $option ) {
			OptionStore::write( $option, array( 'x' => 1 ) );
		}
		$this->save_gate( true );

		Uninstaller::run();

		$this->assertNotEmpty( OptionStore::all() );
		foreach ( OptionStore::all() as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' powinna zniknąć' );
		}
	}

	public function test_deletion_removes_the_throttling_timestamps_written_outside_option_store(): void {
		// Dwa znaczniki throttlingu wpisów do logu są zapisywane bezpośrednio przez `update_option()`
		// (nie są tablicami), więc łatwo było je pominąć w spisie. Po usunięciu wtyczki z otwartą
		// bramką zostawały w `wp_options`.
		$this->install_with_data();
		update_option( RegistrationHandler::ROLE_REFUSAL_OPTION, time(), false );
		update_option( RateLimiter::FAILURE_OPTION, time(), false );
		$this->assertNotFalse( get_option( RegistrationHandler::ROLE_REFUSAL_OPTION ) );
		$this->assertNotFalse( get_option( RateLimiter::FAILURE_OPTION ) );
		$this->save_gate( true );

		Uninstaller::run();

		$this->assertFalse( get_option( RegistrationHandler::ROLE_REFUSAL_OPTION ) );
		$this->assertFalse( get_option( RateLimiter::FAILURE_OPTION ) );
	}

	public function test_deletion_does_not_touch_user_accounts_or_their_meta(): void {
		$this->install_with_data();
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'pusers_profession', 'lekarz' );
		$this->save_gate( true );

		Uninstaller::run();

		$this->assert_data_gone();
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ), 'Konto jest danymi WordPressa.' );
		$this->assertSame( 'lekarz', get_user_meta( $user_id, 'pusers_profession', true ) );
	}

	/**
	 * Stałej nie da się odefiniować na nowo w jednym procesie, więc te dwa testy idą osobno.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_false_overrides_enabled_setting(): void {
		$this->install_with_data();
		$this->save_gate( true );
		define( Uninstaller::CONSTANT_GATE, false );

		$this->assertFalse( Uninstaller::should_delete_data() );
		Uninstaller::run();

		$this->assert_data_intact();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_true_overrides_disabled_setting(): void {
		$this->install_with_data();
		$this->save_gate( false );
		define( Uninstaller::CONSTANT_GATE, true );

		$this->assertTrue( Uninstaller::should_delete_data() );
		Uninstaller::run();

		$this->assert_data_gone();
	}

	/**
	 * Stała ustawiona na napis 'false' nie może otworzyć kasowania.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_string_false_does_not_delete(): void {
		$this->install_with_data();
		$this->save_gate( true );
		define( Uninstaller::CONSTANT_GATE, 'false' );

		$this->assertFalse( Uninstaller::should_delete_data() );
		Uninstaller::run();

		$this->assert_data_intact();
	}

	/**
	 * Wszystkie tabele z bazy o prefiksie wtyczki to dokładnie Migrations::TABLES, w obie strony.
	 * Start od zdjętych filtrów tabel tymczasowych i twardego usunięcia, inaczej test nic nie dowodzi.
	 */
	public function test_plugin_tables_in_database_match_the_tables_constant(): void {
		global $wpdb;
		$this->remove_temporary_table_filters();
		foreach ( $this->plugin_tables_in_database() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_option( Migrations::OPTION_DB_VERSION );
		$this->assertSame( array(), $this->plugin_tables_in_database(), 'Stan wyjściowy: żadna tabela wtyczki nie istnieje.' );

		Migrations::run();

		$expected = array_map( array( Migrations::class, 'table' ), Migrations::TABLES );
		$actual   = $this->plugin_tables_in_database();
		$this->assertSame( array(), array_values( array_diff( $expected, $actual ) ), 'Tabele z TABLES, których run() nie utworzyło.' );
		$this->assertSame( array(), array_values( array_diff( $actual, $expected ) ), 'Tabele w bazie nieobecne w TABLES (odinstalowanie by ich nie usunęło).' );
	}

	/**
	 * @return array<int, string>
	 */
	private function plugin_tables_in_database(): array {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix . 'pusers_' ) . '%';
		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) );
	}

	private function save_gate( bool $enabled ): void {
		SettingsRepository::save( Settings::fromArray( array( 'delete_data_on_uninstall' => $enabled ) ) );
	}

	/**
	 * Stan "zainstalowanej wtyczki z danymi": prawdziwe tabele, wersja schematu i opcja z konfiguracją.
	 */
	private function install_with_data(): void {
		global $wpdb;
		$this->remove_temporary_table_filters();
		foreach ( Migrations::TABLES as $name ) {
			$table = Migrations::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( Migrations::OPTION_DB_VERSION );
		Migrations::run();

		foreach ( Migrations::TABLES as $name ) {
			$this->assertTrue( $this->table_exists( Migrations::table( $name ) ), 'Stan wyjściowy: tabela istnieje.' );
		}
		$this->assertNotFalse( get_option( Migrations::OPTION_DB_VERSION ), 'Stan wyjściowy: wersja schematu zapisana.' );
		OptionStore::write( OptionStore::OPTION_PROFESSIONS, array( 'x' => 1 ) );
	}

	private function assert_data_intact(): void {
		foreach ( Migrations::TABLES as $name ) {
			$this->assertTrue( $this->table_exists( Migrations::table( $name ) ), $name . ' nie powinna zniknąć.' );
		}
		$this->assertNotFalse( get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertSame( array( 'x' => 1 ), OptionStore::read( OptionStore::OPTION_PROFESSIONS ) );
	}

	private function assert_data_gone(): void {
		foreach ( Migrations::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( Migrations::table( $name ) ), $name . ' powinna zniknąć.' );
		}
		$this->assertFalse( get_option( Migrations::OPTION_DB_VERSION ), 'Wersja schematu powinna zniknąć.' );
		$this->assertSame( array(), OptionStore::read( OptionStore::OPTION_PROFESSIONS ) );
		$this->assertSame( array(), OptionStore::read( OptionStore::OPTION_SETTINGS ) );
	}

	private function remove_temporary_table_filters(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
