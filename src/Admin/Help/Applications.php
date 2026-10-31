<?php
/**
 * Część instrukcji: obsługa wniosków.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin\Help;

defined( 'ABSPATH' ) || exit;

/**
 * Czynność, która wraca co tydzień i trafia na osobę, która wtyczki nie stawiała.
 */
final class Applications {

	public const ANCHOR = 'pusers-help-applications';

	/**
	 * Nagłówek części.
	 */
	public static function heading(): string {
		return __( 'Weryfikacja i akceptacja użytkowników', 'portal-users' );
	}

	/**
	 * Wypisuje część.
	 */
	public static function render(): void {
		printf( '<h2 id="%1$s">%2$s</h2>', esc_attr( self::ANCHOR ), esc_html( self::heading() ) );
		echo '<p>' . esc_html__( 'Wnioski czekają na ekranie „Portal: wnioski” pod menu Użytkownicy. Liczba w bąbelku przy nazwie w menu to wnioski oczekujące na decyzję — gdy bąbelka nie ma, nie ma czego rozstrzygać.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Co widzi redakcja', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Lista pokazuje zgłoszenia jednego stanu konta: użytkownika, profesję, numer uprawnień i datę rejestracji. Stan wybiera się nad listą, a filtr zawęża ją do jednej profesji. Przy każdym wniosku stoi odnośnik do rejestru zawodowego wpisanego przy tej profesji — to w nim sprawdza się, czy numer należy do tej osoby. Wtyczka liczy sumę kontrolną numeru i odrzuca numery niemożliwe, ale nie wie i nie może wiedzieć, czy numer poprawny należy do zgłaszającego. Panel szczegółów pokazuje dodatkowo pola, które macierz uznaje DZIŚ za widoczne dla tej profesji. Wartości pól spoza obecnej macierzy są zapisane, ale nie są pokazywane — panel podaje tylko ich liczbę, żeby redakcja wiedziała, że rozstrzyga na podstawie mniejszej liczby danych, niż zgłaszający podał.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Panel szczegółów potrzebuje ACF Pro do pokazania wartości pól i sam mówi, gdy go nie ma. Sama lista i rozstrzyganie wniosków działają bez ACF Pro.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Zatwierdzenie i odrzucenie', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Decyzję podejmuje się w panelu szczegółów albo hurtem na liście, dla zaznaczonych wniosków. Odrzucenie wymaga powodu: lista gotowych powodów z ustawień wypełnia pole tekstowe, które można nadpisać. Wpisany tekst wygrywa, a puste pole oznacza wybrany gotowy powód. Przy pustej liście gotowych powodów odrzucanie nadal działa — powód wpisuje się ręcznie.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Zatwierdzone konto dostaje mail „konto zatwierdzone” i od tej chwili wchodzi na portal. Odrzucone dostaje mail z powodem, a jego sesje są niszczone. Każda decyzja zapisuje się w logu zdarzeń razem z tym, kto ją podjął; log jest w bazie danych i nie ma dla niego ekranu w panelu — „Portal: log wysyłek” to log maili, nie decyzji.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Dlaczego ktoś nie może się zalogować', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Brama logowania wpuszcza tylko konta zatwierdzone i mówi pozostałym, na czym stoją: konto z niepotwierdzonym adresem, konto czekające na decyzję i konto odrzucone dostają różne komunikaty. Jest jeszcze czwarty: „Konto wymaga uwagi redakcji. Napisz do nas.” — dostaje go konto ze stanem, którego wtyczka nie rozpoznaje, czyli po ręcznej zmianie metadanych albo po nieudanym imporcie. Takiego konta nie naprawi się z ekranu wniosków; trzeba mu nadać stan importem albo poprawić metadaną.', 'portal-users' ) . '</p>';
		echo '<p>' . esc_html__( 'Konto sprzed wdrożenia wtyczki, które nie ma stanu, nie jest objęte żadną z tych reguł i wchodzi na portal niezależnie od decyzji — stan nadaje się mu na ekranie importu danych.', 'portal-users' ) . '</p>';

		echo '<h3>' . esc_html__( 'Gdy mail nie dotarł', 'portal-users' ) . '</h3>';
		echo '<p>' . esc_html__( 'Wysyłka idzie kolejką, nie w trakcie żądania, więc mail wychodzi chwilę po decyzji. Ekran „Portal: log wysyłek” pokazuje, co wyszło, co czeka i co padło, razem z powodem. Wiersze „padło” są jedynym miejscem, w którym widać problem z pocztą portalu — sama wtyczka oddaje wiadomość WordPressowi i nie wie, co stało się dalej.', 'portal-users' ) . '</p>';
	}
}
