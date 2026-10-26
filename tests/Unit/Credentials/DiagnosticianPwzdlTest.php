<?php
/**
 * Testy: DiagnosticianPwzdlTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Credentials;

use PortalUsers\Domain\Credentials\Validators\DiagnosticianPwzdl;
use PHPUnit\Framework\TestCase;

final class DiagnosticianPwzdlTest extends TestCase {

	public function test_accepts_plain_digits(): void {
		$this->assertTrue( ( new DiagnosticianPwzdl() )->validate( '399' )->isValid() );
		$this->assertTrue( ( new DiagnosticianPwzdl() )->validate( '12345' )->isValid() );
	}

	public function test_strips_leading_zeros_per_kidl_convention(): void {
		$result = ( new DiagnosticianPwzdl() )->validate( '00399' );
		$this->assertTrue( $result->isValid() );
		$this->assertSame( '399', $result->normalized() );
	}

	public function test_rejects_zero_and_non_digits(): void {
		$this->assertSame( 'invalid_format', ( new DiagnosticianPwzdl() )->validate( '0' )->code() );
		$this->assertSame( 'invalid_format', ( new DiagnosticianPwzdl() )->validate( '12a' )->code() );
	}

	public function test_rejects_empty(): void {
		$this->assertSame( 'empty', ( new DiagnosticianPwzdl() )->validate( '' )->code() );
	}

	public function test_enforces_maximum_length_of_nine_digits(): void {
		$this->assertTrue( ( new DiagnosticianPwzdl() )->validate( '123456789' )->isValid() );
		$this->assertSame( 'invalid_format', ( new DiagnosticianPwzdl() )->validate( '1234567890' )->code() );
		$this->assertSame( 'invalid_format', ( new DiagnosticianPwzdl() )->validate( str_repeat( '1', 120 ) )->code() );
	}
}
