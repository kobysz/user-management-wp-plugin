<?php
/**
 * Testy kształtu `CHANGELOG.md`.
 *
 * Workflow wydania wycina sekcję wersji z tego pliku i publikuje ją dosłownie jako treść
 * wydania na GitHubie, a aktualizator pokazuje ją w oknie „Szczegóły wtyczki". Nagłówek
 * sekcji jest więc interfejsem, nie ozdobą: `awk` szuka dokładnie `## [x.y.z]`.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChangelogTest extends TestCase {

	private function changelog(): string {
		$path = dirname( __DIR__, 2 ) . '/CHANGELOG.md';
		$this->assertFileExists( $path, 'Wtyczka wydawana z GitHuba musi mieć CHANGELOG.md — workflow wydania wycina z niego treść wydania.' );

		return (string) file_get_contents( $path );
	}

	/**
	 * Linie nagłówków sekcji wersji, bez `## [Unreleased]`.
	 *
	 * @return list<string>
	 */
	private function versionHeadings(): array {
		$out = array();
		foreach ( explode( "\n", $this->changelog() ) as $line ) {
			$line = rtrim( $line, "\r" );
			if ( 0 === strpos( $line, '## [' ) && '## [Unreleased]' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	public function test_changelog_exists_and_starts_with_title(): void {
		$first = explode( "\n", $this->changelog() )[0];

		$this->assertSame( '# Changelog', rtrim( $first, "\r" ) );
	}

	public function test_changelog_declares_unreleased_section(): void {
		$this->assertStringContainsString(
			"\n## [Unreleased]\n",
			$this->changelog(),
			'Sekcja „Unreleased" jest miejscem, do którego trafiają wpisy między wydaniami.'
		);
	}

	public function test_every_version_heading_has_the_agreed_shape(): void {
		foreach ( $this->versionHeadings() as $heading ) {
			$this->assertMatchesRegularExpression(
				'/^## \[\d+\.\d+\.\d+\] - \d{4}-\d{2}-\d{2}$/',
				$heading,
				'Nagłówek sekcji wersji musi mieć postać „## [x.y.z] - RRRR-MM-DD" — na tym stoi wycinanie treści wydania.'
			);
		}
	}

	public function test_no_version_heading_carries_a_prerelease_suffix(): void {
		foreach ( $this->versionHeadings() as $heading ) {
			foreach ( array( '-alpha', '-beta', '-rc' ) as $suffix ) {
				$this->assertStringNotContainsString(
					$suffix,
					$heading,
					'Format wersji jest jeden i trzycyfrowy: prerelease rozjechałby nagłówek wtyczki z testem wersji i z bramką workflow wydania.'
				);
			}
		}
	}
}
