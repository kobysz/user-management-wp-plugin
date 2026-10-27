<?php
/**
 * Testy liczników prób.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Front\RateLimiter;
use WP_UnitTestCase;

final class RateLimiterTest extends WP_UnitTestCase {

	public function test_hit_counts_attempts_and_count_does_not(): void {
		$this->assertSame( 0, RateLimiter::count( 'k' ) );
		$this->assertSame( 1, RateLimiter::hit( 'k' ) );
		$this->assertSame( 2, RateLimiter::hit( 'k' ) );
		$this->assertSame( 2, RateLimiter::count( 'k' ) );
		$this->assertSame( 2, RateLimiter::count( 'k' ), 'Odczyt nie zlicza.' );
	}

	public function test_buckets_are_independent(): void {
		RateLimiter::hit( 'a' );
		RateLimiter::hit( 'a' );
		$this->assertSame( 1, RateLimiter::hit( 'b' ) );
		$this->assertSame( 2, RateLimiter::count( 'a' ) );
	}

	public function test_clear_resets_the_bucket_and_only_that_bucket(): void {
		RateLimiter::hit( 'a' );
		RateLimiter::hit( 'b' );

		RateLimiter::clear( 'a' );

		$this->assertSame( 0, RateLimiter::count( 'a' ) );
		$this->assertSame( 1, RateLimiter::count( 'b' ) );
		$this->assertSame( 1, RateLimiter::hit( 'a' ) );
	}

	public function test_further_attempts_do_not_extend_the_window(): void {
		// Okno zaczęło się 50 minut temu: zostało 10, a kolejna próba go nie odnawia.
		set_transient(
			'pusers_rl_' . md5( 'old' ),
			array(
				'count' => 2,
				'start' => time() - 50 * MINUTE_IN_SECONDS,
			),
			10 * MINUTE_IN_SECONDS
		);

		$this->assertSame( 3, RateLimiter::hit( 'old' ) );

		$timeout = (int) get_option( '_transient_timeout_pusers_rl_' . md5( 'old' ) );
		$this->assertEqualsWithDelta( time() + 10 * MINUTE_IN_SECONDS, $timeout, 5 );
	}

	public function test_a_fresh_window_lasts_the_block_period(): void {
		RateLimiter::hit( 'fresh' );

		$timeout = (int) get_option( '_transient_timeout_pusers_rl_' . md5( 'fresh' ) );
		$this->assertEqualsWithDelta( time() + RateLimiter::BLOCK_MINUTES * MINUTE_IN_SECONDS, $timeout, 5 );
	}

	public function test_a_corrupted_counter_counts_as_empty(): void {
		set_transient( 'pusers_rl_' . md5( 'bad' ), 'junk', HOUR_IN_SECONDS );

		$this->assertSame( 0, RateLimiter::count( 'bad' ) );
		$this->assertSame( 1, RateLimiter::hit( 'bad' ) );
	}
}
