<?php
/**
 * Archiwum wersji regulaminu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Wersja regulaminu to jego treść i skrót SHA-1 tej treści. Konto wskazuje na skrót, a treść leży raz
 * w tabeli `terms_versions`: przy sporze da się wykazać, na co konkretnie dana osoba się zgodziła.
 *
 * Wiersz raz zapisany nie jest ani zmieniany, ani usuwany: zmiana regulaminu dopisuje NOWĄ wersję,
 * a konto zarejestrowane wcześniej dalej wskazuje na tekst, który zaakceptowało.
 */
final class TermsRepository {

	/**
	 * Skrót aktualnie skonfigurowanej treści; pusty ciąg, gdy regulaminu nie skonfigurowano.
	 */
	public static function currentHash(): string {
		return self::hash( SettingsRepository::get()->termsText );
	}

	/**
	 * Zapewnia istnienie wersji o tej treści i zwraca jej skrót.
	 *
	 * Ta sama treść nie dopisuje drugiego wiersza (UNIQUE na skrócie, `INSERT IGNORE` przeżywa też
	 * równoległe rejestracje). Pusty ciąg oznacza „brak wersji”: treść jest pusta ALBO zapisu nie
	 * udało się potwierdzić; wywołujący nie może wtedy wiązać konta ze skrótem, którego w archiwum nie ma.
	 *
	 * @param string $content Treść regulaminu.
	 */
	public static function ensureVersion( string $content ): string {
		global $wpdb;

		$hash = self::hash( $content );
		if ( '' === $hash ) {
			return '';
		}

		$table = Migrations::table( 'terms_versions' );
		$quiet = $wpdb->suppress_errors( true );
		try {
			// Tabela pochodzi z Migrations::table(), wartości przechodzą przez prepare().
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (version_hash, content, created_at) VALUES (%s, %s, %s)", $hash, trim( $content ), gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			// O sukcesie rozstrzyga odczyt, nie wynik zapytania: wiersz mógł już istnieć.
			$exists = null !== self::row( $hash );
		} finally {
			$wpdb->suppress_errors( $quiet );
		}

		return $exists ? $hash : '';
	}

	/**
	 * Treść wersji o danym skrócie albo null, gdy takiej wersji nie ma.
	 *
	 * @param string $hash Skrót wersji.
	 */
	public static function contentFor( string $hash ): ?string {
		return self::row( $hash );
	}

	/**
	 * Skrót treści (po obcięciu białych znaków); pusty ciąg dla pustej treści.
	 *
	 * @param string $content Treść.
	 */
	private static function hash( string $content ): string {
		$trimmed = trim( $content );

		return '' === $trimmed ? '' : sha1( $trimmed );
	}

	/**
	 * Treść wiersza o danym skrócie.
	 *
	 * @param string $hash Skrót wersji.
	 */
	private static function row( string $hash ): ?string {
		global $wpdb;

		$table   = Migrations::table( 'terms_versions' );
		$content = $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$table} WHERE version_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		return is_string( $content ) ? $content : null;
	}
}
