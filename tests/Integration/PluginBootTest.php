<?php
/**
 * Testy: gating funkcji wtyczki zapada na plugins_loaded, nie w chwili ładowania.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Plugin;
use WP_UnitTestCase;

final class PluginBootTest extends WP_UnitTestCase {

	private int $registered = 0;

	public function set_up(): void {
		parent::set_up();
		// Czysty start: zdejmij callback z bootstrapu testów i policz rejestracje funkcji.
		remove_action( 'plugins_loaded', array( Plugin::class, 'load_features' ) );
		remove_all_actions( 'pusers_register_features' );
		$this->registered = 0;
		add_action(
			'pusers_register_features',
			function (): void {
				++$this->registered;
			}
		);
	}

	public function test_features_register_only_after_plugins_loaded_when_dependency_appears_late(): void {
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		Plugin::boot( Plugin::plugin_file() );
		$this->assertSame( 0, $this->registered, 'boot() nie może rejestrować funkcji.' );

		// ACF Pro "załadował się" po wtyczce, ale przed plugins_loaded.
		remove_filter( 'pusers_acf_pro_loaded', '__return_false' );
		add_filter( 'pusers_acf_pro_loaded', '__return_true' );
		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->registered );
	}

	public function test_features_do_not_register_when_dependency_missing_after_plugins_loaded(): void {
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		Plugin::boot( Plugin::plugin_file() );
		do_action( 'plugins_loaded' );

		$this->assertSame( 0, $this->registered );
	}
}
