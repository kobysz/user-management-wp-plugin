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
		echo '<li>' . esc_html__( 'Wejdź na macierz pól i ustaw stan nowego pola dla KAŻDEJ profesji. Pole bez ustawionego stanu jest przy rejestracji po cichu odsiewane: formularz go nie pokaże, a wartość się nie zapisze. Raport spójności mówi o tym ostrzeżeniem przy każdej profesji, której stanu brakuje — to jedyne, co stoi między pominiętą kolumną a polem, którego nikt nie wypełnia.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Sprawdź raport spójności na ekranie macierzy. Pusty raport znaczy, że zmiana jest skończona.', 'portal-users' ) . '</li>';
		echo '</ol>';

		echo '<h3>' . esc_html__( 'Przemianowanie pola', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Zmiana ETYKIETY jest bezpieczna: wtyczka trzyma nazwę pola, nie etykietę. Zmiana NAZWY jest dla wtyczki usunięciem jednego pola i dodaniem drugiego: stan ze starej nazwy zostaje w macierzy jako wpis bez pola, a nowa nazwa nie ma stanu dla żadnej profesji i nie pokaże się w formularzu. Jeżeli nazwę już zmieniono: ustaw stany nowego pola w macierzy, a wpisy starego usuń jawną akcją w sekcji „Pola spoza wybranej grupy”.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Wartości, które użytkownicy podali pod starą nazwą, zostają w kontach pod starą nazwą. Wtyczka ich nie przenosi.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Usunięcie pola', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Wpisy usuniętego pola zostają w macierzy — nie czyści się ona sama, żeby chwilowo wyłączona albo przestawiona grupa nie wymazała konfiguracji. Ekran macierzy pokazuje je osobno, w sekcji „Pola spoza wybranej grupy”, i daje tam akcję usunięcia.', 'portal-users' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Pole oznaczone w macierzy jako wymagane, którego nie ma już w grupie, nie blokuje rejestracji.', 'portal-users' ) . '</strong> '
			. esc_html__( 'Przy składaniu formularza macierz jest przycinana do pól, które grupa naprawdę ma, więc wymóg nieistniejącego pola po prostu przestaje obowiązywać — wtyczka świadomie nie zamyka rejestracji z powodu pomyłki w konfiguracji. Raport spójności mówi o tym błędem i warto go naprawić, ale zgłoszenia w tym czasie przechodzą, tylko bez tego pola.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Jeden wyjątek działa inaczej: pole numeru uprawnień. Profesja, której numeru nie da się wyegzekwować — bo pola numeru nie ma w grupie albo nie jest dla niej wymagane w macierzy — nie pojawia się na liście profesji w formularzu. Nikt się wtedy na nią nie zarejestruje, a gdy to była jedyna profesja, strona rejestracji pokaże komunikat o chwilowej niedostępności.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Zmiana typu pola', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Pole numeru uprawnień musi zostać typu tekstowego albo liczbowego. Zmiana na typ wielowartościowy albo logiczny zapali błąd w raporcie i zależnie od typu może uniemożliwić rejestrację tej profesji. Pole zgody na regulamin musi zostać typu „prawda/fałsz”. Zmiana na typ kontenerowy albo układowy sprawia, że wtyczka przestaje pole widzieć — zachowuje się wtedy jak pole usunięte.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Po każdej zmianie', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Otwórz stronę rejestracji i wybierz po kolei każdą profesję: zestaw pól zmienia się razem z wyborem i to jedyny widok tego, co naprawdę zobaczy lekarz. Raport spójności łapie błędy konfiguracji, ale nie powie, czy formularz ma sens dla człowieka.', 'portal-users' ) . '</p>';
	}
}
