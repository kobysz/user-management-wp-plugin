<?php
/**
 * Testy składania dokumentu CSV.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Export;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Export\CsvDocument;

final class CsvDocumentTest extends TestCase {

	/**
	 * Wyjście bez BOM-u, sparsowane z powrotem na wiersze komórek.
	 *
	 * @param string $output Wynik render().
	 * @return array<int, array<int, string|null>>
	 */
	private function parse( string $output ): array {
		$body   = substr( $output, strlen( CsvDocument::BOM ) );
		$rows   = array();
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $body );
		rewind( $handle );
		$row = fgetcsv( $handle, null, ',', '"', '' );
		while ( false !== $row ) {
			$rows[] = $row;
			$row    = fgetcsv( $handle, null, ',', '"', '' );
		}
		fclose( $handle );

		return $rows;
	}

	public function test_dokument_zaczyna_sie_od_bom(): void {
		$doc = new CsvDocument( array( 'A' ) );

		$this->assertStringStartsWith( CsvDocument::BOM, $doc->render() );
	}

	public function test_naglowek_nie_jest_neutralizowany(): void {
		$doc = new CsvDocument( array( '=suma', 'B' ) );

		$this->assertSame( array( array( '=suma', 'B' ) ), $this->parse( $doc->render() ) );
	}

	public function test_komorki_danych_sa_neutralizowane(): void {
		$doc = new CsvDocument( array( 'A', 'B' ) );
		$doc->addRow( array( '=1+1', 'zwykla' ) );

		$this->assertSame( array( "'=1+1", 'zwykla' ), $this->parse( $doc->render() )[1] );
	}

	public function test_wartosc_z_przecinkiem_cudzyslowem_i_nowa_linia_zostaje_jedna_komorka(): void {
		$cells = array( 'a,b', 'on rzekl "nie"', "pierwsza\ndruga" );
		$doc   = new CsvDocument( array( 'X', 'Y', 'Z' ) );
		$doc->addRow( $cells );

		$parsed = $this->parse( $doc->render() );

		$this->assertCount( 2, $parsed, 'Nagłówek i dokładnie jeden wiersz danych.' );
		$this->assertCount( 3, $parsed[1] );
		$this->assertSame( $cells, $parsed[1] );
	}

	public function test_wartosc_z_backslashem_przechodzi_pelny_round_trip(): void {
		// Czyste RFC 4180 nie ma znaku ucieczki: backslash przed cudzysłowem albo na końcu komórki nie może rozsypać wiersza.
		$cells = array( 'konczy sie na \\', 'a\\"b', 'zwykla' );
		$doc   = new CsvDocument( array( 'X', 'Y', 'Z' ) );
		$doc->addRow( $cells );

		$parsed = $this->parse( $doc->render() );

		$this->assertCount( 2, $parsed );
		$this->assertSame( $cells, $parsed[1] );
	}

	public function test_dokument_bez_wierszy_ma_sam_naglowek(): void {
		$doc = new CsvDocument( array( 'A', 'B' ) );

		$this->assertSame( CsvDocument::BOM . "A,B\n", $doc->render() );
	}

	public function test_liczba_komorek_niezgodna_z_naglowkiem_jest_bledem(): void {
		$doc = new CsvDocument( array( 'A', 'B' ) );

		$this->expectException( InvalidArgumentException::class );
		$doc->addRow( array( 'tylko jedna' ) );
	}
}
