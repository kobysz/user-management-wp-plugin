<?php
/**
 * Testy: ProfessionTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Profession;

use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Profession\Profession;
use PHPUnit\Framework\TestCase;

final class ProfessionTest extends TestCase {

	public function test_profession_without_credential_type_requires_no_number(): void {
		$p = new Profession( 'student', 'Student', CredentialType::None );
		$this->assertFalse( $p->requiresCredential() );
	}

	public function test_profession_with_credential_type_requires_a_number(): void {
		$p = new Profession( 'lekarz', 'Lekarz', CredentialType::PhysicianPwz, 'https://rejestr.nil.org.pl/' );
		$this->assertTrue( $p->requiresCredential() );
	}

	public function test_round_trips_through_array(): void {
		$raw = array(
			'key'             => 'pielegniarka',
			'label'           => 'Pielęgniarka',
			'credential_type' => 'nurse_pwz',
			'registry_url'    => 'https://nipip.pl/weryfikacja-pwz',
			'notify_email'    => 'redakcja@example.test',
		);
		$this->assertSame( $raw, Profession::fromArray( $raw )->toArray() );
	}

	public function test_unknown_credential_type_falls_back_to_none(): void {
		$p = Profession::fromArray( array( 'key' => 'x', 'label' => 'X', 'credential_type' => 'bzdura' ) );
		$this->assertSame( CredentialType::None, $p->credentialType );
	}
}
