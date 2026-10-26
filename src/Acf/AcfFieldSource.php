<?php
/**
 * Źródło pól formularza rejestracji oparte o ACF.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Acf;

use PortalUsers\Domain\Fields\FieldSource;

defined( 'ABSPATH' ) || exit;

/**
 * Cienki adapter: czyta pola wskazanej grupy ACF i mapuje je na pary klucz => etykieta.
 * Grupę wskazuje administrator w ustawieniach; nie zgadujemy jej z reguł lokalizacji.
 */
final class AcfFieldSource implements FieldSource {

	/**
	 * Typy ACF, których wtyczka nie obsługuje, więc źródło ich nie widzi.
	 *
	 * Specyfikacja dopuszcza typy pól: tekst, e-mail, liczba, select, radio, checkbox, pole
	 * wielokrotne. Kontenery (`group`, `repeater`, `flexible_content`, `clone`) niosą wartości
	 * zagnieżdżone: odznaczona zgoda w środku spełniałaby `required` kontenera i omijała
	 * normalizację pól logicznych. Typy układowe (`tab`, `message`, `accordion`) nie niosą
	 * żadnej wartości, a bez wykluczenia dałoby się im ustawić stan w macierzy.
	 */
	private const UNSUPPORTED_TYPES = array( 'group', 'repeater', 'flexible_content', 'clone', 'tab', 'message', 'accordion' );

	/**
	 * Migawka definicji pól (null, dopóki niewczytana).
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $snapshot = null;

	/**
	 * Klucz grupy ACF.
	 *
	 * @var string
	 */
	private string $groupKey;

	/**
	 * Konstruktor.
	 *
	 * @param string $groupKey Klucz grupy ACF z pól rejestracji.
	 */
	public function __construct( string $groupKey ) {
		$this->groupKey = $groupKey;
	}

	/**
	 * Czy wskazana grupa nadal istnieje w ACF.
	 *
	 * @return bool
	 */
	public function groupExists(): bool {
		return array_key_exists( $this->groupKey, AcfGroupList::groups() );
	}

	/**
	 * Pola grupy w kolejności z ACF; puste, gdy grupa zniknęła. Typy kontenerowe i układowe
	 * (patrz `UNSUPPORTED_TYPES`) są pominięte.
	 *
	 * Filtr `pusers_acf_fields` pełni dwie role: jest szwem testowym (bez licencjonowanego
	 * ACF Pro w środowisku testowym testy podstawiają surowe pola tym filtrem) oraz punktem
	 * rozszerzeń. Dostaje surową listę pól ACF (tablice z kluczami `name` i `label`) i grupę.
	 *
	 * @return array<string, string> Klucz pola => etykieta.
	 */
	public function fields(): array {
		$fields = array();
		foreach ( $this->definitions() as $name => $field ) {
			$label           = isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label'] ? $field['label'] : (string) $name;
			$fields[ $name ] = $label;
		}

		return $fields;
	}

	/**
	 * Typ ACF każdego pola grupy (np. `true_false`, `text`); pusta lista, gdy grupa zniknęła.
	 *
	 * Osobna metoda, a nie rozszerzenie `fields()`: macierz i raport spójności zależą od
	 * pary klucz => etykieta. Pola bez nazwy lub bez typu są pomijane. Korzysta z tej samej
	 * migawki definicji co `fields()`, więc oba widoki nie mogą się rozjechać.
	 *
	 * @return array<string, string> Klucz pola => typ ACF.
	 */
	public function fieldTypes(): array {
		$types = array();
		foreach ( $this->definitions() as $name => $field ) {
			if ( isset( $field['type'] ) && is_string( $field['type'] ) && '' !== $field['type'] ) {
				$types[ $name ] = $field['type'];
			}
		}

		/**
		 * Filtruje mapę klucz pola => typ ACF. Punkt rozszerzeń dla pól, których typ
		 * definiuje inne źródło niż ACF.
		 *
		 * @param array<string, string> $types    Klucz pola => typ ACF.
		 * @param string                $groupKey Klucz grupy.
		 */
		$filtered = apply_filters( 'pusers_acf_field_types', $types, $this->groupKey );
		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$result = array();
		foreach ( $filtered as $name => $type ) {
			if ( is_string( $type ) ) {
				$result[ (string) $name ] = $type;
			}
		}

		return $result;
	}

	/**
	 * Surowa definicja pola ACF z grupy, potrzebna do jego wyrenderowania.
	 *
	 * @param string $fieldKey Klucz (nazwa) pola.
	 * @return array<string, mixed>|null Definicja albo null, gdy pola nie ma w źródle.
	 */
	public function definition( string $fieldKey ): ?array {
		return $this->definitions()[ $fieldKey ] ?? null;
	}

	/**
	 * Migawka surowych definicji pól grupy po nazwie, wczytana raz na instancję (ACF i filtr
	 * `pusers_acf_fields` wykonują się raz, nie raz na pole). Bez pól typów nieobsługiwanych.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function definitions(): array {
		if ( null !== $this->snapshot ) {
			return $this->snapshot;
		}

		$this->snapshot = array();
		if ( ! $this->groupExists() ) {
			return $this->snapshot;
		}

		$raw = array();
		if ( function_exists( 'acf_get_fields' ) ) {
			$loaded = acf_get_fields( $this->groupKey );
			if ( is_array( $loaded ) ) {
				$raw = $loaded;
			}
		}

		/**
		 * Filtruje surową listę pól ACF przed mapowaniem.
		 *
		 * @param array<int, mixed> $raw      Pola ACF.
		 * @param string            $groupKey Klucz grupy.
		 */
		$raw = apply_filters( 'pusers_acf_fields', $raw, $this->groupKey );
		if ( ! is_array( $raw ) ) {
			return $this->snapshot;
		}

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) || ! is_string( $field['name'] ) || '' === $field['name'] ) {
				continue;
			}
			if ( isset( $field['type'] ) && is_string( $field['type'] ) && in_array( $field['type'], self::UNSUPPORTED_TYPES, true ) ) {
				continue;
			}
			$this->snapshot[ $field['name'] ] = $field;
		}

		return $this->snapshot;
	}
}
