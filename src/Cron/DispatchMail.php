<?php
/**
 * Cron opróżniający kolejkę maili.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Cron;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Mail\Dispatcher;
use PortalUsers\Mail\MailQueue;

/**
 * Harmonogram wysyłki: cykliczny przebieg co minutę i zdarzenie jednorazowe dla maili, na które
 * człowiek czeka przy ekranie (nazwę tego drugiego trzyma MailQueue, bo kolejka zakłada je przy
 * wstawianiu wiersza).
 *
 * Rdzeń nie ma harmonogramu krótszego niż `hourly`, a lekarz czekający na link potwierdzający nie
 * może czekać godzinę: wpis `pusers_minute` dokładamy przez `cron_schedules`.
 */
final class DispatchMail {

	public const HOOK = 'pusers_dispatch_mail';

	/**
	 * Nazwa własnego harmonogramu dokładanego do `cron_schedules`.
	 */
	public const SCHEDULE = 'pusers_minute';

	/**
	 * Interwał harmonogramu w sekundach.
	 */
	private const INTERVAL = 60;

	/**
	 * Podpina przebieg pod oba zdarzenia i dokłada harmonogram minutowy. Harmonogram zakładają
	 * aktywacja i jednorazowa migracja (Migrations::run), NIE to wywołanie: biegnie przy każdym
	 * żądaniu, a administrator, który zdjął zdarzenie (np. WP Crontrol), nie może go odzyskiwać
	 * przy najbliższym wejściu na stronę.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- minuta jest celowa: na mail z linkiem potwierdzającym człowiek czeka przy ekranie.
		add_action( self::HOOK, array( Dispatcher::class, 'run' ) );
		add_action( MailQueue::DISPATCH_HOOK, array( Dispatcher::class, 'run' ) );
	}

	/**
	 * Dokłada harmonogram minutowy do listy rdzenia.
	 *
	 * @param mixed $schedules Harmonogramy crona; obca wtyczka może oddać z tego filtra cokolwiek.
	 * @return array<array-key, mixed>
	 */
	public static function add_schedule( $schedules ): array {
		$out = is_array( $schedules ) ? $schedules : array();

		$out[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => self::display(),
		);

		return $out;
	}

	/**
	 * Nazwa harmonogramu dla ekranów listujących crona (np. WP Crontrol).
	 *
	 * Tłumaczenia wolno żądać dopiero od `init`, a tu wchodzi się także wcześniej: jednorazowy krok
	 * migracji woła `wp_schedule_event()` na `plugins_loaded`, a ten przez `wp_get_schedules()`
	 * dojeżdża do `add_schedule()`. `__()` w tej chwili każe rdzeniowi doładować text domain
	 * „just in time", co od WP 6.7 podnosi `_doing_it_wrong` (sprawdzone w tym środowisku: nota
	 * faktycznie padała, patrz test drogi aktualizacji w MigrationsTest). Napis zapasowy jest tym
	 * samym napisem co msgid, bo językiem źródłowym wtyczki jest polski; ekrany, które tę nazwę
	 * pokazują, biegną długo po `init` i dostają ją przetłumaczoną.
	 */
	private static function display(): string {
		if ( ! did_action( 'init' ) ) {
			return 'Co minutę (Portal Users)';
		}

		return __( 'Co minutę (Portal Users)', 'portal-users' );
	}

	/**
	 * Zakłada harmonogram cykliczny, o ile jeszcze go nie ma. Pierwszy przebieg od razu: wiersze
	 * mogą już leżeć w kolejce (aktualizacja wtyczki nie odpala aktywacji), a opóźnienie startu
	 * trzymałoby w bazie linki, na które ktoś czeka.
	 *
	 * WYMAGA, by `register()` już się wykonało: `wp_schedule_event()` oddaje false, gdy nazwy
	 * harmonogramu nie ma w `wp_get_schedules()`, a `Migrations::run()` zapisuje wersję schematu
	 * PRZED tym wywołaniem — zbyt wczesne wywołanie byłoby więc cichym no-opem, którego
	 * `maybe_upgrade()` nigdy nie ponowi. Trzyma to test drogi aktualizacji w MigrationsTest.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Zdejmuje OBA zdarzenia; po wyłączeniu wtyczki zostawiony cron wołałby nieistniejącą klasę.
	 * Zdarzeń natychmiastowych może leżeć kilka, bo każde kolejkowanie zakłada własne.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( MailQueue::DISPATCH_HOOK );
	}
}
