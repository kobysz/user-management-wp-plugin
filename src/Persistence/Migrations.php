<?php
/**
 * Migracje tabel wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Tworzy i wersjonuje tabele. Niezależne od ACF Pro: baza ma się migrować zawsze.
 */
final class Migrations {

	/**
	 * Wersja schematu bazy. Zwiększ przy każdej zmianie tabel.
	 */
	public const DB_VERSION = 1;

	/**
	 * Nazwa opcji z zapisaną wersją schematu.
	 */
	private const OPTION = 'pusers_db_version';

	/**
	 * Pełna nazwa tabeli wtyczki (z prefiksem WordPressa).
	 *
	 * @param string $name Nazwa bez prefiksu, np. `event_log`.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'pusers_' . $name;
	}

	/**
	 * Tworzy lub aktualizuje tabele i zapisuje wersję schematu.
	 */
	public static function run(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$log     = self::table( 'event_log' );
		$terms   = self::table( 'terms_versions' );

		// Kolumna `anonymized`: żądanie usunięcia danych anonimizuje log, nie kasuje go.
		dbDelta(
			"CREATE TABLE {$log} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  subject_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  action VARCHAR(40) NOT NULL,
  context LONGTEXT NULL,
  anonymized TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY subject_user_id (subject_user_id),
  KEY created_at (created_at)
) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$terms} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  version_hash CHAR(40) NOT NULL,
  content LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY version_hash (version_hash)
) ENGINE=InnoDB {$charset};"
		);

		update_option( self::OPTION, self::DB_VERSION );
	}

	/**
	 * Uruchamia migrację, gdy zapisana wersja jest niższa od bieżącej.
	 * Aktualizacja wtyczki nie odpala hooka aktywacji, więc to jest konieczne.
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( self::OPTION, 0 );
		$stored = is_numeric( $stored ) ? (int) $stored : 0;
		if ( $stored < self::DB_VERSION ) {
			self::run();
		}
	}
}
