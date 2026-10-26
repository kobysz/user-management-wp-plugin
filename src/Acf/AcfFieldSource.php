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
	 * Pola grupy w kolejności z ACF; puste, gdy grupa zniknęła.
	 *
	 * Filtr `pusers_acf_fields` pełni dwie role: jest szwem testowym (bez licencjonowanego
	 * ACF Pro w środowisku testowym testy podstawiają surowe pola tym filtrem) oraz punktem
	 * rozszerzeń. Dostaje surową listę pól ACF (tablice z kluczami `name` i `label`) i grupę.
	 *
	 * @return array<string, string> Klucz pola => etykieta.
	 */
	public function fields(): array {
		if ( ! $this->groupExists() ) {
			return array();
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
			return array();
		}

		$fields = array();
		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) || ! is_string( $field['name'] ) || '' === $field['name'] ) {
				continue;
			}
			$label                    = isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label'] ? $field['label'] : $field['name'];
			$fields[ $field['name'] ] = $label;
		}

		return $fields;
	}
}
