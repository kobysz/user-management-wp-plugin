<?php
/**
 * Testy punktu zaczepienia gatingu funkcji wtyczki.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Plugin;
use WP_UnitTestCase;

final class PluginBootTest extends WP_UnitTestCase {

	public function test_feature_gating_is_deferred_to_plugins_loaded(): void {
		// Gating nie jest liczony w chwili ładowania wtyczki (zależałby od kolejności
		// katalogów wtyczek), tylko w callbacku na plugins_loaded.
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'load_features' ) ) );
	}
}
