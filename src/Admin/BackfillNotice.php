<?php
/**
 * Ostrzeżenie na ekranach wtyczki o kontach, które nie mają stanu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Account\Backfill;

/**
 * Konto bez stanu nie podlega żadnej regule bramy logowania (ani odmowie dla `rejected`, ani dla `pending`):
 * brama wpuszcza je celowo, żeby nie zamknąć poza portalem administratora dodanego ręcznie. Dla kont
 * sprzed wdrożenia wtyczki to luka, którą zamyka dopiero nadanie stanu, więc administrator ma o niej wiedzieć,
 * dopóki istnieje.
 *
 * Komunikat jest ograniczony do ekranów wtyczki: pulpit i ekrany rdzenia go nie dostają. Jest też tani,
 * bo najpierw pyta o uprawnienie i o stronę, a dopiero potem liczy konta jednym zapytaniem.
 */
final class BackfillNotice {

	/**
	 * Podpina komunikat pod `admin_notices`.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Wypisuje ostrzeżenie, o ile jesteśmy na ekranie wtyczki i są konta w zakresie backfillu.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) || ! self::on_plugin_screen() ) {
			return;
		}

		$count = Backfill::stateless_count();
		if ( 0 === $count ) {
			return;
		}

		echo '<div class="notice notice-warning pusers-backfill-notice"><p>'
			. esc_html(
				sprintf(
					/* translators: %d: liczba kont bez stanu */
					__( 'Konta sprzed wdrożenia wtyczki bez stanu: %d. Żadna reguła bramy logowania ich nie obejmuje, więc wchodzą na portal niezależnie od tego, czy ktoś je zatwierdził.', 'portal-users' ),
					$count
				)
			)
			. ' <a href="' . esc_url( Menu::url( Menu::SLUG_IMPORT ) ) . '">'
			. esc_html__( 'Nadaj im stan w narzędziu importu', 'portal-users' )
			. '</a></p></div>';
	}

	/**
	 * Czy bieżący ekran to jedna z podstron wtyczki.
	 */
	private static function on_plugin_screen(): bool {
		// Odczyt do wyświetlenia: nic nie zmienia, więc nonce nie obowiązuje; wartość tylko porównujemy z listą.
		if ( ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array(
			$page,
			array(
				Menu::SLUG_SETTINGS,
				Menu::SLUG_PROFESSIONS,
				Menu::SLUG_MATRIX,
				Menu::SLUG_MAIL_TEMPLATES,
				Menu::SLUG_MAIL_LOG,
				Menu::SLUG_APPLICATIONS,
				Menu::SLUG_IMPORT,
				// Ekran pomocy należy do listy z tego samego powodu co pozostałe: dokumentuje krok
				// o kontach sprzed wdrożenia, więc administrator, który na nim stoi, ma dostać
				// ostrzeżenie razem z odnośnikiem do importu.
				Menu::SLUG_HELP,
			),
			true
		);
	}
}
