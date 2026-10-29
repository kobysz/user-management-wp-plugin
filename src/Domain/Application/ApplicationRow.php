<?php
/**
 * Wiersz wniosku: konto czekające na decyzję albo już rozstrzygnięte.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Application;

use PortalUsers\Domain\Account\AccountStatus;

/**
 * Niesie dane, nie składa ich: wiersz powstaje w repozytorium, bo domena nie może sięgać do platformy.
 * Numer uprawnień jest surowy; werdykt i odnośnik do rejestru liczą osobne klasy.
 */
final class ApplicationRow {

	/**
	 * Konstruktor.
	 *
	 * @param int           $userId           Identyfikator konta.
	 * @param string        $login            Login.
	 * @param string        $email            Adres e-mail.
	 * @param string        $displayName      Nazwa wyświetlana.
	 * @param string        $professionKey    Klucz profesji (także gdy zniknęła z ustawień).
	 * @param string        $credentialNumber Surowy numer uprawnień; pusty, gdy profesja nieznana lub bez numeru.
	 * @param string        $registeredAtUtc  Data rejestracji w UTC.
	 * @param AccountStatus $status           Stan konta.
	 */
	public function __construct(
		public readonly int $userId,
		public readonly string $login,
		public readonly string $email,
		public readonly string $displayName,
		public readonly string $professionKey,
		public readonly string $credentialNumber,
		public readonly string $registeredAtUtc,
		public readonly AccountStatus $status
	) {}
}
