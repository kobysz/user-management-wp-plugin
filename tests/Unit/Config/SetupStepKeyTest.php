<?php
/**
 * Testy: zgodność kroków listy startowej z `docs/konfiguracja.md`.
 *
 * Dokument jest źródłem prawdy dla kroków konfiguracji: ich treści, kolejności i liczby.
 * Ekran pomocy pokazuje te same kroki, więc rozjazd dokumentu z enumem jest awarią, której
 * nikt nie zauważy — stąd ten test. Mapowanie numeru kroku na przypadek enuma trzyma
 * `ConfigurationDoc::STEPS`, czyli kod testowy: jego zmiana ma być świadoma, jak edycja
 * `SCHEMA_SURFACE` w `MigrationsTest`.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Config\SetupStepKey;
use PortalUsers\Tests\Support\ConfigurationDoc;

final class SetupStepKeyTest extends TestCase {

	public function test_dokument_ma_dokladnie_kroki_z_enuma_w_tej_samej_kolejnosci(): void {
		$this->assertSame( array( 1, 2, 3, 4, 5, 6, 7, 8 ), array_keys( ConfigurationDoc::headings() ), 'docs/konfiguracja.md musi mieć osiem nagłówków „## Krok N.” numerowanych po kolei.' );
		$this->assertSame( array( 1, 2, 3, 4, 5, 6, 7, 8 ), array_keys( ConfigurationDoc::STEPS ) );
		$this->assertSame( array_values( ConfigurationDoc::STEPS ), SetupStepKey::cases(), 'Kolejność przypadków SetupStepKey musi być kolejnością kroków w dokumencie.' );
	}

	public function test_kazdy_naglowek_dokumentu_ma_swoj_przypadek_enuma(): void {
		foreach ( ConfigurationDoc::headings() as $number => $title ) {
			$this->assertArrayHasKey( $number, ConfigurationDoc::STEPS, 'Krok ' . $number . ' („' . $title . '”) nie ma przypadku w SetupStepKey ani wpisu w STEPS.' );
			$this->assertNotSame( '', $title );
		}
	}
}
