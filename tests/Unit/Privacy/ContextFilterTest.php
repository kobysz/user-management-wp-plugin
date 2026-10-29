<?php
/**
 * Testy reguły anonimizacji kontekstu wiersza logu zdarzeń.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Privacy\ContextFilter;

final class ContextFilterTest extends TestCase {

	public function test_zostaja_wylacznie_stany_konta(): void {
		$out = ContextFilter::anonymize(
			array(
				'status'          => 'approved',
				'previous_status' => 'pending',
				'ip'              => '203.0.113.7',
				'reason'          => 'numer nieprawidłowy',
				'old'             => '5425740',
				'new'             => '5425741',
				'profession'      => 'lekarz',
			)
		);

		$this->assertSame( 'approved', $out['status'] );
		$this->assertSame( 'pending', $out['previous_status'] );
		$this->assertArrayNotHasKey( 'ip', $out );
		$this->assertArrayNotHasKey( 'reason', $out );
		$this->assertArrayNotHasKey( 'old', $out );
		$this->assertArrayNotHasKey( 'new', $out );
		$this->assertArrayNotHasKey( 'profession', $out );
		$this->assertSame( array( 'ip', 'new', 'old', 'profession', 'reason' ), $out[ ContextFilter::REMOVED_KEY ] );
	}

	public function test_kontekst_bez_danych_osobowych_nie_dostaje_listy_usunietych(): void {
		// Brak klucza, a nie pusta lista: pusta lista mówiłaby audytowi, że coś usunięto.
		$out = ContextFilter::anonymize( array( 'status' => 'approved' ) );

		$this->assertSame( array( 'status' => 'approved' ), $out );
		$this->assertArrayNotHasKey( ContextFilter::REMOVED_KEY, $out );
	}

	public function test_pusty_kontekst_zostaje_pusty(): void {
		$this->assertSame( array(), ContextFilter::anonymize( array() ) );
	}

	public function test_klucz_o_nazwie_removed_w_wejsciu_nie_podszywa_sie_pod_liste(): void {
		// Kontekst z własnym kluczem 'removed' trafia na listę usuniętych, a nie nadpisuje jej.
		$out = ContextFilter::anonymize(
			array(
				'status'                       => 'approved',
				ContextFilter::REMOVED_KEY     => 'podszywka',
				'ip'                           => '203.0.113.7',
			)
		);

		$this->assertSame( 'approved', $out['status'] );
		$this->assertSame( array( 'ip', ContextFilter::REMOVED_KEY ), $out[ ContextFilter::REMOVED_KEY ] );
	}
}
