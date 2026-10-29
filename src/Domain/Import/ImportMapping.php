<?php
/**
 * Konfiguracja mapowania importu ze starej wtyczki członkowskiej.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Import;

use PortalUsers\Domain\Account\AccountStatus;

/**
 * Mówi, który klucz metadanych starej wtyczki zasila które pole, jaką profesję i jaki stan konta.
 *
 * To jedyne miejsce, które zna zamkniętą listę stanów, jakie mapowanie może nadać. Obiekt jest
 * niemutowalny, a każde wczytanie przechodzi sanityzację, więc nic spoza listy nie dociera dalej.
 */
final class ImportMapping {

	/**
	 * Konto bez użytecznej wartości stanu zostaje bez stanu.
	 */
	public const FALLBACK_NONE = 'none';

	/**
	 * Konto bez użytecznej wartości stanu trafia na listę wniosków.
	 */
	public const FALLBACK_PENDING = 'pending';

	/**
	 * Konto bez użytecznej wartości stanu zostaje zatwierdzone.
	 */
	public const FALLBACK_APPROVED = 'approved';

	/**
	 * Stany, które mapowanie może nadać.
	 *
	 * Celowo bez email_unconfirmed: zadanie czyszczące usuwa konta w tym stanie po skonfigurowanym
	 * okresie, więc dopuszczenie go zamieniłoby import w narzędzie kasowania kont.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_STATUSES = array(
		AccountStatus::Pending->value,
		AccountStatus::Approved->value,
		AccountStatus::Rejected->value,
	);

	/**
	 * Konstruktor prywatny: dane przechodzą sanityzację wyłącznie w fromArray().
	 *
	 * @param array<string, string> $fields           Klucz pola ACF => źródłowy klucz metadanych.
	 * @param string                $professionKey    Klucz metadanych z profesją albo pusty tekst.
	 * @param array<string, string> $professionValues Znormalizowana wartość źródłowa => klucz profesji.
	 * @param string                $statusKey        Klucz metadanych ze stanem albo pusty tekst.
	 * @param array<string, string> $statusValues     Znormalizowana wartość źródłowa => kod stanu z ALLOWED_STATUSES.
	 * @param string                $fallback         Jedna ze stałych FALLBACK_*.
	 */
	private function __construct(
		private readonly array $fields,
		private readonly string $professionKey,
		private readonly array $professionValues,
		private readonly string $statusKey,
		private readonly array $statusValues,
		private readonly string $fallback
	) {}

	/**
	 * Wczytuje mapowanie z zapisanej tablicy, odrzucając wszystko, co nie ma sensu.
	 *
	 * Pary o wartościach niebędących tekstem lub o niepoprawnym UTF-8 odpadają, klucze wartości
	 * źródłowych przechodzą przez trim i mb_strtolower, a stany spoza ALLOWED_STATUSES są
	 * odrzucane, nie poprawiane. Gdy dwie wartości źródłowe po normalizacji się zlewają, wygrywa
	 * późniejsza. Liczbowe klucze wartości źródłowych (np. 1, 0) są traktowane jak tekst, bo PHP
	 * zamienia klucz "1" na liczbę. Nieznany fallback daje FALLBACK_NONE.
	 *
	 * @param array<array-key, mixed> $raw Surowa tablica, np. z opcji.
	 * @return self
	 */
	public static function fromArray( array $raw ): self {
		$fallback = $raw['fallback'] ?? null;

		if ( ! is_string( $fallback ) || ! in_array( $fallback, array( self::FALLBACK_PENDING, self::FALLBACK_APPROVED ), true ) ) {
			$fallback = self::FALLBACK_NONE;
		}

		$statusValues = array();

		foreach ( self::pairs( $raw['statusValues'] ?? null, true ) as $source => $code ) {
			if ( in_array( $code, self::ALLOWED_STATUSES, true ) ) {
				$statusValues[ $source ] = $code;
			}
		}

		return new self(
			self::pairs( $raw['fields'] ?? null, false ),
			self::text( $raw['professionKey'] ?? null ),
			self::pairs( $raw['professionValues'] ?? null, true ),
			self::text( $raw['statusKey'] ?? null ),
			$statusValues,
			$fallback
		);
	}

	/**
	 * Zwraca postać do zapisu; fromArray() odtwarza z niej równe mapowanie.
	 *
	 * @return array{fields: array<string, string>, professionKey: string, professionValues: array<string, string>, statusKey: string, statusValues: array<string, string>, fallback: string}
	 */
	public function toArray(): array {
		return array(
			'fields'           => $this->fields,
			'professionKey'    => $this->professionKey,
			'professionValues' => $this->professionValues,
			'statusKey'        => $this->statusKey,
			'statusValues'     => $this->statusValues,
			'fallback'         => $this->fallback,
		);
	}

	/**
	 * Klucz pola ACF => źródłowy klucz metadanych.
	 *
	 * @return array<string, string>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Klucz metadanych z profesją albo pusty tekst, gdy profesji nie mapujemy.
	 *
	 * @return string
	 */
	public function professionKey(): string {
		return $this->professionKey;
	}

	/**
	 * Znajduje klucz profesji dla wartości ze starego systemu, ignorując spacje i wielkość liter.
	 *
	 * @param string $sourceValue Wartość z bazy.
	 * @return string|null Klucz profesji albo null, gdy wartość nie jest zmapowana.
	 */
	public function professionFor( string $sourceValue ): ?string {
		return $this->professionValues[ self::normalize( $sourceValue ) ] ?? null;
	}

	/**
	 * Klucz metadanych ze stanem albo pusty tekst, gdy stanu nie mapujemy.
	 *
	 * @return string
	 */
	public function statusKey(): string {
		return $this->statusKey;
	}

	/**
	 * Znajduje stan dla wartości ze starego systemu, ignorując spacje i wielkość liter.
	 *
	 * @param string $sourceValue Wartość z bazy.
	 * @return AccountStatus|null Stan albo null, gdy wartość nie jest zmapowana.
	 */
	public function statusFor( string $sourceValue ): ?AccountStatus {
		return AccountStatus::tryFromStored( $this->statusValues[ self::normalize( $sourceValue ) ] ?? null );
	}

	/**
	 * Co zrobić z kontem bez użytecznej wartości stanu: jedna ze stałych FALLBACK_*.
	 *
	 * @return string
	 */
	public function fallback(): string {
		return $this->fallback;
	}

	/**
	 * Wszystkie klucze metadanych, które import czyta: bez duplikatów, posortowane.
	 *
	 * @return list<string>
	 */
	public function sourceKeys(): array {
		$keys = array_values( $this->fields );

		foreach ( array( $this->professionKey, $this->statusKey ) as $key ) {
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys, \SORT_STRING );

		return $keys;
	}

	/**
	 * Mapowanie jest puste, gdy import nie czytałby żadnego klucza metadanych.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->sourceKeys();
	}

	/**
	 * Odcisk treści mapowania; nie zależy od kolejności par w tablicach.
	 *
	 * @return string
	 */
	public function hash(): string {
		$data = $this->toArray();

		ksort( $data, \SORT_STRING );

		foreach ( array( 'fields', 'professionValues', 'statusValues' ) as $section ) {
			ksort( $data[ $section ], \SORT_STRING );
		}

		$canonical = '';

		foreach ( $data as $name => $section ) {
			$canonical .= self::part( $name );

			if ( is_array( $section ) ) {
				$canonical .= count( $section ) . '#';

				foreach ( $section as $key => $value ) {
					$canonical .= self::part( (string) $key ) . self::part( $value );
				}
			} else {
				$canonical .= self::part( $section );
			}
		}

		return sha1( $canonical );
	}

	/**
	 * Zwraca kopię bez pól i profesji, których cel już nie istnieje.
	 *
	 * Klucze źródłowe, stany i fallback zostają bez zmian.
	 *
	 * @param string[] $fieldKeys      Klucze pól ACF, które nadal istnieją.
	 * @param string[] $professionKeys Klucze profesji, które nadal istnieją.
	 * @return self
	 */
	public function prunedTo( array $fieldKeys, array $professionKeys ): self {
		return new self(
			array_filter(
				$this->fields,
				static fn ( string $acfKey ): bool => in_array( $acfKey, $fieldKeys, true ),
				\ARRAY_FILTER_USE_KEY
			),
			$this->professionKey,
			array_filter(
				$this->professionValues,
				static fn ( string $profession ): bool => in_array( $profession, $professionKeys, true )
			),
			$this->statusKey,
			$this->statusValues,
			$this->fallback
		);
	}

	/**
	 * Składnik odcisku z prefiksem długości, żeby sąsiednie wartości nie mogły się zlać w inne.
	 *
	 * @param string $value Dowolny tekst.
	 * @return string
	 */
	private static function part( string $value ): string {
		return strlen( $value ) . ':' . $value . ';';
	}

	/**
	 * Sprowadza wartość źródłową do postaci porównywalnej: bez skrajnych spacji i wielkości liter.
	 *
	 * @param string $value Wartość z bazy lub z konfiguracji.
	 * @return string
	 */
	private static function normalize( string $value ): string {
		return mb_strtolower( trim( $value ) );
	}

	/**
	 * Czyta pojedynczy tekst: nie-tekst daje pusty tekst, reszta jest przycinana.
	 *
	 * @param mixed $value Surowa wartość.
	 * @return string
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) && mb_check_encoding( $value, 'UTF-8' ) ? trim( $value ) : '';
	}

	/**
	 * Oczyszcza mapę tekst => tekst: odrzuca pary niebędące tekstem, puste i o niepoprawnym UTF-8.
	 *
	 * @param mixed $value           Surowa mapa.
	 * @param bool  $normalizeSource Czy klucze to wartości źródłowe (przycięte, małymi literami, liczby jako tekst).
	 * @return array<string, string>
	 */
	private static function pairs( mixed $value, bool $normalizeSource ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $key => $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			if ( is_int( $key ) ) {
				if ( ! $normalizeSource ) {
					continue;
				}

				$key = (string) $key;
			}

			$key  = $normalizeSource ? self::normalize( $key ) : trim( $key );
			$item = trim( $item );

			if ( '' === $key || '' === $item || ! mb_check_encoding( $key, 'UTF-8' ) || ! mb_check_encoding( $item, 'UTF-8' ) ) {
				continue;
			}

			$clean[ $key ] = $item;
		}

		return $clean;
	}
}
