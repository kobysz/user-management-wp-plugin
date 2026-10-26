<?php
/**
 * Testy: PhysicianPwzTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Credentials;

use PortalUsers\Domain\Credentials\Validators\PhysicianPwz;
use PHPUnit\Framework\TestCase;

final class PhysicianPwzTest extends TestCase {

	public function test_accepts_nil_reference_number(): void {
		// Przykład z dokumentacji NIL: cyfry 4,2,5,7,4,0 → suma 71 → 71 mod 11 = 5.
		$this->assertTrue( ( new PhysicianPwz() )->validate( '5425740' )->isValid() );
	}

	public function test_accepts_second_valid_number(): void {
		// 1,2,3,4,5,6 ważone 1..6 → 91 → 91 mod 11 = 3.
		$this->assertTrue( ( new PhysicianPwz() )->validate( '3123456' )->isValid() );
	}

	public function test_rejects_wrong_checksum(): void {
		$this->assertSame( 'invalid_checksum', ( new PhysicianPwz() )->validate( '1234567' )->code() );
	}

	public function test_rejects_number_whose_control_digit_would_be_ten(): void {
		// 4,3,0,0,0,0 ważone 1..6 → 10 → 10 mod 11 = 10, cyfra kontrolna nie mieści się
		// w jednym znaku, więc taki numer nie jest wydawany.
		$this->assertSame( 'invalid_checksum', ( new PhysicianPwz() )->validate( '1430000' )->code() );
	}

	public function test_rejects_leading_zero(): void {
		$this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( '0425740' )->code() );
	}

	public function test_rejects_wrong_length(): void {
		$this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( '542574' )->code() );
		$this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( '54257400' )->code() );
	}

	public function test_rejects_non_digits(): void {
		$this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( 'abcdefg' )->code() );
	}

	public function test_rejects_empty(): void {
		$this->assertSame( 'empty', ( new PhysicianPwz() )->validate( '' )->code() );
	}

	public function test_normalizes_before_validating(): void {
		$result = ( new PhysicianPwz() )->validate( ' 542-5740 ' );
		$this->assertTrue( $result->isValid() );
		$this->assertSame( '5425740', $result->normalized() );
	}
}
