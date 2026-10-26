<?php
/**
 * Testy: SubmissionValidatorTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Submission;

use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Domain\Submission\SubmissionValidator;
use PHPUnit\Framework\TestCase;

final class SubmissionValidatorTest extends TestCase {

	private function validator(): SubmissionValidator {
		$professions = ProfessionCollection::fromArray(
			array(
				array(
					'key'             => 'lekarz',
					'label'           => 'Lekarz',
					'credential_type' => 'physician_pwz',
				),
				array(
					'key'             => 'lekarz-opcjonalny',
					'label'           => 'Lekarz (numer opcjonalny)',
					'credential_type' => 'physician_pwz',
				),
				array(
					'key'             => 'student',
					'label'           => 'Student',
					'credential_type' => 'none',
				),
			)
		);
		$matrix      = FieldMatrix::fromArray(
			array(
				'lekarz'  => array(
					'pwz'   => 'required',
					'zgoda' => 'required',
					'pwzp'  => 'hidden',
				),
				'lekarz-opcjonalny' => array(
					'pwz'   => 'visible',
					'zgoda' => 'required',
				),
				'student' => array(
					'pwz'      => 'hidden',
					'uczelnia' => 'required',
					'zgoda'    => 'required',
					'pwzp'     => 'hidden',
				),
			)
		);

		return new SubmissionValidator( $professions, $matrix, new ValidatorRegistry() );
	}

	public function test_rejects_unknown_profession(): void {
		$r = $this->validator()->validate( 'nie-ma', 'pwz', array() );
		$this->assertFalse( $r->isValid() );
		$this->assertSame( 'unknown_profession', $r->errorFor( '__profession' ) );
	}

	public function test_reports_missing_required_field(): void {
		$r = $this->validator()->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740' ) );
		$this->assertSame( 'required', $r->errorFor( 'zgoda' ) );
	}

	public function test_treats_empty_array_empty_string_and_whitespace_as_missing(): void {
		foreach ( array( array(), array( '' ), '', '   ', null ) as $empty ) {
			$r = $this->validator()->validate(
				'lekarz',
				'pwz',
				array(
					'pwz'   => '5425740',
					'zgoda' => $empty,
				)
			);
			$this->assertSame( 'required', $r->errorFor( 'zgoda' ) );
		}
	}

	public function test_accepts_complete_submission(): void {
		$r = $this->validator()->validate(
			'lekarz',
			'pwz',
			array(
				'pwz'   => '5425740',
				'zgoda' => '1',
			)
		);
		$this->assertTrue( $r->isValid() );
	}

	public function test_invalid_credential_number_does_not_fail_the_submission(): void {
		// Walidacja numeru jest miękka: zła suma kontrolna przechodzi do redakcji
		// z ostrzeżeniem, bo lekarz z nietypowym numerem nie ma zostać odcięty.
		$r = $this->validator()->validate(
			'lekarz',
			'pwz',
			array(
				'pwz'   => '1234567',
				'zgoda' => '1',
			)
		);
		$this->assertTrue( $r->isValid() );
		$this->assertSame( 'invalid_checksum', $r->credentialWarning() );
	}

	public function test_missing_credential_number_is_a_hard_error_when_required(): void {
		$r = $this->validator()->validate( 'lekarz', 'pwz', array( 'zgoda' => '1' ) );
		$this->assertSame( 'required', $r->errorFor( 'pwz' ) );
	}

	public function test_profession_without_credential_needs_no_number(): void {
		$r = $this->validator()->validate(
			'student',
			'pwz',
			array(
				'uczelnia' => 'UJ',
				'zgoda'    => '1',
			)
		);
		$this->assertTrue( $r->isValid() );
		$this->assertSame( '', $r->credentialWarning() );
	}

	public function test_values_are_stripped_of_hidden_fields_and_normalized(): void {
		$r = $this->validator()->validate(
			'lekarz',
			'pwz',
			array(
				'pwz'   => ' 542-5740 ',
				'pwzp'  => '0201234P',
				'zgoda' => '1',
			)
		);
		$this->assertArrayNotHasKey( 'pwzp', $r->values() );
		$this->assertSame( '5425740', $r->values()['pwz'] );
	}

	public function test_invalid_number_keeps_cleaned_value_for_the_editor(): void {
		$r = $this->validator()->validate(
			'lekarz',
			'pwz',
			array(
				'pwz'   => ' 123-4567 ',
				'zgoda' => '1',
			)
		);
		$this->assertSame( '1234567', $r->values()['pwz'] );
	}

	public function test_separator_only_number_in_required_field_is_a_hard_error(): void {
		$r = $this->validator()->validate(
			'lekarz',
			'pwz',
			array(
				'pwz'   => '-',
				'zgoda' => '1',
			)
		);
		$this->assertFalse( $r->isValid() );
		$this->assertSame( 'required', $r->errorFor( 'pwz' ) );
		$this->assertSame( '-', $r->values()['pwz'] );
	}

	public function test_separator_only_number_in_optional_field_is_a_warning(): void {
		$r = $this->validator()->validate(
			'lekarz-opcjonalny',
			'pwz',
			array(
				'pwz'   => '-',
				'zgoda' => '1',
			)
		);
		$this->assertTrue( $r->isValid() );
		$this->assertSame( 'empty', $r->credentialWarning() );
		$this->assertSame( '-', $r->values()['pwz'] );
	}

	public function test_falsy_values_count_as_present_by_deliberate_decision(): void {
		// Świadoma decyzja (nie luka): zero jest legalną wartością, więc '0', 0, 0.0 i false
		// spełniają `required`. Odznaczony checkbox musi w warstwie formularza wysłać wartość pustą.
		foreach ( array( '0', 0, 0.0, false ) as $falsy ) {
			$r = $this->validator()->validate(
				'lekarz',
				'pwz',
				array(
					'pwz'   => '5425740',
					'zgoda' => $falsy,
				)
			);
			$this->assertTrue( $r->isValid(), 'Wartość ' . var_export( $falsy, true ) . ' miała liczyć się jako obecna.' );
			$this->assertSame( $falsy, $r->values()['zgoda'] );
		}
	}

	public function test_non_string_credential_value_gives_invalid_format_warning_and_stays_untouched(): void {
		// Kontrakt, od którego zależy obowiązkowa sanityzacja przy zapisie (Plan 3).
		$r = $this->validator()->validate(
			'lekarz',
			'pwz',
			array(
				'pwz'   => array( '5425740' ),
				'zgoda' => '1',
			)
		);
		$this->assertTrue( $r->isValid() );
		$this->assertSame( 'invalid_format', $r->credentialWarning() );
		$this->assertSame( array( '5425740' ), $r->values()['pwz'] );
	}

	public function test_profession_missing_from_matrix_gives_valid_submission_with_no_values(): void {
		// Pułapka produktowa, utrwalona testem: profesja jest w kolekcji, ale nie ma jej w macierzy,
		// więc wszystkie pola są „ukryte” - zgłoszenie przechodzi z pustymi values().
		$professions = ProfessionCollection::fromArray(
			array(
				array(
					'key'             => 'lekarz',
					'label'           => 'Lekarz',
					'credential_type' => 'physician_pwz',
				),
			)
		);
		$validator   = new SubmissionValidator( $professions, FieldMatrix::fromArray( array() ), new ValidatorRegistry() );

		$r = $validator->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740', 'zgoda' => '1' ) );

		$this->assertTrue( $r->isValid() );
		$this->assertSame( array(), $r->values() );
	}
}
