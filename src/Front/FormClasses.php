<?php
/**
 * Klasy CSS formularzy frontu: jedno źródło dla wszystkich widoków.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

/**
 * Mapa rola => klasy Bootstrapa. Nie zna klas `pusers-*`: te są kontraktem wtyczki z własnym
 * skryptem i z zasięgiem arkusza, więc widoki dopisują je same, a filtr nie ma do nich dostępu.
 *
 * Ta mapa jest też jedyną listą, którą musi pokryć arkusz zapasowy — patrz
 * `tests/Unit/Front/StylesheetCoverageTest.php`.
 */
final class FormClasses {

	/**
	 * Filtr otwierający mapę dla skórki, która nie stoi na Bootstrapie.
	 *
	 * Oczekiwany kształt: `array<string, string>`. Wartości pochodzą spoza wtyczki, więc są
	 * traktowane jak dane z żądania: nie-tablica, wartość niebędąca napisem i rola spoza mapy
	 * są odrzucane, a to, co zostaje, wychodzi do HTML-a wyłącznie przez `esc_attr()`.
	 */
	public const FILTER = 'pusers_form_classes';

	/**
	 * Role i ich klasy domyślne. Rola o pustej wartości istnieje po to, żeby skórka mogła coś
	 * w to miejsce wstawić filtrem, nie dlatego, że o niej zapomniano.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULTS = array(
		'wrapper'          => '',
		'wrapper_card'     => 'card',
		'card_body'        => 'card-body',
		'form'             => '',
		'field'            => 'mb-3',
		'label'            => 'form-label',
		'control'          => 'form-control',
		'select'           => 'form-select',
		'check'            => 'form-check',
		'check_input'      => 'form-check-input',
		'check_label'      => 'form-check-label',
		'button'           => 'btn btn-primary',
		'notice_success'   => 'alert alert-success',
		'notice_info'      => 'alert alert-info',
		'notice_danger'    => 'alert alert-danger',
		'notice_list'      => 'mb-0 ps-3',
		'invalid'          => 'is-invalid',
		'invalid_feedback' => 'invalid-feedback d-block',
		'warning_feedback' => 'form-text pusers-warning-feedback',
	);

	/**
	 * Mapa domyślna, bez filtra. Czyta ją test pokrycia arkusza zapasowego.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Klasy roli. Rola spoza mapy daje pusty łańcuch także wtedy, gdy dołożył ją filtr.
	 *
	 * @param string $role Nazwa roli.
	 */
	public static function for( string $role ): string {
		if ( ! array_key_exists( $role, self::DEFAULTS ) ) {
			return '';
		}

		// Nazwa filtra wpisana dosłownie (reguła prefiksów); stała FILTER służy testom i dokumentacji.
		$filtered = apply_filters( 'pusers_form_classes', self::DEFAULTS );
		if ( ! is_array( $filtered ) || ! isset( $filtered[ $role ] ) || ! is_string( $filtered[ $role ] ) ) {
			return self::DEFAULTS[ $role ];
		}

		return $filtered[ $role ];
	}

	/**
	 * Gotowa zawartość atrybutu `class`: klasy własne wtyczki, potem klasy roli, zescapowane.
	 *
	 * @param string $role     Nazwa roli.
	 * @param string ...$extra Klasy wtyczki (`pusers-*`), których filtr nie dotyka.
	 */
	public static function attr( string $role, string ...$extra ): string {
		$classes   = $extra;
		$classes[] = self::for( $role );

		return esc_attr( implode( ' ', array_filter( $classes, static fn( string $name ): bool => '' !== $name ) ) );
	}
}
