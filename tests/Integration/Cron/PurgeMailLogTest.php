<?php
/**
 * Testy crona retencji logu wysyłek.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Cron;

use PortalUsers\Cron\PurgeMailLog;
use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\Migrations;
use WP_UnitTestCase;

final class PurgeMailLogTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_clear_scheduled_hook( PurgeMailLog::HOOK );
		remove_all_filters( 'pusers_mail_log_retention_days' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_mail_log_retention_days' );
		parent::tear_down();
	}

	/**
	 * Wstawia wiersz o danym stanie i wieku w dniach.
	 *
	 * @param QueueStatus $status  Stan.
	 * @param int         $ageDays Wiek względem `created_at`.
	 * @param string      $key     Klucz odporności.
	 */
	private function seed( QueueStatus $status, int $ageDays, string $key ): int {
		global $wpdb;
		$created = gmdate( 'Y-m-d H:i:s', time() - $ageDays * DAY_IN_SECONDS );
		MailQueueRepository::insert(
			array(
				'created_at'   => $created,
				'scheduled_at' => $created,
				'kind'         => 'confirmation',
				'dedupe_key'   => $key,
				'user_id'      => 7,
				'recipient'    => 'lekarz@example.com',
				'subject'      => 'Temat',
				'body'         => 'Treść',
			)
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->update( Migrations::table( 'mail_queue' ), array( 'status' => $status->value ), array( 'id' => $id ) );
		return $id;
	}

	public function test_rows_older_than_the_retention_window_are_deleted(): void {
		$oldSent   = $this->seed( QueueStatus::Sent, PurgeMailLog::RETENTION_DAYS + 1, 'a' );
		$oldFailed = $this->seed( QueueStatus::Failed, PurgeMailLog::RETENTION_DAYS + 1, 'b' );
		$recent    = $this->seed( QueueStatus::Sent, PurgeMailLog::RETENTION_DAYS - 1, 'c' );

		PurgeMailLog::run();

		$this->assertNull( MailQueueRepository::find( $oldSent ) );
		$this->assertNull( MailQueueRepository::find( $oldFailed ) );
		$this->assertNotNull( MailQueueRepository::find( $recent ) );
	}

	public function test_a_row_still_waiting_is_kept_regardless_of_age(): void {
		// Cron nie chodził przez pół roku; mail nadal ma wyjść, nie zniknąć.
		$this->seed( QueueStatus::Queued, 200, 'a' );
		$this->seed( QueueStatus::Sending, 200, 'b' );

		PurgeMailLog::run();

		$this->assertSame( 1, MailQueueRepository::count_by_status( QueueStatus::Queued->value ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( QueueStatus::Sending->value ) );
	}

	public function test_the_run_writes_nothing_to_the_event_log(): void {
		// Inaczej niż PurgeUnconfirmed: dzienny wiersz o skasowanych logach zapchałby tabelę audytu.
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		$this->seed( QueueStatus::Sent, 200, 'a' );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().

		PurgeMailLog::run();

		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- j.w.
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ), 'Przebieg faktycznie coś skasował.' );
	}

	public function test_the_retention_window_can_be_changed_by_the_filter(): void {
		$id = $this->seed( QueueStatus::Sent, 40, 'a' );
		add_filter( 'pusers_mail_log_retention_days', static fn() => 30 );

		PurgeMailLog::run();

		$this->assertNull( MailQueueRepository::find( $id ) );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function bad_windows(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
			'garbage'  => array( 'abc' ),
		);
	}

	/**
	 * @dataProvider bad_windows
	 * @param mixed $value Wartość z filtra.
	 */
	public function test_the_retention_filter_cannot_be_set_to_zero_days( $value ): void {
		// Zero skasowałoby dzisiejszy log; wartość niepoprawna wraca do domyślnej.
		$today = $this->seed( QueueStatus::Sent, 0, 'a' );
		$old   = $this->seed( QueueStatus::Sent, PurgeMailLog::RETENTION_DAYS + 1, 'b' );
		add_filter( 'pusers_mail_log_retention_days', static fn() => $value );

		PurgeMailLog::run();

		$this->assertNotNull( MailQueueRepository::find( $today ) );
		$this->assertNull( MailQueueRepository::find( $old ) );
	}

	public function test_register_binds_the_run_without_creating_the_event(): void {
		PurgeMailLog::register();

		$this->assertNotFalse( has_action( PurgeMailLog::HOOK, array( PurgeMailLog::class, 'run' ) ) );
		$this->assertFalse( wp_next_scheduled( PurgeMailLog::HOOK ) );
	}

	public function test_schedule_is_daily_and_idempotent_and_unschedule_removes_it(): void {
		PurgeMailLog::schedule();
		PurgeMailLog::schedule();

		$this->assertSame( 'daily', wp_get_schedule( PurgeMailLog::HOOK ) );
		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			$count += count( (array) ( $events[ PurgeMailLog::HOOK ] ?? array() ) );
		}
		$this->assertSame( 1, $count );

		PurgeMailLog::unschedule();
		$this->assertFalse( wp_next_scheduled( PurgeMailLog::HOOK ) );
	}
}
