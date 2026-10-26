<?php
/**
 * Testy guarda zapisu: uprawnienia przed nonce'em, identyczny komunikat odmowy.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\Request;
use WP_UnitTestCase;
use WPDieException;

final class RequestTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_REQUEST['pusers_nonce'], $_GET['pusers_notice'] );
		parent::tear_down();
	}

	public function test_guard_rejects_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
		$this->expectException( WPDieException::class );
		Request::guard( 'pusers_save_settings' );
	}

	public function test_guard_rejects_a_missing_nonce_even_for_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $_REQUEST['pusers_nonce'] );
		$this->expectException( WPDieException::class );
		Request::guard( 'pusers_save_settings' );
	}

	public function test_guard_rejects_a_nonce_issued_for_another_action(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_matrix' );
		$this->expectException( WPDieException::class );
		Request::guard( 'pusers_save_settings' );
	}

	public function test_guard_passes_for_an_administrator_with_a_matching_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
		Request::guard( 'pusers_save_settings' );
		$this->assertTrue( true ); // brak wyjątku jest wynikiem
	}

	public function test_capability_is_checked_before_the_nonce(): void {
		// Subskrybent z poprawnym nonce'em i subskrybent bez nonce'a muszą dostać to samo:
		// odmowę z powodu uprawnień, nie podpowiedź, że nonce był zły.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
		$first = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		unset( $_REQUEST['pusers_nonce'] );
		$second = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		$this->assertSame( $first, $second );
	}

	public function test_nonce_failure_gives_the_same_message_as_a_capability_failure(): void {
		// Administrator z błędnym nonce'em nie może dostać innego komunikatu niż odmowa uprawnień.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $_REQUEST['pusers_nonce'] );
		$bad_nonce = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		$this->assertNotSame( '', $denied );
		$this->assertSame( $denied, $bad_nonce );
	}

	public function test_notice_reads_and_sanitises_the_query_argument(): void {
		$this->assertSame( '', Request::notice() );
		$_GET['pusers_notice'] = '<b>saved</b>';
		$this->assertSame( 'saved', Request::notice() );
	}

	private function captureDieMessage( callable $callback ): string {
		try {
			$callback();
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'Oczekiwano wp_die().' );
	}
}
