<?php
/**
 * Odczyt faktów o stanie konfiguracji dla listy startowej.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Account\Backfill;
use PortalUsers\Acf\AcfGroupList;
use PortalUsers\Dependencies;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Config\SettingsIssue;
use PortalUsers\Domain\Config\SetupFacts;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;

/**
 * Zbiera w jedno miejsce wszystko, co o stanie konfiguracji wie WordPress, ACF i repozytoria
 * wtyczki, i oddaje to jako `SetupFacts`. Reguły kroków są czystą domeną i nie mogą tu sięgać;
 * ten sam podział ma dziś `MatrixScreen::issues()`, które dopina regułę o roli domyślnej do
 * wyniku `ConsistencyChecker`.
 */
final class SetupFactsReader {

	/**
	 * Kody problemów raportu, które znaczą „macierz albo pole numeru są niegotowe”.
	 *
	 * Świadomie węższa lista niż cały raport: krok macierzy nie ma świecić na czerwono
	 * z powodu nieopublikowanej strony ani zablokowanej roli domyślnej — te mają własne kroki.
	 */
	private const MATRIX_ERROR_CODES = array(
		'profession_without_matrix_row',
		'credential_field_not_required',
		'credential_field_wrong_type',
		'matrix_required_field_not_in_source',
	);

	/**
	 * Klucze ustawień trzech stron, które muszą istnieć, żeby portal przyjmował zgłoszenia.
	 */
	private const SHORTCODE_PAGE_KEYS = array( 'login_page_id', 'register_page_id', 'reset_page_id' );

	/**
	 * Fakty o bieżącej konfiguracji.
	 *
	 * @param bool $withLegacyAccounts Czy policzyć konta sprzed wdrożenia bez stanu. Linia na ekranie
	 *                                 ustawień czyta tylko kroki 1-6, a liczenie kont to zapytanie
	 *                                 po użytkownikach, które robi już `BackfillNotice`.
	 */
	public static function read( bool $withLegacyAccounts = true ): SetupFacts {
		$settings = SettingsRepository::get();
		$groups   = AcfGroupList::groups();
		// Jedna instancja źródła na wywołanie: `groupExists()` i `fields()` mają opisywać tę samą
		// migawkę definicji, a każde wywołanie `source()` buduje nową.
		$source = MatrixScreen::source();
		$issues = MatrixScreen::issues();

		return new SetupFacts(
			Dependencies::satisfied(),
			function_exists( 'acf_get_field_groups' ) || array() !== $groups,
			array() !== $groups,
			'' !== $settings->acfGroupKey,
			$source->groupExists(),
			array() !== $source->fields(),
			array() !== ProfessionRepository::get()->all(),
			self::matrix_error_free( $issues ),
			self::shortcode_pages_published( $settings ),
			self::after_login_page_set( $settings ),
			array() !== $settings->notifyEmails,
			$settings->approvalEnabled,
			array() !== $settings->rejectionReasons,
			! RegistrationHandler::default_role_state()['blocked'],
			$withLegacyAccounts ? Backfill::stateless_count() : 0
		);
	}

	/**
	 * Czy raport nie ma błędów dotyczących macierzy i pól numeru.
	 *
	 * @param array<int, SettingsIssue> $issues Problemy raportu spójności.
	 */
	private static function matrix_error_free( array $issues ): bool {
		foreach ( $issues as $issue ) {
			if ( SettingsIssue::ERROR === $issue->severity && in_array( $issue->code, self::MATRIX_ERROR_CODES, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Czy trzy strony z shortcode'ami są wskazane i opublikowane.
	 *
	 * @param Settings $settings Ustawienia wtyczki.
	 */
	private static function shortcode_pages_published( Settings $settings ): bool {
		$ids        = array(
			'login_page_id'    => $settings->loginPageId,
			'register_page_id' => $settings->registerPageId,
			'reset_page_id'    => $settings->resetPageId,
		);
		$pageIssues = SettingsScreen::page_issues();
		foreach ( self::SHORTCODE_PAGE_KEYS as $key ) {
			if ( $ids[ $key ] <= 0 || isset( $pageIssues[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Czy strona po zalogowaniu jest wskazana i opublikowana.
	 *
	 * @param Settings $settings Ustawienia wtyczki.
	 */
	private static function after_login_page_set( Settings $settings ): bool {
		return $settings->afterLoginPageId > 0 && ! isset( SettingsScreen::page_issues()['after_login_page_id'] );
	}
}
