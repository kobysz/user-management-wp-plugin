<?php
/**
 * Pilnuje wszystkich `require_once ABSPATH . 'wp-admin/includes/...'` w kodzie wtyczki.
 *
 * Funkcje administracyjne nie istnieją w kontekście crona ani na froncie, a literówka w ścieżce
 * daje fatal dopiero tam, gdzie nikt nie patrzy (cron: DOING_CRON tłumi wyjście, a harmonogram
 * jest przestawiany PRZED wywołaniem hooka, więc błąd powtarza się codziennie). Testy integracyjne
 * ładują cały panel, więc samej brakującej linii nie widać; tu sprawdzamy, że każdy taki plik
 * istnieje i naprawdę deklaruje funkcję, dla której jest ładowany.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Plugin;
use WP_UnitTestCase;

final class AdminIncludesTest extends WP_UnitTestCase {

	/**
	 * Plik => funkcja, którą wtyczka z niego bierze. Nowy `require_once` musi tu trafić, inaczej test pada.
	 *
	 * @var array<string, string>
	 */
	private const EXPECTED = array(
		'wp-admin/includes/user.php'    => 'wp_delete_user',
		'wp-admin/includes/upgrade.php' => 'dbDelta',
	);

	/**
	 * @return array<string, list<string>> Plik źródłowy => lista ścieżek wp-admin/includes/* w nim.
	 */
	private function found(): array {
		$found    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( Plugin::path( 'src' ), \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plik lokalny.
			if ( preg_match_all( "~ABSPATH\\s*\\.\\s*'(wp-admin/includes/[^']+)'~", $code, $matches ) > 0 ) {
				$found[ $file->getPathname() ] = $matches[1];
			}
		}
		return $found;
	}

	public function test_every_admin_include_in_the_plugin_exists_and_declares_the_function_it_is_loaded_for(): void {
		$found = $this->found();
		$this->assertNotEmpty( $found, 'Skaner nic nie znalazł: wzorzec przestał pasować do kodu.' );

		$seen = array();
		foreach ( $found as $source => $paths ) {
			foreach ( $paths as $path ) {
				$this->assertArrayHasKey( $path, self::EXPECTED, $source . ': nowy require_once ' . $path . ' trzeba dopisać do EXPECTED.' );
				$this->assertFileExists( ABSPATH . $path, $source . ': ' . $path . ' nie istnieje w tym WordPressie.' );
				$declared = (string) file_get_contents( ABSPATH . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plik lokalny.
				$this->assertMatchesRegularExpression(
					'/^\s*function\s+' . preg_quote( self::EXPECTED[ $path ], '/' ) . '\s*\(/m',
					$declared,
					$path . ' nie deklaruje ' . self::EXPECTED[ $path ] . '().'
				);
				$seen[ $path ] = true;
			}
		}
		foreach ( array_keys( self::EXPECTED ) as $path ) {
			$this->assertArrayHasKey( $path, $seen, 'Wtyczka nie ładuje już ' . $path . ': usuń wpis z EXPECTED albo przywróć linię.' );
		}
	}
}
