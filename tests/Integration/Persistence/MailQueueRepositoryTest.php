<?php
/**
 * Testy kolejki maili w prawdziwej bazie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Persistence\MailQueueRepository;
use WP_UnitTestCase;

final class MailQueueRepositoryTest extends WP_UnitTestCase {

	/**
	 * Wiersz do wstawienia; domyślnie gotowy do wysyłki od razu.
	 *
	 * @param string      $dedupe_key   Klucz odporności.
	 * @param string|null $scheduled_at Termin wysyłki (UTC), domyślnie teraz.
	 * @param string|null $created_at   Moment utworzenia (UTC), domyślnie teraz.
	 * @return array{created_at:string,scheduled_at:string,kind:string,dedupe_key:string,user_id:int,recipient:string,subject:string,body:string}
	 */
	private function row( string $dedupe_key, ?string $scheduled_at = null, ?string $created_at = null ): array {
		$now = gmdate( 'Y-m-d H:i:s' );
		return array(
			'created_at'   => $created_at ?? $now,
			'scheduled_at' => $scheduled_at ?? $now,
			'kind'         => 'confirmation',
			'dedupe_key'   => $dedupe_key,
			'user_id'      => 7,
			'recipient'    => 'lekarz@example.com',
			'subject'      => 'Potwierdź konto',
			'body'         => 'Treść maila',
		);
	}

	public function test_a_duplicate_dedupe_key_does_not_create_a_second_row(): void {
		$this->assertTrue( MailQueueRepository::insert( $this->row( 'klucz-a' ) ) );
		$this->assertFalse( MailQueueRepository::insert( $this->row( 'klucz-a' ) ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_only_the_first_claim_wins(): void {
		// Cron minutowy i zdarzenie natychmiastowe mogą trafić w ten sam wiersz. Lekarz nie może dostać
		// dwóch linków potwierdzających, więc zajęcie jest warunkowym UPDATE-em, nie odczytem i zapisem.
		MailQueueRepository::insert( $this->row( 'klucz-b' ) );
		$id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
		$this->assertTrue( MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertFalse( MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertSame( 1, (int) MailQueueRepository::find( $id )['attempts'] );
	}

	public function test_a_row_scheduled_in_the_future_is_not_due(): void {
		MailQueueRepository::insert( $this->row( 'klucz-c', gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
		$this->assertSame( array(), MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 ) );
	}

	public function test_a_stale_sending_row_returns_to_the_queue(): void {
		// Przebieg przerwany w połowie (timeout PHP) zostawiłby wiersz w stanie sending na zawsze.
		MailQueueRepository::insert( $this->row( 'klucz-d' ) );
		$id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
		MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
		$this->assertSame( 1, MailQueueRepository::recover_stale( gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
		$this->assertSame( QueueStatus::Queued->value, MailQueueRepository::find( $id )['status'] );
	}

	public function test_requeue_touches_failed_rows_only(): void {
		MailQueueRepository::insert( $this->row( 'klucz-e' ) );
		$id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
		$this->assertFalse( MailQueueRepository::requeue_failed( $id ) );   // jeszcze queued.
		MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );
		MailQueueRepository::mark_failed( $id, 'SMTP nie odpowiada' );
		$this->assertTrue( MailQueueRepository::requeue_failed( $id ) );
		$this->assertSame( 0, (int) MailQueueRepository::find( $id )['attempts'] );
	}

	public function test_retention_keeps_rows_still_waiting(): void {
		// Czyszczenie logu nie może zjeść maila, który czeka na wysyłkę od dawna (cron nie chodził).
		MailQueueRepository::insert( $this->row( 'klucz-f', gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 0, MailQueueRepository::purge_old( gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( QueueStatus::Queued->value ) );
	}

	public function test_retention_deletes_old_sent_and_failed_rows(): void {
		$old = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
		foreach ( array( 'stary-sent', 'stary-failed', 'nowy-sent' ) as $key ) {
			MailQueueRepository::insert( $this->row( $key, null, 'nowy-sent' === $key ? null : $old ) );
		}
		$ids = array_map( static fn( array $r ): int => (int) $r['id'], MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 ) );
		foreach ( $ids as $id ) {
			MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );
		}
		MailQueueRepository::mark_sent( $ids[0], gmdate( 'Y-m-d H:i:s' ) );
		MailQueueRepository::mark_failed( $ids[1], 'błąd' );
		MailQueueRepository::mark_sent( $ids[2], gmdate( 'Y-m-d H:i:s' ) );

		$this->assertSame( 2, MailQueueRepository::purge_old( gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_mark_sent_stores_status_and_timestamp(): void {
		MailQueueRepository::insert( $this->row( 'klucz-g' ) );
		$id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
		MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );
		MailQueueRepository::mark_sent( $id, '2026-01-02 03:04:05' );

		$row = MailQueueRepository::find( $id );
		$this->assertSame( QueueStatus::Sent->value, $row['status'] );
		$this->assertSame( '2026-01-02 03:04:05', $row['sent_at'] );
	}

	public function test_reschedule_returns_row_to_queue_with_error_and_new_term(): void {
		MailQueueRepository::insert( $this->row( 'klucz-h' ) );
		$id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
		MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );
		MailQueueRepository::reschedule( $id, '2030-01-01 00:00:00', 'chwilowy błąd' );

		$row = MailQueueRepository::find( $id );
		$this->assertSame( QueueStatus::Queued->value, $row['status'] );
		$this->assertSame( '2030-01-01 00:00:00', $row['scheduled_at'] );
		$this->assertSame( 'chwilowy błąd', $row['last_error'] );
		$this->assertSame( array(), MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 ) );
	}

	public function test_recover_stale_leaves_fresh_sending_rows(): void {
		MailQueueRepository::insert( $this->row( 'klucz-i' ) );
		$id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
		MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );

		$this->assertSame( 0, MailQueueRepository::recover_stale( gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
		$this->assertSame( QueueStatus::Sending->value, MailQueueRepository::find( $id )['status'] );
	}

	public function test_due_returns_oldest_id_first_and_respects_limit(): void {
		foreach ( array( 'j1', 'j2', 'j3' ) as $key ) {
			MailQueueRepository::insert( $this->row( $key ) );
		}
		$due = MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 2 );
		$this->assertCount( 2, $due );
		$this->assertSame( 'j1', $due[0]['dedupe_key'] );
		$this->assertSame( 'j2', $due[1]['dedupe_key'] );
	}

	public function test_paginate_and_count_filter_by_status_newest_first(): void {
		foreach ( array( 'k1', 'k2', 'k3' ) as $key ) {
			MailQueueRepository::insert( $this->row( $key ) );
		}
		$id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 1 )[0]['id'];
		MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );

		$all = MailQueueRepository::paginate( '', 10, 0 );
		$this->assertSame( array( 'k3', 'k2', 'k1' ), array_column( $all, 'dedupe_key' ) );
		$this->assertSame( array( 'k2' ), array_column( MailQueueRepository::paginate( '', 1, 1 ), 'dedupe_key' ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( QueueStatus::Sending->value ) );
		$this->assertCount( 2, MailQueueRepository::paginate( QueueStatus::Queued->value, 10, 0 ) );
	}

	public function test_unknown_status_filter_never_reaches_sql_and_matches_nothing(): void {
		MailQueueRepository::insert( $this->row( 'l1' ) );
		$this->assertSame( 0, MailQueueRepository::count_by_status( "queued' OR '1'='1" ) );
		$this->assertSame( array(), MailQueueRepository::paginate( "queued' OR '1'='1", 10, 0 ) );
	}

	public function test_find_returns_null_for_missing_row(): void {
		$this->assertNull( MailQueueRepository::find( 999999 ) );
	}
}
