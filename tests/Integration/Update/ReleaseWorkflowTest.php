<?php
/**
 * Testy spójności workflow wydania z kodem wtyczki.
 *
 * Workflow nie da się uruchomić lokalnie, więc te trzy wartości pinujemy testem: nazwę
 * assetu, nazwę katalogu montażowego i format wersji w bramce. Każda z nich rozjechana
 * z kodem daje awarię, której nie widać aż do wydania.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Update;

use PortalUsers\Plugin;
use PortalUsers\Update\GitHubUpdater;
use ReflectionClass;
use WP_UnitTestCase;

final class ReleaseWorkflowTest extends WP_UnitTestCase {

	private function workflow(): string {
		$path = dirname( Plugin::plugin_file() ) . '/.github/workflows/release.yml';
		$this->assertFileExists( $path, 'Wydanie powstaje z tagu przez workflow — bez pliku nie ma wydania.' );

		return (string) file_get_contents( $path );
	}

	private function slug(): string {
		return basename( dirname( Plugin::plugin_file() ) );
	}

	public function test_workflow_builds_asset_named_like_the_updater_constant(): void {
		// Aktualizator szuka assetu po dosłownej nazwie. Rozjechanie się tych dwóch miejsc
		// kończy się cichym „brak aktualizacji": wydanie jest, assetu o tej nazwie nie ma.
		$asset = ( new ReflectionClass( GitHubUpdater::class ) )->getConstant( 'ASSET_NAME' );
		$this->assertIsString( $asset );

		$this->assertStringContainsString( $asset, $this->workflow() );
	}

	public function test_workflow_stages_into_a_directory_named_like_the_plugin_slug(): void {
		// Nazwa katalogu w korzeniu archiwum decyduje o katalogu instalacji. Inna nazwa każe
		// WordPressowi rozpakować aktualizację do NOWEGO katalogu i zostawić starą wtyczkę
		// obok, nieaktywną — portal traci zarządzanie kontami w trakcie aktualizacji.
		$this->assertStringContainsString(
			sprintf( 'STAGE="%s"', $this->slug() ),
			$this->workflow(),
			'Katalog montażowy musi nazywać się dokładnie tak jak katalog wtyczki.'
		);
	}

	public function test_workflow_version_guard_uses_the_agreed_version_pattern(): void {
		// Ten sam format pilnuje PluginTest::test_real_header_has_semver i ChangelogTest.
		// Rozluźnienie bramki w workflow bez ruszania testów przepuściłoby prerelease,
		// na którym test nagłówka i tak by się wywrócił.
		$this->assertStringContainsString(
			'[0-9]+\.[0-9]+\.[0-9]+',
			$this->workflow(),
			'Bramka wersji w workflow musi używać tego samego trzycyfrowego formatu co testy.'
		);
	}
}
