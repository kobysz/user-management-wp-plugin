<?php
/**
 * Testy: NormalizerTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Credentials;

use PortalUsers\Domain\Credentials\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase {

	public function test_strips_whitespace_dashes_and_nbsp(): void {
		$this->assertSame( '5425740', Normalizer::clean( " 542-57 40\u{00A0}" ) );
		$this->assertSame( '0201234P', Normalizer::clean( '02 01234 p' ) );
	}

	public function test_keeps_empty_string_empty(): void {
		$this->assertSame( '', Normalizer::clean( "  \u{00A0} " ) );
	}

	public function test_strips_typographic_dashes_and_invisible_characters(): void {
		$this->assertSame( '5425740', Normalizer::clean( "542\u{2013}5740" ) ); // Półpauza.
		$this->assertSame( '5425740', Normalizer::clean( "542\u{2014}5740" ) ); // Pauza.
		$this->assertSame( '5425740', Normalizer::clean( "542\u{2011}5740" ) ); // Niełamliwy dywiz.
		$this->assertSame( '5425740', Normalizer::clean( "542\u{2212}5740" ) ); // Minus.
		$this->assertSame( '5425740', Normalizer::clean( "542\u{2012}5740" ) ); // Myślnik cyfrowy.
		$this->assertSame( '5425740', Normalizer::clean( "542\u{00AD}5740" ) ); // Miękki dywiz.
		$this->assertSame( '5425740', Normalizer::clean( "542\u{200B}5740" ) ); // Spacja zerowej szerokości.
		$this->assertSame( '5425740', Normalizer::clean( "\u{FEFF}5425740" ) ); // BOM.
		$this->assertSame( '5425740', Normalizer::clean( "542\u{202F}57\u{3000}40" ) ); // Wąska i ideograficzna spacja.
	}

	public function test_strips_dots_and_slashes(): void {
		$this->assertSame( '5425740', Normalizer::clean( '54/25/740' ) );
		$this->assertSame( '5425740', Normalizer::clean( '5.4.2.5.7.4.0' ) );
	}
}
