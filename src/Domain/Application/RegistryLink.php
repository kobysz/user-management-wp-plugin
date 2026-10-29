<?php
/**
 * Odnośnik do rejestru zawodowego dla numeru z wniosku.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Application;

/**
 * Adres rejestru pochodzi z ustawień edytowanych w panelu, więc nie ufamy mu: przepuszczamy tylko http(s).
 */
final class RegistryLink {

	public const PLACEHOLDER = '{numer}';

	/**
	 * Adres rejestru z wstawionym numerem; pusty napis, gdy odnośnika nie da się zbudować.
	 *
	 * Adres bez znacznika zostaje bez zmian: część rejestrów ma formularz, w który numer wpisuje się ręcznie.
	 *
	 * @param string $registryUrl Adres z ustawień profesji, ze znacznikiem {numer} albo bez.
	 * @param string $number      Numer uprawnień.
	 * @return string
	 */
	public static function for( string $registryUrl, string $number ): string {
		if ( '' === $registryUrl || '' === $number ) {
			return '';
		}
		// Schemat czytamy z początku adresu bez względu na wielkość liter (`HTTPS://`), a przepuszczamy tylko http(s):
		// `javascript:` czy `ftp:` w ustawieniach zrobiłby z linku w panelu wektor ataku. `parse_url()` odpada,
		// bo domena nie wywołuje funkcji spoza PHP-a, a styl projektu zabrania gołego `parse_url()`.
		if ( 1 !== preg_match( '#^https?://#i', $registryUrl ) ) {
			return '';
		}

		return str_replace( self::PLACEHOLDER, rawurlencode( $number ), $registryUrl );
	}
}
