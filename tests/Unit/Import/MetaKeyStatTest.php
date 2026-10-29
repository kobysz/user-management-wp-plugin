<?php
/**
 * Testy statystyki klucza metadanych.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Import\MetaKeyStat;

final class MetaKeyStatTest extends TestCase {

	public function test_probki_sa_obcinane_do_limitu_i_do_dlugosci(): void {
		$stat = MetaKeyStat::fromRow( 'wpmem_reg', 12, array( 'a', 'b', 'c', 'd' ) );

		$this->assertCount( 3, $stat->samples );
		$this->assertSame( MetaKeyStat::SAMPLE_LIMIT, count( $stat->samples ) );

		// Polskie znaki liczy się jako znaki, nie bajty: 80 znaków ż obcinamy do 60 i dopisujemy wielokropek.
		$long = MetaKeyStat::fromRow( 'wpmem_reg', 1, array( str_repeat( 'ż', 80 ) ) );

		$this->assertSame( str_repeat( 'ż', 60 ) . '…', $long->samples[0] );
		$this->assertSame( 61, mb_strlen( $long->samples[0] ) );
	}

	public function test_wartosc_wielolinijkowa_jest_splaszczana_do_jednej_linii(): void {
		$stat = MetaKeyStat::fromRow( 'wpmem_reg', 1, array( "ala\nma\tkota" ) );

		$this->assertSame( array( 'ala ma kota' ), $stat->samples );
	}

	public function test_duplikaty_probek_sa_usuwane_a_kolejnosc_zachowana(): void {
		$stat = MetaKeyStat::fromRow( 'wpmem_reg', 1, array( 'b', 'a', 'b', ' ', '', 'c', 'a' ) );

		$this->assertSame( array( 'b', 'a', 'c' ), $stat->samples );
	}

	public function test_konstruktor_niesie_klucz_i_liczbe_kont(): void {
		$stat = MetaKeyStat::fromRow( 'wpmem_reg', 12, array() );

		$this->assertSame( 'wpmem_reg', $stat->key );
		$this->assertSame( 12, $stat->accounts );
		$this->assertSame( array(), $stat->samples );
	}
}
