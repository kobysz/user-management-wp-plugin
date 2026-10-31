<?php
/**
 * Reguły listy startowej: fakty o konfiguracji na stany kroków.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

/**
 * Lista startowa odpowiada na jedno pytanie: GDZIE JESTEŚ w konfiguracji. Nie odpowiada na
 * pytanie, co jest nie tak — to robi `ConsistencyChecker` i jego raport. Dlatego krok niesie
 * stan i co najwyżej podpowiedź, a nigdy tekstu problemu.
 *
 * Kolejność kroków jest kolejnością `SetupStepKey`, czyli kolejnością z `docs/konfiguracja.md`.
 */
final class SetupChecklist {

	/**
	 * Kroki, których ukończenie decyduje o skończonej konfiguracji.
	 *
	 * Bez szablonów maili (mają treści domyślne i działają nietknięte) oraz bez kont sprzed
	 * wdrożenia (dotyczą tylko portalu, który już działał, i pokazują się warunkowo).
	 */
	private const REQUIRED = array(
		SetupStepKey::AcfGroup,
		SetupStepKey::GroupSelected,
		SetupStepKey::Professions,
		SetupStepKey::Matrix,
		SetupStepKey::Pages,
		SetupStepKey::Settings,
	);

	/**
	 * Konstruktor.
	 *
	 * @param SetupFacts $facts Fakty o stanie konfiguracji.
	 */
	public function __construct( private SetupFacts $facts ) {}

	/**
	 * Kroki w kolejności z dokumentu. Ósmy krok tylko wtedy, gdy ma o czym mówić.
	 *
	 * @return list<SetupStep>
	 */
	public function steps(): array {
		$steps = array(
			new SetupStep( SetupStepKey::AcfGroup, $this->acfGroupStatus(), array() ),
			new SetupStep( SetupStepKey::GroupSelected, $this->groupSelectedStatus(), array() ),
			new SetupStep( SetupStepKey::Professions, $this->facts->anyProfession ? SetupStep::DONE : SetupStep::TODO, array() ),
			new SetupStep( SetupStepKey::Matrix, $this->matrixStatus(), array() ),
			new SetupStep( SetupStepKey::Pages, $this->facts->shortcodePagesPublished ? SetupStep::DONE : SetupStep::TODO, $this->pagesHints() ),
			new SetupStep( SetupStepKey::Settings, $this->settingsStatus(), $this->settingsHints() ),
			new SetupStep( SetupStepKey::MailTemplates, SetupStep::INFO, array() ),
		);

		if ( $this->facts->statelessAccounts > 0 ) {
			$steps[] = new SetupStep( SetupStepKey::LegacyAccounts, SetupStep::TODO, array() );
		}

		return $steps;
	}

	/**
	 * Czy konfiguracja jest skończona: każdy wymagany krok zrobiony.
	 *
	 * `UNKNOWN` nie jest ukończeniem. Bez ACF Pro nie wiemy, czy grupa pól i macierz są
	 * w porządku, a „nie wiemy” nie uprawnia do powiedzenia administratorowi, że skończył.
	 */
	public function isComplete(): bool {
		foreach ( $this->steps() as $step ) {
			if ( in_array( $step->key, self::REQUIRED, true ) && SetupStep::DONE !== $step->status ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Stan kroku „grupa pól w ACF”: istnieje w ogóle jakakolwiek grupa.
	 */
	private function acfGroupStatus(): string {
		if ( ! $this->facts->acfReadable ) {
			return SetupStep::UNKNOWN;
		}

		return $this->facts->anyAcfGroup ? SetupStep::DONE : SetupStep::TODO;
	}

	/**
	 * Stan kroku „wskazanie grupy”: grupa wskazana, nadal istnieje i ma czym wypełnić formularz.
	 */
	private function groupSelectedStatus(): string {
		if ( ! $this->facts->acfReadable ) {
			return SetupStep::UNKNOWN;
		}
		$ready = $this->facts->groupSelected && $this->facts->groupReadable && $this->facts->groupHasFields;

		return $ready ? SetupStep::DONE : SetupStep::TODO;
	}

	/**
	 * Macierz jest zrobiona, gdy jest dla kogo ją wypełnić i raport nie ma o niej błędów.
	 *
	 * Brak profesji daje „do zrobienia”, a nie „zrobione”: macierz bez kolumn nie jest
	 * macierzą wypełnioną, tylko macierzą, której nie ma czym wypełnić.
	 */
	private function matrixStatus(): string {
		if ( ! $this->facts->acfReadable ) {
			return SetupStep::UNKNOWN;
		}

		return $this->facts->anyProfession && $this->facts->matrixErrorFree ? SetupStep::DONE : SetupStep::TODO;
	}

	/**
	 * Stan kroku „pozostałe ustawienia”: jest komu wysłać powiadomienie i rola domyślna nie blokuje rejestracji.
	 */
	private function settingsStatus(): string {
		return $this->facts->notifyEmailsPresent && $this->facts->defaultRoleOk ? SetupStep::DONE : SetupStep::TODO;
	}

	/**
	 * Podpowiedzi kroku stron: brak strony po zalogowaniu nie blokuje portalu, ale warto o nim wiedzieć.
	 *
	 * @return list<string>
	 */
	private function pagesHints(): array {
		return $this->facts->afterLoginPageSet ? array() : array( SetupStep::HINT_AFTER_LOGIN_PAGE_UNSET );
	}

	/**
	 * Podpowiedzi kroku ustawień: puste powody odrzucenia mają znaczenie tylko przy włączonej akceptacji.
	 *
	 * @return list<string>
	 */
	private function settingsHints(): array {
		if ( $this->facts->rejectionReasonsNeeded && ! $this->facts->rejectionReasonsPresent ) {
			return array( SetupStep::HINT_REJECTION_REASONS_EMPTY );
		}

		return array();
	}
}
