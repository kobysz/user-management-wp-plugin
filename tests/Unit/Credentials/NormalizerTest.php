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
}
