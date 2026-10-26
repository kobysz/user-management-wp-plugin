<?php
/**
 * Odinstalowanie wtyczki: jedyne miejsce, które celowo i nieodwracalnie kasuje dane.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Usuwa opcje i tabele wtyczki, ale wyłącznie za bramką. Domyślnie nie kasuje niczego:
 * usunięcie wtyczki nie może zniszczyć wniosków i logu zdarzeń.
 *
 * Decyzja administratora to `Settings::$deleteDataOnUninstall` (pole wyboru na ekranie
 * ustawień). Stała `PORTAL_USERS_DELETE_DATA_ON_UNINSTALL` ma priorytet: gdy jest
 * zdefiniowana, decyduje jej wartość logiczna, a ustawienia nie są czytane wcale.
 *
 * Metadane użytkowników (`pusers_profession`, stan konta) NIE są kasowane. Konta są danymi
 * WordPressa, nie wtyczki; usunięcie wtyczki nie może usuwać ani degradować kont.
 *
 * Znane ograniczenie: w sieci wielowitrynowej uninstall.php działa raz, w kontekście witryny
 * głównej, więc sprzątana jest tylko ona (opcje i tabele z jej prefiksem).
 */
final class Uninstaller {

	/**
	 * Stała z wp-config.php, nadrzędna wobec ustawienia.
	 */
	public const CONSTANT_GATE = 'PORTAL_USERS_DELETE_DATA_ON_UNINSTALL';

	/**
	 * Czy odinstalowanie ma skasować dane.
	 */
	public static function should_delete_data(): bool {
		if ( defined( self::CONSTANT_GATE ) ) {
			return (bool) constant( self::CONSTANT_GATE );
		}
		return SettingsRepository::get()->deleteDataOnUninstall;
	}

	/**
	 * Kasuje opcje i obie tabele wtyczki, o ile bramka jest otwarta.
	 */
	public static function run(): void {
		if ( ! self::should_delete_data() ) {
			return;
		}

		global $wpdb;

		foreach ( OptionStore::all() as $option ) {
			OptionStore::delete( $option );
		}
		delete_option( Migrations::OPTION_DB_VERSION );

		foreach ( array( 'event_log', 'terms_versions' ) as $name ) {
			$table = Migrations::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nazwa z Migrations::table(), bez danych wejściowych.
		}
	}
}
