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
		// Asercja o OSTATNIEJ próbie przed wyczerpaniem: bez niej `assertNull( next( MAX_ATTEMPTS ) )`
		// przechodzi dla KAŻDEJ wartości >= 3, więc podniesienie MAX_ATTEMPTS do 5 bez dołożenia
		// opóźnień zostawiłoby test zielony, a kolejka poddawałaby się nadal po trzeciej próbie.
		$this->assertNotNull( RetryPolicy::next( RetryPolicy::MAX_ATTEMPTS - 1 ) );
		$this->assertNull( RetryPolicy::next( RetryPolicy::MAX_ATTEMPTS ) );
		$this->assertNull( RetryPolicy::next( 99 ) );
	}
}
