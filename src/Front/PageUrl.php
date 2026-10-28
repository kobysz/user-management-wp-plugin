<?php
/**
 * Adres strony wskazanej w ustawieniach.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

/**
 * JEDYNE miejsce reguły „skąd się bierze adres strony z ustawień”: ta sama jest potrzebna stronie
 * rejestracji, logowania i odzyskiwania hasła, a kopie rozjechałyby się przy pierwszej zmianie.
 */
final class PageUrl {

	/**
	 * Adres strony o danym identyfikatorze albo strona główna.
	 *
	 * Strona główna także wtedy, gdy strona została usunięta z panelu: pusty adres w przekierowaniu
	 * zostawia człowieka tam, skąd przyszedł, bez komunikatu i bez wskazówki, co się stało.
	 *
	 * @param int $pageId Identyfikator strony z ustawień (0 = brak).
	 */
	public static function for( int $pageId ): string {
		$url = self::existing( $pageId );

		return '' === $url ? home_url( '/' ) : $url;
	}

	/**
	 * Adres strony o danym identyfikatorze albo `''`, gdy strony nie wskazano lub została usunięta
	 * z panelu.
	 *
	 * To ta sama reguła co w `for()`, tylko BEZ odwrotu na stronę główną — dla wywołujących, dla
	 * których strona główna jest złą odpowiedzią. Odnośnik w mailu jest tego przykładem: kieruje
	 * na konkretną stronę wtyczki i jeżeli tej strony nie ma, lepiej zostawić odnośnik rdzenia niż
	 * wysłać człowieka na stronę główną, która nic z jego kluczem nie zrobi.
	 *
	 * @param int $pageId Identyfikator strony z ustawień (0 = brak).
	 */
	public static function existing( int $pageId ): string {
		$link = $pageId > 0 ? get_permalink( $pageId ) : false;

		return false === $link ? '' : $link;
	}
}
