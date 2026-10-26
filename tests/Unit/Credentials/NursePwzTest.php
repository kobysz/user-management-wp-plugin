<?php
/**
 * Testy: NursePwzTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Credentials;

use PortalUsers\Domain\Credentials\Validators\NursePwz;
use PHPUnit\Framework\TestCase;

final class NursePwzTest extends TestCase {

	public function test_accepts_nurse_and_midwife_letters(): void {
		$this->assertTrue( ( new NursePwz() )->validate( '0201234P' )->isValid() );
		$this->assertTrue( ( new NursePwz() )->validate( '0201234A' )->isValid() );
	}

	public function test_accepts_chamber_bounds(): void {
		$this->assertTrue( ( new NursePwz() )->validate( '0101234P' )->isValid() );
		$this->assertTrue( ( new NursePwz() )->validate( '4501234P' )->isValid() );
	}

	public function test_rejects_chamber_out_of_range(): void {
		$this->assertSame( 'invalid_chamber', ( new NursePwz() )->validate( '0001234P' )->code() );
		$this->assertSame( 'invalid_chamber', ( new NursePwz() )->validate( '4601234P' )->code() );
	}

	public function test_rejects_wrong_letter(): void {
		$this->assertSame( 'invalid_format', ( new NursePwz() )->validate( '0201234X' )->code() );
	}

	public function test_rejects_missing_letter(): void {
		$this->assertSame( 'invalid_format', ( new NursePwz() )->validate( '0201234' )->code() );
	}

	public function test_accepts_lowercase_letter_and_normalizes_it(): void {
		$result = ( new NursePwz() )->validate( '0201234p' );
		$this->assertTrue( $result->isValid() );
		$this->assertSame( '0201234P', $result->normalized() );
	}
}
