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
			'key'              => 'pielegniarka',
			'label'            => 'Pielęgniarka',
			'credential_type'  => 'nurse_pwz',
			'registry_url'     => 'https://nipip.pl/weryfikacja-pwz',
			'notify_email'     => 'redakcja@example.test',
			'credential_field' => '',
		);
		$this->assertSame( $raw, Profession::fromArray( $raw )->toArray() );
	}

	public function test_unknown_credential_type_falls_back_to_none(): void {
		$p = Profession::fromArray( array( 'key' => 'x', 'label' => 'X', 'credential_type' => 'bzdura' ) );
		$this->assertSame( CredentialType::None, $p->credentialType );
	}

	public function test_array_label_falls_back_to_key(): void {
		$p = Profession::fromArray( array( 'key' => 'lekarz', 'label' => array( 'x' ), 'credential_type' => 'physician_pwz' ) );
		$this->assertSame( 'lekarz', $p->label );
	}

	public function test_null_label_falls_back_to_key(): void {
		$p = Profession::fromArray( array( 'key' => 'lekarz', 'label' => null ) );
		$this->assertSame( 'lekarz', $p->label );
	}

	public function test_key_and_label_are_trimmed(): void {
		$p = Profession::fromArray( array( 'key' => ' lekarz ', 'label' => "  Lekarz	" ) );
		$this->assertSame( 'lekarz', $p->key );
		$this->assertSame( 'Lekarz', $p->label );
	}

	public function test_whitespace_only_label_falls_back_to_key(): void {
		$p = Profession::fromArray( array( 'key' => 'lekarz', 'label' => "  	 " ) );
		$this->assertSame( 'lekarz', $p->label );
	}

	public function test_credential_field_round_trips_through_array(): void {
		$raw = array(
			'key'              => 'lekarz',
			'label'            => 'Lekarz',
			'credential_type'  => 'physician_pwz',
			'registry_url'     => 'https://rejestr.nil.org.pl/',
			'notify_email'     => 'redakcja@example.test',
			'credential_field' => 'pwz',
		);
		$this->assertSame( $raw, Profession::fromArray( $raw )->toArray() );
	}

	public function test_credential_field_defaults_to_empty_and_is_trimmed(): void {
		$this->assertSame( '', Profession::fromArray( array( 'key' => 'x', 'label' => 'X' ) )->credentialField );
		$this->assertSame( 'pwz', Profession::fromArray( array( 'key' => 'x', 'label' => 'X', 'credential_field' => ' pwz ' ) )->credentialField );
	}
}
