<?php
/**
 * Pola ACF w formularzu rejestracji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

use PortalUsers\Acf\AcfFieldSource;

defined( 'ABSPATH' ) || exit;

/**
 * Warstwa integracji z ACF: renderowanie i zapis pól oddaje ACF, wtyczka kontroluje
 * tylko nazwy w żądaniu, atrybut wymagalności i wartość początkową.
 */
final class AcfFields {

	/**
	 * Nazwa kontenera w żądaniu: pola trafiają do `pusers_fields[<klucz pola>]`.
	 */
	public const INPUT = 'pusers_fields';

	/**
	 * Typ ACF pola logicznego.
	 */
	private const BOOLEAN_TYPE = 'true_false';

	/**
	 * Źródło pól grupy.
	 *
	 * @var AcfFieldSource
	 */
	private AcfFieldSource $source;

	/**
	 * Konstruktor.
	 *
	 * @param string $groupKey Klucz grupy ACF z polami rejestracji.
	 */
	public function __construct( string $groupKey ) {
		$this->source = new AcfFieldSource( $groupKey );
	}

	/**
	 * Klucze pól logicznych. Typ pochodzi z definicji ACF, nigdy z ręcznej listy ani z nazwy pola.
	 *
	 * @return list<string>
	 */
	public function booleanKeys(): array {
		$keys = array();
		foreach ( $this->source->fieldTypes() as $fieldKey => $type ) {
			if ( self::BOOLEAN_TYPE === $type ) {
				$keys[] = $fieldKey;
			}
		}

		return $keys;
	}

	/**
	 * Renderuje pole przez ACF. Pole spoza grupy nie jest renderowane.
	 *
	 * @param string $fieldKey Klucz pola.
	 * @param bool   $required Czy pole jest wymagane dla wybranej profesji.
	 * @param mixed  $value    Wartość początkowa.
	 */
	public function render( string $fieldKey, bool $required, mixed $value ): void {
		$field = $this->source->definition( $fieldKey );
		if ( null === $field || ! function_exists( 'acf_render_field_wrap' ) ) {
			return;
		}

		$field['prefix']   = self::INPUT;
		$field['name']     = self::INPUT . '[' . $fieldKey . ']';
		$field['required'] = $required ? 1 : 0;
		$field['value']    = $value;

		acf_render_field_wrap( $field );
	}

	/**
	 * Czyta wartości pól z żądania białą listą kluczy ze źródła; reszta żądania jest ignorowana.
	 * Wartości są surowe (w tym `'0'` z odznaczonego pola logicznego): normalizacja i sanityzacja
	 * to osobne kroki.
	 *
	 * @param array<mixed> $raw Żądanie (np. `$_POST` po `wp_unslash()`).
	 * @return array<string, mixed> Klucz pola => wartość.
	 */
	public function fromRequest( array $raw ): array {
		$submitted = $raw[ self::INPUT ] ?? null;
		if ( ! is_array( $submitted ) ) {
			return array();
		}

		$values = array();
		foreach ( array_keys( $this->source->fields() ) as $fieldKey ) {
			if ( array_key_exists( $fieldKey, $submitted ) ) {
				$values[ $fieldKey ] = $submitted[ $fieldKey ];
			}
		}

		return $values;
	}
}
