<?php
/**
 * Wspólny guard zapisu i przekierowanie po zapisie (PRG).
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Każdy zapis w panelu: uprawnienia, nonce, akcja, przekierowanie.
 */
final class Request {

	public const NONCE_FIELD  = 'pusers_nonce';
	public const NOTICE_ARG   = 'pusers_notice';
	public const NOTICE_SAVED = 'saved';

	/**
	 * Kończy żądanie, gdy brakuje uprawnień albo nonce'a. Uprawnienia sprawdzane są
	 * pierwsze, a oba niepowodzenia dają identyczny komunikat: inaczej odpowiedź
	 * potwierdzałaby osobie bez uprawnień, że przeszkodą był sam nonce.
	 *
	 * @param string $action Akcja, dla której wystawiono nonce.
	 */
	public static function guard( string $action ): void {
		$allowed = current_user_can( Menu::CAPABILITY );

		if ( $allowed ) {
			// Tylko $_POST: nonce w adresie trafiałby do logów serwera i nagłówka referer.
			$nonce   = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- to jest weryfikacja nonce'a.
				? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: '';
			$allowed = '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
		}

		if ( ! $allowed ) {
			wp_die(
				esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Przekierowuje na ekran panelu z powiadomieniem i kończy żądanie.
	 *
	 * Przekierowanie anulowane filtrem `wp_redirect` nie kończy żądania; to szew testowy.
	 *
	 * @param string               $slug   Slug podstrony.
	 * @param string               $notice Kod powiadomienia.
	 * @param array<string, mixed> $args   Dodatkowe argumenty zapytania.
	 */
	public static function redirect( string $slug, string $notice, array $args = array() ): void {
		$url = Menu::url( $slug, array_merge( $args, array( self::NOTICE_ARG => $notice ) ) );
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * Kod powiadomienia z adresu strony; pusty, gdy go nie ma.
	 */
	public static function notice(): string {
		// Odczyt powiadomienia wyświetlanego po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) || ! is_string( $_GET[ self::NOTICE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		return sanitize_key( wp_strip_all_tags( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
