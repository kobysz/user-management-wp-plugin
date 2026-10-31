<?php
/**
 * Krok konfiguracji z wyliczonym stanem.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

/**
 * Krok listy startowej: który to krok, jaki ma stan i jakie podpowiedzi mu towarzyszą.
 *
 * Stany są cztery, bo trzy nie wystarczają. `UNKNOWN` to „nie można sprawdzić” — wyłączone
 * ACF Pro odbiera czym zmierzyć kroki zależne od grupy pól, a pokazanie ich wtedy jako
 * niezrobionych kłamałoby o konfiguracji, która może być w porządku. `INFO` to krok, którego
 * ukończenia nie da się zmierzyć (szablony maili mają treści domyślne i działają nietknięte),
 * więc nie wchodzi do warunku ukończenia.
 *
 * Podpowiedzi są kodami, nie tekstami: tłumaczenie na komunikat należy do warstwy panelu,
 * dokładnie jak w `SettingsIssue`.
 */
final class SetupStep {

	public const DONE    = 'done';
	public const TODO    = 'todo';
	public const UNKNOWN = 'unknown';
	public const INFO    = 'info';

	public const HINT_AFTER_LOGIN_PAGE_UNSET  = 'after_login_page_unset';
	public const HINT_REJECTION_REASONS_EMPTY = 'rejection_reasons_empty';

	/**
	 * Konstruktor.
	 *
	 * @param SetupStepKey       $key    Który krok.
	 * @param string             $status Stan: DONE, TODO, UNKNOWN albo INFO.
	 * @param array<int, string> $hints  Kody podpowiedzi.
	 */
	public function __construct(
		public readonly SetupStepKey $key,
		public readonly string $status,
		public readonly array $hints
	) {}
}
