<?php
/**
 * Testy werdyktu numeru uprawnień.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Domain\Credentials\CredentialResult;
use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Profession\Profession;

final class CredentialVerdictTest extends TestCase {

	private function physician( string $field = 'numer_pwz' ): Profession {
		return new Profession( 'lekarz', 'Lekarz', CredentialType::PhysicianPwz, '', '', $field );
	}

	public function test_brak_profesji_nie_dotyczy(): void {
		$this->assertSame( CredentialVerdict::NOT_APPLICABLE, CredentialVerdict::for( null, '5425740' ) );
	}

	public function test_profesja_bez_typu_numeru_nie_dotyczy(): void {
		$p = new Profession( 'student', 'Student', CredentialType::None, '', '', 'numer' );
		$this->assertSame( CredentialVerdict::NOT_APPLICABLE, CredentialVerdict::for( $p, '5425740' ) );
	}

	public function test_profesja_bez_pola_numeru_nie_dotyczy(): void {
		$this->assertSame( CredentialVerdict::NOT_APPLICABLE, CredentialVerdict::for( $this->physician( '' ), '5425740' ) );
	}

	public function test_pusty_numer_to_brak(): void {
		$this->assertSame( CredentialVerdict::MISSING, CredentialVerdict::for( $this->physician(), '' ) );
	}

	public function test_poprawny_numer(): void {
		$this->assertSame( CredentialVerdict::VALID, CredentialVerdict::for( $this->physician(), '5425740' ) );
	}

	public function test_zepsuta_cyfra_kontrolna(): void {
		$this->assertSame( CredentialResult::INVALID_CHECKSUM, CredentialVerdict::for( $this->physician(), '5425741' ) );
	}

	public function test_zly_format(): void {
		$this->assertSame( CredentialResult::INVALID_FORMAT, CredentialVerdict::for( $this->physician(), 'abc' ) );
	}

	public function test_ostrzegac_nalezy_o_braku_i_nieprawidlowosciach(): void {
		foreach ( array( CredentialVerdict::MISSING, CredentialResult::INVALID_FORMAT, CredentialResult::INVALID_CHECKSUM, CredentialResult::INVALID_CHAMBER ) as $code ) {
			$this->assertTrue( CredentialVerdict::isWarning( $code ), $code );
		}
		$this->assertFalse( CredentialVerdict::isWarning( CredentialVerdict::VALID ) );
		$this->assertFalse( CredentialVerdict::isWarning( CredentialVerdict::NOT_APPLICABLE ) );
	}
}
