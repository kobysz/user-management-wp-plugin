<?php
/**
 * Numer prawa wykonywania zawodu diagnosty laboratoryjnego (PWZDL).
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials\Validators;

use PortalUsers\Domain\Credentials\CredentialResult;
use PortalUsers\Domain\Credentials\CredentialValidator;
use PortalUsers\Domain\Credentials\Normalizer;

/**
 * Dodatnia liczba całkowita; zera wiodące są obcinane zgodnie z konwencją KIDL.
 */
final class DiagnosticianPwzdl implements CredentialValidator {

	/**
	 * Waliduje numer i zwraca wynik z kodem błędu albo znormalizowanym numerem.
	 *
	 * @param string $raw Numer w postaci wpisanej przez użytkownika.
	 * @return CredentialResult
	 */
	public function validate( string $raw ): CredentialResult {
		$number = Normalizer::clean( $raw );

		if ( '' === $number ) {
			return CredentialResult::invalid( CredentialResult::EMPTY );
		}

		if ( 1 !== preg_match( '/^\d{1,9}$/', $number ) ) {
			return CredentialResult::invalid( CredentialResult::INVALID_FORMAT );
		}

		$trimmed = ltrim( $number, '0' );
		if ( '' === $trimmed ) {
			return CredentialResult::invalid( CredentialResult::INVALID_FORMAT );
		}

		return CredentialResult::valid( $trimmed );
	}
}
