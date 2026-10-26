<?php
/**
 * Testy adaptera guarda zależności w prawdziwym WordPressie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Dependencies;
use WP_UnitTestCase;

final class DependenciesTest extends WP_UnitTestCase {

	public function test_notice_is_hooked_unconditionally(): void {
		$this->assertNotFalse( has_action( 'admin_notices', array( Dependencies::class, 'render_notice' ) ) );
	}

	public function test_notice_is_rendered_when_acf_pro_missing(): void {
		// Kontener testowy nie ma ACF Pro.
		$this->assertFalse( Dependencies::satisfied() );

		ob_start();
		Dependencies::render_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'ACF Pro', $html );
	}
}
