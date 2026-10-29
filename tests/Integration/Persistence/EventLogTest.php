<?php
/**
 * Testy logu zdarzeń w prawdziwej bazie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\Migrations;
use WP_UnitTestCase;

final class EventLogTest extends WP_UnitTestCase {

	/**
	 * @return list<object> Wszystkie wiersze logu, od najstarszego.
	 */
	private function rows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_event_log_row_lands_with_action_context_and_timestamp(): void {
		$before = time();
		EventLog::write(
			'account_approved',
			12,
			3,
			array(
				'note' => 'zażółć gęślą jaźń',
				'n'    => 5,
			)
		);
		$after = time();

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'account_approved', $row->action );
		$this->assertSame( '12', $row->subject_user_id );
		$this->assertSame( '3', $row->actor_user_id );
		$this->assertSame( '0', $row->anonymized );
		$this->assertSame(
			array(
				'note' => 'zażółć gęślą jaźń',
				'n'    => 5,
			),
			json_decode( (string) $row->context, true )
		);
		$stamp = strtotime( $row->created_at . ' UTC' );
		$this->assertGreaterThanOrEqual( $before, $stamp );
		$this->assertLessThanOrEqual( $after, $stamp );
	}

	public function test_quotes_backslashes_and_sql_in_the_context_and_action_are_stored_verbatim(): void {
		$context = array(
			'note' => "O'Brien \"cytat\" \ back\slash '; DROP TABLE x; --",
			'ip'   => '10.0.0.1',
		);
		EventLog::write( "it's \"x\"", 0, 0, $context );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( "it's \"x\"", $rows[0]->action );
		$this->assertSame( $context, json_decode( (string) $rows[0]->context, true ) );
	}

	public function test_every_write_adds_a_row(): void {
		EventLog::write( 'a', 0, 0, array() );
		EventLog::write( 'b', 0, 0, array() );

		$this->assertSame( array( 'a', 'b' ), array_map( static fn ( object $r ): string => $r->action, $this->rows() ) );
	}

	public function test_an_action_longer_than_the_column_is_truncated_not_lost(): void {
		EventLog::write( str_repeat( 'x', 60 ), 0, 0, array() );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( str_repeat( 'x', 40 ), $rows[0]->action );
	}

	public function test_write_zwraca_prawde_i_wiersz_jest_czytelny_po_podmiocie(): void {
		$this->assertTrue( EventLog::write( 'account_approved', 21, 3, array( 'status' => 'approved' ) ) );

		$rows = EventLog::for_subject( 21, 10, 0 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'account_approved', $rows[0]['action'] );
		$this->assertSame( 3, $rows[0]['actor_user_id'] );
		$this->assertFalse( $rows[0]['anonymized'] );
	}

	public function test_for_subject_zwraca_od_najnowszego_i_stronicuje(): void {
		EventLog::write( 'a', 21, 0, array() );
		EventLog::write( 'b', 21, 0, array() );
		EventLog::write( 'c', 21, 0, array() );

		$this->assertSame( array( 'c', 'b', 'a' ), array_column( EventLog::for_subject( 21, 10, 0 ), 'action' ) );
		$this->assertSame( array( 'c', 'b' ), array_column( EventLog::for_subject( 21, 2, 0 ), 'action' ) );
		$this->assertSame( array( 'a' ), array_column( EventLog::for_subject( 21, 2, 2 ), 'action' ) );
		$this->assertSame( array(), EventLog::for_subject( 21, 2, 3 ) );
	}

	public function test_for_subject_nie_widzi_wierszy_innego_konta(): void {
		EventLog::write( 'moje', 21, 0, array() );
		EventLog::write( 'cudze', 22, 0, array() );

		$this->assertSame( array( 'moje' ), array_column( EventLog::for_subject( 21, 10, 0 ), 'action' ) );
	}

	public function test_for_subject_odserializowuje_kontekst_do_tablicy(): void {
		EventLog::write(
			'application_rejected',
			21,
			3,
			array(
				'reason' => 'zażółć',
				'n'      => 5,
			)
		);

		$rows = EventLog::for_subject( 21, 10, 0 );
		$this->assertSame(
			array(
				'reason' => 'zażółć',
				'n'      => 5,
			),
			$rows[0]['context']
		);
	}

	public function test_anonymize_subject_czysci_kontekst_zostawia_dowod_decyzji(): void {
		EventLog::write(
			'application_rejected',
			21,
			3,
			array(
				'status' => 'rejected',
				'reason' => 'brak PWZ',
				'ip'     => '10.0.0.1',
			)
		);
		$before = $this->rows()[0];

		$this->assertSame( 1, EventLog::anonymize_subject( 21 ) );

		$after = $this->rows()[0];
		$this->assertSame( $before->action, $after->action );
		$this->assertSame( $before->created_at, $after->created_at );
		$this->assertSame( $before->actor_user_id, $after->actor_user_id );
		$this->assertSame( $before->subject_user_id, $after->subject_user_id );
		$this->assertSame( '1', $after->anonymized );
		$this->assertSame(
			array(
				'status'  => 'rejected',
				'removed' => array( 'ip', 'reason' ),
			),
			json_decode( (string) $after->context, true )
		);
		$this->assertTrue( EventLog::for_subject( 21, 10, 0 )[0]['anonymized'] );
	}

	public function test_anonymize_subject_jest_idempotentne(): void {
		EventLog::write(
			'application_rejected',
			21,
			3,
			array(
				'status' => 'rejected',
				'reason' => 'brak PWZ',
			)
		);

		$this->assertSame( 1, EventLog::anonymize_subject( 21 ) );
		$afterFirst = $this->rows()[0];

		$this->assertSame( 0, EventLog::anonymize_subject( 21 ) );
		$afterSecond = $this->rows()[0];

		$this->assertEquals( $afterFirst, $afterSecond );
		$this->assertSame(
			array(
				'status'  => 'rejected',
				'removed' => array( 'reason' ),
			),
			json_decode( (string) $afterSecond->context, true )
		);
	}

	public function test_anonymize_subject_zwraca_sume_po_wielu_wierszach_tego_samego_konta(): void {
		EventLog::write(
			'a',
			21,
			3,
			array(
				'status' => 'approved',
				'ip'     => '10.0.0.1',
			)
		);
		EventLog::write( 'b', 21, 3, array( 'reason' => 'brak PWZ' ) );
		EventLog::write(
			'c',
			21,
			3,
			array(
				'email' => 'a@example.com',
				'ip'    => '10.0.0.3',
			)
		);

		$this->assertSame( 3, EventLog::anonymize_subject( 21 ) );

		$expected = array(
			array(
				'status'  => 'approved',
				'removed' => array( 'ip' ),
			),
			array( 'removed' => array( 'reason' ) ),
			array( 'removed' => array( 'email', 'ip' ) ),
		);
		$rows     = $this->rows();
		$this->assertCount( 3, $rows );
		foreach ( $rows as $i => $row ) {
			$this->assertSame( '1', $row->anonymized );
			$this->assertSame( $expected[ $i ], json_decode( (string) $row->context, true ) );
		}
		$this->assertSame( 0, EventLog::anonymize_subject( 21 ) );
	}

	public function test_anonymize_subject_kasuje_uszkodzony_kontekst_i_ustawia_flage(): void {
		$this->insert_raw_context( 21, '{nie-json' );

		$this->assertSame( 1, EventLog::anonymize_subject( 21 ) );

		$row = $this->rows()[0];
		$this->assertSame( '1', $row->anonymized );
		$this->assertSame( array(), json_decode( (string) $row->context, true ) );
	}

	public function test_for_subject_pokazuje_uszkodzony_kontekst_jak_pusty(): void {
		$this->insert_raw_context( 21, '{nie-json' );

		$rows = EventLog::for_subject( 21, 10, 0 );
		$this->assertCount( 1, $rows );
		$this->assertSame( array(), $rows[0]['context'] );
		$this->assertFalse( $rows[0]['anonymized'] );
	}

	/**
	 * Wstawia wiersz z surowym `context`, którego write() nigdy by nie wyprodukował.
	 */
	private function insert_raw_context( int $subject, string $raw ): void {
		global $wpdb;
		$wpdb->insert(
			Migrations::table( 'event_log' ),
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'actor_user_id'   => 3,
				'subject_user_id' => $subject,
				'action'          => 'x',
				'context'         => $raw,
				'anonymized'      => 0,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d' )
		);
	}

	public function test_anonymize_subject_nie_rusza_wierszy_innego_konta(): void {
		EventLog::write( 'a', 21, 3, array( 'ip' => '10.0.0.1' ) );
		EventLog::write( 'b', 22, 3, array( 'ip' => '10.0.0.2' ) );
		$otherBefore = $this->rows()[1];

		$this->assertSame( 1, EventLog::anonymize_subject( 21 ) );

		$otherAfter = $this->rows()[1];
		$this->assertEquals( $otherBefore, $otherAfter );
		$this->assertSame( '0', $otherAfter->anonymized );
		$this->assertSame( array( 'ip' => '10.0.0.2' ), json_decode( (string) $otherAfter->context, true ) );
	}

	public function test_anonymize_subject_dla_konta_bez_wierszy_zwraca_zero(): void {
		EventLog::write( 'a', 22, 3, array( 'ip' => '10.0.0.2' ) );

		$this->assertSame( 0, EventLog::anonymize_subject( 21 ) );
	}
}
