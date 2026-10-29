<?php
/**
 * Reguła anonimizacji kontekstu wiersza logu zdarzeń.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Privacy;

/**
 * Wiersz logu zostaje po żądaniu usunięcia danych, bo wykazuje decyzję: kto i kiedy zatwierdził konto.
 * Zostają więc wyłącznie stany konta; cokolwiek innego jest daną o osobie i odpada.
 */
final class ContextFilter {

	/**
	 * Klucze, które wolno zostawić: stany konta, a nie dane osobowe.
	 *
	 * @var list<string>
	 */
	public const KEEP = array( 'status', 'previous_status', 'target_status' );

	/**
	 * Klucz, pod którym zapisujemy posortowaną listę nazw usuniętych kluczy.
	 * Nazwy kluczy nie są danymi osobowymi, a audyt musi widzieć, że coś w wierszu było.
	 */
	public const REMOVED_KEY = 'removed';

	/**
	 * Zwraca kontekst ograniczony do `KEEP`, z listą usuniętych kluczy pod `REMOVED_KEY`.
	 *
	 * Lista pojawia się tylko wtedy, gdy coś faktycznie usunięto: sam brak klucza mówi, że nie było czego usuwać.
	 *
	 * @param array<string, mixed> $context Kontekst wiersza przed anonimizacją.
	 * @return array<string, mixed>
	 */
	public static function anonymize( array $context ): array {
		$kept    = array();
		$removed = array();
		foreach ( $context as $key => $value ) {
			$name = (string) $key;
			if ( in_array( $name, self::KEEP, true ) ) {
				$kept[ $name ] = $value;
				continue;
			}
			$removed[] = $name;
		}
		if ( array() !== $removed ) {
			sort( $removed );
			$kept[ self::REMOVED_KEY ] = $removed;
		}

		return $kept;
	}
}
