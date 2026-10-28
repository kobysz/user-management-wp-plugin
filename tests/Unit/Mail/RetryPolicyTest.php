<?php
/**
 * Testy polityki ponawiania wysyłek.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Mail\RetryPolicy;

final class RetryPolicyTest extends TestCase {

	public function test_delays_grow_and_then_run_out(): void {
		$this->assertSame( 60, RetryPolicy::next( 1 ) );
		$this->assertSame( 300, RetryPolicy::next( 2 ) );
		$this->assertNull( RetryPolicy::next( RetryPolicy::MAX_ATTEMPTS ) );
		$this->assertNull( RetryPolicy::next( 99 ) );
	}
}
