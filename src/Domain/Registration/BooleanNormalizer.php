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
	 * Zamienia fałszywą wartość pola logicznego (`'0'`, `0`, `0.0`, `false`) na brak (`''`).
	 * Wartości pozostałych pól oraz inne wartości pól logicznych zostają nietknięte.
	 *
	 * @param array<string, mixed> $values Wartości z żądania: pole => wartość.
	 * @return array<string, mixed>
	 */
	public function normalize( array $values ): array {
		foreach ( $this->booleanFieldKeys as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];
			if ( '0' === $value || 0 === $value || 0.0 === $value || false === $value ) {
				$values[ $key ] = '';
			}
		}

		return $values;
	}
}
