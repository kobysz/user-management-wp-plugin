<?php
/**
 * Testy: IpAddress.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Spam;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Spam\IpAddress;

final class IpAddressTest extends TestCase {

	public function test_untrusted_proxy_header_is_ignored(): void {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
		);
		$this->assertSame( '10.0.0.1', IpAddress::from( $server, '' ) );
	}

	public function test_trusted_header_is_honoured_when_the_administrator_named_it(): void {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
		);
		$this->assertSame( '1.2.3.4', IpAddress::from( $server, 'HTTP_X_FORWARDED_FOR' ) );
	}

	public function test_only_the_header_the_administrator_named_is_read(): void {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
			'HTTP_X_REAL_IP'       => '5.6.7.8',
		);
		$this->assertSame( '5.6.7.8', IpAddress::from( $server, 'HTTP_X_REAL_IP' ) );
	}

	public function test_first_address_is_taken_from_a_comma_separated_chain(): void {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => ' 1.2.3.4 , 172.16.0.9, 10.0.0.1',
		);
		$this->assertSame( '1.2.3.4', IpAddress::from( $server, 'HTTP_X_FORWARDED_FOR' ) );
	}

	public function test_trusted_header_with_a_nonsense_value_falls_back_to_remote_addr(): void {
		foreach ( array( 'not-an-ip', '999.1.1.1', '', ',1.2.3.4', 'unknown' ) as $junk ) {
			$server = array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => $junk,
			);
			$this->assertSame( '10.0.0.1', IpAddress::from( $server, 'HTTP_X_FORWARDED_FOR' ), var_export( $junk, true ) );
		}
	}

	public function test_trusted_header_that_is_absent_falls_back_to_remote_addr(): void {
		$this->assertSame( '10.0.0.1', IpAddress::from( array( 'REMOTE_ADDR' => '10.0.0.1' ), 'HTTP_X_FORWARDED_FOR' ) );
	}

	public function test_ipv6_addresses_are_accepted(): void {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '2001:db8::1',
		);
		$this->assertSame( '2001:db8::1', IpAddress::from( $server, 'HTTP_X_FORWARDED_FOR' ) );
	}

	public function test_missing_or_invalid_remote_addr_gives_an_empty_address(): void {
		$this->assertSame( '', IpAddress::from( array(), '' ) );
		$this->assertSame( '', IpAddress::from( array( 'REMOTE_ADDR' => 'junk' ), '' ) );
		$this->assertSame( '', IpAddress::from( array( 'REMOTE_ADDR' => array( '1.2.3.4' ) ), '' ) );
	}
}
