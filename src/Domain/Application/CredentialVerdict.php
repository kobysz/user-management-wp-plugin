<?php
/**
 * Werdykt o numerze uprawnień z wniosku.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Application;

use PortalUsers\Domain\Credentials\CredentialResult;
use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Profession\Profession;

/**
 * Jedno źródło faktu o numerze: mail do redakcji i ekran wniosków orzekają tu, a nie każdy po swojemu.
 * Zwraca kody, nie teksty; tłumaczenie na język człowieka należy do warstwy, która je pokazuje.
 */
final class CredentialVerdict {

	public const NOT_APPLICABLE = 'not_applicable';
	public const MISSING        = 'missing';
	public const VALID          = 'valid';

	/**
	 * Werdykt dla profesji i zapisanego numeru: jedna ze stałych klasy albo kod z `CredentialResult`.
	 *
	 * @param Profession|null $profession Profesja konta.
	 * @param string          $number     Zapisany numer.
	 * @return string
	 */
	public static function for( ?Profession $profession, string $number ): string {
		// Profesja wycofana z ustawień: nie wiadomo już, czego konto miało dowodzić, więc nie orzekamy.
		if ( null === $profession || CredentialType::None === $profession->credentialType || '' === $profession->credentialField ) {
			return self::NOT_APPLICABLE;
		}
		if ( '' === $number ) {
			return self::MISSING;
		}
		$validator = ( new ValidatorRegistry() )->for( $profession->credentialType );
		if ( null === $validator ) {
			return self::NOT_APPLICABLE;
		}
		$result = $validator->validate( $number );

		return $result->isValid() ? self::VALID : $result->code();
	}

	/**
	 * Czy werdykt wymaga uwagi redakcji: brak numeru albo numer, który nie przeszedł walidacji.
	 *
	 * @param string $code Werdykt z `for()`.
	 * @return bool
	 */
	public static function isWarning( string $code ): bool {
		return self::VALID !== $code && self::NOT_APPLICABLE !== $code;
	}
}
