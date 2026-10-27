<?php
/**
 * Liczniki prób na transientach z godzinnym oknem.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Persistence\EventLog;

/**
 * Licznik prób per kubełek (np. adres IP albo adres e-mail). Okno jest stałe: liczy się od pierwszej
 * próby i po BLOCK_MINUTES licznik znika sam, bo żyje w transiencie. Kolejne próby NIE przedłużają okna,
 * więc uczciwy użytkownik odzyskuje dostęp najpóźniej godzinę po pierwszej próbie.
 *
 * Odczyt i zapis nie są atomowe: równoległe żądania mogą się policzyć raz za mało. Dla progów godzinnych
 * to dopuszczalny błąd w stronę łagodniejszą o pojedyncze próby.
 */
final class RateLimiter {

	public const BLOCK_MINUTES = 60;

	private const KEY_PREFIX = 'pusers_rl_';

	/**
	 * Akcja w logu zdarzeń, gdy licznik nie daje się zapisać (progi przestają wtedy obowiązywać).
	 */
	public const FAILURE_LOG_ACTION = 'rate_limiter_failed';

	/**
	 * Opcja z momentem ostatniego zgłoszenia awarii zapisu. Opcja, nie transient: przy awarii magazynu
	 * transientów flaga w transiencie by nie działała i zapis w logu wróciłby na każde żądanie.
	 */
	public const FAILURE_OPTION = 'pusers_limiter_failure_at';

	/**
	 * Zlicza próbę.
	 *
	 * @param string $bucket Nazwa kubełka.
	 * @return int Liczba prób w bieżącym oknie, z tą próbą włącznie.
	 */
	public static function hit( string $bucket ): int {
		$now    = time();
		$entry  = self::read( $bucket );
		$count  = null === $entry ? 1 : $entry['count'] + 1;
		$start  = null === $entry ? $now : $entry['start'];
		$expiry = max( 1, self::BLOCK_MINUTES * MINUTE_IN_SECONDS - ( $now - $start ) );

		$saved = set_transient(
			self::key( $bucket ),
			array(
				'count' => $count,
				'start' => $start,
			),
			$expiry
		);
		// set_transient() zwraca false także wtedy, gdy zapisywana wartość jest identyczna z zapisaną (np. dwa
		// równoległe żądania policzyły to samo), więc przed zgłoszeniem awarii czytamy licznik z powrotem.
		if ( ! $saved ) {
			$stored = self::read( $bucket );
			if ( null === $stored || $stored['count'] !== $count ) {
				self::report_failure();
			}
		}

		return $count;
	}

	/**
	 * Czy to pierwsze wywołanie dla danego okna kubełka: zwraca true raz na okno licznika `$windowBucket`
	 * (okno zaczyna się razem z licznikiem i razem z nim się kończy), potem false. Służy do pisania do logu
	 * jednego wiersza na okno. Gdy okno nie istnieje, zwraca true.
	 *
	 * @param string $marker       Nazwa znacznika (osobny od kubełka licznika).
	 * @param string $windowBucket Kubełek, którego okno obowiązuje.
	 */
	public static function once_per_window( string $marker, string $windowBucket ): bool {
		$entry = self::read( $windowBucket );
		if ( null === $entry ) {
			return true;
		}

		if ( get_transient( self::key( $marker ) ) === $entry['start'] ) {
			return false;
		}

		$remaining = max( 1, self::BLOCK_MINUTES * MINUTE_IN_SECONDS - ( time() - $entry['start'] ) );
		set_transient( self::key( $marker ), $entry['start'], $remaining );
		return true;
	}

	/**
	 * Liczba prób w bieżącym oknie, bez zliczania.
	 *
	 * @param string $bucket Nazwa kubełka.
	 */
	public static function count( string $bucket ): int {
		$entry = self::read( $bucket );
		return null === $entry ? 0 : $entry['count'];
	}

	/**
	 * Zeruje licznik. Dla przyszłego przycisku zdejmującego blokadę w panelu; kod produkcyjny
	 * nie woła tego dziś.
	 *
	 * @param string $bucket Nazwa kubełka.
	 */
	public static function clear( string $bucket ): void {
		delete_transient( self::key( $bucket ) );
	}

	/**
	 * Zapomina zgłoszenie awarii zapisu (używane przez testy).
	 */
	public static function reset_failure_report(): void {
		delete_option( self::FAILURE_OPTION );
	}

	/**
	 * Zgłasza w logu zdarzeń nieudany zapis licznika, najwyżej raz na godzinę, żeby trwająca awaria nie
	 * puchła tempem ruchu. Nie zmienia zachowania bramki: administrator ma się tylko dowiedzieć, że
	 * progi chwilowo nie działają.
	 */
	private static function report_failure(): void {
		$last = get_option( self::FAILURE_OPTION, 0 );
		if ( is_numeric( $last ) && time() - (int) $last < HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::FAILURE_OPTION, time(), false );
		EventLog::write( self::FAILURE_LOG_ACTION, 0, 0, array( 'reason' => 'storage_unavailable' ) );
	}

	/**
	 * Zapisany stan kubełka albo null, gdy licznika nie ma lub jest uszkodzony.
	 *
	 * @param string $bucket Nazwa kubełka.
	 * @return array{count: int, start: int}|null
	 */
	private static function read( string $bucket ): ?array {
		$value = get_transient( self::key( $bucket ) );
		if ( is_array( $value ) && isset( $value['count'], $value['start'] ) && is_int( $value['count'] ) && is_int( $value['start'] ) ) {
			return array(
				'count' => $value['count'],
				'start' => $value['start'],
			);
		}
		return null;
	}

	/**
	 * Klucz transientu: skrót, bo nazwa kubełka zawiera adresy (długość klucza jest ograniczona, a adresu
	 * e-mail nie trzymamy jawnie w tabeli opcji).
	 *
	 * @param string $bucket Nazwa kubełka.
	 */
	private static function key( string $bucket ): string {
		return self::KEY_PREFIX . md5( $bucket );
	}
}
