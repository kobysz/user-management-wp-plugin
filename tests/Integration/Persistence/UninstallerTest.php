<?php
/**
 * Testy odinstalowania w prawdziwej bazie WordPressa.
 *
 * Testy kasują prawdziwe tabele. WP_UnitTestCase zamienia CREATE TABLE na tymczasowe,
 * a tabele tymczasowe są niewidoczne dla SHOW TABLES, więc testy zdejmują te filtry
 * (tak jak MigrationsTest) i w tear_down odtwarzają bazę.
 *
 * DDL (DROP/CREATE) zatwierdza transakcję testu, więc opcje zapisane po nim nie zostałyby
 * wycofane; tear_down sprząta je jawnie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\Uninstaller;
use WP_UnitTestCase;

final class UninstallerTest extends WP_UnitTestCase {

	private const TABLES = array( 'event_log', 'terms_versions' );

	public function tear_down(): void {
		try {
			$this->remove_temporary_table_filters();
			foreach ( OptionStore::all() as $option ) {
				OptionStore::delete( $option );
			}
			Migrations::run();
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

	private function save_gate( bool $enabled ): void {
		SettingsRepository::save( Settings::fromArray( array( 'delete_data_on_uninstall' => $enabled ) ) );
	}

	/**
	 * Stan "zainstalowanej wtyczki z danymi": prawdziwe tabele, wersja schematu i opcja z konfiguracją.
	 */
	private function install_with_data(): void {
		global $wpdb;
		$this->remove_temporary_table_filters();
		foreach ( self::TABLES as $name ) {
			$table = Migrations::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( Migrations::OPTION_DB_VERSION );
		Migrations::run();

		foreach ( self::TABLES as $name ) {
			$this->assertTrue( $this->table_exists( Migrations::table( $name ) ), 'Stan wyjściowy: tabela istnieje.' );
		}
		$this->assertNotFalse( get_option( Migrations::OPTION_DB_VERSION ), 'Stan wyjściowy: wersja schematu zapisana.' );
		OptionStore::write( OptionStore::OPTION_PROFESSIONS, array( 'x' => 1 ) );
	}

	private function assert_data_intact(): void {
		foreach ( self::TABLES as $name ) {
			$this->assertTrue( $this->table_exists( Migrations::table( $name ) ), $name . ' nie powinna zniknąć.' );
		}
		$this->assertNotFalse( get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertSame( array( 'x' => 1 ), OptionStore::read( OptionStore::OPTION_PROFESSIONS ) );
	}

	private function assert_data_gone(): void {
		foreach ( self::TABLES as $name ) {
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
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
