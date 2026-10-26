<?php
/**
 * Tłumaczenie kodów problemów konfiguracji na komunikaty dla administratora.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Config\SettingsIssue;

/**
 * Jedyne miejsce, które zamienia kody domeny na teksty. Kody problemów i powody
 * mapowane są OSOBNO: stała SettingsIssue::REASON_CREDENTIAL_FIELD_NOT_REQUIRED ma tę
 * samą wartość co kod `credential_field_not_required`, więc wspólna tablica pomyliłaby
 * oba pojęcia bez żadnego błędu.
 *
 * Zwracany tekst nie jest eskejpowany; robi to miejsce wyświetlenia.
 */
final class IssueMessages {

	/**
	 * Komunikat dla problemu.
	 *
	 * @param SettingsIssue $issue Problem wykryty w konfiguracji.
	 */
	public static function text( SettingsIssue $issue ): string {
		$profession = $issue->context['profession'] ?? '';
		$field      = $issue->context['field'] ?? '';
		$page       = $issue->context['page'] ?? '';

		switch ( $issue->code ) {
			case 'profession_without_matrix_row':
				/* translators: %s: klucz profesji. */
				return sprintf( __( 'Profesja „%s” nie ma wiersza w macierzy pól.', 'portal-users' ), $profession );
			case 'credential_field_not_required':
				/* translators: 1: klucz profesji, 2: powód. */
				return sprintf( __( 'Profesja „%1$s” wymaga numeru uprawnień, ale %2$s', 'portal-users' ), $profession, self::reason_text( $issue ) );
			case 'field_not_configured':
				/* translators: 1: klucz profesji, 2: klucz pola. */
				return sprintf( __( 'Pole „%2$s” nie ma ustawionego stanu dla profesji „%1$s”.', 'portal-users' ), $profession, $field );
			case 'matrix_field_not_in_source':
				/* translators: 1: klucz profesji, 2: klucz pola. */
				return sprintf( __( 'Macierz profesji „%1$s” zawiera pole „%2$s”, którego nie ma w wybranej grupie pól.', 'portal-users' ), $profession, $field );
			case 'matrix_required_field_not_in_source':
				/* translators: 1: klucz profesji, 2: klucz pola. */
				return sprintf( __( 'Rejestracja profesji „%1$s” jest zablokowana: macierz oznacza jako wymagane pole „%2$s”, którego nie ma w wybranej grupie pól, więc formularz go nie pokaże. Zmień ten wpis w macierzy albo dodaj pole do grupy.', 'portal-users' ), $profession, $field );
			case 'matrix_profession_unknown':
				/* translators: %s: klucz profesji. */
				return sprintf( __( 'Macierz zawiera wiersz dla nieistniejącej profesji „%s”.', 'portal-users' ), $profession );
			case 'page_missing':
				/* translators: %s: nazwa ustawienia strony. */
				return sprintf( __( '%s: wskazana strona nie istnieje.', 'portal-users' ), $page );
			case 'page_not_published':
				/* translators: %s: nazwa ustawienia strony. */
				return sprintf( __( '%s: wskazana strona nie jest opublikowana.', 'portal-users' ), $page );
			default:
				/* translators: %s: kod problemu. */
				return sprintf( __( 'Nierozpoznany problem konfiguracji (%s).', 'portal-users' ), $issue->code );
		}
	}

	/**
	 * Powód niespełnienia wymogu numeru uprawnień; osobna mapa niż kody problemów.
	 *
	 * @param SettingsIssue $issue Problem z powodem w kontekście.
	 */
	private static function reason_text( SettingsIssue $issue ): string {
		$reason = $issue->context['reason'] ?? '';
		$field  = $issue->context['field'] ?? '';

		switch ( $reason ) {
			case SettingsIssue::REASON_CREDENTIAL_FIELD_UNSET:
				return __( 'nie wskazano pola, w którym podaje się ten numer.', 'portal-users' );
			case SettingsIssue::REASON_CREDENTIAL_FIELD_NOT_REQUIRED:
				/* translators: %s: klucz pola. */
				return sprintf( __( 'pole „%s” nie jest w macierzy oznaczone jako wymagane.', 'portal-users' ), $field );
			default:
				return __( 'pole z numerem nie jest wymagane.', 'portal-users' );
		}
	}
}
