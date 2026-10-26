<?php
/**
 * Testy: SubmissionFilterTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Fields;

use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\SubmissionFilter;
use PHPUnit\Framework\TestCase;

final class SubmissionFilterTest extends TestCase {

	private function matrix(): FieldMatrix {
		return FieldMatrix::fromArray(
			array(
				'lekarz'  => array(
					'pwz'    => 'required',
					'miasto' => 'visible',
					'pwzp'   => 'hidden',
				),
				'student' => array(
					'pwz'      => 'hidden',
					'miasto'   => 'visible',
					'uczelnia' => 'required',
				),
			)
		);
	}

	public function test_drops_values_of_fields_hidden_for_the_profession(): void {
		$filter = new SubmissionFilter( $this->matrix() );
		$out    = $filter->strip(
			'student',
			array(
				'pwz'      => '5425740',
				'uczelnia' => 'UJ',
				'miasto'   => 'Kraków',
			)
		);
		$this->assertSame(
			array(
				'uczelnia' => 'UJ',
				'miasto'   => 'Kraków',
			),
			$out
		);
	}

	public function test_drops_everything_for_unknown_profession(): void {
		$filter = new SubmissionFilter( $this->matrix() );
		$this->assertSame( array(), $filter->strip( 'nie-ma', array( 'miasto' => 'Kraków' ) ) );
	}

	public function test_drops_field_absent_from_matrix_for_a_known_profession(): void {
		$filter = new SubmissionFilter( $this->matrix() );
		$out    = $filter->strip(
			'lekarz',
			array(
				'pwz'       => '5425740',
				'nie-ma-tu' => 'x',
			)
		);
		$this->assertSame( array( 'pwz' => '5425740' ), $out );
	}
}
