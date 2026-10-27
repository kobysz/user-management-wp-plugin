<?php
/**
 * Normalizacja odznaczonych pól logicznych.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Registration;

/**
 * Pole ACF `true_false` wysyła ukryte `0` także wtedy, gdy checkbox jest odznaczony,
 * a walidator zgłoszeń traktuje `'0'` jako wartość obecną (zero jest legalną liczbą,
 * np. lata praktyki). Bez tej normalizacji odznaczona wymagana zgoda spełniałaby `required`.
 * Dlatego normalizacja dotyczy wyłącznie pól z listy kluczy pól logicznych.
 */
final class BooleanNormalizer {

	/**
	 * Konstruktor.
	 *
	 * @param array<int, string> $booleanFieldKeys Klucze pól, których typem jest pole logiczne.
	 */
	public function __construct( private array $booleanFieldKeys ) {}

	/**
	 * Sprowadza pole logiczne do `'1'` albo `''`. Prawdą jest wyłącznie `'1'`, `1` i `true` (to wysyła
	 * przeglądarka zaznaczonym polem ACF `true_false`); cokolwiek innego (`'0'`, `'nie'`, `'2'`, `'true'`,
	 * tablica) jest brakiem. Serwer nie ufa przeglądarce: wymagana zgoda ma być zaznaczona, a nie niepusta.
	 * Wartości pozostałych pól zostają nietknięte.
	 *
	 * @param array<string, mixed> $values Wartości z żądania: pole => wartość.
	 * @return array<string, mixed>
	 */
	public function normalize( array $values ): array {
		foreach ( $this->booleanFieldKeys as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value          = $values[ $key ];
			$values[ $key ] = ( '1' === $value || 1 === $value || true === $value ) ? '1' : '';
		}

		return $values;
	}
}
