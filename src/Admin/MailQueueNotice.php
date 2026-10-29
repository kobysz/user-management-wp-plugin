<?php
/**
 * Komunikat w panelu o kolejce maili, która przestała się opróżniać.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Persistence\MailQueueRepository;

/**
 * Wysyłka maili zależy od crona, a cron na części hostingów nie chodzi: `DISABLE_WP_CRON` bez crona
 * systemowego albo zablokowane połączenie zwrotne (typowe na zamkniętym hostingu firmowym) sprawiają,
 * że nie wychodzi NIC i nigdy. Strona rejestracji mówi wtedy „sprawdź skrzynkę", lekarz nie dostaje
 * linku, a `PurgeUnconfirmed` kasuje konto po siedmiu dniach. Jedynym śladem jest licznik na ekranie
 * logu wysyłek, czyli ekranie, na który administrator wchodzi dopiero wtedy, gdy już podejrzewa awarię.
 * Specyfikacja nazywa cel wrogi tego samego kształtu: „Redakcja nie wie o czekających wnioskach, bo nic
 * jej o nich nie przypomina".
 *
 * Komunikat jest TANI: biegnie przy wejściach do panelu, więc najpierw pyta o uprawnienie (bez niego
 * nie ma dla kogo), a potem robi jedno zapytanie po indeksie. Pusta albo świeża kolejka nie wypisuje nic:
 * przy przebiegu minutowym świeże wiersze są normą, a komunikat przy każdym z nich administrator
 * przestałby czytać.
 */
final class MailQueueNotice {

	/**
	 * Po tylu sekundach zalegania najstarszego wiersza ostrzegamy. 15 minut, czyli piętnaście
	 * nieodbytych przebiegów minutowych: tyle nie zbiera się z powodu chwilowego opóźnienia crona,
	 * a jednocześnie jest krócej niż doba, po której lekarz dawno porzucił rejestrację.
	 */
	public const WARN_AFTER = 900;

	/**
	 * Podpina komunikat pod `admin_notices`.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Wypisuje ostrzeżenie, o ile kolejka faktycznie zalega.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		$oldest = MailQueueRepository::oldest_queued_at();
		if ( null === $oldest ) {
			return;
		}

		$queuedAt = strtotime( $oldest . ' UTC' );
		if ( false === $queuedAt ) {
			// Nieczytelny znacznik z bazy nie może udawać zalegania sprzed epoki.
			return;
		}

		$waiting = time() - $queuedAt;
		if ( $waiting < self::WARN_AFTER ) {
			return;
		}

		echo '<div class="notice notice-warning pusers-mail-queue-notice"><p>'
			. esc_html__( 'Kolejka maili nie jest opróżniana. Najczęstsza przyczyna to niedziałający WP-Cron: stała DISABLE_WP_CRON bez crona systemowego albo zablokowane połączenie zwrotne serwera. Dopóki to trwa, nie wychodzi żaden mail wtyczki — w tym linki potwierdzające adres, bez których nowe konta zostaną usunięte jako niepotwierdzone.', 'portal-users' )
			. ' ' . esc_html(
				sprintf(
					/* translators: %d: liczba minut. */
					__( 'Najstarsza wiadomość czeka od minut: %d.', 'portal-users' ),
					(int) floor( $waiting / MINUTE_IN_SECONDS )
				)
			)
			. ' <a href="' . esc_url( Menu::url( Menu::SLUG_MAIL_LOG ) ) . '">'
			. esc_html__( 'Zobacz log wysyłek', 'portal-users' )
			. '</a></p></div>';
	}
}
