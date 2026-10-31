<?php
/**
 * Część instrukcji: uruchomienie wtyczki od zera.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin\Help;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Admin\HelpScreen;
use PortalUsers\Domain\Config\SetupStepKey;

/**
 * Osiem kroków w postaci długiej. Nagłówki kroków bierze z `HelpScreen::title()`, żeby ich
 * teksty nie istniały w dwóch miejscach: lista startowa i ta część muszą nazywać kroki
 * identycznie, a `HelpScreenTest` wiąże te nazwy z `docs/konfiguracja.md`.
 */
final class SetupGuide {

	public const ANCHOR = 'pusers-help-setup';

	/**
	 * Nagłówek części.
	 */
	public static function heading(): string {
		return __( 'Uruchomienie od zera', 'portal-users' );
	}

	/**
	 * Wypisuje część.
	 */
	public static function render(): void {
		printf( '<h2 id="%1$s">%2$s</h2>', esc_attr( self::ANCHOR ), esc_html( self::heading() ) );
		echo '<p>' . esc_html__( 'Kolejność nie jest dowolna: każdy krok karmi listę wyborów w następnym. Raport spójności na ekranie macierzy pól jest listą kontrolną — dopóki stoi w nim choć jeden błąd, konfiguracja jest niedokończona, a część błędów wyłącza rejestrację.', 'portal-users' ) . '</p>';

		self::step( SetupStepKey::AcfGroup );
		echo '<p>' . esc_html__( 'Pola grupy ACF są formularzem rejestracji: wszystko poza adresem e-mail i wyborem profesji, które wtyczka rysuje sama. Grupę wskazuje się w ustawieniach, więc reguły lokalizacji w ACF nie decydują o formularzu — ustaw je na „Użytkownik”, bo od nich zależy co innego: czy redakcja zobaczy i poprawi te pola na ekranie edycji konta.', 'portal-users' ) . '</p>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Pole numeru uprawnień (PWZ, PWZDL) musi być typu tekstowego albo liczbowego. Pole wielowartościowe niesie wartość, której bramka rejestracji nie przyjmie; pole logiczne dałoby numer o wartości „1”.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Pole zgody na regulamin musi być typu „prawda/fałsz”.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Wtyczka nie widzi pól kontenerowych (grupa, powtarzalne, elastyczna treść, klon) ani układowych (zakładka, komunikat, akordeon). Nie pokażą się w macierzy i nie trafią do formularza.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Macierz i eksport trzymają nazwę pola, nie etykietę. Etykietę można zmieniać swobodnie; nazwę — patrz część o zmianie formularza.', 'portal-users' ) . '</li>';
		echo '</ul>';

		self::step( SetupStepKey::GroupSelected );
		echo '<p>' . esc_html__( 'Ustawienia wtyczki, pole „Grupa pól ACF”. Dopóki stoi w nim „— nie wybrano —”: macierz nie ma z czego zbudować wierszy, lista „Pole numeru” przy profesji jest pusta, a rejestracja jest wyłączona — strona z formularzem pokaże komunikat o chwilowej niedostępności.', 'portal-users' ) . '</p>';

		self::step( SetupStepKey::Professions );
		echo '<p>' . esc_html__( 'Jedna pozycja na zawód, który portal obsługuje. Klucz to małe litery, cyfry i myślniki; konta przechowują klucz, więc po utworzeniu nie można go zmienić. Rodzaj numeru decyduje, którą sumę kontrolną liczy walidator. Pole numeru wybiera się z pól grupy z kroku pierwszego i zostaje puste tylko dla profesji bez numeru. Adres rejestru pokazuje się przy wniosku jako odnośnik dla redakcji.', 'portal-users' ) . '</p>';

		self::step( SetupStepKey::Matrix );
		echo '<p>' . esc_html__( 'Wiersze to pola grupy, kolumny to profesje. Każda para dostaje jeden z trzech stanów:', 'portal-users' ) . '</p>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Ukryte — formularz nie pokaże pola tej profesji i nie zapisze jego wartości.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Widoczne — pole jest, ale wolno je zostawić puste.', 'portal-users' ) . '</li>';
		echo '<li>' . esc_html__( 'Wymagane — bez wypełnienia formularz nie przejdzie.', 'portal-users' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'Pole numeru uprawnień musi mieć stan „Wymagane” dla każdej profesji, która numeru wymaga. Inaczej raport zapali błąd, a zgłoszenie mogłoby przejść bez numeru: walidator czyta wartości już po odsiewie według macierzy.', 'portal-users' ) . '</p>';

		self::step( SetupStepKey::Pages );
		echo '<p>' . esc_html__( 'Utwórz i opublikuj trzy strony, każdą z jednym skrótem:', 'portal-users' ) . '</p>';
		echo '<ul><li><code>[portal_users_registration]</code></li><li><code>[portal_users_login]</code></li><li><code>[portal_users_password_reset]</code></li></ul>';
		echo '<p>' . esc_html__( 'Potem wskaż je w ustawieniach, razem ze stroną po zalogowaniu. Strona wskazana, ale nieopublikowana albo usunięta, liczy się jak niewskazana i zapala ostrzeżenie w raporcie.', 'portal-users' ) . '</p>';

		self::step( SetupStepKey::Settings );
		echo '<p>' . esc_html__( 'Wartości domyślne są pracujące: link potwierdzający ważny 48 godzin, usuwanie niepotwierdzonych kont po 7 dniach, 50 zgłoszeń z jednego adresu IP na godzinę, 3 sekundy minimalnego czasu wypełniania formularza. Ruszaj je świadomie — limit z jednego adresu podnieś, gdy użytkownicy wychodzą do internetu przez wspólny adres szpitala albo uczelni.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Dwie pozycje są puste domyślnie i trzeba je wypełnić: adresy redakcji (bez nich nikt nie dowie się o nowym wniosku) oraz powody odrzucenia, których redakcja używa najczęściej. Regulamin wpisuje się razem z polem zgody albo wcale: treść bez wskazanego pola zgody znaczy, że nie będzie czym wykazać, na co użytkownik się zgodził.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Osobno, poza wtyczką: Ustawienia → Ogólne → Domyślna rola nowego użytkownika. Rola z uprawnieniem, którego publiczny formularz nie może nadać osobie zakładającej konto, wyłącza rejestrację — raport powie, która to rola i które uprawnienie.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Usuwanie danych przy odinstalowaniu jest domyślnie wyłączone i zostaw je wyłączone, chyba że świadomie chcesz, by usunięcie wtyczki skasowało ustawienia, profesje, macierz, log zdarzeń i archiwum wersji regulaminu.', 'portal-users' ) . '</p>';

		self::step( SetupStepKey::MailTemplates );
		echo '<p>' . esc_html__( 'Osiem wiadomości ma treści domyślne i działa nietknięte: potwierdzenie adresu, zgłoszenie do redakcji (w dwóch wersjach, zależnie od tego, czy konto czeka na akceptację), rejestracja na istniejący adres, wniosek przyjęty, konto zatwierdzone, wniosek odrzucony i odzyskiwanie hasła. Przejrzyj je i podmień na język portalu; każdy szablon ma do dyspozycji tylko zmienne swojego rodzaju, a ekran je wypisuje.', 'portal-users' ) . '</p>';

		self::step( SetupStepKey::LegacyAccounts );
		echo '<p>' . esc_html__( 'Dotyczy tylko portalu z kontami sprzed wdrożenia wtyczki. Ekran importu danych robi dwie rzeczy. Pierwsza to import z poprzedniej wtyczki: zmapuj metadane na pola i stany, zapisz mapowanie, uruchom tryb próbny (nic nie zapisuje, liczy co by zmienił), przejrzyj raport i dopiero wtedy zapisz import. Druga to nadanie stanu kontom, które go nie mają — takie konto nie jest objęte żadną regułą bramy logowania i wchodzi na portal niezależnie od tego, czy ktoś je zatwierdził. Przycisk nadaje „zatwierdzone” wszystkim takim kontom i jest dla portalu, który stanu nie trzymał; portal, który trzymał, powinien użyć mapowania.', 'portal-users' ) . '</p>';
	}

	/**
	 * Nagłówek kroku.
	 *
	 * @param SetupStepKey $key Krok.
	 */
	private static function step( SetupStepKey $key ): void {
		echo '<h3>' . esc_html( HelpScreen::title( $key ) ) . '</h3>';
	}
}
