<?php
/**
 * Numer prawa wykonywania zawodu pielęgniarki lub położnej (PWZP).
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials\Validators;

use PortalUsers\Domain\Credentials\CredentialResult;
use PortalUsers\Domain\Credentials\CredentialValidator;
use PortalUsers\Domain\Credentials\Normalizer;

/**
 * Format `IIXXXXXL`: numer izby 01–45, pięć cyfr numeru, litera P (pielęgniarka)
 * albo A (położna).
 */
final class NursePwz implements CredentialValidator {

	private const CHAMBER_MIN = 1;
	private const CHAMBER_MAX = 45;

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

		if ( 1 !== preg_match( '/^(\d{2})\d{5}[PA]$/', $number, $matches ) ) {
			return CredentialResult::invalid( CredentialResult::INVALID_FORMAT );
		}

		$chamber = (int) $matches[1];
		if ( $chamber < self::CHAMBER_MIN || $chamber > self::CHAMBER_MAX ) {
			return CredentialResult::invalid( CredentialResult::INVALID_CHAMBER );
		}

		return CredentialResult::valid( $number );
	}
}
