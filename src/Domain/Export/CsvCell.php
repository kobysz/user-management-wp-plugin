<?php
/**
 * Neutralizacja komórki CSV.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Export;

/**
 * Wartość wpisana przez lekarza trafia do pliku otwieranego w arkuszu, więc nie może się w nim wykonać jak formuła.
 */
final class CsvCell {

	/**
	 * Pierwsze znaki, od których arkusz zaczyna liczyć komórkę jako formułę.
	 */
	public const DANGEROUS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Wartość z apostrofem z przodu, gdy zaczyna się od znaku formuły; pozostałe bez zmian.
	 *
	 * Znak niebezpieczny w środku wartości nie uruchamia formuły, więc go nie ruszamy.
	 *
	 * @param string $value Wartość komórki.
	 * @return string
	 */
	public static function neutralize( string $value ): string {
		if ( '' !== $value && in_array( $value[0], self::DANGEROUS, true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
