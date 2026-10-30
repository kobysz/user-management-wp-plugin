<?php
/**
 * Statystyka klucza metadanych w starym systemie członkowskim.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Import;

/**
 * Pokazuje administratorowi, jaki klucz znaleziono i jakie wartości przyjmuje, zanim cokolwiek zostanie zaimportowane.
 */
final class MetaKeyStat {

	/**
	 * Najwyżej tyle przykładowych wartości trafia do raportu.
	 */
	public const SAMPLE_LIMIT = 3;

	/**
	 * Najdłuższa przykładowa wartość w znakach; dłuższa kończy się wielokropkiem.
	 */
	public const SAMPLE_MAX_LENGTH = 60;

	/**
	 * Konstruktor prywatny: wartości przechodzą normalizację wyłącznie w fromRow().
	 *
	 * @param string   $key      Nazwa klucza metadanych.
	 * @param int      $accounts Liczba kont, w których klucz występuje.
	 * @param string[] $samples  Znormalizowane przykładowe wartości.
	 */
	private function __construct(
		public readonly string $key,
		public readonly int $accounts,
		public readonly array $samples
	) {}

	/**
	 * Buduje statystykę z surowych wartości, normalizując je do jednej linii.
	 *
	 * Każdy ciąg białych znaków zamienia się na spację, wartość się przycina, puste odrzuca,
	 * a powtórzenia usuwa z zachowaniem kolejności. Wartość obcina się do SAMPLE_MAX_LENGTH
	 * znaków (z wielokropkiem), a listę do SAMPLE_LIMIT pozycji.
	 *
	 * @param string   $key      Nazwa klucza metadanych.
	 * @param int      $accounts Liczba kont, w których klucz występuje.
	 * @param string[] $samples  Surowe wartości z bazy.
	 * @return self
	 */
	public static function fromRow( string $key, int $accounts, array $samples ): self {
		$clean = array();

		foreach ( $samples as $sample ) {
			$normalized = preg_replace( '/\s+/u', ' ', $sample );

			// Niepoprawny UTF-8 daje null: zamiast kasować wartość, zamieniamy zepsute bajty i liczymy jeszcze raz.
			if ( null === $normalized ) {
				$normalized = preg_replace( '/\s+/u', ' ', mb_convert_encoding( $sample, 'UTF-8', 'UTF-8' ) );
			}

			if ( null === $normalized ) {
				continue;
			}

			$line = trim( $normalized );

			if ( '' === $line ) {
				continue;
			}

			if ( mb_strlen( $line ) > self::SAMPLE_MAX_LENGTH ) {
				$line = mb_substr( $line, 0, self::SAMPLE_MAX_LENGTH ) . '…';
			}

			if ( ! in_array( $line, $clean, true ) ) {
				$clean[] = $line;
			}
		}

		return new self( $key, $accounts, array_slice( $clean, 0, self::SAMPLE_LIMIT ) );
	}
}
