<?php
/**
 * Testy adaptera guarda zależności w prawdziwym WordPressie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Dependencies;
use WP_UnitTestCase;

final class DependenciesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Przesłanka testów jest jawna: ACF Pro nie jest załadowany, niezależnie od stanu kontenera.
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'pusers_acf_pro_loaded', '__return_false' );
		parent::tear_down();
	}

	private function render(): string {
		ob_start();
		Dependencies::render_notice();
		return (string) ob_get_clean();
	}

	public function test_notice_is_hooked_unconditionally(): void {
		$this->assertNotFalse( has_action( 'admin_notices', array( Dependencies::class, 'render_notice' ) ) );
	}

	public function test_notice_is_hooked_for_network_admin(): void {
		$this->assertNotFalse( has_action( 'network_admin_notices', array( Dependencies::class, 'render_notice' ) ) );
	}

	public function test_notice_is_rendered_when_acf_pro_missing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( Dependencies::satisfied() );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'ACF Pro', $html );
	}

	public function test_notice_is_hidden_from_users_who_cannot_activate_plugins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( Dependencies::satisfied() );

		$this->assertSame( '', $this->render() );
	}
}
