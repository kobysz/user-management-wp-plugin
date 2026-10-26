<?php
/**
 * Stan konta w procesie rejestracji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Account;

/**
 * Konto przechodzi: niepotwierdzony mail, oczekujące, potem zatwierdzone albo odrzucone.
 */
enum AccountStatus: string {

	case EmailUnconfirmed = 'email_unconfirmed';
	case Pending          = 'pending';
	case Approved         = 'approved';
	case Rejected         = 'rejected';

	/**
	 * Odczytuje stan z zapisanych danych konta; nieznana lub pusta wartość to brak stanu.
	 *
	 * @param string|null $raw Surowa zapisana wartość.
	 * @return self|null
	 */
	public static function tryFromStored( ?string $raw ): ?self {
		return null === $raw ? null : self::tryFrom( $raw );
	}
}
