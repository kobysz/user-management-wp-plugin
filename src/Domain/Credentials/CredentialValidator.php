<?php
/**
 * Kontrakt walidatora numeru uprawnień.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials;

interface CredentialValidator {

	/**
	 * Waliduje numer i zwraca wynik z kodem błędu albo znormalizowanym numerem.
	 *
	 * @param string $raw Numer w postaci wpisanej przez użytkownika.
	 * @return CredentialResult
	 */
	public function validate( string $raw ): CredentialResult;
}
