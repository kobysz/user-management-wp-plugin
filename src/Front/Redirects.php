<?php
/**
 * Przekierowania, odcięcie panelu i pasek administracyjny dla kont bez uprawnień redakcyjnych.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Persistence\SettingsRepository;
use WP_User;

/**
 * Specyfikacja, „Strony na froncie": konto bez uprawnień do edycji treści po zalogowaniu idzie na
 * wskazaną stronę, a nie do kokpitu; wejście w `wp-admin` odsyła je na front; pasek administracyjny
 * jest dla niego ukryty. `wp-login.php` i `wp-admin` ZOSTAJĄ otwarte — to wejście zapasowe redakcji,
 * więc każda reguła tej klasy pyta o UPRAWNIENIE, nie o rolę, i przepuszcza redakcję bez zmian.
 *
 * Granicą jest `CAPABILITY`, czyli `edit_posts`. Reguła pisana po ROLI pękła już w Planie 3: wymóg
 * „podzbiór subscribera" zamykał rejestrację portalom z rolą `contributor`. Specyfikacja nazywa
 * „brak uprawnień do edycji treści" i tym właśnie jest `edit_posts` — ma je współpracownik, autor,
 * redaktor i administrator, nie ma ich subskrybent ani konto lekarza.
 *
 * ODCIĘCIE PANELU MUSI PRZEPUŚCIĆ admin-post.php I admin-ajax.php. `wp-admin/admin-post.php` woła
 * `do_action( 'admin_init' )` PRZED rozdzieleniem akcji, a komentarz rdzenia w `wp-admin/admin.php`
 * mówi to wprost: „It runs on admin-ajax.php and admin-post.php as well". Na admin-post.php stoi
 * KAŻDY formularz tej wtyczki — ustawienie hasła, rejestracja, logowanie, odzyskiwanie hasła — więc
 * przekierowanie bez wyjątku zabiłoby je wszystkie dla każdego zalogowanego nie-redaktora, czyli po
 * rejestracji dla każdego lekarza portalu. `$pagenow` bierze się z `$_SERVER['PHP_SELF']`
 * (`wp-includes/vars.php`), nie z danych klienta, więc jest do tego podstawą wiarygodną.
 *
 * Klasa rejestruje się pod `pusers_register_features`, czyli ZA sprawdzeniem zależności — inaczej
 * niż brama logowania i strażnik sesji, które są ZAMKAMI i nie mogą zniknąć razem z ACF Pro.
 * Przekierowanie zamkiem nie jest: gdy wtyczka bezczynnie czeka na zależność, panel ma zachowywać
 * się jak w czystym WordPressie, a nie odsyłać ludzi regułami, których bezczynna wtyczka nie
 * obsłuży. Wyjątku bramy nie wolno tu generalizować.
 *
 * Co z `wp-admin/profile.php`: reguła specyfikacji obejmuje CAŁY `wp-admin`, a ten ekran jest dziś
 * jedynym miejscem, w którym zalogowany lekarz zmieniłby sobie hasło albo adres e-mail. Wyjątku dla
 * niego TU NIE MA (litera specyfikacji), więc po tym kroku lekarz zmienia hasło wyłącznie przez
 * odzyskiwanie hasła, z linkiem z maila. Odwrócenie tej decyzji to jedna linia wyjątku w
 * `block_admin()`; alternatywą jest własna strona „moje konto" na froncie, czyli osobny plan.
 */
final class Redirects {

	/**
	 * Uprawnienie redakcyjne: granica wszystkich reguł tej klasy. To UPRAWNIENIE, nie rola.
	 */
	public const CAPABILITY = 'edit_posts';

	/**
	 * Podpina adres po zalogowaniu, odcięcie panelu, pasek i adresy odnośników rdzenia.
	 */
	public static function register(): void {
		add_filter( 'login_redirect', array( self::class, 'after_login' ), 10, 3 );
		add_action( 'admin_init', array( self::class, 'block_admin' ) );
		add_filter( 'show_admin_bar', array( self::class, 'hide_admin_bar' ) );
		add_filter( 'login_url', array( self::class, 'login_url' ), 10, 3 );
		add_filter( 'lostpassword_url', array( self::class, 'lostpassword_url' ), 10, 2 );
		add_filter( 'register_url', array( self::class, 'register_url' ) );
	}

	/**
	 * Adres po zalogowaniu (filtr `login_redirect`).
	 *
	 * Redakcja dostaje wyrok rdzenia bez zmian: kokpit jest jej miejscem pracy. Konto bez
	 * uprawnień redakcyjnych idzie na stronę z ustawień, bo rdzeń podstawia mu `profile.php`,
	 * czyli ekran panelu, z którego `block_admin()` i tak by je odesłało.
	 *
	 * Adres z żądania to DANE Z ŻĄDANIA, więc przechodzi przez `wp_validate_redirect()`; adres
	 * wskazujący inny serwis odpada i zostaje strona z ustawień. Adres z tej witryny zostaje —
	 * bez tego pole adresu docelowego formularza logowania przestałoby działać dla lekarzy.
	 *
	 * O uprawnieniach pytamy PODANE konto, nie `current_user_can()`: filtr odpala się w trakcie
	 * logowania i rdzeń podaje konto właśnie dlatego, że bieżącego użytkownika może jeszcze nie być.
	 * Wejście, które nie jest kontem (odmowa rdzenia), oddajemy bez zmian — nie wiadomo, czyje to
	 * logowanie, a zgadywanie na rzecz „nie-redaktora" odcinałoby redakcję.
	 *
	 * @param string $redirectTo Adres, który ustalił rdzeń.
	 * @param string $requested  Adres z żądania (`''` = brak).
	 * @param mixed  $user       Zalogowane konto (`WP_User`) albo odmowa rdzenia.
	 */
	public static function after_login( string $redirectTo, string $requested, mixed $user ): string {
		if ( ! $user instanceof WP_User || $user->has_cap( self::CAPABILITY ) ) {
			return $redirectTo;
		}

		$wanted = '' === $requested ? '' : wp_validate_redirect( $requested, '' );

		return '' === $wanted ? self::after_login_url() : $wanted;
	}

	/**
	 * Odcina panel kontom bez uprawnień redakcyjnych (akcja `admin_init`).
	 *
	 * Warunki wyjścia są tu wymogiem, nie ostrożnością (patrz dokumentacja klasy): żądanie AJAX,
	 * żądanie własnych handlerów wtyczki pod admin-post.php, cron oraz redakcja.
	 */
	public static function block_admin(): void {
		if ( wp_doing_ajax() ) {
			return; // admin-ajax.php: `admin_init` odpala się tam, choć to nie wejście w panel.
		}

		// Handlery wtyczki: admin-post.php odpala `admin_init` PRZED rozdzieleniem akcji, więc bez
		// tego wyjątku przekierowanie kończyłoby każde wysłanie formularza wtyczki.
		if ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) {
			return;
		}

		if ( wp_doing_cron() || current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Przekierowanie wyłącznie przez `wp_safe_redirect()`; adres pochodzi z ustawień, nie z
		// żądania. Anulowane przekierowanie nie kończy żądania (szew testowy), więc wracamy.
		if ( wp_safe_redirect( self::after_login_url() ) ) {
			exit;
		}
	}

	/**
	 * Ukrywa pasek administracyjny kontom bez uprawnień redakcyjnych (filtr `show_admin_bar`).
	 *
	 * Decyzję rdzenia (albo motywu) dla redakcji oddajemy nietkniętą: pasek jest jej drogą do
	 * panelu z każdej strony portalu.
	 *
	 * @param bool $show Czy pasek ma się pokazać.
	 */
	public static function hide_admin_bar( bool $show ): bool {
		return current_user_can( self::CAPABILITY ) ? $show : false;
	}

	/**
	 * Adres strony logowania dla odnośników rdzenia (filtr `login_url`).
	 *
	 * Adresu docelowego NIE przenosimy do argumentu: dla konta bez uprawnień redakcyjnych
	 * prowadziłby z powrotem do panelu, z którego `block_admin()` je odsyła, a o miejscu po
	 * zalogowaniu rozstrzyga `after_login()`. Redakcja wchodzi przez `wp-login.php`, czyli
	 * wejście zapasowe, które zostaje otwarte.
	 *
	 * @param string $url         Adres ustalony przez rdzeń.
	 * @param string $redirect    Adres docelowy po zalogowaniu (`''` = brak).
	 * @param bool   $forceReauth Czy rdzeń wymusza ponowne uwierzytelnienie.
	 */
	public static function login_url( string $url, string $redirect, bool $forceReauth ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- sygnatura filtra rdzenia; oba argumenty zostają świadomie nieużyte (patrz opis metody).
		return self::page_url_or( SettingsRepository::get()->loginPageId, $url );
	}

	/**
	 * Adres strony odzyskiwania hasła dla odnośników rdzenia (filtr `lostpassword_url`).
	 *
	 * Adresu docelowego nie przenosimy z tego samego powodu, co w `login_url()`, i dodatkowo
	 * dlatego, że strona odzyskiwania hasła takiego argumentu nie czyta — przeniesiony byłby
	 * obietnicą, której nic nie dotrzyma.
	 *
	 * @param string $url      Adres ustalony przez rdzeń.
	 * @param string $redirect Adres docelowy po zmianie hasła (`''` = brak).
	 */
	public static function lostpassword_url( string $url, string $redirect ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- sygnatura filtra rdzenia; argument zostaje świadomie nieużyty (patrz opis metody).
		return self::page_url_or( SettingsRepository::get()->resetPageId, $url );
	}

	/**
	 * Adres strony rejestracji dla odnośników rdzenia (filtr `register_url`).
	 *
	 * @param string $url Adres ustalony przez rdzeń.
	 */
	public static function register_url( string $url ): string {
		return self::page_url_or( SettingsRepository::get()->registerPageId, $url );
	}

	/**
	 * Strona po zalogowaniu z ustawień albo strona główna.
	 *
	 * Reguła odwrotu („brak wskazania albo strona usunięta = strona główna") należy do
	 * `PageUrl::for()` i tu się jej NIE powtarza. To ona pilnuje, żeby przekierowanie nie
	 * zdegenerowało się do pustego adresu, i to ona trzyma odwrót z dala od strony logowania,
	 * która byłaby pętlą.
	 */
	public static function after_login_url(): string {
		return PageUrl::for( SettingsRepository::get()->afterLoginPageId );
	}

	/**
	 * Adres strony wskazanej w ustawieniach albo wartość wejściowa, gdy wskazania nie ma.
	 *
	 * Wartość wejściowa, nie pusty łańcuch: pusty adres logowania w mailu rdzenia jest martwym
	 * odnośnikiem, a portal bez wskazanej strony ma po prostu zostać przy adresach rdzenia.
	 *
	 * @param int    $pageId Identyfikator strony z ustawień (0 = brak wskazania).
	 * @param string $url    Adres ustalony przez rdzeń.
	 */
	private static function page_url_or( int $pageId, string $url ): string {
		return $pageId > 0 ? PageUrl::for( $pageId ) : $url;
	}
}
