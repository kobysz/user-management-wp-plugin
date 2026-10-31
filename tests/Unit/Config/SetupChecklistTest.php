<?php
/**
 * Testy: SetupChecklist.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Config\SetupChecklist;
use PortalUsers\Domain\Config\SetupFacts;
use PortalUsers\Domain\Config\SetupStep;
use PortalUsers\Domain\Config\SetupStepKey;

final class SetupChecklistTest extends TestCase {

	/**
	 * Fakty świeżej instalacji z nadpisaniem pojedynczych pól argumentami nazwanymi.
	 *
	 * Świeża instalacja ma ACF Pro na miejscu (`acfReadable`), bo to stan, w którym
	 * administrator zaczyna konfigurację; brak ACF Pro jest osobnym przypadkiem.
	 */
	private function facts(
		bool $acfReadable = true,
		bool $anyAcfGroup = false,
		bool $groupSelected = false,
		bool $groupReadable = false,
		bool $groupHasFields = false,
		bool $anyProfession = false,
		bool $matrixErrorFree = false,
		bool $shortcodePagesPublished = false,
		bool $afterLoginPageSet = false,
		bool $notifyEmailsPresent = false,
		bool $rejectionReasonsNeeded = false,
		bool $rejectionReasonsPresent = false,
		bool $defaultRoleOk = false,
		int $statelessAccounts = 0
	): SetupFacts {
		return new SetupFacts(
			$acfReadable,
			$anyAcfGroup,
			$groupSelected,
			$groupReadable,
			$groupHasFields,
			$anyProfession,
			$matrixErrorFree,
			$shortcodePagesPublished,
			$afterLoginPageSet,
			$notifyEmailsPresent,
			$rejectionReasonsNeeded,
			$rejectionReasonsPresent,
			$defaultRoleOk,
			$statelessAccounts
		);
	}

	/**
	 * Fakty portalu skonfigurowanego do końca.
	 */
	private function complete( int $statelessAccounts = 0 ): SetupFacts {
		return $this->facts(
			anyAcfGroup: true,
			groupSelected: true,
			groupReadable: true,
			groupHasFields: true,
			anyProfession: true,
			matrixErrorFree: true,
			shortcodePagesPublished: true,
			afterLoginPageSet: true,
			notifyEmailsPresent: true,
			defaultRoleOk: true,
			statelessAccounts: $statelessAccounts
		);
	}

	/**
	 * Stany kroków: wartość klucza kroku => status.
	 *
	 * @return array<string, string>
	 */
	private function statuses( SetupFacts $facts ): array {
		$out = array();
		foreach ( ( new SetupChecklist( $facts ) )->steps() as $step ) {
			$out[ $step->key->value ] = $step->status;
		}

		return $out;
	}

	/**
	 * Podpowiedzi kroku.
	 *
	 * @return list<string>
	 */
	private function hintsOf( SetupFacts $facts, SetupStepKey $key ): array {
		foreach ( ( new SetupChecklist( $facts ) )->steps() as $step ) {
			if ( $step->key === $key ) {
				return $step->hints;
			}
		}

		return array();
	}

	public function test_swieza_instalacja_ma_szesc_krokow_do_zrobienia(): void {
		$statuses = $this->statuses( $this->facts() );

		$this->assertSame(
			array(
				SetupStepKey::AcfGroup->value      => SetupStep::TODO,
				SetupStepKey::GroupSelected->value => SetupStep::TODO,
				SetupStepKey::Professions->value   => SetupStep::TODO,
				SetupStepKey::Matrix->value        => SetupStep::TODO,
				SetupStepKey::Pages->value         => SetupStep::TODO,
				SetupStepKey::Settings->value      => SetupStep::TODO,
				SetupStepKey::MailTemplates->value => SetupStep::INFO,
			),
			$statuses,
			'Świeża instalacja: sześć kroków do zrobienia, szablony maili bez stanu, ósmy krok nieobecny.'
		);
		$this->assertFalse( ( new SetupChecklist( $this->facts() ) )->isComplete() );
	}

	public function test_bez_acf_pro_kroki_zalezne_od_acf_sa_niemozliwe_do_sprawdzenia(): void {
		$facts    = $this->facts( acfReadable: false, anyProfession: true, matrixErrorFree: true );
		$statuses = $this->statuses( $facts );

		$this->assertSame( SetupStep::UNKNOWN, $statuses[ SetupStepKey::AcfGroup->value ] );
		$this->assertSame( SetupStep::UNKNOWN, $statuses[ SetupStepKey::GroupSelected->value ] );
		$this->assertSame( SetupStep::UNKNOWN, $statuses[ SetupStepKey::Matrix->value ], 'Bez ACF Pro raport spójności nie ma czym zmierzyć macierzy, więc krok nie jest ani zrobiony, ani niezrobiony.' );
		$this->assertFalse( ( new SetupChecklist( $facts ) )->isComplete(), 'Nie można sprawdzić nie jest tym samym co zrobione.' );
	}

	public function test_pelna_konfiguracja_jest_skonczona(): void {
		$facts    = $this->complete();
		$statuses = $this->statuses( $facts );

		foreach ( array( SetupStepKey::AcfGroup, SetupStepKey::GroupSelected, SetupStepKey::Professions, SetupStepKey::Matrix, SetupStepKey::Pages, SetupStepKey::Settings ) as $key ) {
			$this->assertSame( SetupStep::DONE, $statuses[ $key->value ], $key->value . ' powinien być zrobiony przy pełnej konfiguracji.' );
		}
		$this->assertTrue( ( new SetupChecklist( $facts ) )->isComplete() );
	}

	public function test_krok_macierzy_nie_jest_zrobiony_bez_profesji(): void {
		$facts = $this->facts( anyAcfGroup: true, groupSelected: true, groupReadable: true, groupHasFields: true, matrixErrorFree: true );

		$this->assertSame( SetupStep::TODO, $this->statuses( $facts )[ SetupStepKey::Matrix->value ], 'Brak profesji oznacza, że macierz nie ma ani jednej kolumny do ustawienia.' );
	}

	public function test_blad_macierzy_trzyma_krok_na_czerwono(): void {
		$facts = $this->facts(
			anyAcfGroup: true,
			groupSelected: true,
			groupReadable: true,
			groupHasFields: true,
			anyProfession: true,
			matrixErrorFree: false,
			shortcodePagesPublished: true,
			afterLoginPageSet: true,
			notifyEmailsPresent: true,
			defaultRoleOk: true
		);

		$this->assertSame( SetupStep::TODO, $this->statuses( $facts )[ SetupStepKey::Matrix->value ] );
		$this->assertFalse( ( new SetupChecklist( $facts ) )->isComplete() );
	}

	public function test_zablokowana_rola_domyslna_trzyma_krok_ustawien_na_czerwono(): void {
		$facts = $this->facts(
			anyAcfGroup: true,
			groupSelected: true,
			groupReadable: true,
			groupHasFields: true,
			anyProfession: true,
			matrixErrorFree: true,
			shortcodePagesPublished: true,
			afterLoginPageSet: true,
			notifyEmailsPresent: true,
			defaultRoleOk: false
		);

		$this->assertSame( SetupStep::TODO, $this->statuses( $facts )[ SetupStepKey::Settings->value ], 'Zablokowana rola domyślna wyłącza rejestrację, więc ustawienia nie są skończone.' );
		$this->assertFalse( ( new SetupChecklist( $facts ) )->isComplete() );
	}

	public function test_brak_strony_po_zalogowaniu_jest_podpowiedzia_nie_bledem_kroku(): void {
		$facts = $this->facts( shortcodePagesPublished: true, afterLoginPageSet: false );

		$this->assertSame( SetupStep::DONE, $this->statuses( $facts )[ SetupStepKey::Pages->value ], 'Trzy strony z shortcode’ami wystarczą, żeby krok był zrobiony.' );
		$this->assertSame( array( SetupStep::HINT_AFTER_LOGIN_PAGE_UNSET ), $this->hintsOf( $facts, SetupStepKey::Pages ) );
		$this->assertSame( array(), $this->hintsOf( $this->facts( shortcodePagesPublished: true, afterLoginPageSet: true ), SetupStepKey::Pages ) );
	}

	public function test_puste_powody_odrzucenia_sa_podpowiedzia_tylko_przy_wlaczonej_akceptacji(): void {
		$withApproval = $this->facts( rejectionReasonsNeeded: true, rejectionReasonsPresent: false );
		$this->assertSame( array( SetupStep::HINT_REJECTION_REASONS_EMPTY ), $this->hintsOf( $withApproval, SetupStepKey::Settings ) );

		$withoutApproval = $this->facts( rejectionReasonsNeeded: false, rejectionReasonsPresent: false );
		$this->assertSame( array(), $this->hintsOf( $withoutApproval, SetupStepKey::Settings ), 'Przy wyłączonej akceptacji nikt nie odrzuca wniosków, więc brak powodów nie jest brakiem.' );

		$filled = $this->facts( rejectionReasonsNeeded: true, rejectionReasonsPresent: true );
		$this->assertSame( array(), $this->hintsOf( $filled, SetupStepKey::Settings ) );
	}

	public function test_konta_sprzed_wdrozenia_pokazuja_osmy_krok(): void {
		$statuses = $this->statuses( $this->facts( statelessAccounts: 3 ) );
		$this->assertArrayHasKey( SetupStepKey::LegacyAccounts->value, $statuses );
		$this->assertSame( SetupStep::TODO, $statuses[ SetupStepKey::LegacyAccounts->value ] );

		$this->assertArrayNotHasKey(
			SetupStepKey::LegacyAccounts->value,
			$this->statuses( $this->facts( statelessAccounts: 0 ) ),
			'Portal bez kont sprzed wdrożenia nie ma po co widzieć tego kroku.'
		);
	}

	public function test_szablony_maili_nigdy_nie_blokuja_ukonczenia(): void {
		$this->assertSame( SetupStep::INFO, $this->statuses( $this->facts() )[ SetupStepKey::MailTemplates->value ] );
		$this->assertSame( SetupStep::INFO, $this->statuses( $this->complete( 5 ) )[ SetupStepKey::MailTemplates->value ] );
		$this->assertTrue( ( new SetupChecklist( $this->complete( 5 ) ) )->isComplete(), 'Ani szablony maili, ani konta sprzed wdrożenia nie wchodzą do warunku ukończenia.' );
	}
}
