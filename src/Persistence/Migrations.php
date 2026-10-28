<?php
/**
 * Migracje tabel wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Cron\DispatchMail;
use PortalUsers\Cron\PurgeUnconfirmed;

/**
 * Tworzy i wersjonuje tabele. Niezależne od ACF Pro: baza ma się migrować zawsze.
 */
final class Migrations {

	/**
	 * Wersja schematu bazy i jednorazowych kroków aktualizacji (2: harmonogram czyszczenia kont, 3: tabela kolejki maili, 4: harmonogram wysyłki z kolejki). Zwiększ przy każdej zmianie tabel albo nowym kroku.
	 */
	public const DB_VERSION = 4;

	/**
	 * Nazwy tabel wtyczki bez prefiksu. Jedyne źródło dla odinstalowania i warunku zapisu wersji w run();
	 * każda nowa tabela musi tu trafić obok swojego CREATE, a test pilnuje zgodności z bazą.
	 *
	 * @var array<int, string>
	 */
	public const TABLES = array( 'event_log', 'terms_versions', 'mail_queue' );

	/**
	 * Nazwa opcji z zapisaną wersją schematu.
	 */
	public const OPTION_DB_VERSION = 'pusers_db_version';

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
	 * Tworzy lub aktualizuje tabele i zapisuje wersję schematu, o ile obie tabele istnieją.
	 */
	public static function run(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();
		$log     = self::table( 'event_log' );
		$terms   = self::table( 'terms_versions' );
		$queue   = self::table( 'mail_queue' );

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

		// Kolejka jest też logiem wysyłek, więc wiersz zostaje po wysłaniu. `user_id` i `anonymized` istnieją od początku:
		// anonimizacja logu wysyłek potrzebuje po czym szukać wierszy konta (adres się zmienia, identyfikator nie).
		// VARCHAR(191), nie 255: indeks na 255 znakach utf8mb4 przekracza limit 767 bajtów na starszych konfiguracjach.
		dbDelta(
			"CREATE TABLE {$queue} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  scheduled_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  kind VARCHAR(40) NOT NULL,
  dedupe_key VARCHAR(191) NOT NULL,
  user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  recipient VARCHAR(191) NOT NULL,
  subject TEXT NOT NULL,
  body LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL,
  attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  anonymized TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY dispatch (status, scheduled_at),
  KEY user_id (user_id)
) ENGINE=InnoDB {$charset};"
		);

		// dbDelta() nie sygnalizuje błędów. Wersję zapisujemy dopiero, gdy istnieją wszystkie tabele z TABLES,
		// inaczej maybe_upgrade() nigdy nie ponowiłby próby.
		$all_exist = true;
		foreach ( self::TABLES as $name ) {
			$all_exist = $all_exist && self::table_exists( self::table( $name ) );
		}
		if ( $all_exist ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
			// Jednorazowy krok aktualizacji: instalacje sprzed crona nie przechodzą aktywacji.
			PurgeUnconfirmed::schedule();
			// To samo dla crona wysyłki: instalacja, która przeszła już migrację 3, nigdy nie
			// zaplanowałaby dispatchera, a wiersze kolejki czekałyby w bazie bez konsumenta.
			DispatchMail::schedule();
		}
	}

	/**
	 * Czy tabela istnieje w bazie.
	 *
	 * @param string $table Pełna nazwa tabeli.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Uruchamia migrację, gdy zapisana wersja jest niższa od bieżącej.
	 * Aktualizacja wtyczki nie odpala hooka aktywacji, więc to jest konieczne.
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( self::OPTION_DB_VERSION, 0 );
		$stored = is_numeric( $stored ) ? (int) $stored : 0;
		if ( $stored < self::DB_VERSION ) {
			self::run();
		}
	}
}
