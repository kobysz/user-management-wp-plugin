<?php
/**
 * Testy reguły czyszczenia niepotwierdzonych kont.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Account\PurgeSelection;

final class PurgeSelectionTest extends TestCase {

	private const NOW = 1_800_000_000;
	private const DAY = 86400;

	public function test_unconfirmed_account_older_than_the_window_qualifies(): void {
		$selection = new PurgeSelection( 7 );
		$this->assertTrue( $selection->qualifies( AccountStatus::EmailUnconfirmed, self::NOW - 8 * self::DAY, self::NOW ) );
	}

	public function test_unconfirmed_account_inside_the_window_does_not_qualify(): void {
		$selection = new PurgeSelection( 7 );
		$this->assertFalse( $selection->qualifies( AccountStatus::EmailUnconfirmed, self::NOW - 6 * self::DAY, self::NOW ) );
	}

	public function test_account_exactly_at_the_window_edge_does_not_qualify(): void {
		// "Starsze niż okno": równo siedem dni to jeszcze nie starsze.
		$selection = new PurgeSelection( 7 );
		$this->assertFalse( $selection->qualifies( AccountStatus::EmailUnconfirmed, self::NOW - 7 * self::DAY, self::NOW ) );
		$this->assertTrue( $selection->qualifies( AccountStatus::EmailUnconfirmed, self::NOW - 7 * self::DAY - 1, self::NOW ) );
	}

	public function test_rejected_account_never_qualifies(): void {
		// Odrzucenie to udokumentowana decyzja redakcji; nawet bardzo stare konto zostaje.
		$selection = new PurgeSelection( 7 );
		$this->assertFalse( $selection->qualifies( AccountStatus::Rejected, self::NOW - 4000 * self::DAY, self::NOW ) );
	}

	public function test_approved_and_pending_accounts_never_qualify(): void {
		$selection = new PurgeSelection( 7 );
		$this->assertFalse( $selection->qualifies( AccountStatus::Approved, self::NOW - 4000 * self::DAY, self::NOW ) );
		$this->assertFalse( $selection->qualifies( AccountStatus::Pending, self::NOW - 4000 * self::DAY, self::NOW ) );
	}

	public function test_account_without_a_status_never_qualifies(): void {
		// Administrator dodany ręcznie nie ma stanu i nie może zostać skasowany cronem,
		// choćby został założony dawno temu. Ten sam null dostaje konto ze stanem nierozpoznanym.
		$selection = new PurgeSelection( 7 );
		$this->assertFalse( $selection->qualifies( null, self::NOW - 4000 * self::DAY, self::NOW ) );
	}

	public function test_a_zero_or_negative_window_disables_purging(): void {
		// Okno 0 oznaczałoby kasowanie każdego świeżego konta w trakcie rejestracji.
		$this->assertFalse( ( new PurgeSelection( 0 ) )->qualifies( AccountStatus::EmailUnconfirmed, self::NOW - 4000 * self::DAY, self::NOW ) );
		$this->assertFalse( ( new PurgeSelection( -3 ) )->qualifies( AccountStatus::EmailUnconfirmed, self::NOW - 4000 * self::DAY, self::NOW ) );
	}
}
