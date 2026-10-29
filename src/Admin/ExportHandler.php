<?php
/**
 * Pobieranie eksportu wniosków do CSV: bramki, nagłówki i strumień pliku.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Export\UserCsvExport;

/**
 * Plik idzie przez `admin_post_`, nie przez render ekranu: przed nim nie może stać ani bajt wyjścia panelu.
 * To odczyt, ale wydaje dane osobowe wszystkich kont z zakładki, więc obowiązuje ta sama bramka co zapis:
 * uprawnienie, nonce i POST (adres z danymi trafiłby do logów serwera i historii przeglądarki).
 */
final class ExportHandler {

	public const ACTION = 'pusers_export_users';

	/**
	 * Filtr, którym test anuluje końcowe `exit`.
	 */
	public const FILTER_EXIT = 'pusers_export_exit';

	/**
	 * Podpina uchwyt pobierania. Wołane z `Plugin::boot()` poza bramką zależności ACF Pro, tak jak ekran wniosków.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Wydaje plik CSV dla zakładki i profesji z formularza ekranu.
	 */
	public static function handle(): void {
		Request::guard( self::ACTION );

		// Admin-post odpala się dla każdej metody, a eksport wydaje dane osobowe, więc GET odpada.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		$status = self::posted_status();

		// Cały tekst powstaje przed pierwszym nagłówkiem: gdyby składanie padło po nagłówkach,
		// przeglądarka dostałaby plik do pobrania z fatalem PHP w środku.
		$csv = UserCsvExport::for_filter( $status, self::posted_profession() );

		if ( ! headers_sent() ) {
			foreach ( self::headers( $status ) as $header ) {
				header( $header );
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plik CSV, nie HTML; komórki neutralizuje CsvDocument.
		echo $csv;

		// Szew testowy: bez niego test ścieżki sukcesu nie mógłby istnieć, bo `exit` kończy przebieg phpunita.
		if ( apply_filters( self::FILTER_EXIT, true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej FILTER_EXIT, która ma prefiks pusers_.
			exit;
		}
	}

	/**
	 * Nazwa pliku: `wnioski-<stan>-<data>.csv`.
	 *
	 * @param AccountStatus $status Stan, czyli zakładka.
	 */
	public static function filename( AccountStatus $status ): string {
		return sanitize_file_name( 'wnioski-' . $status->value . '-' . wp_date( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Nagłówki pobierania. Osobna metoda, bo `header()` w teście z konsoli niczego nie zostawia do odczytania.
	 *
	 * @param AccountStatus $status Stan, czyli zakładka.
	 * @return array<int, string>
	 */
	public static function headers( AccountStatus $status ): array {
		return array(
			'Content-Type: text/csv; charset=utf-8',
			'Content-Disposition: attachment; filename="' . self::filename( $status ) . '"',
			// Plik z danymi osobowymi nie może zostać w pośredniej pamięci podręcznej.
			'Cache-Control: no-store, no-cache, must-revalidate',
		);
	}

	/**
	 * Zakładka z żądania; brak albo `email_unconfirmed` (to nie wniosek i nie ma zakładki) znaczy „oczekujące", tak jak na ekranie.
	 */
	private static function posted_status(): AccountStatus {
		$status = AccountStatus::tryFrom( sanitize_key( self::posted( ApplicationsScreen::ARG_STATUS ) ) );
		return null !== $status && AccountStatus::EmailUnconfirmed !== $status ? $status : AccountStatus::Pending;
	}

	/**
	 * Klucz profesji z żądania albo '' dla wszystkich.
	 */
	private static function posted_profession(): string {
		return sanitize_key( self::posted( ApplicationsScreen::ARG_PROFESSION ) );
	}

	/**
	 * Pole żądania jako tekst; pusty, gdy go nie ma.
	 *
	 * @param string $name Nazwa pola.
	 */
	private static function posted( string $name ): string {
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() na początku handle().
			return '';
		}
		return sanitize_text_field( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- j.w.
	}
}
