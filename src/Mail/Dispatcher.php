<?php
/**
 * Konsument kolejki maili: zajmuje wiersz, wysyła go i zapisuje wynik.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Mail;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\HtmlBody;
use PortalUsers\Domain\Mail\RetryPolicy;
use PortalUsers\Persistence\MailQueueRepository;

/**
 * Jedyne miejsce we wtyczce, które woła `wp_mail()`. SQL-a nie dotyka: wszystko idzie przez
 * MailQueueRepository.
 *
 * Przebieg nie zakłada, że jest sam. Cron minutowy i zdarzenie natychmiastowe mogą ruszyć w tej samej
 * sekundzie, więc o prawo do wysyłki rozstrzyga `claim()` — warunkowy UPDATE, który oddaje numer próby.
 * Ten numer jedzie dalej do każdego zapisu wyniku: bez niego spóźniony przebieg nadpisałby wiersz, który
 * po odzysku należy już do kogoś innego, i lekarz dostałby drugi link potwierdzający.
 */
final class Dispatcher {

	/**
	 * Po tylu sekundach wiersz w stanie `sending` uznajemy za porzucony (przebieg ubity timeoutem PHP).
	 */
	public const STALE_AFTER = 300;

	/**
	 * Domyślna liczba wierszy na przebieg.
	 */
	public const DEFAULT_BATCH = 20;

	/**
	 * Górna granica paczki, niezależna od filtra. Paczka na całą tabelę skończyłaby się timeoutem
	 * PHP w jej środku, a to zostawia wiersz w stanie `sending` — czyli w tym jednym stanie, który
	 * wymaga odzysku i grozi powtórną wysyłką. Lepiej wyjść z przebiegu i wrócić za minutę.
	 */
	public const MAX_BATCH = 200;

	/**
	 * Odzyskuje porzucone wiersze i wysyła paczkę gotowych do wysyłki.
	 */
	public static function run(): void {
		MailQueueRepository::recover_stale( gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER ) );

		/**
		 * Liczba wierszy wysyłanych w jednym przebiegu.
		 *
		 * @param int $batch Rozmiar paczki.
		 */
		$batch = (int) apply_filters( 'pusers_mail_batch_size', self::DEFAULT_BATCH );
		if ( $batch <= 0 ) {
			// Wartość ≤ 0 zatrzymałaby kolejkę na zawsze (`LIMIT 0` nie oddaje nic), a razem z nią
			// linki potwierdzające. Obca wtyczka nie może tego zrobić po cichu: wracamy na domyślną.
			$batch = self::DEFAULT_BATCH;
		}
		$batch = min( $batch, self::MAX_BATCH );

		foreach ( MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), $batch ) as $row ) {
			self::dispatch( $row );
		}
	}

	/**
	 * Zajmuje wiersz, wysyła go i zapisuje wynik tą próbą, którą zajęcie oddało.
	 *
	 * @param array<string, mixed> $row Wiersz kolejki z `due()`.
	 */
	private static function dispatch( array $row ): void {
		$id      = (int) self::text( $row, 'id' );
		$attempt = MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );

		// Porównanie identycznościowe z null, NIE test prawdziwości: `0` jest tu czymś innym niż
		// przegrane zajęcie i tych dwóch wartości nie wolno zlewać. Przegrane zajęcie znaczy, że
		// wiersz wysyła ktoś inny — mijamy go bez wysyłki i bez zapisu.
		if ( null === $attempt ) {
			return;
		}

		$reason = '';
		$failed = static function ( $error ) use ( &$reason ): void {
			if ( $error instanceof \WP_Error ) {
				$reason = $error->get_error_message();
			}
		};

		// Nasłuch powodu i opakowanie HTML zakładamy TUŻ PRZED jednym wywołaniem i zdejmujemy
		// w `finally`. Filtr zostawiony na stałe zmieniłby typ treści mailom innych wtyczek w tym
		// samym żądaniu, a `wp_mail()` potrafi rzucić wyjątkiem z PHPMailera.
		//
		// Priorytet PHP_INT_MAX, nie domyślny: `wp_mail()` czyta typ treści NAJPIERW z nagłówków,
		// a filtr stosuje po nich, więc własny nagłówek `Content-Type` byłby tu SŁABSZY od filtra,
		// nie mocniejszy — przegrałby z filtrem obcej wtyczki na dowolnym priorytecie. Wygranie
		// bezwarunkowe jest bezpieczne, bo filtr żyje przez dokładnie jedno `wp_mail()` na mailu
		// tej wtyczki, a cena przegranej jest widoczna dla człowieka: lekarz dostaje `<!DOCTYPE html>`
		// jako tekst. Wtyczka SMTP, która podmienia całe `wp_mail()`, może filtr zignorować —
		// to już poza naszym zasięgiem.
		add_action( 'wp_mail_failed', $failed );
		add_filter( 'wp_mail_content_type', array( self::class, 'html_content_type' ), PHP_INT_MAX );
		try {
			$sent = wp_mail( self::text( $row, 'recipient' ), self::text( $row, 'subject' ), HtmlBody::wrap( self::text( $row, 'body' ) ) );
		} catch ( \Throwable $e ) {
			// Wiersz zostawiony w `sending` przez wyjątek wyszedłby drugi raz po odzysku, a paczka
			// stanęłaby na pierwszym zepsutym mailu.
			$sent   = false;
			$reason = $e->getMessage();
		} finally {
			remove_filter( 'wp_mail_content_type', array( self::class, 'html_content_type' ), PHP_INT_MAX );
			remove_action( 'wp_mail_failed', $failed );
		}

		// Wyniku zapisów (`mark_sent`, `reschedule`, `mark_failed`) celowo nie sprawdzamy, ale cisza
		// jest właściwa tylko dla jednej z dwóch przyczyn odmowy. Pierwsza: wiersz należy już do
		// innego przebiegu — wtedy nie ma tu nic do robienia i tak ma być. Druga: zapis padł na
		// bazie — wtedy wiersz zostaje w `sending`, a log wysyłek nie odpowiada na pytanie „czy mail
		// wyszedł", dopóki `recover_stale()` nie zwróci wiersza do kolejki. Rozróżnienia tych dwóch
		// przypadków repozytorium nie oddaje, więc nie udajemy, że je mamy.
		if ( $sent ) {
			MailQueueRepository::mark_sent( $id, gmdate( 'Y-m-d H:i:s' ), $attempt );
			return;
		}

		if ( '' === $reason ) {
			// `wp_mail()` potrafi oddać false bez podniesienia `wp_mail_failed`. Log wysyłek ma
			// odpowiadać na pytanie „czy mail wyszedł", więc puste pole błędu nie jest odpowiedzią.
			$reason = __( 'Wysyłka nie udała się, a transport nie podał powodu.', 'portal-users' );
		}

		// `claim()` podniósł już licznik, więc to liczba prób wykonanych do tej pory, łącznie z tą,
		// która właśnie zawiodła: 1 => 60 s, 2 => 300 s, 3 => koniec.
		$delay = RetryPolicy::next( $attempt );
		if ( null === $delay ) {
			MailQueueRepository::mark_failed( $id, $reason, $attempt );
			return;
		}

		MailQueueRepository::reschedule( $id, gmdate( 'Y-m-d H:i:s', time() + $delay ), $reason, $attempt );
	}

	/**
	 * Kolumna wiersza jako tekst. `due()` oddaje kolumny jako `mixed`, choć wpdb zwraca napisy.
	 *
	 * @param array<string, mixed> $row  Wiersz kolejki.
	 * @param string               $name Nazwa kolumny.
	 */
	private static function text( array $row, string $name ): string {
		$value = $row[ $name ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Typ treści wysyłanego maila. Metoda nazwana, nie domknięcie: `remove_filter()` musi mieć
	 * co zdjąć w `finally`.
	 */
	public static function html_content_type(): string {
		return 'text/html';
	}
}
