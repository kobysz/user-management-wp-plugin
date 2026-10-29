<?php
/**
 * Testy przejść stanów konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Account\StatusTransition;

final class StatusTransitionTest extends TestCase {

	public function test_rozstrzygac_wolno_tylko_po_potwierdzeniu_adresu(): void {
		$this->assertFalse( StatusTransition::decidable( AccountStatus::EmailUnconfirmed ) );
		$this->assertTrue( StatusTransition::decidable( AccountStatus::Pending ) );
		$this->assertTrue( StatusTransition::decidable( AccountStatus::Approved ) );
		$this->assertTrue( StatusTransition::decidable( AccountStatus::Rejected ) );
	}

	public function test_przejscia_dozwolone_i_zabronione(): void {
		$this->assertTrue( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::Approved ) );
		$this->assertTrue( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::Rejected ) );
		$this->assertTrue( StatusTransition::allowed( AccountStatus::Rejected, AccountStatus::Approved ) );
		$this->assertTrue( StatusTransition::allowed( AccountStatus::Approved, AccountStatus::Rejected ) );
		// Konto bez potwierdzonego adresu: zatwierdzenie dałoby logowanie komuś, kto adresu nie dowiódł.
		$this->assertFalse( StatusTransition::allowed( AccountStatus::EmailUnconfirmed, AccountStatus::Approved ) );
		$this->assertFalse( StatusTransition::allowed( AccountStatus::EmailUnconfirmed, AccountStatus::Rejected ) );
		// Cel inny niż rozstrzygnięcie nie jest ruchem panelu.
		$this->assertFalse( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::Pending ) );
		$this->assertFalse( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::EmailUnconfirmed ) );
	}
}
