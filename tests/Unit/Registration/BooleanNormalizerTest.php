<?php
/**
 * Testy: BooleanNormalizerTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Registration;

use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Domain\Registration\BooleanNormalizer;
use PortalUsers\Domain\Submission\SubmissionValidator;
use PHPUnit\Framework\TestCase;

final class BooleanNormalizerTest extends TestCase {

	public function test_unchecked_boolean_becomes_an_absent_value(): void {
		$n = new BooleanNormalizer( array( 'zgoda' ) );
		$this->assertSame( array( 'zgoda' => '' ), $n->normalize( array( 'zgoda' => '0' ) ) );
	}

	public function test_checked_boolean_survives(): void {
		$n = new BooleanNormalizer( array( 'zgoda' ) );
		$this->assertSame( array( 'zgoda' => '1' ), $n->normalize( array( 'zgoda' => '1' ) ) );
	}

	public function test_zero_in_a_field_that_is_not_boolean_survives(): void {
		$n = new BooleanNormalizer( array( 'zgoda' ) );
		$this->assertSame( array( 'lata' => '0' ), $n->normalize( array( 'lata' => '0' ) ) );
	}

	public function test_every_falsy_shape_of_an_unchecked_boolean_is_treated_alike(): void {
		$n = new BooleanNormalizer( array( 'zgoda' ) );
		foreach ( array( '0', 0, 0.0, false ) as $falsy ) {
			$this->assertSame( array( 'zgoda' => '' ), $n->normalize( array( 'zgoda' => $falsy ) ), var_export( $falsy, true ) );
		}
	}

	public function test_every_falsy_shape_survives_in_a_field_that_is_not_boolean(): void {
		$n = new BooleanNormalizer( array( 'zgoda' ) );
		foreach ( array( '0', 0, 0.0, false ) as $falsy ) {
			$this->assertSame( array( 'lata' => $falsy ), $n->normalize( array( 'lata' => $falsy ) ), var_export( $falsy, true ) );
		}
	}

	public function test_boolean_field_missing_from_the_values_is_not_invented(): void {
		$n = new BooleanNormalizer( array( 'zgoda' ) );
		$this->assertSame( array( 'lata' => '3' ), $n->normalize( array( 'lata' => '3' ) ) );
	}

	public function test_only_truth_survives_in_a_boolean_field(): void {
		$n = new BooleanNormalizer( array( 'zgoda' ) );
		foreach ( array( 'tak', 'nie', '2', 'true', 'on', array( '1' ), array() ) as $notTruth ) {
			$this->assertSame( array( 'zgoda' => '' ), $n->normalize( array( 'zgoda' => $notTruth ) ), var_export( $notTruth, true ) );
		}
		foreach ( array( '1', 1, true ) as $truth ) {
			$this->assertSame( array( 'zgoda' => '1' ), $n->normalize( array( 'zgoda' => $truth ) ), var_export( $truth, true ) );
		}
	}

	/**
	 * Walidator z Planu 1 z wymaganą zgodą i wymaganym polem liczbowym.
	 */
	private function validator(): SubmissionValidator {
		$professions = ProfessionCollection::fromArray(
			array(
				array(
					'key'             => 'lekarz',
					'label'           => 'Lekarz',
					'credential_type' => 'none',
				),
			)
		);
		$matrix      = FieldMatrix::fromArray(
			array(
				'lekarz' => array(
					'zgoda' => 'required',
					'lata'  => 'required',
				),
			)
		);

		return new SubmissionValidator( $professions, $matrix, new ValidatorRegistry() );
	}

	public function test_unchecked_required_consent_fails_validation_after_normalisation(): void {
		$submitted = array(
			'zgoda' => '0',
			'lata'  => '0',
		);

		// Kontrola: bez normalizacji odznaczona zgoda spełnia `required`, więc test niżej
		// naprawdę dowodzi normalizacji, a nie tego, że zgoda i tak była wymagana.
		$this->assertTrue( $this->validator()->validate( 'lekarz', '', $submitted )->isValid() );

		$values = ( new BooleanNormalizer( array( 'zgoda' ) ) )->normalize( $submitted );
		$r      = $this->validator()->validate( 'lekarz', '', $values );

		$this->assertFalse( $r->isValid() );
		$this->assertSame( 'required', $r->errorFor( 'zgoda' ) );
		// Zero w polu liczbowym nadal spełnia `required`.
		$this->assertSame( '', $r->errorFor( 'lata' ) );
	}

	public function test_checked_required_consent_passes_validation_after_normalisation(): void {
		$values = ( new BooleanNormalizer( array( 'zgoda' ) ) )->normalize( array( 'zgoda' => '1', 'lata' => '0' ) );
		$this->assertTrue( $this->validator()->validate( 'lekarz', '', $values )->isValid() );
	}
}
