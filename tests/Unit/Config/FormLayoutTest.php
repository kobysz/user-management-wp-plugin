<?php
/**
 * Testy: układ formularzy frontu.
 *
 * Wartość przychodzi z zapisanej opcji, czyli z miejsca, które ktoś mógł edytować ręcznie albo
 * które pamięta starszą wersję wtyczki. Nieznana wartość nie może przewrócić renderu formularza,
 * więc wraca do układu gołego — tego, który niczego nie narzuca.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Config\FormLayout;

final class FormLayoutTest extends TestCase {

	public function test_znane_wartosci_daja_swoje_przypadki(): void {
		$this->assertSame( FormLayout::Plain, FormLayout::fromValue( 'goly' ) );
		$this->assertSame( FormLayout::Card, FormLayout::fromValue( 'karta' ) );
	}

	/**
	 * @dataProvider garbage_provider
	 *
	 * @param mixed $input Wartość z zapisu.
	 */
	public function test_nieznana_wartosc_daje_goly( mixed $input ): void {
		$this->assertSame( FormLayout::Plain, FormLayout::fromValue( $input ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function garbage_provider(): array {
		return array(
			'nieznany napis' => array( 'karteczka' ),
			'pusty napis'    => array( '' ),
			'null'           => array( null ),
			'liczba'         => array( 7 ),
			'tablica'        => array( array( 'karta' ) ),
			'logiczna'       => array( true ),
		);
	}

	public function test_goly_jest_wartoscia_domyslna_enuma(): void {
		$this->assertSame( 'goly', FormLayout::Plain->value );
		$this->assertSame( 'karta', FormLayout::Card->value );
	}
}
