<?php
/**
 * Decyzja, czy konto z niepotwierdzonym adresem podlega usunięciu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Account;

/**
 * Czysta reguła czyszczenia kont. Usuwanie jest nieodwracalne, więc zakres jest wąski:
 * kwalifikuje się WYŁĄCZNIE konto w stanie `email_unconfirmed` starsze niż okno.
 *
 * Nie kwalifikuje się: konto `rejected` (odrzucenie to udokumentowana decyzja redakcji),
 * `approved`, `pending` oraz konto BEZ stanu. Brak stanu (null) ma administrator dodany
 * ręcznie w panelu, a konto z metadaną nierozpoznaną ma ją uszkodzoną; get() oddaje dla obu
 * null, więc ten sam null chroni oba przypadki.
 */
final class PurgeSelection {

	private const SECONDS_PER_DAY = 86400;

	/**
	 * Ustawia okno czyszczenia.
	 *
	 * @param int $purgeDays Okno w dniach; wartość mniejsza niż 1 wyłącza czyszczenie całkowicie.
	 */
	public function __construct( private readonly int $purgeDays ) {
	}

	/**
	 * Czy konto należy usunąć.
	 *
	 * @param AccountStatus|null $status       Stan konta albo null, gdy go nie ma lub jest nierozpoznany.
	 * @param int                $registeredAt Moment rejestracji (uniksowy, UTC).
	 * @param int                $now          Bieżący moment (uniksowy, UTC).
	 */
	public function qualifies( ?AccountStatus $status, int $registeredAt, int $now ): bool {
		if ( AccountStatus::EmailUnconfirmed !== $status ) {
			return false;
		}
		if ( $this->purgeDays < 1 ) {
			return false;
		}
		return ( $now - $registeredAt ) > $this->purgeDays * self::SECONDS_PER_DAY;
	}
}
