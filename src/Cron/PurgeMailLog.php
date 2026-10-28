<?php
/**
 * Cron czyszczący log wysyłek maili po okresie retencji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Cron;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Persistence\MailQueueRepository;

/**
 * Tabela kolejki jest też logiem wysyłek: wiersz zostaje po wysłaniu, więc bez czyszczenia rosłaby bez końca.
 *
 * Które wiersze wolno skasować, rozstrzyga MailQueueRepository::purge_old(): tylko `sent` i `failed`,
 * a `queued` i `sending` zostają bez względu na wiek, bo to maile, które jeszcze nie wyszły.
 *
 * Przebieg NIE zapisuje nic w logu zdarzeń. PurgeUnconfirmed loguje usunięcia, bo konto jest nieodtwarzalne
 * i jego zniknięcie trzeba umieć wyjaśnić; dzienny wiersz o skasowanych wpisach logu wysyłek zapchałby
 * natomiast tabelę, która jest śladem decyzji redakcji o zatwierdzaniu kont. Brak wpisu jest zamierzony.
 */
final class PurgeMailLog {

	public const HOOK = 'pusers_purge_mail_log';

	/**
	 * Domyślny okres przechowywania wysłanych i nieudanych maili, w dniach od `created_at`.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Podpina przebieg pod hook crona. Harmonogram zakładają aktywacja i jednorazowa migracja
	 * (Migrations::run), nie to wywołanie: biegnie przy każdym żądaniu, a zdarzenie zdjęte przez
	 * administratora nie może wracać przy najbliższym wejściu na stronę.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Zakłada dzienny harmonogram, o ile jeszcze go nie ma.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Zdejmuje harmonogram.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Okres retencji w dniach: domyślny, nadpisywalny filtrem `pusers_mail_log_retention_days`.
	 * Wartość poniżej 1 (zero skasowałoby dzisiejszy log) albo niepoprawna wraca do domyślnej.
	 */
	public static function retention_days(): int {
		$days = apply_filters( 'pusers_mail_log_retention_days', self::RETENTION_DAYS );

		return is_numeric( $days ) && (int) $days >= 1 ? (int) $days : self::RETENTION_DAYS;
	}

	/**
	 * Kasuje wysłane i nieudane wiersze starsze niż okres retencji.
	 */
	public static function run(): void {
		MailQueueRepository::purge_old( gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS ) );
	}
}
