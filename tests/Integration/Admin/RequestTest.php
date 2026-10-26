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
		unset( $_POST['pusers_nonce'], $_REQUEST['pusers_nonce'], $_GET['pusers_nonce'], $_GET['pusers_notice'] );
		parent::tear_down();
	}

	public function test_guard_rejects_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
		$this->expectException( WPDieException::class );
		Request::guard( 'pusers_save_settings' );
	}

	public function test_guard_rejects_a_missing_nonce_even_for_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $_POST['pusers_nonce'] );
		$this->expectException( WPDieException::class );
		Request::guard( 'pusers_save_settings' );
	}

	public function test_guard_rejects_a_nonce_issued_for_another_action(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['pusers_nonce'] = wp_create_nonce( 'pusers_save_matrix' );
		$this->expectException( WPDieException::class );
		Request::guard( 'pusers_save_settings' );
	}

	public function test_guard_passes_for_an_administrator_with_a_matching_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
		Request::guard( 'pusers_save_settings' );
		$this->assertTrue( true ); // brak wyjątku jest wynikiem
	}

	public function test_same_message_whatever_the_nonce_for_a_user_without_the_capability(): void {
		// Subskrybent z poprawnym nonce'em i subskrybent bez nonce'a dostają to samo.
		// Kolejność sprawdzeń dowodzi następny test.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
		$first = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		unset( $_POST['pusers_nonce'] );
		$second = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		$this->assertSame( $first, $second );
	}

	public function test_nonce_is_not_even_verified_for_a_user_without_the_capability(): void {
		// Obie odmowy dają ten sam komunikat, więc kolejność widać tylko po tym, czy nonce w ogóle
		// był weryfikowany: błędny nonce uruchamia wp_verify_nonce_failed.
		$verified = 0;
		add_action(
			'wp_verify_nonce_failed',
			static function () use ( &$verified ): void {
				++$verified;
			}
		);
		$_POST['pusers_nonce'] = 'zly-nonce';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		$this->assertSame( 0, $verified, 'Nonce nie może być weryfikowany przed sprawdzeniem uprawnień.' );

		// Kontrola szpiega: administrator z tym samym nonce'em jest weryfikowany i odrzucany.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		$this->assertSame( 1, $verified );
	}

	public function test_guard_does_not_accept_a_nonce_from_the_query_string(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nonce                    = wp_create_nonce( 'pusers_save_settings' );
		$_GET['pusers_nonce']     = $nonce;
		$_REQUEST['pusers_nonce'] = $nonce;
		unset( $_POST['pusers_nonce'] );
		$this->expectException( WPDieException::class );
		Request::guard( 'pusers_save_settings' );
	}

	public function test_nonce_failure_gives_the_same_message_as_a_capability_failure(): void {
		// Administrator z błędnym nonce'em nie może dostać innego komunikatu niż odmowa uprawnień.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $_POST['pusers_nonce'] );
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
