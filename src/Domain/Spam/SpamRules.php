<?php
/**
 * Reguły antyspamu niewymagające stanu: honeypot i pułapka czasowa.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Spam;

/**
 * Honeypot i minimalny czas wypełniania formularza. Liczniki prób (stan) żyją poza domeną.
 */
final class SpamRules {

	/**
	 * Konstruktor.
	 *
	 * @param int $timeTrapSeconds Minimalny czas wypełniania formularza w sekundach; 0 wyłącza pułapkę.
	 */
	public function __construct( private int $timeTrapSeconds ) {}

	/**
	 * Sprawdza zgłoszenie.
	 *
	 * Moment załadowania formularza, który nie jest dodatnią liczbą (brak, błędny podpis → 0), albo leży
	 * w przyszłości (ujemny czas wypełniania jest mniejszy od progu), daje to samo co odesłanie zbyt szybkie: kierunek błędu to odmowa.
	 *
	 * @param string $honeypotValue Wartość ukrytego pola; człowiek zostawia je puste.
	 * @param int    $loadedAt      Moment załadowania formularza (uniksowy); 0 = nieznany.
	 * @param int    $now           Bieżący moment (uniksowy).
	 */
	public function check( string $honeypotValue, int $loadedAt, int $now ): SpamVerdict {
		if ( '' !== $honeypotValue ) {
			return SpamVerdict::reject( SpamVerdict::HONEYPOT );
		}

		if ( $this->timeTrapSeconds > 0 && ( $loadedAt <= 0 || $now - $loadedAt < $this->timeTrapSeconds ) ) {
			return SpamVerdict::reject( SpamVerdict::TOO_FAST );
		}

		return SpamVerdict::pass();
	}
}
