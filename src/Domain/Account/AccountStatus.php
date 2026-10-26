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
	 * Nierozpoznana wartość czyta się jako brak stanu ŚWIADOMIE: domena widzi tylko „stan albo
	 * brak stanu”. Odróżnienie „brak klucza” od „klucz z nierozpoznaną wartością” należy do
	 * warstwy przechowywania, która ma traktować drugi przypadek jako odmowę logowania oraz
	 * zapisać wpis do logu.
	 *
	 * @param string|null $raw Surowa zapisana wartość.
	 * @return self|null
	 */
	public static function tryFromStored( ?string $raw ): ?self {
		return null === $raw ? null : self::tryFrom( $raw );
	}
}
