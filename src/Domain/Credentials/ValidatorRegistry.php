<?php
/**
 * Dobór walidatora do rodzaju numeru uprawnień.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials;

use PortalUsers\Domain\Credentials\Validators\DiagnosticianPwzdl;
use PortalUsers\Domain\Credentials\Validators\NursePwz;
use PortalUsers\Domain\Credentials\Validators\PhysicianPwz;

/**
 * Mapuje rodzaj numeru na walidator.
 */
final class ValidatorRegistry {

	/**
	 * Zwraca `null` dla `None`: profesje bez numeru uprawnień nie mają walidatora.
	 *
	 * @param CredentialType $type Rodzaj numeru uprawnień.
	 * @return CredentialValidator|null
	 */
	public function for( CredentialType $type ): ?CredentialValidator {
		return match ( $type ) {
			CredentialType::PhysicianPwz       => new PhysicianPwz(),
			CredentialType::NursePwz           => new NursePwz(),
			CredentialType::DiagnosticianPwzdl => new DiagnosticianPwzdl(),
			CredentialType::None               => null,
		};
	}
}
