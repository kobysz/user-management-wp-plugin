<?php
/**
 * Testy: RegistrationPlanTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Registration;

use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldSource;
use PortalUsers\Domain\Fields\FieldState;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Domain\Registration\RegistrationPlan;
use PortalUsers\Domain\Submission\SubmissionValidator;
use PHPUnit\Framework\TestCase;

final class RegistrationPlanTest extends TestCase {

	/**
	 * Źródło pól w stałej kolejności.
	 *
	 * @param array<string, string> $fields Klucz pola => etykieta.
	 */
	private function source( array $fields ): FieldSource {
		return new class( $fields ) implements FieldSource {
			/**
			 * @param array<string, string> $fields Pola.
			 */
			public function __construct( private array $fields ) {}

			public function fields(): array {
				return $this->fields;
			}
		};
	}

	private function professions(): ProfessionCollection {
		return ProfessionCollection::fromArray(
			array(
				array(
					'key'             => 'lekarz',
					'label'           => 'Lekarz',
					'credential_type' => 'physician_pwz',
				),
			)
		);
	}

	public function test_a_required_field_missing_from_the_source_does_not_block_registration(): void {
		$matrix = FieldMatrix::fromArray(
			array(
				'lekarz' => array(
					'pwz'    => 'required',
					'znikle' => 'required',
				),
			)
		);

		// Kontrola: bez przycięcia walidator twardo wymaga pola, którego nikt nie wyrenderuje.
		$unpruned = ( new SubmissionValidator( $this->professions(), $matrix, new ValidatorRegistry() ) )
			->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740' ) );
		$this->assertFalse( $unpruned->isValid() );
		$this->assertSame( 'required', $unpruned->errorFor( 'znikle' ) );

		$pruned = $matrix->prunedTo( array_keys( $this->source( array( 'pwz' => 'Numer PWZ' ) )->fields() ) );
		$result = ( new SubmissionValidator( $this->professions(), $pruned, new ValidatorRegistry() ) )
			->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740' ) );

		$this->assertTrue( $result->isValid() );
	}

	public function test_plan_lists_visible_and_required_fields_in_source_order_and_omits_hidden(): void {
		$plan = new RegistrationPlan(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'pwz'    => 'required',
						'miasto' => 'visible',
						'zgoda'  => 'hidden',
					),
				)
			),
			$this->source(
				array(
					'miasto' => 'Miasto',
					'pwz'    => 'Numer PWZ',
					'zgoda'  => 'Zgoda',
				)
			)
		);

		$fields = $plan->fieldsFor( 'lekarz' );

		$this->assertSame(
			array(
				'miasto' => FieldState::Visible,
				'pwz'    => FieldState::Required,
			),
			$fields
		);
		$this->assertSame( array( 'miasto', 'pwz' ), array_keys( $fields ) );
		$this->assertSame( array( 'pwz' ), $plan->requiredFor( 'lekarz' ) );
	}

	public function test_plan_omits_a_field_absent_from_the_matrix(): void {
		$plan = new RegistrationPlan(
			FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ),
			$this->source(
				array(
					'pwz'   => 'Numer PWZ',
					'nowe'  => 'Nowe pole',
				)
			)
		);

		$this->assertSame( array( 'pwz' => FieldState::Required ), $plan->fieldsFor( 'lekarz' ) );
	}

	public function test_plan_for_an_unknown_profession_is_empty(): void {
		$plan = new RegistrationPlan(
			FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ),
			$this->source( array( 'pwz' => 'Numer PWZ' ) )
		);

		$this->assertSame( array(), $plan->fieldsFor( 'nie-ma' ) );
		$this->assertSame( array(), $plan->requiredFor( 'nie-ma' ) );
	}
}
