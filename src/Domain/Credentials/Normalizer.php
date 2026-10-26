<?php
/**
 * Normalizacja numeru uprawnień przed walidacją.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials;

/**
 * Sprowadza numer do postaci porównywalnej.
 */
final class Normalizer {

	/**
	 * Usuwa białe znaki (także twardą spację), myślniki, kropki i ukośniki,
	 * a litery zamienia na wielkie.
	 *
	 * @param string $raw Numer w postaci wpisanej przez użytkownika.
	 * @return string
	 */
	public static function clean( string $raw ): string {
		$stripped = preg_replace( '/[\s\x{00A0}\-.\/]+/u', '', $raw );

		// Niepoprawny UTF-8: oddaj wejście bez zmian, walidator odrzuci je jako zły format.
		return strtoupper( $stripped ?? $raw );
	}
}
