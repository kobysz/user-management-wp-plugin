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
}
