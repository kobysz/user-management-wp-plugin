<?php
/**
 * Testy: FieldMatrixTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Fields;

use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldState;
use PHPUnit\Framework\TestCase;

final class FieldMatrixTest extends TestCase {

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

	public function test_reads_state_for_known_profession_and_field(): void {
		$this->assertSame( FieldState::Required, $this->matrix()->stateFor( 'lekarz', 'pwz' ) );
		$this->assertSame( FieldState::Visible, $this->matrix()->stateFor( 'lekarz', 'miasto' ) );
	}

	public function test_unknown_profession_hides_everything(): void {
		// Profesja usunięta z ustawień, a konta z nią zostały.
		$this->assertSame( FieldState::Hidden, $this->matrix()->stateFor( 'nie-ma', 'miasto' ) );
		$this->assertSame( array(), $this->matrix()->visibleFields( 'nie-ma' ) );
	}

	public function test_field_absent_from_matrix_is_hidden(): void {
		// Pole dodane w ACF, jeszcze nie ustawione w macierzy.
		$this->assertSame( FieldState::Hidden, $this->matrix()->stateFor( 'lekarz', 'nowe-pole' ) );
	}

	public function test_unknown_state_string_degrades_to_hidden(): void {
		$m = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'bzdura' ) ) );
		$this->assertSame( FieldState::Hidden, $m->stateFor( 'lekarz', 'pwz' ) );
	}

	public function test_visible_fields_include_required_ones(): void {
		$this->assertSame( array( 'pwz', 'miasto' ), $this->matrix()->visibleFields( 'lekarz' ) );
	}

	public function test_required_fields_exclude_merely_visible_ones(): void {
		$this->assertSame( array( 'pwz' ), $this->matrix()->requiredFields( 'lekarz' ) );
		$this->assertSame( array( 'uczelnia' ), $this->matrix()->requiredFields( 'student' ) );
	}
}
