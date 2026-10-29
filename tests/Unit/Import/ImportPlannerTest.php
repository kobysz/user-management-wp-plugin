<?php
/**
 * Testy planera importu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Import\AccountChange;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Import\ImportPlanner;
use PortalUsers\Domain\Profession\ProfessionCollection;

final class ImportPlannerTest extends TestCase {

	/**
	 * Buduje planer z typowym zestawem: lekarz (numer PWZ), student (bez numeru), pielegniarka (numer bez pola).
	 *
	 * @param string $fallback Fallback stanu.
	 * @return ImportPlanner
	 */
	private function planner( string $fallback = ImportMapping::FALLBACK_NONE ): ImportPlanner {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'           => array(
					'pwz'   => 'wpmem_pwz',
					'name'  => 'wpmem_name',
					'phone' => 'wpmem_phone',
				),
				'professionKey'    => 'wpmem_role',
				'professionValues' => array(
					'doctor'  => 'lekarz',
					'student' => 'student',
					'nurse'   => 'pielegniarka',
					'ghost'   => 'nieistniejaca',
				),
				'statusKey'        => 'wpmem_status',
				'statusValues'     => array(
					'active' => 'approved',
					'banned' => 'rejected',
				),
				'fallback'         => $fallback,
			)
		);

		$professions = ProfessionCollection::fromArray(
			array(
				array(
					'key'              => 'lekarz',
					'label'            => 'Lekarz',
					'credential_type'  => 'physician_pwz',
					'credential_field' => 'pwz',
				),
				array(
					'key'             => 'student',
					'label'           => 'Student',
					'credential_type' => 'none',
				),
				array(
					'key'             => 'pielegniarka',
					'label'           => 'Pielegniarka',
					'credential_type' => 'nurse_pwz',
				),
			)
		);

		$matrix = FieldMatrix::fromArray(
			array(
				'lekarz'       => array(
					'pwz'   => 'required',
					'name'  => 'visible',
					'phone' => 'visible',
				),
				'student'      => array(
					'pwz'   => 'hidden',
					'name'  => 'required',
					'phone' => 'visible',
				),
				'pielegniarka' => array(
					'pwz'  => 'required',
					'name' => 'visible',
				),
			)
		);

		return new ImportPlanner( $mapping, $professions, $matrix, new ValidatorRegistry() );
	}

	public function test_wartosc_tablicowa_jest_pomijana_i_oznaczona(): void {
		$warnings = array();
		set_error_handler(
			static function ( int $no, string $str ) use ( &$warnings ): bool {
				$warnings[] = $str;
				return true;
			}
		);

		try {
			$change = $this->planner()->plan( 7, array( 'wpmem_pwz' => array( '1234567' ) ) );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings );
		$this->assertSame( array(), $change->fields );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_VALUE_UNUSABLE ) );
	}

	public function test_obiekt_jest_pomijany_i_oznaczony(): void {
		$change = $this->planner()->plan( 7, array( 'wpmem_name' => new \stdClass() ) );

		$this->assertSame( array(), $change->fields );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_VALUE_UNUSABLE ) );
	}

	public function test_wartosc_nieskalarna_profesji_i_stanu_jest_oznaczona_i_nie_daje_wyniku(): void {
		$change = $this->planner( ImportMapping::FALLBACK_PENDING )->plan(
			7,
			array(
				'wpmem_role'   => array( 'doctor' ),
				'wpmem_status' => array( 'active' ),
			)
		);

		$this->assertNull( $change->professionKey );
		$this->assertSame( AccountStatus::Pending, $change->status );
		$this->assertSame(
			array(
				AccountChange::ISSUE_PROFESSION_MISSING,
				AccountChange::ISSUE_STATUS_MISSING,
				AccountChange::ISSUE_VALUE_UNUSABLE,
			),
			$change->issues
		);
	}

	public function test_skalary_ida_przez_rzutowanie_na_lancuch(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_name'  => true,
				'wpmem_phone' => 48123456,
			)
		);

		$this->assertSame(
			array(
				'name'  => '1',
				'phone' => '48123456',
			),
			$change->fields
		);
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_VALUE_UNUSABLE ) );
	}

	public function test_false_i_null_to_wartosci_puste_a_nie_nieuzyteczne(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_name'  => false,
				'wpmem_phone' => null,
			)
		);

		$this->assertSame( array(), $change->fields );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_VALUE_UNUSABLE ) );
	}

	public function test_wartosc_pusta_nie_jest_zapisywana(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_name'  => '   ',
				'wpmem_phone' => '600100200',
			)
		);

		$this->assertSame( array( 'phone' => '600100200' ), $change->fields );
		$this->assertArrayNotHasKey( 'name', $change->fields );
	}

	public function test_wartosci_nie_sa_przycinane_poza_sprawdzeniem_pustki(): void {
		$change = $this->planner()->plan( 7, array( 'wpmem_name' => '  Jan Kowalski ' ) );

		$this->assertSame( array( 'name' => '  Jan Kowalski ' ), $change->fields );
	}

	public function test_numer_niepoprawny_wchodzi_znormalizowany_i_jest_oznaczony(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role' => 'doctor',
				'wpmem_pwz'  => ' 12 34 567 ',
			)
		);

		$this->assertSame( 'lekarz', $change->professionKey );
		$this->assertSame( '1234567', $change->fields['pwz'] );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_CREDENTIAL_INVALID ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_PROFESSION_UNMAPPED ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_PROFESSION_MISSING ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_VALUE_UNUSABLE ) );
	}

	public function test_numer_poprawny_wchodzi_znormalizowany_bez_zastrzezenia(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role' => 'doctor',
				'wpmem_pwz'  => ' 542-5740 ',
			)
		);

		$this->assertSame( '5425740', $change->fields['pwz'] );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_CREDENTIAL_INVALID ) );
	}

	public function test_numer_skladajacy_sie_z_samych_separatorow_nie_jest_zapisywany(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role' => 'doctor',
				'wpmem_pwz'  => ' - . - ',
			)
		);

		$this->assertArrayNotHasKey( 'pwz', $change->fields );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_CREDENTIAL_INVALID ) );
	}

	public function test_numer_pola_ukrytego_nie_jest_sprawdzany(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role' => 'student',
				'wpmem_pwz'  => '1234567',
				'wpmem_name' => 'Ola',
			)
		);

		$this->assertSame( array( 'name' => 'Ola' ), $change->fields );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_CREDENTIAL_INVALID ) );
	}

	public function test_profesja_bez_pola_numeru_nie_sprawdza_numeru(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role' => 'nurse',
				'wpmem_pwz'  => 'x',
			)
		);

		$this->assertSame( 'pielegniarka', $change->professionKey );
		$this->assertSame( array( 'pwz' => 'x' ), $change->fields );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_CREDENTIAL_INVALID ) );
	}

	public function test_lekarz_bez_numeru_w_zrodle_nie_dostaje_zastrzezenia_o_numerze(): void {
		$change = $this->planner()->plan( 7, array( 'wpmem_role' => 'doctor' ) );

		$this->assertArrayNotHasKey( 'pwz', $change->fields );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_CREDENTIAL_INVALID ) );
	}

	public function test_profesja_nieznana_zostawia_klucz_pusty_i_zapisuje_wszystkie_pola(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role'  => 'kosmonauta',
				'wpmem_pwz'   => '1234567',
				'wpmem_name'  => 'Ola',
				'wpmem_phone' => '600',
			)
		);

		$this->assertNull( $change->professionKey );
		$this->assertSame(
			array(
				'pwz'   => '1234567',
				'name'  => 'Ola',
				'phone' => '600',
			),
			$change->fields
		);
		$this->assertSame( array( AccountChange::ISSUE_PROFESSION_UNMAPPED, AccountChange::ISSUE_STATUS_MISSING ), $change->issues );
	}

	public function test_profesja_zmapowana_na_klucz_spoza_zbioru_jest_nierozpoznana(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role' => 'ghost',
				'wpmem_pwz'  => '1234567',
			)
		);

		$this->assertNull( $change->professionKey );
		$this->assertSame( array( 'pwz' => '1234567' ), $change->fields );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_PROFESSION_UNMAPPED ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_PROFESSION_MISSING ) );
	}

	public function test_brak_profesji_albo_wartosc_pusta_daje_zastrzezenie_o_braku(): void {
		foreach ( array( array(), array( 'wpmem_role' => '  ' ) ) as $meta ) {
			$change = $this->planner()->plan( 7, $meta );

			$this->assertNull( $change->professionKey );
			$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_PROFESSION_MISSING ) );
			$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_PROFESSION_UNMAPPED ) );
		}
	}

	public function test_profesja_rozpoznana_nie_ma_zastrzezen_o_profesji(): void {
		$change = $this->planner()->plan( 7, array( 'wpmem_role' => ' Doctor ' ) );

		$this->assertSame( 'lekarz', $change->professionKey );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_PROFESSION_UNMAPPED ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_PROFESSION_MISSING ) );
	}

	public function test_pole_ukryte_dla_rozpoznanej_profesji_nie_jest_zapisywane(): void {
		$change = $this->planner()->plan(
			7,
			array(
				'wpmem_role'  => 'student',
				'wpmem_pwz'   => '5425740',
				'wpmem_name'  => 'Ola',
				'wpmem_phone' => '600',
			)
		);

		$this->assertSame(
			array(
				'name'  => 'Ola',
				'phone' => '600',
			),
			$change->fields
		);
	}

	public function test_klucze_zrodlowe_spoza_mapowania_sa_ignorowane(): void {
		$change = $this->planner()->plan( 7, array( 'cos_innego' => 'x' ) );

		$this->assertFalse( $change->hasWrites() );
	}

	public function test_stan_zmapowany_jest_nadawany_bez_zastrzezen(): void {
		$change = $this->planner( ImportMapping::FALLBACK_PENDING )->plan( 7, array( 'wpmem_status' => ' ACTIVE ' ) );

		$this->assertSame( AccountStatus::Approved, $change->status );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_STATUS_UNMAPPED ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_STATUS_MISSING ) );
	}

	public function test_stan_nieznany_nie_jest_zgadywany(): void {
		$change = $this->planner( ImportMapping::FALLBACK_APPROVED )->plan( 7, array( 'wpmem_status' => 'dziwne' ) );

		$this->assertNull( $change->status );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_STATUS_UNMAPPED ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_STATUS_MISSING ) );
	}

	public function test_brak_stanu_w_zrodle_bierze_fallback(): void {
		$change = $this->planner( ImportMapping::FALLBACK_PENDING )->plan( 7, array() );

		$this->assertSame( AccountStatus::Pending, $change->status );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_STATUS_MISSING ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_STATUS_UNMAPPED ) );
	}

	public function test_pusty_stan_w_zrodle_bierze_fallback_zatwierdzony(): void {
		$change = $this->planner( ImportMapping::FALLBACK_APPROVED )->plan( 7, array( 'wpmem_status' => ' ' ) );

		$this->assertSame( AccountStatus::Approved, $change->status );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_STATUS_MISSING ) );
	}

	public function test_fallback_none_zostawia_konto_bez_stanu(): void {
		$change = $this->planner( ImportMapping::FALLBACK_NONE )->plan( 7, array() );

		$this->assertNull( $change->status );
		$this->assertTrue( $change->hasIssue( AccountChange::ISSUE_STATUS_MISSING ) );
	}

	public function test_konto_bez_zadnych_danych_nie_ma_zapisow(): void {
		$change = $this->planner()->plan( 7, array() );

		$this->assertFalse( $change->hasWrites() );
		$this->assertSame( 7, $change->userId );
	}

	public function test_mapowanie_samych_pol_nie_zglasza_zadnych_zastrzezen_o_profesji_i_stanie(): void {
		$planner = new ImportPlanner(
			ImportMapping::fromArray( array( 'fields' => array( 'name' => 'wpmem_name' ) ) ),
			ProfessionCollection::fromArray( array() ),
			FieldMatrix::fromArray( array() ),
			new ValidatorRegistry()
		);

		$change = $planner->plan( 7, array( 'wpmem_name' => 'Ola' ) );

		$this->assertSame( array( 'name' => 'Ola' ), $change->fields );
		$this->assertNull( $change->professionKey );
		$this->assertNull( $change->status );
		$this->assertSame( array(), $change->issues );
	}

	public function test_pusty_klucz_stanu_z_fallbackiem_zatwierdzonym_zatwierdza_po_cichu(): void {
		$planner = new ImportPlanner(
			ImportMapping::fromArray(
				array(
					'fields'   => array( 'name' => 'wpmem_name' ),
					'fallback' => ImportMapping::FALLBACK_APPROVED,
				)
			),
			ProfessionCollection::fromArray( array() ),
			FieldMatrix::fromArray( array() ),
			new ValidatorRegistry()
		);

		$change = $planner->plan( 7, array( 'wpmem_name' => 'Ola' ) );

		$this->assertSame( AccountStatus::Approved, $change->status );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_STATUS_MISSING ) );
		$this->assertFalse( $change->hasIssue( AccountChange::ISSUE_STATUS_UNMAPPED ) );
		$this->assertSame( array(), $change->issues );
	}
}
