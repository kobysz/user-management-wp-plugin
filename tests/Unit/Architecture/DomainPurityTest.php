<?php
/**
 * Pilnuje, by src/Domain nie dotykało WordPressa.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class DomainPurityTest extends TestCase {

	/**
	 * Wzorce wycieków WordPressa (bez rozróżniania wielkości liter).
	 * Każdy jest zakotwiczony lookbehindem na granicy identyfikatora, więc
	 * `preg_replace` czy `__construct` nie dają trafień.
	 *
	 * @var list<string>
	 */
	private const DENIED = array(
		'\$wpdb',
		'wp_',          // Funkcje, stałe i klasy: wp_*, WP_Error, WP_User, WP_Query, WP_REST_Request.
		'esc_',
		'sanitize_',
		'(?:get|update|add|delete)_(?:user_|post_|term_)?(?:option|meta)\b',
		'is_email\b',
		'current_time\b',
		'(?:home|admin|site)_url\b',
		'add_action\b',
		'add_filter\b',
		'apply_filters\b',
		'do_action\b',
		'__\(',
		'_e\(',
		'_n\(',
		'_x\(',
		'ABSPATH\b',
	);

	/**
	 * Zwraca trafione wzorce zakazane w podanym kodzie.
	 *
	 * @return list<string>
	 */
	private function violations( string $src ): array {
		$found = array();
		foreach ( self::DENIED as $pattern ) {
			if ( 1 === preg_match( '/(?<![A-Za-z0-9_])' . $pattern . '/i', $src, $m ) ) {
				$found[] = $m[0];
			}
		}
		return $found;
	}

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
		$files = $this->domainFiles();
		$this->assertIsArray( $files );
		foreach ( $files as $file ) {
			$hits = $this->violations( (string) file_get_contents( $file ) );
			$this->assertSame( array(), $hits, basename( $file ) . ' zawiera zakazane: ' . implode( ', ', $hits ) );
		}
	}

	public function test_detector_catches_wordpress_leaks(): void {
		$leaks = array(
			'return new WP_Error( "x" );',
			'function f( \WP_REST_Request $r ) {}',
			'$u = new WP_User( 1 );',
			'$q = new wp_query();',
			'global $wpdb;',
			'$x = sanitize_text_field( $v );',
			'is_email( $e );',
			'$t = current_time( "mysql" );',
			'get_user_meta( 1, "k" );',
			'$s = __( "tekst", "portal-users" );',
			'_e( "x" );',
			'if ( ! defined( "ABSPATH" ) ) {}',
		);
		foreach ( $leaks as $code ) {
			$this->assertNotSame( array(), $this->violations( $code ), 'Nie wykryto wycieku: ' . $code );
		}
	}

	public function test_detector_ignores_innocent_code(): void {
		$innocent = array(
			'$a = preg_replace( "/x/", "y", $s );',
			'$a = strtoupper( $s );',
			'public function __construct() {}',
			'$this->swp_value = 1; $my_escape_hatch = 2;',
			'$optionsCount = 3; $x = $this->get_options_list();',
		);
		foreach ( $innocent as $code ) {
			$this->assertSame( array(), $this->violations( $code ), 'Fałszywe trafienie: ' . $code );
		}
	}
}
