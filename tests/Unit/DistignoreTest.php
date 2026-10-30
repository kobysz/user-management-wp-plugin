<?php
/**
 * Testy pokrycia listy wykluczeń paczki wydania.
 *
 * Workflow wydania montuje archiwum przez `rsync --exclude-from=.distignore`, więc każda
 * ścieżka pierwszego poziomu, która nie jest wysyłana do portalu, musi być w `.distignore`.
 * Biała lista w tym teście jest jedynym miejscem, gdzie zapada decyzja „to jedzie do paczki":
 * nowy katalog deweloperski zapala ten test, zamiast cicho wyjechać z wydaniem.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DistignoreTest extends TestCase {

	/**
	 * Ścieżki pierwszego poziomu, które mają trafić do paczki.
	 */
	private const SHIPPED = array(
		'src',
		'assets',
		'languages',
		'vendor',
		'portal-users.php',
		'uninstall.php',
		'CHANGELOG.md',
	);

	/**
	 * Pliki, które workflow wydania tworzy w katalogu głównym PRZED montażem paczki.
	 *
	 * Testu pokrycia pierwszego poziomu one nie dotyczą: na maszynie deweloperskiej nie
	 * istnieją, więc `scandir()` ich nie widzi. Bez tej listy `RELEASE_BODY.md` jechał
	 * w każdej wydanej paczce — dokładnie ta klasa wycieku, przed którą postawiono ten test.
	 */
	private const RELEASE_ARTIFACTS = array(
		'RELEASE_BODY.md',
		'junit-unit.xml',
		'portal-users.zip',
	);

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Wzorce z `.distignore`, bez komentarzy i linii pustych.
	 *
	 * @return list<string>
	 */
	private function patterns(): array {
		$path = $this->root() . '/.distignore';
		$this->assertFileExists( $path, 'Workflow wydania montuje paczkę przez --exclude-from=.distignore.' );

		$out = array();
		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && 0 !== strpos( $line, '#' ) ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Wpisy pierwszego poziomu repozytorium. `.git` pomijamy: nie jest częścią drzewa roboczego.
	 *
	 * @return list<string>
	 */
	private function topLevelEntries(): array {
		$out = array();
		foreach ( (array) scandir( $this->root() ) as $entry ) {
			$entry = (string) $entry;
			if ( '.' !== $entry && '..' !== $entry && '.git' !== $entry ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	private function isExcluded( string $entry ): bool {
		foreach ( $this->patterns() as $pattern ) {
			if ( fnmatch( $pattern, $entry ) || fnmatch( rtrim( $pattern, '/' ), rtrim( $entry, '/' ) ) ) {
				return true;
			}
		}

		return false;
	}

	public function test_distignore_covers_every_top_level_path(): void {
		$entries = $this->topLevelEntries();
		$this->assertNotEmpty( $entries, 'Odczyt katalogu głównego nie może być pusty.' );

		foreach ( $entries as $entry ) {
			if ( in_array( $entry, self::SHIPPED, true ) ) {
				continue;
			}

			$this->assertTrue(
				$this->isExcluded( $entry ),
				sprintf( '„%s" nie jedzie do portalu, więc musi być w .distignore albo na białej liście tego testu.', $entry )
			);
		}
	}

	public function test_distignore_covers_files_created_by_the_release_workflow(): void {
		foreach ( self::RELEASE_ARTIFACTS as $artifact ) {
			$this->assertTrue(
				$this->isExcluded( $artifact ),
				sprintf( '„%s" powstaje w trakcie wydania i nie może wyjechać w paczce.', $artifact )
			);
		}
	}

	public function test_distignore_does_not_exclude_shipped_paths(): void {
		foreach ( self::SHIPPED as $entry ) {
			$this->assertFalse(
				$this->isExcluded( $entry ),
				sprintf( '„%s" jest potrzebne do działania wtyczki na portalu i nie może być wykluczone z paczki.', $entry )
			);
		}
	}
}
