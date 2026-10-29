<?php
/**
 * Jedyne miejsce czytające i zapisujące opcje wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Dostęp do opcji WordPressa należących do wtyczki. Opcja wersji schematu
 * (`pusers_db_version`) należy do klasy Migrations i nie jest tu wymieniana.
 */
final class OptionStore {

	public const OPTION_SETTINGS    = 'pusers_settings';
	public const OPTION_PROFESSIONS = 'pusers_professions';
	public const OPTION_MATRIX      = 'pusers_field_matrix';

	/**
	 * Mapowanie importu ze starej wtyczki i wynik jego podglądu. Podgląd pilnuje, żeby zastosować
	 * można było tylko mapowanie, które administrator widział.
	 */
	public const OPTION_IMPORT_MAPPING = 'pusers_import_mapping';
	public const OPTION_IMPORT_PREVIEW = 'pusers_import_preview';

	/**
	 * Znacznik czasu wdrożenia. Nie jest tablicą, więc jego właściciel czyta i zapisuje go sam;
	 * spis `all()` musi go jednak znać.
	 */
	public const OPTION_DEPLOYED_AT = 'pusers_deployed_at';

	/**
	 * Nadpisania szablonów maili wpisane przez admina, kluczowane `MailKind::value`.
	 */
	public const OPTION_MAIL_TEMPLATES = 'pusers_mail_templates';

	/**
	 * Znaczniki czasu throttlingu wpisów do logu zdarzeń. Nie są tablicami, więc nie przechodzą
	 * przez `read()` ani `write()` — ich właściciele czytają i zapisują je sami, zawsze
	 * z `autoload = false`. Spis `all()` musi je jednak znać, bo inaczej odinstalowanie
	 * zostawiłoby po wtyczce wiersze w `wp_options`.
	 */
	public const OPTION_ROLE_REFUSAL_AT    = 'pusers_role_refusal_logged_at';
	public const OPTION_LIMITER_FAILURE_AT = 'pusers_limiter_failure_at';
	public const OPTION_PURGE_SKIP_AT      = 'pusers_purge_skip_logged_at';

	/**
	 * Czyta opcję jako tablicę; brak opcji lub wartość nietablicowa daje pustą tablicę.
	 *
	 * @param string $option Nazwa opcji.
	 * @return array<mixed>
	 */
	public static function read( string $option ): array {
		$value = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Zapisuje opcję bez autoloadu: żadna z opcji wtyczki nie jest potrzebna przy każdym żądaniu.
	 *
	 * @param string       $option Nazwa opcji.
	 * @param array<mixed> $value  Wartość.
	 */
	public static function write( string $option, array $value ): bool {
		return update_option( $option, $value, false );
	}

	/**
	 * Usuwa opcję.
	 *
	 * @param string $option Nazwa opcji.
	 */
	public static function delete( string $option ): bool {
		return delete_option( $option );
	}

	/**
	 * Nazwy wszystkich opcji wtyczki, po których iteruje odinstalowanie.
	 * Każda nowa opcja musi zostać dopisana tutaj.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::OPTION_SETTINGS,
			self::OPTION_PROFESSIONS,
			self::OPTION_MATRIX,
			self::OPTION_MAIL_TEMPLATES,
			self::OPTION_ROLE_REFUSAL_AT,
			self::OPTION_LIMITER_FAILURE_AT,
			self::OPTION_PURGE_SKIP_AT,
			self::OPTION_IMPORT_MAPPING,
			self::OPTION_IMPORT_PREVIEW,
			self::OPTION_DEPLOYED_AT,
		);
	}
}
