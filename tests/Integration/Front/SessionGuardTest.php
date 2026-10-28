<?php
/**
 * Testy unieważniania sesji po utracie stanu „zatwierdzony”.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Front\SessionGuard;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use WP_Session_Tokens;
use WP_UnitTestCase;

final class SessionGuardTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Podpięcie jest idempotentne, więc nie zależy od tego, czy start wtyczki już je wykonał.
		SessionGuard::register();
	}

	/**
	 * Tworzy konto w podanym stanie i otwiera mu dwie sesje.
	 *
	 * @param AccountStatus $status Stan początkowy.
	 */
	private function account_with_sessions( AccountStatus $status ): int {
		$id = self::factory()->user->create();
		AccountStatusRepository::set( $id, $status );
		$tokens = WP_Session_Tokens::get_instance( $id );
		$tokens->create( time() + HOUR_IN_SECONDS );
		$tokens->create( time() + HOUR_IN_SECONDS );
		$this->assertCount( 2, $tokens->get_all(), 'Konto testowe musi mieć dwie sesje.' );
		return $id;
	}

	private function session_count( int $userId ): int {
		return count( WP_Session_Tokens::get_instance( $userId )->get_all() );
	}

	/**
	 * Wiersze logu zdarzeń tej akcji.
	 *
	 * @return list<object>
	 */
	private function log_rows(): array {
		global $wpdb;
		$table  = Migrations::table( 'event_log' );
		$action = SessionGuard::LOG_ACTION;
		return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE action = '{$action}' ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_losing_the_approved_status_destroys_every_session_of_that_account(): void {
		$id = $this->account_with_sessions( AccountStatus::Approved );

		AccountStatusRepository::set( $id, AccountStatus::Rejected );

		$this->assertSame( 0, $this->session_count( $id ) );
		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, (int) $rows[0]->subject_user_id );
	}

	public function test_gaining_the_approved_status_destroys_nothing(): void {
		// email_unconfirmed -> approved w jednym przebiegu (akceptacja wyłączona w ustawieniach).
		$id = $this->account_with_sessions( AccountStatus::EmailUnconfirmed );

		AccountStatusRepository::set( $id, AccountStatus::Approved );

		$this->assertSame( 2, $this->session_count( $id ) );
		$this->assertSame( array(), $this->log_rows() );
	}

	public function test_only_the_subject_account_loses_its_sessions(): void {
		$subject = $this->account_with_sessions( AccountStatus::Approved );
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$tokens  = WP_Session_Tokens::get_instance( $editor );
		$tokens->create( time() + HOUR_IN_SECONDS );
		wp_set_current_user( $editor );

		AccountStatusRepository::set( $subject, AccountStatus::Rejected );

		$this->assertSame( 0, $this->session_count( $subject ) );
		$this->assertSame( 1, $this->session_count( $editor ), 'Redaktor odrzucający wniosek nie może stracić sesji.' );
		$rows = $this->log_rows();
		$this->assertSame( $editor, (int) $rows[0]->actor_user_id );
	}

	public function test_setting_the_same_status_twice_destroys_nothing(): void {
		$id = $this->account_with_sessions( AccountStatus::Approved );

		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'pusers_account_status_changed', $count );

		AccountStatusRepository::set( $id, AccountStatus::Approved );
		$this->assertSame( 0, $fired, 'Ten sam stan nie jest zmianą, więc akcja nie może się odpalić.' );
		$this->assertSame( 2, $this->session_count( $id ) );

		// Dowód, że licznik widzi akcję: prawdziwa zmiana ją odpala, a zaraz po niej powtórka już nie.
		AccountStatusRepository::set( $id, AccountStatus::Rejected );
		$this->assertSame( 1, $fired );
		$this->assertSame( 0, $this->session_count( $id ) );

		$tokens = WP_Session_Tokens::get_instance( $id );
		$tokens->create( time() + HOUR_IN_SECONDS );
		AccountStatusRepository::set( $id, AccountStatus::Rejected );
		$this->assertSame( 1, $fired );
		$this->assertSame( 1, $this->session_count( $id ), 'Powtórny zapis tej samej wartości nie rusza sesji.' );
		remove_action( 'pusers_account_status_changed', $count );
	}

	public function test_the_listener_itself_ignores_a_status_that_is_still_approved(): void {
		// Druga połowa warunku sprawdzana wprost na nasłuchu, bez repozytorium, które i tak
		// nie odpala akcji przy niezmienionym stanie.
		$id = $this->account_with_sessions( AccountStatus::Approved );

		SessionGuard::on_status_changed( $id, AccountStatus::Approved, AccountStatus::Approved );

		$this->assertSame( 2, $this->session_count( $id ) );
	}

	public function test_the_action_carries_the_previous_status(): void {
		$id    = self::factory()->user->create();
		$calls = array();
		$spy   = static function ( ...$args ) use ( &$calls ): void {
			$calls[] = $args;
		};
		add_action( 'pusers_account_status_changed', $spy, 10, 3 );

		AccountStatusRepository::set( $id, AccountStatus::Pending );
		AccountStatusRepository::set( $id, AccountStatus::Approved );

		remove_action( 'pusers_account_status_changed', $spy, 10 );
		$this->assertSame(
			array(
				array( $id, null, AccountStatus::Pending ),
				array( $id, AccountStatus::Pending, AccountStatus::Approved ),
			),
			$calls
		);
	}
}
