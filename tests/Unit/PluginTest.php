<?php
/**
 * Testy odczytu wersji wtyczki z nagłówka.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PortalUsers\Plugin;

final class PluginTest extends TestCase {

	private function fixturePath(): string {
		return __DIR__ . '/fixtures/plugin-header.php';
	}

	public function test_version_is_read_from_plugin_header(): void {
		// Czyta z pliku-atrapy, nie z prawdziwego nagłówka — test jednostkowy nie ma WP.
		$this->assertSame( '1.2.3', Plugin::parse_version( $this->fixturePath() ) );
	}

	public function test_version_constant_does_not_exist(): void {
		$this->assertFalse( defined( 'PortalUsers\Plugin::VERSION' ) );
	}

	public function test_real_header_has_semver(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Plugin::parse_version( dirname( __DIR__, 2 ) . '/portal-users.php' ) );
	}
}
