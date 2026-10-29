<?php
/**
 * Testy odnośnika do rejestru.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Application\RegistryLink;

final class RegistryLinkTest extends TestCase {

	public function test_numer_wchodzi_w_miejsce_znacznika(): void {
		$this->assertSame(
			'https://rejestr.nil.org.pl/?pwz=5425740',
			RegistryLink::for( 'https://rejestr.nil.org.pl/?pwz={numer}', '5425740' )
		);
	}

	public function test_adres_bez_znacznika_zostaje_bez_zmian(): void {
		$this->assertSame( 'https://nipip.pl/weryfikacja-pwz', RegistryLink::for( 'https://nipip.pl/weryfikacja-pwz', '0201234P' ) );
	}

	public function test_numer_jest_kodowany_do_adresu(): void {
		$this->assertSame( 'https://x.pl/?q=a%20b%26c', RegistryLink::for( 'https://x.pl/?q={numer}', 'a b&c' ) );
	}

	public function test_brak_adresu_brak_numeru_i_obcy_schemat_daja_pustke(): void {
		$this->assertSame( '', RegistryLink::for( '', '5425740' ) );
		$this->assertSame( '', RegistryLink::for( 'https://x.pl/?q={numer}', '' ) );
		$this->assertSame( '', RegistryLink::for( 'javascript:alert(1)', '5425740' ) );
		$this->assertSame( '', RegistryLink::for( 'ftp://x.pl/{numer}', '5425740' ) );
	}
}
