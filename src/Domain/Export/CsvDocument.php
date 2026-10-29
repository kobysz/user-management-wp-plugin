<?php
/**
 * Dokument CSV z nagłówkiem i wierszami danych.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Export;

use InvalidArgumentException;
use RuntimeException;

/**
 * Neutralizacja komórek siedzi tu, a nie u wołającego: dwukrotna dałaby w arkuszu widoczny podwójny apostrof.
 */
final class CsvDocument {

	/**
	 * Bez BOM-u Excel czyta plik w stronie kodowej systemu i psuje polskie znaki.
	 */
	public const BOM = "\xEF\xBB\xBF";

	/**
	 * Etykiety kolumn.
	 *
	 * @var array<int, string>
	 */
	private array $headers;

	/**
	 * Wiersze danych po neutralizacji.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $rows = array();

	/**
	 * Nagłówek to etykiety autora i tłumaczeń, nie dane użytkownika, więc nie przechodzi neutralizacji.
	 *
	 * @param array<int, string> $headers Etykiety kolumn.
	 */
	public function __construct( array $headers ) {
		$this->headers = array_values( $headers );
	}

	/**
	 * Dodaje wiersz danych; każda komórka przechodzi neutralizację.
	 *
	 * Rozjechana liczba komórek to wyjątek: jeden krótszy wiersz w gotowym pliku jest trudniejszy do zauważenia niż błąd przy pisaniu kodu.
	 *
	 * @param array<int, string> $cells Komórki wiersza, tyle samo co kolumn w nagłówku.
	 * @throws InvalidArgumentException Gdy liczba komórek różni się od liczby kolumn.
	 */
	public function addRow( array $cells ): void {
		if ( count( $cells ) !== count( $this->headers ) ) {
			throw new InvalidArgumentException( 'Liczba komórek wiersza musi być równa liczbie kolumn nagłówka.' );
		}

		$this->rows[] = array_map( array( CsvCell::class, 'neutralize' ), array_values( $cells ) );
	}

	/**
	 * Gotowy tekst pliku: BOM, nagłówek, wiersze.
	 *
	 * @return string
	 * @throws RuntimeException Gdy nie da się otworzyć bufora w pamięci.
	 */
	public function render(): string {
		// Bufor w pamięci, nie plik na dysku.
		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			// Sam BOM wyglądałby jak poprawny, pusty eksport i ukryłby awarię przed administratorem.
			throw new RuntimeException( 'Nie udało się otworzyć bufora w pamięci do złożenia pliku CSV.' );
		}

		fputcsv( $handle, $this->headers, ',', '"', '\\' );
		foreach ( $this->rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		// Zamyka bufor w pamięci otwarty powyżej, nie plik na dysku.
		fclose( $handle );

		if ( false === $csv ) {
			// Rzutowanie na napis zamieniłoby false w pusty tekst, czyli znów sam BOM udający pusty eksport.
			throw new RuntimeException( 'Nie udało się odczytać złożonego dokumentu CSV z bufora w pamięci.' );
		}

		return self::BOM . $csv;
	}
}
