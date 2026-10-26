<?php
/**
 * Testy czystej funkcji decyzyjnej sprawdzającej zależność od ACF Pro.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PortalUsers\Dependencies;

final class DependenciesTest extends TestCase {

	public function test_reports_acf_pro_missing_when_class_absent(): void {
		$this->assertSame( array( 'acf_pro' ), Dependencies::missing( array(), false ) );
	}

	public function test_reports_nothing_when_acf_pro_present(): void {
		$this->assertSame( array(), Dependencies::missing( array( 'advanced-custom-fields-pro/acf.php' ), true ) );
	}

	public function test_free_acf_is_not_enough_and_is_reported(): void {
		$this->assertSame(
			array( 'acf_pro', 'acf_free_active' ),
			Dependencies::missing( array( 'advanced-custom-fields/acf.php' ), false )
		);
	}

	public function test_unrelated_plugins_do_not_add_codes(): void {
		$this->assertSame( array( 'acf_pro' ), Dependencies::missing( array( 'akismet/akismet.php' ), false ) );
	}
}
