<?php
/**
 * Testy crona czyszczącego niepotwierdzone konta, na prawdziwej bazie.
 *
 * Każdy test tworzy własne konta i sprawdza wyłącznie ich losy; niczego nie zakłada o kontach z bootstrapu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Cron;

use PortalUsers\Cron\PurgeUnconfirmed;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Plugin;
use WP_UnitTestCase;

final class PurgeUnconfirmedTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		SettingsRepository::save( Settings::fromArray( array( 'unconfirmed_purge_days' => 7 ) ) );
		wp_clear_scheduled_hook( PurgeUnconfirmed::HOOK );
	}

	/**
	 * Tworzy konto zarejestrowane dawniej o podaną liczbę dni.
	 *
	 * @param int                 $daysAgo Wiek konta.
	 * @param AccountStatus|null  $status  Stan albo null (brak metadanej).
	 * @param array<string,mixed> $extra   Dodatkowe argumenty konta.
	 */
	private function account( int $daysAgo, ?AccountStatus $status, array $extra = array() ): int {
		$id = self::factory()->user->create(
			array_merge(
				array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $daysAgo * DAY_IN_SECONDS ) ),
				$extra
			)
		);
		if ( null !== $status ) {
			AccountStatusRepository::set( $id, $status );
		}
		return $id;
	}

	/**
	 * @return list<object> Wiersze logu o danej akcji.
	 */
	private function logRows( string $action ): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id ASC", $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * @return int Ile zdarzeń crona czeka na hooka.
	 */
	private function scheduledCount(): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ PurgeUnconfirmed::HOOK ] ) ) {
				$count += count( $hooks[ PurgeUnconfirmed::HOOK ] );
			}
		}
		return $count;
	}

	public function test_run_deletes_only_qualifying_accounts_and_frees_the_address(): void {
		$stale = $this->account( 10, AccountStatus::EmailUnconfirmed, array( 'user_email' => 'porzucony@example.com' ) );
		$this->assertSame( $stale, email_exists( 'porzucony@example.com' ) );

		$fresh      = $this->account( 2, AccountStatus::EmailUnconfirmed );
		$rejected   = $this->account( 400, AccountStatus::Rejected );
		$approved   = $this->account( 400, AccountStatus::Approved );
		$pending    = $this->account( 400, AccountStatus::Pending );
		$stateless  = $this->account( 400, null, array( 'role' => 'administrator' ) );
		$unreadable = $this->account( 400, null );
		update_user_meta( $unreadable, UserMeta::STATUS, 'uszkodzone' );

		PurgeUnconfirmed::run();

		$this->assertFalse( get_userdata( $stale ), 'Stare niepotwierdzone konto powinno zniknąć.' );
		$this->assertFalse( email_exists( 'porzucony@example.com' ) );
		$this->assertIsInt(
			wp_insert_user(
				array(
					'user_login' => 'nowy_lekarz',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'porzucony@example.com',
				)
			),
			'Adres musi dać się zarejestrować ponownie.'
		);

		$survivors = array(
			'w oknie'                   => $fresh,
			'rejected'                  => $rejected,
			'approved'                  => $approved,
			'pending'                   => $pending,
			'bez stanu (administrator)' => $stateless,
			'stan nierozpoznany'        => $unreadable,
		);
		foreach ( $survivors as $label => $id ) {
			$this->assertNotFalse( get_userdata( $id ), 'Konto nie może zostać usunięte: ' . $label );
		}
	}

	public function test_account_without_a_status_survives_however_old_it_is(): void {
		$admin = $this->account( 5000, null, array( 'role' => 'administrator' ) );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $admin ) );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_account_with_an_unrecognised_status_survives_however_old_it_is(): void {
		$id = $this->account( 5000, null );
		update_user_meta( $id, UserMeta::STATUS, 'uszkodzone' );
		$this->assertTrue( AccountStatusRepository::isUnrecognised( $id ) );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ) );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_rejected_account_survives_however_old_it_is(): void {
		$id = $this->account( 5000, AccountStatus::Rejected );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ) );
	}

	public function test_unconfirmed_account_inside_the_window_survives(): void {
		$id = $this->account( 2, AccountStatus::EmailUnconfirmed );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ) );
	}

	public function test_run_deletes_nothing_when_no_account_qualifies(): void {
		$ids    = array(
			$this->account( 2, AccountStatus::EmailUnconfirmed ),
			$this->account( 400, AccountStatus::Rejected ),
			$this->account( 400, null, array( 'role' => 'administrator' ) ),
		);
		$before = count_users()['total_users'];

		PurgeUnconfirmed::run();

		$this->assertSame( $before, count_users()['total_users'] );
		foreach ( $ids as $id ) {
			$this->assertNotFalse( get_userdata( $id ) );
		}
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_run_writes_each_deletion_to_the_event_log(): void {
		$one = $this->account( 10, AccountStatus::EmailUnconfirmed );
		$two = $this->account( 30, AccountStatus::EmailUnconfirmed );
		$this->account( 400, AccountStatus::Rejected );

		PurgeUnconfirmed::run();

		$rows = $this->logRows( PurgeUnconfirmed::LOG_ACTION );
		$this->assertCount( 2, $rows );
		$subjects = array_map( static fn ( $row ): int => (int) $row->subject_user_id, $rows );
		sort( $subjects );
		$expected = array( $one, $two );
		sort( $expected );
		$this->assertSame( $expected, $subjects );
		$this->assertSame( 0, (int) $rows[0]->actor_user_id );
	}

	public function test_run_honours_the_configured_window(): void {
		$id = $this->account( 10, AccountStatus::EmailUnconfirmed );
		SettingsRepository::save( Settings::fromArray( array( 'unconfirmed_purge_days' => 30 ) ) );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ), 'Okno 30 dni nie obejmuje konta sprzed 10 dni.' );
	}

	public function test_activation_schedules_the_hook_and_deactivation_removes_it(): void {
		$this->assertFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );

		PurgeUnconfirmed::schedule();
		$this->assertNotFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( PurgeUnconfirmed::HOOK ) );

		PurgeUnconfirmed::unschedule();
		$this->assertFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );
	}

	public function test_schedule_does_not_duplicate_an_existing_event(): void {
		// WordPress sam odrzuca duplikat tylko w ciągu 10 minut od istniejącego zdarzenia,
		// więc istniejące zdarzenie stawiamy dobę dalej: tylko strażnik w schedule() ma szansę je zauważyć.
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', PurgeUnconfirmed::HOOK );

		PurgeUnconfirmed::schedule();

		$this->assertSame( 1, $this->scheduledCount() );
	}

	public function test_plugin_file_wires_activation_and_deactivation_to_the_schedule(): void {
		$base = plugin_basename( Plugin::plugin_file() );
		$this->assertNotFalse( has_action( 'activate_' . $base, array( PurgeUnconfirmed::class, 'schedule' ) ) );
		$this->assertNotFalse( has_action( 'deactivate_' . $base, array( PurgeUnconfirmed::class, 'unschedule' ) ) );
	}

	public function test_register_hooks_the_run_and_restores_a_missing_schedule(): void {
		remove_all_actions( PurgeUnconfirmed::HOOK );

		PurgeUnconfirmed::register();

		$this->assertNotFalse( has_action( PurgeUnconfirmed::HOOK, array( PurgeUnconfirmed::class, 'run' ) ) );
		$this->assertNotFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );
	}
}
