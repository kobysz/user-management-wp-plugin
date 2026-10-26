<?php
/**
 * Komunikat o błędach spójności konfiguracji na ekranach profesji i ustawień.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

use PortalUsers\Domain\Config\SettingsIssue;

defined( 'ABSPATH' ) || exit;

/**
 * Raport spójności żyje na ekranie macierzy, ale administrator, który doda profesję i nigdy
 * nie otworzy macierzy, nie zobaczyłby błędu (profesja bez wiersza przepuszcza zgłoszenie
 * bez numeru uprawnień). Ten komunikat pokazuje tylko liczbę błędów i odnośnik do raportu;
 * ostrzeżeń nie liczy, a bez błędów nie wypisuje niczego.
 */
final class ConsistencyNotice {

	/**
	 * Liczba błędów raportu spójności (bez ostrzeżeń).
	 */
	public static function error_count(): int {
		$errors = array_filter(
			MatrixScreen::checker()->issues(),
			static fn ( SettingsIssue $issue ): bool => SettingsIssue::ERROR === $issue->severity
		);
		return count( $errors );
	}

	/**
	 * Wypisuje komunikat błędu z liczbą i odnośnikiem do ekranu macierzy; przy zerze błędów nic.
	 */
	public static function render(): void {
		$count = self::error_count();
		if ( 0 === $count ) {
			return;
		}

		echo '<div class="notice notice-error pusers-consistency-notice"><p>'
			. esc_html(
				sprintf(
					/* translators: %d: liczba błędów. */
					_n(
						'Konfiguracja jest niespójna: wykryto %d błąd. Dopóki go nie poprawisz, rejestracja niektórych profesji może przepuszczać konta bez numeru uprawnień albo być zablokowana.',
						'Konfiguracja jest niespójna: wykryto %d błędów. Dopóki ich nie poprawisz, rejestracja niektórych profesji może przepuszczać konta bez numeru uprawnień albo być zablokowana.',
						$count,
						'portal-users'
					),
					$count
				)
			)
			. ' <a href="' . esc_url( Menu::url( Menu::SLUG_MATRIX ) ) . '">'
			. esc_html__( 'Zobacz raport w macierzy pól', 'portal-users' )
			. '</a></p></div>';
	}
}
