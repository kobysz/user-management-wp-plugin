<?php
/**
 * Brama logowania oparta na stanie konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Account;

/**
 * Czysta reguła używana przez warstwę logowania; kod odmowy tłumaczy warstwa prezentacji.
 */
final class LoginGate {

	/**
	 * Wpuszcza konto zatwierdzone oraz konto bez stanu.
	 *
	 * Brak stanu oznacza konto założone ręcznie w panelu po wdrożeniu (np. administratora).
	 * Odmowa dla takiego konta zamknęłaby właściciela portalu poza jego portalem.
	 *
	 * @param AccountStatus|null $status Stan konta albo null, gdy go nie ma.
	 * @return LoginDecision
	 */
	public static function decide( ?AccountStatus $status ): LoginDecision {
		if ( null === $status || AccountStatus::Approved === $status ) {
			return LoginDecision::allow();
		}
		return LoginDecision::deny( $status->value );
	}
}
