<?php
/**
 * Numer prawa wykonywania zawodu lekarza (PWZ).
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials\Validators;

use PortalUsers\Domain\Credentials\CredentialResult;
use PortalUsers\Domain\Credentials\CredentialValidator;
use PortalUsers\Domain\Credentials\Normalizer;

/**
 * Format `KABCDEF`: siedem cyfr, pierwsza nie jest zerem, `K` to cyfra kontrolna
 * `(A·1 + B·2 + C·3 + D·4 + E·5 + F·6) mod 11`. Reszta 10 nie mieści się w jednym
 * znaku, więc takie numery nie są wydawane i są odrzucane.
 */
final class PhysicianPwz implements CredentialValidator {

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

		if ( 1 !== preg_match( '/^[1-9]\d{6}$/', $number ) ) {
			return CredentialResult::invalid( CredentialResult::INVALID_FORMAT );
		}

		$sum = 0;
		for ( $i = 1; $i <= 6; $i++ ) {
			$sum += (int) $number[ $i ] * $i;
		}
		$control = $sum % 11;

		if ( 10 === $control || (int) $number[0] !== $control ) {
			return CredentialResult::invalid( CredentialResult::INVALID_CHECKSUM );
		}

		return CredentialResult::valid( $number );
	}
}
