<?php
/**
 * Testy bramy logowania.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Account\LoginGate;

final class LoginGateTest extends TestCase {

	public function test_account_without_stored_status_is_allowed_in(): void {
		// Administrator dodany ręcznie w panelu po wdrożeniu nie ma stanu.
		// Brama musi go wpuścić, inaczej wtyczka zamyka właściciela portalu poza portalem.
		$this->assertTrue( LoginGate::decide( null )->allowed() );
	}

	public function test_approved_is_allowed_in(): void {
		$this->assertTrue( LoginGate::decide( AccountStatus::Approved )->allowed() );
	}

	public function test_each_other_status_is_denied_with_its_own_code(): void {
		$this->assertSame( 'email_unconfirmed', LoginGate::decide( AccountStatus::EmailUnconfirmed )->code() );
		$this->assertSame( 'pending', LoginGate::decide( AccountStatus::Pending )->code() );
		$this->assertSame( 'rejected', LoginGate::decide( AccountStatus::Rejected )->code() );
		foreach ( array( AccountStatus::EmailUnconfirmed, AccountStatus::Pending, AccountStatus::Rejected ) as $s ) {
			$this->assertFalse( LoginGate::decide( $s )->allowed() );
		}
	}

	public function test_allowed_decision_carries_no_code(): void {
		$this->assertSame( '', LoginGate::decide( AccountStatus::Approved )->code() );
	}

	public function test_unknown_stored_value_reads_as_no_status(): void {
		$this->assertNull( AccountStatus::tryFromStored( 'bzdura' ) );
		$this->assertNull( AccountStatus::tryFromStored( '' ) );
		$this->assertNull( AccountStatus::tryFromStored( null ) );
	}
}
