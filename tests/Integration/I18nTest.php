<?php
/**
 * Testy podpięcia i ładowania tłumaczeń z katalogu `languages/`.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\I18n;
use PortalUsers\Plugin;
use WP_UnitTestCase;

final class I18nTest extends WP_UnitTestCase {

	private const MSGID = 'Testowy klucz tłumaczenia';

	private string $mo_file = '';

	public function set_up(): void {
		parent::set_up();
		// Niezależnie od locale kontenera.
		add_filter( 'locale', array( $this, 'force_pl_locale' ), 99 );
		$this->mo_file = Plugin::path( 'languages/portal-users-pl_PL.mo' );
	}

	public function force_pl_locale(): string {
		return 'pl_PL';
	}

	public function tear_down(): void {
		if ( is_file( $this->mo_file ) ) {
			unlink( $this->mo_file );
		}
		unload_textdomain( 'portal-users' );
		remove_filter( 'locale', array( $this, 'force_pl_locale' ), 99 );
		parent::tear_down();
	}

	/**
	 * Minimalny plik .mo z jednym wpisem (format GNU gettext, little endian).
	 *
	 * @param string $msgid  Oryginał.
	 * @param string $msgstr Tłumaczenie.
	 */
	private function build_mo( string $msgid, string $msgstr ): string {
		$header = pack( 'V*', 0x950412de, 0, 1, 28, 36, 0, 0 );
		$tables = pack( 'V*', strlen( $msgid ), 44 + 0, strlen( $msgstr ), 44 + strlen( $msgid ) + 1 );
		return $header . $tables . $msgid . "\0" . $msgstr . "\0";
	}

	public function test_boot_hooks_loader_on_init(): void {
		$this->assertNotFalse( has_action( 'init', array( I18n::class, 'load' ) ) );
	}

	public function test_languages_dir_matches_domain_path(): void {
		$this->assertSame( 'portal-users/languages', I18n::relative_languages_dir() );
	}

	public function test_without_mo_file_source_string_is_returned(): void {
		I18n::load();

		$this->assertSame( self::MSGID, __( 'Testowy klucz tłumaczenia', 'portal-users' ) );
	}

	public function test_mo_file_from_languages_dir_is_used(): void {
		file_put_contents( $this->mo_file, $this->build_mo( self::MSGID, 'Test translation key' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		unload_textdomain( 'portal-users' );
		I18n::load();

		$this->assertSame( 'Test translation key', __( 'Testowy klucz tłumaczenia', 'portal-users' ) );
	}
}
