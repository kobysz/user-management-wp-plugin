<?php
/**
 * Część instrukcji: zmiana formularza na żywym portalu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin\Help;

defined( 'ABSPATH' ) || exit;

/**
 * Najgroźniejsza z trzech czynności, bo przechodzi przez dwa ekrany w narzuconej kolejności
 * i zrobiona w połowie nie daje żadnego błędu w ACF — formularz po prostu zachowuje się
 * inaczej, niż redakcja zakłada. Ta część mówi, czego nie widać z ekranu ACF.
 */
final class FormChanges {

	public const ANCHOR = 'pusers-help-form';

	/**
	 * Nagłówek części.
	 */
	public static function heading(): string {
		return __( 'Zmiana formularza na żywym portalu', 'portal-users' );
	}

	/**
	 * Wypisuje część.
	 */
	public static function render(): void {
		printf( '<h2 id="%1$s">%2$s</h2>', esc_attr( self::ANCHOR ), esc_html( self::heading() ) );
		echo '<p>' . esc_html__( 'Dodanie pola to dwa ekrany, nie jeden: najpierw grupa pól w ACF, potem macierz pól. Samo ACF nie wystarcza i nie zgłosi, że czegoś brakuje.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Dodanie pola', 'portal-users' ) . '</h3>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Dodaj pole do grupy wskazanej w ustawieniach wtyczki. Pole w innej grupie nie istnieje dla wtyczki.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Wejdź na macierz pól i ustaw stan nowego pola dla KAŻDEJ profesji. Pole bez ustawionego stanu jest przy rejestracji po cichu odsiewane: formularz go nie pokaże, a wartość się nie zapisze. Nie ma po tym żadnego błędu — jest tylko pole, którego nikt nie wypełnia.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Sprawdź raport spójności na ekranie macierzy. Pusty raport znaczy, że zmiana jest skończona.', 'portal-users' ) . '</li>';
		echo '</ol>';

		echo '<h3>' . esc_html__( 'Przemianowanie pola', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Zmiana ETYKIETY jest bezpieczna: wtyczka trzyma nazwę pola, nie etykietę. Zmiana NAZWY jest dla wtyczki usunięciem jednego pola i dodaniem drugiego: stan ze starej nazwy zostaje w macierzy jako martwy wiersz, a nowa nazwa nie ma stanu dla żadnej profesji i nie pokaże się w formularzu. Jeżeli nazwę już zmieniono: ustaw stany nowego pola w macierzy, a wpisy starego usuń jawną akcją w sekcji martwych pól.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Wartości, które użytkownicy podali pod starą nazwą, zostają w kontach pod starą nazwą. Wtyczka ich nie przenosi.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Usunięcie pola', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Wpisy usuniętego pola zostają w macierzy — nie czyści się ona sama, żeby chwilowo wyłączona albo przestawiona grupa nie wymazała konfiguracji. Ekran macierzy pokazuje je osobno jako martwe wiersze i daje akcję usunięcia.', 'portal-users' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Pole oznaczone w macierzy jako wymagane, którego nie ma już w grupie, blokuje rejestrację tej profesji.', 'portal-users' ) . '</strong> ' . esc_html__( 'Formularz nie pokaże pola, którego nie ma w źródle, więc wymóg jest niespełnialny. Raport mówi o tym błędem: popraw wpis w macierzy albo wróć polem do grupy.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Zmiana typu pola', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Pole numeru uprawnień musi zostać typu tekstowego albo liczbowego. Zmiana na typ wielowartościowy albo logiczny zapali błąd w raporcie i zależnie od typu może uniemożliwić rejestrację tej profesji. Pole zgody na regulamin musi zostać typu „prawda/fałsz”. Zmiana na typ kontenerowy albo układowy sprawia, że wtyczka przestaje pole widzieć — zachowuje się wtedy jak pole usunięte.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Po każdej zmianie', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Otwórz stronę rejestracji i wybierz po kolei każdą profesję: zestaw pól zmienia się razem z wyborem i to jedyny widok tego, co naprawdę zobaczy lekarz. Raport spójności łapie błędy konfiguracji, ale nie powie, czy formularz ma sens dla człowieka.', 'portal-users' ) . '</p>';
	}
}
