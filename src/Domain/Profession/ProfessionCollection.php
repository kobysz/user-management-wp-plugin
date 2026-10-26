<?php
/**
 * Zbiór profesji indeksowany kluczem.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Profession;

/**
 * Zachowuje kolejność zapisaną w konfiguracji.
 */
final class ProfessionCollection {

	/**
	 * Konstruktor.
	 *
	 * @param array<string, Profession> $items Profesje pod swoimi kluczami.
	 */
	private function __construct( private readonly array $items ) {}

	/**
	 * Buduje zbiór z zapisanej konfiguracji. Wpisy bez klucza są pomijane.
	 *
	 * @param array<int|string, mixed> $raw Lista zapisanych profesji.
	 * @return self
	 */
	public static function fromArray( array $raw ): self {
		$items = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$profession = Profession::fromArray( $entry );
			if ( '' === $profession->key ) {
				continue;
			}
			$items[ $profession->key ] = $profession;
		}
		return new self( $items );
	}

	/**
	 * Zwraca profesję o danym kluczu albo null.
	 *
	 * @param string $key Klucz profesji.
	 * @return Profession|null
	 */
	public function get( string $key ): ?Profession {
		return $this->items[ $key ] ?? null;
	}

	/**
	 * Czy profesja o danym kluczu istnieje.
	 *
	 * @param string $key Klucz profesji.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->items[ $key ] );
	}

	/**
	 * Wszystkie profesje pod swoimi kluczami.
	 *
	 * @return array<string, Profession>
	 */
	public function all(): array {
		return $this->items;
	}

	/**
	 * Klucze wszystkich profesji, w kolejności konfiguracji.
	 *
	 * @return array<int, string>
	 */
	public function keys(): array {
		return array_map( 'strval', array_keys( $this->items ) );
	}

	/**
	 * Zapisywalna postać zbioru: lista (od zera) tablic profesji, w kolejności konfiguracji.
	 * Odwrotność fromArray().
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray(): array {
		return array_values(
			array_map(
				static fn ( Profession $profession ): array => $profession->toArray(),
				$this->items
			)
		);
	}
}
