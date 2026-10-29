<?php
/**
 * Testy zmiany planowanej dla jednego konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Import\AccountChange;

final class AccountChangeTest extends TestCase {

	public function test_brak_zapisow_gdy_nie_ma_ani_pol_ani_profesji_ani_stanu(): void {
		$change = new AccountChange( 7, array(), null, null, array( AccountChange::ISSUE_PROFESSION_MISSING ) );

		$this->assertFalse( $change->hasWrites() );
	}

	public function test_sam_stan_liczy_sie_jako_zapis(): void {
		$change = new AccountChange( 7, array(), null, AccountStatus::Approved, array() );

		$this->assertTrue( $change->hasWrites() );
	}

	public function test_pola_albo_profesja_tez_licza_sie_jako_zapis(): void {
		$withField = new AccountChange( 7, array( 'field_abc' => 'Tak' ), null, null, array() );
		$withProfession = new AccountChange( 7, array(), 'lekarz', null, array() );

		$this->assertTrue( $withField->hasWrites() );
		$this->assertTrue( $withProfession->hasWrites() );
	}

	public function test_kody_zastrzezen_sa_posortowane_i_bez_duplikatow(): void {
		$change = new AccountChange(
			7,
			array(),
			null,
			null,
			array(
				AccountChange::ISSUE_STATUS_UNMAPPED,
				AccountChange::ISSUE_PROFESSION_UNMAPPED,
				AccountChange::ISSUE_STATUS_UNMAPPED,
			)
		);

		$this->assertSame(
			array( AccountChange::ISSUE_PROFESSION_UNMAPPED, AccountChange::ISSUE_STATUS_UNMAPPED ),
			$change->issues
		);
	}

	public function test_has_issue_rozpoznaje_zastrzezenie(): void {
		$change = new AccountChange( 7, array(), null, null, array( AccountChange::ISSUE_CREDENTIAL_INVALID ) );

		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_CREDENTIAL_INVALID ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_VALUE_UNUSABLE ) );
	}

	public function test_konstruktor_niesie_wszystkie_wlasciwosci(): void {
		$change = new AccountChange( 7, array( 'field_abc' => 'Tak' ), 'lekarz', AccountStatus::Pending, array() );

		$this->assertSame( 7, $change->userId );
		$this->assertSame( array( 'field_abc' => 'Tak' ), $change->fields );
		$this->assertSame( 'lekarz', $change->professionKey );
		$this->assertSame( AccountStatus::Pending, $change->status );
	}
}
