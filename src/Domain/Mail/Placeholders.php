<?php
/**
 * Zbiór wartości podstawianych w szablonie maila.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Mail;

/**
 * Zbiór wartości podstawianych w szablonie maila. Klucze bez klamr.
 */
final class Placeholders {

	/**
	 * Wartości placeholderów.
	 *
	 * @var array<string,string>
	 */
	private array $values;

	/**
	 * Tworzy zbiór z mapy klucz => wartość.
	 *
	 * @param array<string,string> $values Wartości placeholderów, klucze bez klamr.
	 */
	public function __construct( array $values = array() ) {
		$this->values = $values;
	}

	/**
	 * Zwraca nowy zbiór z dodaną wartością. Nie modyfikuje bieżącego.
	 *
	 * @param string $key   Klucz bez klamr.
	 * @param string $value Wartość do podstawienia.
	 */
	public function with( string $key, string $value ): self {
		$values         = $this->values;
		$values[ $key ] = $value;

		return new self( $values );
	}

	/**
	 * Czy zbiór zna podany klucz.
	 *
	 * @param string $key Klucz bez klamr.
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->values );
	}

	/**
	 * Zwraca wartość klucza albo pusty łańcuch, gdy klucza brak.
	 *
	 * @param string $key Klucz bez klamr.
	 */
	public function get( string $key ): string {
		return $this->values[ $key ] ?? '';
	}

	/**
	 * Zwraca wszystkie wartości.
	 *
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return $this->values;
	}
}
