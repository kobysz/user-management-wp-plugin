<?php
/**
 * Reguły hasła konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Account;

/**
 * Czysta reguła hasła, wspólna dla ustawienia hasła z biletu i odzyskiwania hasła: jedno
 * źródło progu, żeby dwie ścieżki nie rozjechały się przy zmianie. Kod błędu tłumaczy
 * warstwa prezentacji.
 */
final class PasswordPolicy {

	/**
	 * Minimalna długość hasła (znaków, nie bajtów).
	 */
	public const MIN_LENGTH = 12;

	public const ERROR_SHORT = 'short';
	public const ERROR_EMAIL = 'email';

	/**
	 * Zwraca kod błędu albo `''`, gdy hasło spełnia reguły. Najpierw długość, potem równość adresowi.
	 *
	 * Hasło równe adresowi e-mail jest publicznie znane każdemu, kto widział zgłoszenie.
	 * Porównanie bez względu na wielkość liter: `Jan@Szpital.pl` jest tym samym hasłem.
	 *
	 * @param string $password Hasło.
	 * @param string $email    Adres e-mail konta.
	 */
	public static function check( string $password, string $email ): string {
		if ( mb_strlen( $password ) < self::MIN_LENGTH ) {
			return self::ERROR_SHORT;
		}
		if ( strtolower( $password ) === strtolower( $email ) ) {
			return self::ERROR_EMAIL;
		}
		return '';
	}
}
