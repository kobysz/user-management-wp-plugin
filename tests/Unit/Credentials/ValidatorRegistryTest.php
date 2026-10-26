<?php
/**
 * Testy: ValidatorRegistryTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Credentials;

use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Credentials\Validators\DiagnosticianPwzdl;
use PortalUsers\Domain\Credentials\Validators\NursePwz;
use PortalUsers\Domain\Credentials\Validators\PhysicianPwz;
use PHPUnit\Framework\TestCase;

final class ValidatorRegistryTest extends TestCase {

	public function test_maps_each_type_to_its_validator(): void {
		$registry = new ValidatorRegistry();
		$this->assertInstanceOf( PhysicianPwz::class, $registry->for( CredentialType::PhysicianPwz ) );
		$this->assertInstanceOf( NursePwz::class, $registry->for( CredentialType::NursePwz ) );
		$this->assertInstanceOf( DiagnosticianPwzdl::class, $registry->for( CredentialType::DiagnosticianPwzdl ) );
	}

	public function test_none_has_no_validator(): void {
		$this->assertNull( ( new ValidatorRegistry() )->for( CredentialType::None ) );
	}
}
