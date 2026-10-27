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
	 * Czy awaria zapisu została już zgłoszona w tym żądaniu.
	 *
	 * @var bool
	 */
	private static bool $failure_reported = false;

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
		if ( ! $saved ) {
			self::report_failure();
		}

		return $count;
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
	 * Zeruje flagę zgłoszonej awarii (nowe żądanie; używane też przez testy).
	 */
	public static function reset_failure_report(): void {
		self::$failure_reported = false;
	}

	/**
	 * Zgłasza w logu zdarzeń nieudany zapis licznika, raz na żądanie. Nie zmienia zachowania bramki:
	 * administrator ma się tylko dowiedzieć, że progi chwilowo nie działają.
	 */
	private static function report_failure(): void {
		if ( self::$failure_reported ) {
			return;
		}
		self::$failure_reported = true;
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
