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
 * null, więc ten sam null chroni oba przypadki. Nie kwalifikuje się też konto, którego moment
 * rejestracji jest nieznany (wartość niedodatnia: zerowa data kolumny, nieparsowalny napis),
 * bo wiek takiego konta jest nieobliczalny, a zero obeszłoby okno.
 */
final class PurgeSelection {

	/**
	 * Okno dłuższe niż rok oznacza w praktyce wyłączenie czyszczenia; panel ostrzega o takiej wartości.
	 * Adres osoby, która nie potwierdziła konta w ciągu roku, nie ma powodu być zajęty dłużej.
	 */
	public const MAX_REASONABLE_DAYS = 365;

	public const WARNING_WINDOW_TOO_LONG   = 'purge_window_too_long';
	public const WARNING_SHORTER_THAN_LINK = 'purge_window_shorter_than_link';

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
	 * @param int                $registeredAt Moment rejestracji (uniksowy, UTC); wartość niedodatnia oznacza datę nieznaną.
	 * @param int                $now          Bieżący moment (uniksowy, UTC).
	 */
	public function qualifies( ?AccountStatus $status, int $registeredAt, int $now ): bool {
		if ( AccountStatus::EmailUnconfirmed !== $status ) {
			return false;
		}
		if ( $this->purgeDays < 1 || $registeredAt <= 0 ) {
			return false;
		}
		return ( $now - $registeredAt ) > $this->purgeDays * self::SECONDS_PER_DAY;
	}

	/**
	 * Problemy samego okna czyszczenia, jako kody ostrzeżeń.
	 *
	 * Okno krótsze niż ważność linku potwierdzającego oznacza ważny link do konta, które już usunięto.
	 *
	 * @param int $purgeDays Okno czyszczenia w dniach.
	 * @param int $linkHours Ważność linku potwierdzającego w godzinach.
	 * @return list<string>
	 */
	public static function windowWarnings( int $purgeDays, int $linkHours ): array {
		$found = array();
		if ( $purgeDays > self::MAX_REASONABLE_DAYS ) {
			$found[] = self::WARNING_WINDOW_TOO_LONG;
		}
		if ( $purgeDays * 24 < $linkHours ) {
			$found[] = self::WARNING_SHORTER_THAN_LINK;
		}
		return $found;
	}
}
