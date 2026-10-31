<?php
/**
 * Czytnik kroków konfiguracji z `docs/konfiguracja.md`.
 *
 * Dokument jest źródłem prawdy dla kroków: ich treści, kolejności i liczby. Ekran pomocy
 * pokazuje te same kroki, a testy obu suit (jednostkowej i integracyjnej) czytają je stąd,
 * żeby tytuł kroku nie istniał w dwóch miejscach naraz.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Support;

use PortalUsers\Domain\Config\SetupStepKey;

final class ConfigurationDoc { // phpcs:ignore

	/**
	 * Numer kroku w dokumencie => przypadek enuma.
	 *
	 * Mapowanie mieszka w kodzie testowym, nie produkcyjnym: jego zmiana ma być świadoma,
	 * dokładnie jak edycja `SCHEMA_SURFACE` w `MigrationsTest`. Dopisanie kroku do dokumentu
	 * bez dopisania go tutaj (i do `SetupStepKey`) zapala `SetupStepKeyTest`.
	 *
	 * @var array<int, SetupStepKey>
	 */
	public const STEPS = array(
		1 => SetupStepKey::AcfGroup,
		2 => SetupStepKey::GroupSelected,
		3 => SetupStepKey::Professions,
		4 => SetupStepKey::Matrix,
		5 => SetupStepKey::Pages,
		6 => SetupStepKey::Settings,
		7 => SetupStepKey::MailTemplates,
		8 => SetupStepKey::LegacyAccounts,
	);

	/**
	 * Nagłówki kroków: numer kroku => treść nagłówka bez przedrostka `## Krok N. `.
	 *
	 * @return array<int, string>
	 */
	public static function headings(): array {
		$path = dirname( __DIR__, 2 ) . '/docs/konfiguracja.md';
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$out = array();
		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $line ) {
			if ( preg_match( '/^## Krok (\d+)\. (.+)$/', rtrim( $line, "\r" ), $m ) === 1 ) {
				$out[ (int) $m[1] ] = $m[2];
			}
		}

		return $out;
	}
}
