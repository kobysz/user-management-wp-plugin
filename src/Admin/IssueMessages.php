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
		$role       = $issue->context['role'] ?? '';
		$capability = $issue->context['capability'] ?? '';
		$type       = $issue->context['type'] ?? '';

		switch ( $issue->code ) {
			case 'profession_without_matrix_row':
				/* translators: %s: klucz profesji. */
				return sprintf( __( 'Profesja „%s” nie ma wiersza w macierzy pól.', 'portal-users' ), $profession );
			case 'credential_field_not_required':
				/* translators: 1: klucz profesji, 2: powód. */
				return sprintf( __( 'Profesja „%1$s” wymaga numeru uprawnień, ale %2$s', 'portal-users' ), $profession, self::reason_text( $issue ) );
			case 'credential_field_wrong_type':
				/* translators: 1: klucz profesji, 2: klucz pola, 3: typ pola w ACF. */
				return sprintf( __( 'Profesja „%1$s” wymaga numeru uprawnień, a wskazane pole „%2$s” jest w ACF typu „%3$s”. Numer uprawnień musi być polem jednowartościowym tekstowym (typ „text” albo „number”): pole wielowartościowe sprawia, że nikt z tej profesji nie zarejestruje się nigdy, a pole logiczne daje numer o wartości „1”. Zmień typ pola w ACF albo wskaż inne pole przy profesji.', 'portal-users' ), $profession, $field, $type );
			case 'field_not_configured':
				/* translators: 1: klucz profesji, 2: klucz pola. */
				return sprintf( __( 'Pole „%2$s” nie ma ustawionego stanu dla profesji „%1$s”.', 'portal-users' ), $profession, $field );
			case 'matrix_field_not_in_source':
				/* translators: 1: klucz profesji, 2: klucz pola. */
				return sprintf( __( 'Macierz profesji „%1$s” zawiera pole „%2$s”, którego nie ma w wybranej grupie pól. Pole może też istnieć w ACF, ale mieć typ nieobsługiwany przez wtyczkę (kontener albo typ układowy).', 'portal-users' ), $profession, $field );
			case 'matrix_required_field_not_in_source':
				/* translators: 1: klucz profesji, 2: klucz pola. */
				return sprintf( __( 'Rejestracja profesji „%1$s” jest zablokowana: macierz oznacza jako wymagane pole „%2$s”, którego nie ma w wybranej grupie pól, więc formularz go nie pokaże. Zmień ten wpis w macierzy albo dodaj pole do grupy. Pole może też istnieć w ACF, ale mieć typ nieobsługiwany przez wtyczkę (kontener albo typ układowy).', 'portal-users' ), $profession, $field );
			case 'matrix_profession_unknown':
				/* translators: %s: klucz profesji. */
				return sprintf( __( 'Macierz zawiera wiersz dla nieistniejącej profesji „%s”.', 'portal-users' ), $profession );
			case 'default_role_dangerous_capability':
				/* translators: 1: nazwa roli, 2: nazwa uprawnienia. */
				return sprintf( __( 'Rejestracja jest wyłączona: domyślna rola nowych kont („%1$s”) ma uprawnienie „%2$s”, którego publiczny formularz nie może nadać osobie zakładającej konto. Wskaż inną rolę domyślną w Ustawieniach → Ogólne albo odbierz tej roli to uprawnienie.', 'portal-users' ), $role, $capability );
			case 'default_role_unknown':
				/* translators: %s: nazwa roli z ustawień. */
				return sprintf( __( 'Rejestracja jest wyłączona: domyślnej roli nowych kont („%s”) nie ma na tej witrynie. Wskaż istniejącą rolę w Ustawieniach → Ogólne.', 'portal-users' ), $role );
			case 'terms_version_not_recorded':
				return __( 'Portal wymaga od użytkowników zgody, ale wersja regulaminu nie jest zapisywana, więc nie będzie czym wykazać, na co się zgodzili. Wpisz treść regulaminu i wskaż pole zgody (pole logiczne z wybranej grupy) w Ustawieniach → Ogólne.', 'portal-users' );
			case 'purge_window_too_long':
				/* translators: 1: okno w dniach, 2: największe rozsądne okno w dniach. */
				return sprintf( __( 'Niepotwierdzone konta są usuwane po %1$s dniach. Okno dłuższe niż %2$s dni praktycznie wyłącza czyszczenie, a adres porzuconej rejestracji pozostaje zajęty. Skróć okno w Ustawieniach → Ogólne.', 'portal-users' ), $issue->context['days'] ?? '', $issue->context['max'] ?? '' );
			case 'purge_window_shorter_than_link':
				/* translators: 1: okno w dniach, 2: ważność linku w godzinach. */
				return sprintf( __( 'Niepotwierdzone konta są usuwane po %1$s dniach, a link potwierdzający jest ważny %2$s godz.: użytkownik mógłby kliknąć ważny link do konta, które już usunięto. Wydłuż okno albo skróć ważność linku w Ustawieniach → Ogólne.', 'portal-users' ), $issue->context['days'] ?? '', $issue->context['hours'] ?? '' );
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
