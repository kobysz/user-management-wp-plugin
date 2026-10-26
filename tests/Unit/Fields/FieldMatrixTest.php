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

	public function test_pruning_drops_entries_for_fields_absent_from_the_list(): void {
		$m = FieldMatrix::fromArray(
			array(
				'lekarz' => array(
					'pwz'    => 'required',
					'znikle' => 'required',
				),
			)
		);
		$p = $m->prunedTo( array( 'pwz' ) );

		$this->assertSame( array( 'pwz' ), $p->requiredFields( 'lekarz' ) );
		$this->assertSame( FieldState::Hidden, $p->stateFor( 'lekarz', 'znikle' ) );
		$this->assertSame( array( 'lekarz' => array( 'pwz' => 'required' ) ), $p->toArray() );
	}

	public function test_pruning_never_adds_an_entry_or_raises_a_state(): void {
		$m = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'visible' ) ) );
		$p = $m->prunedTo( array( 'pwz', 'miasto' ) );

		$this->assertSame( FieldState::Visible, $p->stateFor( 'lekarz', 'pwz' ) );
		$this->assertSame( FieldState::Hidden, $p->stateFor( 'lekarz', 'miasto' ) );
		$this->assertSame( array(), $p->requiredFields( 'lekarz' ) );
		$this->assertSame( $m->toArray(), $p->toArray() );
	}

	public function test_pruning_leaves_the_original_untouched(): void {
		$m = FieldMatrix::fromArray(
			array(
				'lekarz' => array(
					'pwz'    => 'required',
					'znikle' => 'required',
				),
			)
		);
		$p = $m->prunedTo( array( 'pwz' ) );

		$this->assertNotSame( $m, $p );
		$this->assertSame( array( 'pwz', 'znikle' ), $m->requiredFields( 'lekarz' ) );
		$this->assertSame( FieldState::Required, $m->stateFor( 'lekarz', 'znikle' ) );
	}

	public function test_pruning_keeps_the_order_of_surviving_entries_and_applies_to_every_profession(): void {
		$m = FieldMatrix::fromArray(
			array(
				'lekarz'  => array(
					'b' => 'visible',
					'x' => 'required',
					'a' => 'required',
				),
				'student' => array(
					'x' => 'required',
					'a' => 'visible',
				),
			)
		);
		$p = $m->prunedTo( array( 'a', 'b' ) );

		$this->assertSame( array( 'b', 'a' ), $p->visibleFields( 'lekarz' ) );
		$this->assertSame( array( 'a' ), $p->visibleFields( 'student' ) );
		$this->assertSame( array(), $p->requiredFields( 'student' ) );
	}

	public function test_pruning_to_an_empty_list_removes_every_entry_including_required_credential_field(): void {
		// OSTRZEZENIE: pusta lista znosi WSZYSTKIE wymagania, lacznie z wymogiem numeru uprawnien (pwz).
		// Wywolujacy odpowiada za to, by lista pochodzila z czytelnego zrodla.
		$m = FieldMatrix::fromArray(
			array(
				'lekarz'  => array(
					'pwz'    => 'required',
					'miasto' => 'visible',
				),
				'student' => array(
					'uczelnia' => 'required',
				),
			)
		);
		$p = $m->prunedTo( array() );

		$this->assertSame( array(), $p->requiredFields( 'lekarz' ) );
		$this->assertSame( array(), $p->requiredFields( 'student' ) );
		$this->assertSame( FieldState::Hidden, $p->stateFor( 'lekarz', 'pwz' ) );
		$this->assertSame(
			array(
				'lekarz'  => array(),
				'student' => array(),
			),
			$p->toArray()
		);
	}

	public function test_a_profession_with_all_fields_pruned_behaves_like_an_absent_one(): void {
		$p = FieldMatrix::fromArray( array( 'lekarz' => array( 'znikle' => 'required' ) ) )->prunedTo( array( 'pwz' ) );

		$this->assertSame( array(), $p->requiredFields( 'lekarz' ) );
		$this->assertSame( array(), $p->visibleFields( 'lekarz' ) );
		$this->assertSame( FieldState::Hidden, $p->stateFor( 'lekarz', 'znikle' ) );
		$this->assertSame( $p->requiredFields( 'nie-ma' ), $p->requiredFields( 'lekarz' ) );
		$this->assertSame( $p->visibleFields( 'nie-ma' ), $p->visibleFields( 'lekarz' ) );
		$this->assertSame( $p->stateFor( 'nie-ma', 'pwz' ), $p->stateFor( 'lekarz', 'pwz' ) );
	}
}
