<?php
/**
 * Dozwolone przejścia stanu konta w panelu wniosków.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Account;

/**
 * Jedyne miejsce, które mówi, co redakcja może zrobić z kontem; ekran i obsługa żądania pytają tutaj.
 */
final class StatusTransition {

	/**
	 * Czy konto w tym stanie wolno rozstrzygać (zatwierdzić albo odrzucić).
	 *
	 * Konto bez potwierdzonego adresu jest poza zasięgiem panelu: zatwierdzenie dałoby logowanie
	 * komuś, kto adresu nie dowiódł.
	 *
	 * @param AccountStatus $status Obecny stan konta.
	 * @return bool
	 */
	public static function decidable( AccountStatus $status ): bool {
		return AccountStatus::EmailUnconfirmed !== $status;
	}

	/**
	 * Czy panel może przenieść konto z jednego stanu do drugiego.
	 *
	 * Celem ruchu panelu jest wyłącznie rozstrzygnięcie; powrót do oczekującego czy niepotwierdzonego
	 * nie jest decyzją redakcji.
	 *
	 * @param AccountStatus $from Stan obecny.
	 * @param AccountStatus $to   Stan docelowy.
	 * @return bool
	 */
	public static function allowed( AccountStatus $from, AccountStatus $to ): bool {
		if ( ! self::decidable( $from ) ) {
			return false;
		}

		return AccountStatus::Approved === $to || AccountStatus::Rejected === $to;
	}
}
