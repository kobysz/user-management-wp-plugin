<?php
/**
 * Polityka ponawiania nieudanych wysyłek.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Mail;

/**
 * Polityka ponawiania nieudanych wysyłek: trzy próby, narastające opóźnienie.
 */
final class RetryPolicy {

	public const MAX_ATTEMPTS = 3;

	/**
	 * Opóźnienie w sekundach po n-tej nieudanej próbie.
	 *
	 * @var array<int,int>
	 */
	private const DELAYS = array(
		1 => 60,
		2 => 300,
	);

	/**
	 * Zwraca opóźnienie do następnej próby albo null, gdy próby się wyczerpały.
	 *
	 * @param int $attempts Liczba prób wykonanych do tej pory (łącznie z tą, która właśnie zawiodła).
	 * @return int|null Sekundy do następnej próby; null oznacza status failed.
	 */
	public static function next( int $attempts ): ?int {
		return self::DELAYS[ $attempts ] ?? null;
	}
}
