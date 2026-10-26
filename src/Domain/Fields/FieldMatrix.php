<?php
/**
 * Macierz profesja x pole: jedno źródło prawdy o tym, co widać i co jest wymagane.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Fields;

/**
 * Z macierzy korzystają trzy warstwy: ukrywanie pól na froncie, walidacja po
 * stronie serwera i widok wniosku w panelu. Każde nieznane wejście (profesja,
 * pole, stan) daje stan „ukryte", więc brak konfiguracji nigdy nie odsłania
 * pola, którego nie miało być widać.
 */
final class FieldMatrix {

	/**
	 * Konstruktor.
	 *
	 * @param array<string, array<string, FieldState>> $states Stany pól według profesji.
	 */
	private function __construct( private array $states ) {}

	/**
	 * Buduje macierz z zapisanej konfiguracji. Wpisy o nieprawidłowym kształcie
	 * są pomijane, a nierozpoznany stan degraduje się do „ukryte".
	 *
	 * @param array<mixed> $raw Zapisana konfiguracja: profesja => pole => stan.
	 * @return self
	 */
	public static function fromArray( array $raw ): self {
		$states = array();

		foreach ( $raw as $professionKey => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$profession            = (string) $professionKey;
			$states[ $profession ] = array();

			foreach ( $fields as $fieldKey => $state ) {
				$parsed = is_string( $state ) ? FieldState::tryFrom( $state ) : null;

				$states[ $profession ][ (string) $fieldKey ] = $parsed ?? FieldState::Hidden;
			}
		}

		return new self( $states );
	}

	/**
	 * Stan pola dla profesji. Nieznana profesja lub pole oznacza „ukryte".
	 *
	 * @param string $professionKey Klucz profesji.
	 * @param string $fieldKey      Klucz pola.
	 * @return FieldState
	 */
	public function stateFor( string $professionKey, string $fieldKey ): FieldState {
		return $this->states[ $professionKey ][ $fieldKey ] ?? FieldState::Hidden;
	}

	/**
	 * Klucze pól widocznych (w tym wymaganych), w kolejności z macierzy.
	 *
	 * @param string $professionKey Klucz profesji.
	 * @return array<int, string>
	 */
	public function visibleFields( string $professionKey ): array {
		return $this->fieldsMatching( $professionKey, array( FieldState::Visible, FieldState::Required ) );
	}

	/**
	 * Klucze pól wymaganych, w kolejności z macierzy.
	 *
	 * @param string $professionKey Klucz profesji.
	 * @return array<int, string>
	 */
	public function requiredFields( string $professionKey ): array {
		return $this->fieldsMatching( $professionKey, array( FieldState::Required ) );
	}

	/**
	 * Nowa macierz bez wpisów dla pól spoza podanej listy.
	 *
	 * Przycięcie tylko usuwa: nie dodaje wpisu i nie podnosi stanu, więc macierz
	 * pozostaje jedynym źródłem prawdy. Oryginał się nie zmienia.
	 *
	 * UWAGA: metoda ufa swojemu argumentowi. Pusta lista usuwa KAŻDY wpis, także
	 * wymóg numeru uprawnień zawodowych, więc zgłoszenie bez numeru przeszłoby
	 * walidację. Wywołujący odpowiada za to, by lista pochodziła z czytelnego
	 * źródła definicji pól; nieczytelnego źródła nie wolno zamieniać na pustą listę.
	 *
	 * @param array<int, string> $fieldKeys Klucze pól, które istnieją w źródle definicji.
	 * @return self
	 */
	public function prunedTo( array $fieldKeys ): self {
		$allowed = array_fill_keys( $fieldKeys, true );
		$states  = array();

		foreach ( $this->states as $professionKey => $fields ) {
			$states[ $professionKey ] = array_intersect_key( $fields, $allowed );
		}

		return new self( $states );
	}

	/**
	 * Postać do zapisu: profesja => pole => tekst stanu.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function toArray(): array {
		$out = array();

		foreach ( $this->states as $profession => $fields ) {
			$out[ $profession ] = array();

			foreach ( $fields as $fieldKey => $state ) {
				$out[ $profession ][ $fieldKey ] = $state->value;
			}
		}

		return $out;
	}

	/**
	 * Klucze pól o jednym ze wskazanych stanów.
	 *
	 * @param string                 $professionKey Klucz profesji.
	 * @param array<int, FieldState> $wanted        Szukane stany.
	 * @return array<int, string>
	 */
	private function fieldsMatching( string $professionKey, array $wanted ): array {
		$keys = array();

		foreach ( $this->states[ $professionKey ] ?? array() as $fieldKey => $state ) {
			if ( in_array( $state, $wanted, true ) ) {
				$keys[] = (string) $fieldKey;
			}
		}

		return $keys;
	}
}
