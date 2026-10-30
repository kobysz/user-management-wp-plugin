<?php
/**
 * Testy spójności wydania: nagłówek `Update URI`, repozytorium aktualizatora i nazwa assetu.
 *
 * Test integracyjny, bo `GitHubUpdater` ma `defined( 'ABSPATH' ) || exit` i nie da się go
 * wczytać bez WordPressa. Stałe są prywatne i czyta je refleksja — mają być prywatne,
 * a jednocześnie muszą zgadzać się z nagłówkiem wtyczki i z workflow wydania.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Update;

use PortalUsers\Plugin;
use PortalUsers\Update\GitHubUpdater;
use ReflectionClass;
use WP_UnitTestCase;

final class ReleaseConsistencyTest extends WP_UnitTestCase {

	private function updaterConstant( string $name ): string {
		$value = ( new ReflectionClass( GitHubUpdater::class ) )->getConstant( $name );
		$this->assertIsString( $value, sprintf( 'GitHubUpdater::%s musi być napisem.', $name ) );

		return $value;
	}

	private function headerField( string $field ): string {
		$head = (string) file_get_contents( Plugin::plugin_file(), false, null, 0, 8192 );
		$this->assertSame(
			1,
			preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':[ \t]*(\S+)/mi', $head, $m ),
			sprintf( 'Nagłówek wtyczki nie deklaruje „%s".', $field )
		);

		return $m[1];
	}

	public function test_update_uri_header_points_to_the_updater_repository(): void {
		// Od WordPressa 5.8 ten nagłówek powstrzymuje rdzeń od podstawiania pod nasz katalog
		// aktualizacji wtyczki o tym samym slugu z wordpress.org. Slug „portal-users" jest
		// generyczny, a podmiana wtyczki zarządzającej kontami na obcą o tej samej nazwie
		// katalogu byłaby awarią bez drogi powrotu z panelu.
		$this->assertSame(
			'https://github.com/' . $this->updaterConstant( 'REPO' ),
			$this->headerField( 'Update URI' ),
			'Nagłówek „Update URI" i stała REPO aktualizatora muszą wskazywać to samo repozytorium.'
		);
	}

	public function test_asset_name_matches_the_plugin_slug(): void {
		// Tę samą nazwę buduje workflow wydania; rozjechanie się jej z aktualizatorem kończy się
		// cichym „brak aktualizacji", bo wydanie nie ma assetu o oczekiwanej nazwie.
		$slug = basename( dirname( Plugin::plugin_file() ) );

		$this->assertSame( $slug . '.zip', $this->updaterConstant( 'ASSET_NAME' ) );
	}
}
