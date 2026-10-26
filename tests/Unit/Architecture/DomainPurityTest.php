<?php
/**
 * Pilnuje, by src/Domain nie dotykało WordPressa.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class DomainPurityTest extends TestCase {

	/**
	 * @return list<string>
	 */
	private function domainFiles(): array {
		$dir   = dirname( __DIR__, 3 ) . '/src/Domain';
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}

	public function test_domain_contains_no_wordpress_calls(): void {
		$denied = array(
			'$wpdb',
			'add_action(',
			'add_filter(',
			'get_option(',
			'update_option(',
			'wp_',
			'esc_',
			'__(',
			'_e(',
			'apply_filters(',
			'do_action(',
			'ABSPATH',
		);
		$this->assertIsArray( $this->domainFiles() );
		foreach ( $this->domainFiles() as $file ) {
			$src = (string) file_get_contents( $file );
			foreach ( $denied as $needle ) {
				$this->assertStringNotContainsString( $needle, $src, basename( $file ) . ' zawiera ' . $needle );
			}
		}
	}
}
