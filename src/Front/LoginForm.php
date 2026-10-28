<?php
/**
 * Formularz logowania na stronie: shortcode i jego widok.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Persistence\SettingsRepository;
use WP_Post;

/**
 * Widok formularza logowania; obsługa wysłanego żądania należy do osobnego kroku.
 *
 * Formularza NIE chroni pułapka czasowa, i to jest wymóg, nie brak: logowanie wypełnia menedżer
 * haseł i wysyła w ułamku sekundy, więc pułapka trzech sekund odcinałaby właśnie ostrożniejszą
 * część użytkowników. Bota zatrzymuje pułapka (`HONEYPOT`, nic nie kosztuje) i próg prób, który
 * liczy wyłącznie porażki. Nie dokładać pułapki czasowej dla symetrii z formularzem rejestracji.
 */
final class LoginForm {

	public const SHORTCODE    = 'portal_users_login';
	public const NONCE_ACTION = 'pusers_login';
	public const NONCE_FIELD  = 'pusers_login_nonce';

	public const IDENTITY = 'pusers_login_identity';
	public const PASSWORD = 'pusers_login_password';
	public const REMEMBER = 'pusers_login_remember';
	public const REDIRECT = 'pusers_login_redirect';

	/**
	 * Nazwa pułapki na boty, odmagnesowana tak jak w `RegistrationForm::HONEYPOT`: żadnego
	 * `website`, `url`, `email`, `name`, `phone` ani `address`, bo menedżery haseł wypełniają takie
	 * pola wbrew `autocomplete="off"`, a wypełniona pułapka to odmowa.
	 */
	public const HONEYPOT = 'pusers_pole_zwrotne';

	/**
	 * Argument adresu z wynikiem logowania po przekierowaniu. Kody wyniku i ich komunikaty należą
	 * do obsługi żądania (`LoginHandler::message_for()`); widok zna tylko nazwę argumentu.
	 */
	public const RESULT_ARG = 'pusers_login_result';

	/**
	 * Punkt rozszerzenia: wewnątrz formularza, pod nim Turnstile albo hCaptcha dokładają swoje pole
	 * bez modyfikowania wtyczki.
	 */
	public const EXTRA_FIELDS_ACTION = 'pusers_login_form_fields';

	/**
	 * Podpina shortcode i zakaz cache przed renderem.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
	}

	/**
	 * Wyłącza z cache stronę, której treść niesie shortcode logowania — PRZED renderem (powód jak
	 * w `RegistrationForm::on_template_redirect()`: z `the_content` nagłówki wychodzą za późno).
	 */
	public static function on_template_redirect(): void {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}

		RegistrationHandler::no_cache();
	}

	/**
	 * Strona logowania z ustawień albo strona główna.
	 */
	public static function base_url(): string {
		return PageUrl::for( SettingsRepository::get()->loginPageId );
	}

	/**
	 * Zwraca formularz albo, dla zalogowanego, notkę z odnośnikami dalej i do wylogowania.
	 *
	 * Druga warstwa zakazu cache: pierwsza jedzie z `on_template_redirect()`, tu pokrywamy
	 * umieszczenie formularza poza treścią wpisu. Wywołanie jest idempotentne.
	 */
	public static function render(): string {
		RegistrationHandler::no_cache();

		if ( is_user_logged_in() ) {
			return self::render_logged_in();
		}

		$redirect = self::requested_redirect();

		$html  = self::result_notice();
		$html .= '<form class="pusers-login" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( LoginHandler::ACTION ) . '" />';

		$html .= '<p class="pusers-field-identity"><label for="' . esc_attr( self::IDENTITY ) . '">' . esc_html__( 'Adres e-mail lub nazwa użytkownika', 'portal-users' ) . '</label> ';
		$html .= '<input type="text" id="' . esc_attr( self::IDENTITY ) . '" name="' . esc_attr( self::IDENTITY ) . '" value="" autocomplete="username" required="required" /></p>';

		$html .= '<p class="pusers-field-password"><label for="' . esc_attr( self::PASSWORD ) . '">' . esc_html__( 'Hasło', 'portal-users' ) . '</label> ';
		$html .= '<input type="password" id="' . esc_attr( self::PASSWORD ) . '" name="' . esc_attr( self::PASSWORD ) . '" value="" autocomplete="current-password" required="required" /></p>';

		$html .= '<p class="pusers-field-remember"><label><input type="checkbox" name="' . esc_attr( self::REMEMBER ) . '" value="1" /> ' . esc_html__( 'Zapamiętaj mnie', 'portal-users' ) . '</label></p>';

		// Pułapka: zwykłe pole tekstowe ukryte stylem; etykieta mówi wprost, żeby pola nie wypełniać.
		$html .= '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
		$html .= '<label for="' . esc_attr( self::HONEYPOT ) . '">' . esc_html__( 'Nie wypełniaj tego pola.', 'portal-users' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( self::HONEYPOT ) . '" name="' . esc_attr( self::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off" /></div>';

		if ( '' !== $redirect ) {
			$html .= '<input type="hidden" name="' . esc_attr( self::REDIRECT ) . '" value="' . esc_attr( $redirect ) . '" />';
		}

		ob_start();
		do_action( self::EXTRA_FIELDS_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej EXTRA_FIELDS_ACTION, która ma prefiks pusers_.
		$html .= (string) ob_get_clean();

		$html .= wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$html .= '<p><button type="submit">' . esc_html__( 'Zaloguj się', 'portal-users' ) . '</button></p>';

		return $html . '</form>';
	}

	/**
	 * Notka dla zalogowanego: kto jest zalogowany, odnośnik dalej i odnośnik do wylogowania.
	 * Formularz logowania dla zalogowanego byłby zagadką albo pętlą.
	 */
	private static function render_logged_in(): string {
		$user = wp_get_current_user();
		$next = PageUrl::for( SettingsRepository::get()->afterLoginPageId );

		$html  = '<p class="pusers-notice" role="status">';
		$html .= esc_html__( 'Jesteś zalogowany jako:', 'portal-users' ) . ' ' . esc_html( $user->display_name );
		$html .= '</p>';
		$html .= '<p class="pusers-login-links"><a href="' . esc_url( $next ) . '">' . esc_html__( 'Przejdź dalej', 'portal-users' ) . '</a> ';
		$html .= '<a href="' . esc_url( wp_logout_url() ) . '">' . esc_html__( 'Wyloguj się', 'portal-users' ) . '</a></p>';

		return $html;
	}

	/**
	 * Komunikat o wyniku poprzedniej próby logowania (PRG) albo pusty łańcuch.
	 *
	 * Treści komunikatów należą do `LoginHandler::message_for()`, a stany konta — do
	 * `LoginGateway::message_for()`, który tamta metoda pyta o wszystko poza własnymi kodami. Widok
	 * nie ma drugiej mapy: rozjechałaby się z pierwszą przy pierwszej zmianie tekstu. Kod, którego
	 * nie zna ani obsługa, ani brama, daje pusty komunikat i wtedy nie rysujemy NICZEGO: puste
	 * okienko alertu to awaria najtrudniejsza do zgłoszenia.
	 */
	private static function result_notice(): string {
		// Odczyt wyniku po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		$code = isset( $_GET[ self::RESULT_ARG ] ) && is_string( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return '';
		}

		$message = LoginHandler::message_for( $code );

		return '' === $message ? '' : '<p class="pusers-notice" role="alert">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Adres docelowy z żądania po sprawdzeniu: pusty, gdy go brak albo wskazuje inny serwis.
	 * Adres z żądania to dane z żądania — nie trafia do HTML-a bez `wp_validate_redirect()`.
	 */
	private static function requested_redirect(): string {
		// Odczyt nic nie zmienia, więc nonce nie obowiązuje; adres przechodzi przez wp_validate_redirect().
		if ( ! isset( $_GET[ self::REDIRECT ] ) || ! is_string( $_GET[ self::REDIRECT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		return wp_validate_redirect( wp_unslash( $_GET[ self::REDIRECT ] ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanityzacja i kontrola hosta w wp_validate_redirect().
	}
}
