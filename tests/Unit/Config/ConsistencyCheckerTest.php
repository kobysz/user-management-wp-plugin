<?php
/**
 * Testy: ConsistencyChecker.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Config\ConsistencyChecker;
use PortalUsers\Domain\Config\SettingsIssue;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldSource;
use PortalUsers\Domain\Profession\ProfessionCollection;

final class ConsistencyCheckerTest extends TestCase {

	private function source( array $fields ): FieldSource {
		return new class( $fields ) implements FieldSource {
			public function __construct( private array $fields ) {}
			public function fields(): array {
				return $this->fields;
			}
		};
	}

	private function codes( ConsistencyChecker $checker ): array {
		return array_map( static fn ( SettingsIssue $i ): string => $i->code, $checker->issues() );
	}

	public function test_clean_configuration_reports_nothing(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				)
			),
			FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'zgoda' => 'required' ) ) ),
			$this->source( array( 'pwz' => 'Numer PWZ', 'zgoda' => 'Regulamin' ) )
		);
		$this->assertSame( array(), $this->codes( $checker ) );
	}

	public function test_profession_without_a_matrix_row_is_an_error(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
			FieldMatrix::fromArray( array() ),
			$this->source( array( 'zgoda' => 'Regulamin' ) )
		);
		$this->assertSame( array( 'profession_without_matrix_row' ), $this->codes( $checker ) );
		$this->assertSame( SettingsIssue::ERROR, $checker->issues()[0]->severity );
		$this->assertSame( 'student', $checker->issues()[0]->context['profession'] );
	}

	public function test_profession_with_credential_type_whose_credential_field_is_not_required_is_an_error(): void {
		// Typ numeru ustawiony, ale pole numeru ukryte w macierzy: zgłoszenie przejdzie bez numeru,
		// bez błędu i bez ostrzeżenia dla redakcji.
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				)
			),
			FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden' ) ) ),
			$this->source( array( 'pwz' => 'Numer PWZ' ) )
		);
		$this->assertSame( array( 'credential_field_not_required' ), $this->codes( $checker ) );
	}

	public function test_hidden_credential_field_and_unspecified_credential_field_are_told_apart_in_context(): void {
		$unspecified = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ) ) ),
			FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ),
			$this->source( array( 'pwz' => 'Numer PWZ' ) )
		);
		$hidden      = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ) ) ),
			FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden' ) ) ),
			$this->source( array( 'pwz' => 'Numer PWZ' ) )
		);
		$this->assertSame( array( 'credential_field_not_required' ), $this->codes( $unspecified ) );
		$this->assertSame( array( 'credential_field_not_required' ), $this->codes( $hidden ) );
		$this->assertSame( SettingsIssue::REASON_CREDENTIAL_FIELD_UNSET, $unspecified->issues()[0]->context['reason'] );
		$this->assertSame( SettingsIssue::REASON_CREDENTIAL_FIELD_NOT_REQUIRED, $hidden->issues()[0]->context['reason'] );
	}

	public function test_profession_with_credential_type_and_no_matrix_row_reports_only_the_missing_row(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ) ) ),
			FieldMatrix::fromArray( array() ),
			$this->source( array( 'pwz' => 'Numer PWZ' ) )
		);
		$this->assertSame( array( 'profession_without_matrix_row' ), $this->codes( $checker ) );
	}

	public function test_field_present_in_source_but_absent_from_a_matrix_row_is_a_warning(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
			FieldMatrix::fromArray( array( 'student' => array( 'zgoda' => 'required' ) ) ),
			$this->source( array( 'zgoda' => 'Regulamin', 'uczelnia' => 'Uczelnia' ) )
		);
		$issues = $checker->issues();
		$this->assertSame( array( 'field_not_configured' ), $this->codes( $checker ) );
		$this->assertSame( SettingsIssue::WARNING, $issues[0]->severity );
		$this->assertSame( 'uczelnia', $issues[0]->context['field'] );
		$this->assertSame( 'student', $issues[0]->context['profession'] );
	}

	public function test_required_matrix_field_missing_from_source_is_an_error_that_blocks_registration(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
			FieldMatrix::fromArray( array( 'student' => array( 'skasowane' => 'required' ) ) ),
			$this->source( array() )
		);
		$issues = $checker->issues();
		$this->assertSame( array( 'matrix_required_field_not_in_source' ), $this->codes( $checker ) );
		$this->assertSame( SettingsIssue::ERROR, $issues[0]->severity );
		$this->assertSame( 'skasowane', $issues[0]->context['field'] );
		$this->assertSame( 'student', $issues[0]->context['profession'] );
	}

	public function test_visible_matrix_field_missing_from_source_stays_a_warning(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
			FieldMatrix::fromArray( array( 'student' => array( 'skasowane' => 'visible' ) ) ),
			$this->source( array() )
		);
		$issues = $checker->issues();
		$this->assertSame( array( 'matrix_field_not_in_source' ), $this->codes( $checker ) );
		$this->assertSame( SettingsIssue::WARNING, $issues[0]->severity );
	}

	public function test_hidden_matrix_field_missing_from_source_stays_a_warning(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
			FieldMatrix::fromArray( array( 'student' => array( 'skasowane' => 'hidden' ) ) ),
			$this->source( array() )
		);
		$issues = $checker->issues();
		$this->assertSame( array( 'matrix_field_not_in_source' ), $this->codes( $checker ) );
		$this->assertSame( SettingsIssue::WARNING, $issues[0]->severity );
	}

	public function test_matrix_row_for_an_unknown_profession_is_a_warning(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array() ),
			FieldMatrix::fromArray( array( 'skasowana' => array( 'zgoda' => 'required' ) ) ),
			$this->source( array( 'zgoda' => 'Regulamin' ) )
		);
		$this->assertSame( array( 'matrix_profession_unknown' ), $this->codes( $checker ) );
	}

	public function test_profession_without_credential_type_needs_no_credential_field(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
			FieldMatrix::fromArray( array( 'student' => array( 'zgoda' => 'required' ) ) ),
			$this->source( array( 'zgoda' => 'Regulamin' ) )
		);
		$this->assertSame( array(), $this->codes( $checker ) );
	}

	public function test_issues_are_ordered_errors_before_warnings_and_keep_detection_order_within_severity(): void {
		// Pierwsza profesja daje tylko ostrzeżenia, dwie kolejne błędy: kolejność wykrycia jest
		// odwrotna do oczekiwanej, więc test pada, gdy sortowanie zniknie.
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
					array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka', 'credential_type' => 'nurse_pwz' ),
				)
			),
			FieldMatrix::fromArray(
				array(
					'student'      => array( 'zgoda' => 'required' ),
					'lekarz'       => array( 'zgoda' => 'required', 'uczelnia' => 'hidden', 'pwz' => 'hidden' ),
					'pielegniarka' => array( 'zgoda' => 'required', 'uczelnia' => 'hidden', 'pwz' => 'hidden' ),
				)
			),
			$this->source( array( 'zgoda' => 'Regulamin', 'uczelnia' => 'Uczelnia', 'pwz' => 'Numer PWZ' ) )
		);
		$summary = array_map(
			static fn ( SettingsIssue $i ): string => $i->code . ':' . $i->context['profession'] . ':' . $i->context['field'],
			$checker->issues()
		);
		$this->assertSame(
			array(
				'credential_field_not_required:lekarz:pwz',
				'credential_field_not_required:pielegniarka:',
				'field_not_configured:student:uczelnia',
				'field_not_configured:student:pwz',
			),
			$summary
		);
	}

	public function test_empty_matrix_row_is_configured_and_not_a_missing_row(): void {
		// Wiersz pusty to „skonfigurowano i nic nie zaznaczono"; brak wiersza to „nie dotknięto".
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
			FieldMatrix::fromArray( array( 'student' => array() ) ),
			$this->source( array( 'zgoda' => 'Regulamin' ) )
		);
		$this->assertSame( array( 'field_not_configured' ), $this->codes( $checker ) );
	}

	public function test_empty_matrix_row_of_a_profession_with_credential_type_is_also_an_error(): void {
		$checker = new ConsistencyChecker(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				)
			),
			FieldMatrix::fromArray( array( 'lekarz' => array() ) ),
			$this->source( array( 'pwz' => 'Numer PWZ' ) )
		);
		$this->assertSame( array( 'credential_field_not_required', 'field_not_configured' ), $this->codes( $checker ) );
	}
}
