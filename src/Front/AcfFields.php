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
 * Warstwa integracji z ACF. Renderowanie pól należy do ACF; wtyczka kontroluje tylko nazwy
 * w żądaniu, atrybut wymagalności i wartość początkową. Zapis i sanityzacja należą do
 * wtyczki: input jest świadomie poza kontenerem `acf[...]`, więc `acf_save_post()` oraz
 * walidacja ACF nigdy się nie uruchomią i nic nie sanityzuje wartości za wtyczkę.
 */
final class AcfFields {

	/**
	 * Nazwa kontenera w żądaniu: pola trafiają do `pusers_fields[<indeks pola>]`.
	 *
	 * Indeksem jest KLUCZ ACF (`field_abc123`), gdy definicja go nosi, a nazwa pola tylko wtedy,
	 * gdy klucza nie ma (tak wyglądają definicje podane filtrem `pusers_acf_fields`). Nazywa
	 * pole ACF, nie wtyczka: patrz `render()` i `indexMap()`.
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
	 * Źródło jest wymagane i współdzielone z wywołującym (jedna migawka definicji pól): obiekt nie
	 * buduje własnego, żeby plan i render nie mogły czytać dwóch różnych migawek.
	 *
	 * @param AcfFieldSource $source Źródło pól grupy rejestracji.
	 */
	public function __construct( AcfFieldSource $source ) {
		$this->source = $source;
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
				$keys[] = (string) $fieldKey;
			}
		}

		return $keys;
	}

	/**
	 * Renderuje pole przez ACF. Pole spoza grupy nie jest renderowane.
	 *
	 * Wtyczka ustawia WYŁĄCZNIE prefiks i pozwala ACF nazwać pole: `acf_prepare_field()`
	 * nadpisuje nazwę kluczem pola, a dopiero potem owija ją prefiksem. Własne przypisanie
	 * `name` byłoby kodem martwym przy definicji z kluczem, a przy definicji bez klucza
	 * dawałoby nazwę owiniętą dwa razy (`pusers_fields[pusers_fields[pwz]]`).
	 *
	 * @param string $fieldKey Nazwa pola w źródle.
	 * @param bool   $required Czy pole jest wymagane dla wybranej profesji.
	 * @param mixed  $value    Wartość początkowa.
	 */
	public function render( string $fieldKey, bool $required, mixed $value ): void {
		$field = $this->source->definition( $fieldKey );
		if ( null === $field || ! function_exists( 'acf_render_field_wrap' ) ) {
			return;
		}

		$field['prefix']   = self::INPUT;
		$field['required'] = $required ? 1 : 0;
		$field['value']    = $value;

		acf_render_field_wrap( $field );
	}

	/**
	 * Czyta wartości pól z żądania po INDEKSIE, którym ACF nazwało input, i oddaje je pod
	 * NAZWAMI pól: po nazwach pracują macierz, walidator i zapis metadanych, więc mapowanie
	 * dotyczy wyłącznie wejścia. Wejście, którego nie ma w mapie indeksów, jest odrzucane
	 * (fail-closed). Wartości są surowe (w tym `'0'` z odznaczonego pola logicznego):
	 * normalizacja i sanityzacja to osobne kroki.
	 *
	 * @param array<mixed> $raw Żądanie (np. `$_POST` po `wp_unslash()`).
	 * @return array<string, mixed> Nazwa pola => wartość.
	 */
	public function fromRequest( array $raw ): array {
		$submitted = $raw[ self::INPUT ] ?? null;
		if ( ! is_array( $submitted ) ) {
			return array();
		}

		$values = array();
		foreach ( $this->indexMap() as $index => $fieldKey ) {
			if ( array_key_exists( $index, $submitted ) ) {
				$values[ $fieldKey ] = $submitted[ $index ];
			}
		}

		return $values;
	}

	/**
	 * Mapa indeks w żądaniu => nazwa pola, z jednej migawki definicji. Indeksem jest klucz ACF,
	 * gdy definicja go nosi, a w przeciwnym razie nazwa pola.
	 *
	 * O „nosi klucz” pytamy DOKŁADNIE tak, jak pyta ACF: `if ( $field['key'] )`, czyli czystą
	 * prawdziwością. Napis `'0'` jest więc brakiem klucza (ACF nazwie input nazwą pola), a klucz
	 * liczbowy `123` kluczem (ACF nazwie input `123`). Każde inne pytanie — `is_string()`,
	 * `isset()` — rozjeżdża się z ACF na którymś z tych dwóch kształtów i gubi wartość po cichu,
	 * a dla pola wymaganego czyni profesję nierejestrowalną na zawsze. Indeks bierzemy jako
	 * `(string)`, bo ACF składa nazwę interpolacją; klucz niebędący skalarem nie ma
	 * reprezentacji tekstowej, więc jest traktowany jak brak klucza.
	 *
	 * Gdy dwie definicje dają ten sam indeks (klucz jednego pola równy nazwie drugiego albo
	 * powtórzony klucz), odrzucane są OBIE: wejście jest wtedy nierozstrzygalne, a cicha wygrana
	 * jednej z nich byłaby tym samym rodzajem błędu co czytanie pola pod nazwą, której ACF nie
	 * wysyła. Pole wymagane, które tak wypadło, zatrzyma zgłoszenie na `required`.
	 *
	 * @return array<string, string>
	 */
	private function indexMap(): array {
		$map      = array();
		$collided = array();
		foreach ( array_keys( $this->source->fields() ) as $fieldKey ) {
			$fieldKey   = (string) $fieldKey;
			$definition = $this->source->definition( $fieldKey );
			$key        = $definition['key'] ?? null;
			$index      = is_scalar( $key ) && $key ? (string) $key : $fieldKey;
			if ( array_key_exists( $index, $map ) ) {
				$collided[ $index ] = true;
				continue;
			}
			$map[ $index ] = $fieldKey;
		}

		return array_diff_key( $map, $collided );
	}
}
