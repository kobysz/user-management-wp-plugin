<?php
/**
 * Testy neutralizacji komórki CSV.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Export\CsvCell;

final class CsvCellTest extends TestCase {

	public function test_komorka_zaczynajaca_sie_od_znaku_formuly_dostaje_apostrof(): void {
		$this->assertSame( "'=1+1", CsvCell::neutralize( '=1+1' ) );
		$this->assertSame( "'+48 600 100 200", CsvCell::neutralize( '+48 600 100 200' ) );
		$this->assertSame( "'-5", CsvCell::neutralize( '-5' ) );
		$this->assertSame( "'@SUM(A1)", CsvCell::neutralize( '@SUM(A1)' ) );
		$this->assertSame( "'\tpoczatek tabulatorem", CsvCell::neutralize( "\tpoczatek tabulatorem" ) );
		$this->assertSame( "'\rpoczatek powrotem karetki", CsvCell::neutralize( "\rpoczatek powrotem karetki" ) );
	}

	public function test_zwykla_wartosc_zostaje_bez_zmian(): void {
		$this->assertSame( 'Szpital Miejski', CsvCell::neutralize( 'Szpital Miejski' ) );
		$this->assertSame( '', CsvCell::neutralize( '' ) );
		// Znak niebezpieczny w ŚRODKU wartości nie jest formułą i nie wolno go ruszać.
		$this->assertSame( 'a=b', CsvCell::neutralize( 'a=b' ) );
		$this->assertSame( 'tel. +48', CsvCell::neutralize( 'tel. +48' ) );
	}
}
