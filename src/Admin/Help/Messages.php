<?php
/**
 * Część instrukcji: ściąga komunikatów.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin\Help;

defined( 'ABSPATH' ) || exit;

/**
 * Tabela „komunikat → przyczyna”. Osobna część, bo czyta się ją od komunikatu, a nie od
 * początku: ktoś przychodzi tu z tekstem, który zobaczył na ekranie.
 *
 * Komunikaty podane są w formie skróconej, z wielokropkiem w miejscach, w których wtyczka
 * wstawia klucz profesji albo nazwę pola.
 */
final class Messages {

	public const ANCHOR = 'pusers-help-messages';

	/**
	 * Nagłówek części.
	 */
	public static function heading(): string {
		return __( 'Najczęstsze komunikaty', 'portal-users' );
	}

	/**
	 * Wypisuje część.
	 */
	public static function render(): void {
		printf( '<h2 id="%1$s">%2$s</h2>', esc_attr( self::ANCHOR ), esc_html( self::heading() ) );
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Komunikat', 'portal-users' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Przyczyna', 'portal-users' ) . '</th>';
		echo '</tr></thead><tbody>';

		self::row(
			__( 'Profesja … nie ma wiersza w macierzy pól', 'portal-users' ),
			__( 'Macierz nie jest wypełniona dla tej profesji. Gdy jednocześnie macierz mówi, że nie wskazano grupy pól, prawdziwą przyczyną jest brak wskazanej grupy — macierz nie ma wtedy z czego zbudować wierszy.', 'portal-users' )
		);
		self::row(
			__( 'Wybranej grupy pól nie wskazano albo nie istnieje już w ACF', 'portal-users' ),
			__( 'W ustawieniach nie ma wskazanej grupy pól albo wskazana grupa została usunięta lub przemianowana w ACF.', 'portal-users' )
		);
		self::row(
			__( 'Wybrana grupa pól nie zawiera żadnych pól', 'portal-users' ),
			__( 'Grupa jest wskazana, ale pusta — albo ma wyłącznie pola typów, których wtyczka nie obsługuje (kontenerowe i układowe).', 'portal-users' )
		);
		self::row(
			__( 'Profesja … wymaga numeru uprawnień, ale …', 'portal-users' ),
			__( 'Przy profesji nie wskazano pola numeru albo to pole nie ma w macierzy stanu „Wymagane”. Zgłoszenie mogłoby wtedy przejść bez numeru.', 'portal-users' )
		);
		self::row(
			__( '… wskazane pole … jest w ACF typu …, którego wtyczka nie przyjmuje jako pola numeru', 'portal-users' ),
			__( 'Numerem uprawnień musi być pole jednowartościowe tekstowe albo liczbowe. Zmień typ pola w ACF albo wskaż inne pole przy profesji.', 'portal-users' )
		);
		self::row(
			__( 'Rejestracja profesji … jest zablokowana: macierz oznacza jako wymagane pole …, którego nie ma w wybranej grupie pól', 'portal-users' ),
			__( 'Pole zniknęło z grupy albo zostało przemianowane, a w macierzy zostało wymagane. Wymóg jest niespełnialny, bo formularz nie pokaże pola, którego nie ma w źródle. Popraw wpis w macierzy albo wróć polem do grupy.', 'portal-users' )
		);
		self::row(
			__( 'Macierz zawiera pole …, którego nie ma w wybranej grupie pól', 'portal-users' ),
			__( 'Martwy wiersz po polu usuniętym albo przemianowanym w ACF. Macierz nie czyści się sama; wpisy usuwa jawna akcja w sekcji martwych pól.', 'portal-users' )
		);
		self::row(
			__( 'Rejestracja jest wyłączona: domyślna rola …', 'portal-users' ),
			__( 'Ustawienia → Ogólne: domyślna rola nowych kont ma uprawnienie, którego publiczny formularz nie może nadać, albo tej roli nie ma na witrynie.', 'portal-users' )
		);
		self::row(
			__( 'Rejestracja jest chwilowo niedostępna (na stronie portalu)', 'portal-users' ),
			__( 'Nie wskazano grupy pól albo nie ma ani jednej profesji. Formularz nie ma z czego powstać.', 'portal-users' )
		);
		self::row(
			__( 'Wtyczka Portal Users wymaga ACF Pro', 'portal-users' ),
			__( 'ACF Pro jest wyłączony, nieobecny albo aktywny jest darmowy ACF, który nie wystarcza. Do jego włączenia widoczne są tylko ten ekran i ekran wniosków.', 'portal-users' )
		);
		self::row(
			__( 'Wtyczka Portal Users nie może wystartować: brakuje katalogu vendor', 'portal-users' ),
			__( 'Wtyczkę zainstalowano z archiwum „Source code”, a nie z assetu portal-users.zip z wydania. Zainstaluj ją z właściwego archiwum.', 'portal-users' )
		);

		echo '</tbody></table>';
	}

	/**
	 * Wiersz tabeli.
	 *
	 * @param string $message Komunikat.
	 * @param string $cause   Przyczyna.
	 */
	private static function row( string $message, string $cause ): void {
		printf(
			'<tr><td>%1$s</td><td>%2$s</td></tr>',
			esc_html( $message ),
			esc_html( $cause )
		);
	}
}
