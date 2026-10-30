<?php
/**
 * Testy spójności podłogi PHP: nagłówek wtyczki, `require.php` i `config.platform.php`.
 *
 * Ta ostatnia wartość decyduje, jaką gramatyką PHPStan sprawdza kod — bez `phpVersion`
 * w konfiguracji bierze ją z `composer.json`. Jej zniknięcie albo podniesienie przenosi
 * bramkę na wersję hosta po cichu, więc trzy miejsca muszą mówić to samo.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhpFloorTest extends TestCase {

	private function rootPath( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	private function headerFloor(): string {
		$head = (string) file_get_contents( $this->rootPath( 'portal-users.php' ), false, null, 0, 8192 );
		$this->assertSame( 1, preg_match( '/^[ \t\/*#@]*Requires PHP:[ \t]*(\S+)/mi', $head, $m ), 'Nagłówek wtyczki nie deklaruje „Requires PHP".' );

		return $m[1];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function composer(): array {
		$decoded = json_decode( (string) file_get_contents( $this->rootPath( 'composer.json' ) ), true );
		$this->assertIsArray( $decoded, 'composer.json nie jest poprawnym JSON-em.' );

		return $decoded;
	}

	public function test_plugin_header_php_floor_matches_composer(): void {
		$composer = $this->composer();

		$this->assertSame(
			'>=' . $this->headerFloor(),
			$composer['require']['php'] ?? null,
			'„Requires PHP" w nagłówku wtyczki i `require.php` w composer.json muszą deklarować tę samą podłogę.'
		);
	}

	public function test_composer_platform_pins_the_same_floor(): void {
		$composer = $this->composer();

		$this->assertSame(
			$this->headerFloor(),
			$composer['config']['platform']['php'] ?? null,
			'`config.platform.php` decyduje, jaką wersją PHP analizuje PHPStan — musi być dokładnie podłogą z nagłówka wtyczki.'
		);
	}
}
