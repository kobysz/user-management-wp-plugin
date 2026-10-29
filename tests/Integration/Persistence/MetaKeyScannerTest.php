<?php
/**
 * Testy skanera kluczy user_meta w prawdziwej bazie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Import\MetaKeyStat;
use PortalUsers\Persistence\MetaKeyScanner;
use WP_UnitTestCase;

final class MetaKeyScannerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Instalacja testowa ma własne metadane domyślnych kont (nickname, rich_editing...); skan ma się opierać tylko na danych testu.
		// Transakcja testu jest wycofywana, więc nic z tego nie przeżywa.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->usermeta ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- czyszczenie tabeli rdzenia w transakcji testu.
	}

	/**
	 * @return int Identyfikator nowego konta.
	 */
	private function account(): int {
		global $wpdb;
		$id = (int) self::factory()->user->create();

		// Rdzeń dopisuje nowemu kontu własne metadane (nickname, use_ssl...); test ma widzieć wyłącznie to, co sam zapisze.
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- czyszczenie tabeli rdzenia w transakcji testu.

		return $id;
	}

	/**
	 * @param list<MetaKeyStat> $stats Wynik skanu.
	 * @return list<string> Klucze w kolejności wyniku.
	 */
	private function keys( array $stats ): array {
		return array_map( static fn( MetaKeyStat $stat ): string => $stat->key, $stats );
	}

	public function test_liczy_konta_a_nie_wiersze(): void {
		$id = $this->account();
		add_user_meta( $id, 'wpmem_pwz', '111' );
		add_user_meta( $id, 'wpmem_pwz', '222' );

		$stats = MetaKeyScanner::scan();

		$this->assertSame( array( 'wpmem_pwz' ), $this->keys( $stats ) );
		$this->assertSame( 1, $stats[0]->accounts );
	}

	public function test_pomija_klucze_wtyczki_i_prywatne_i_systemowe(): void {
		global $wpdb;
		$id = $this->account();
		foreach ( array( 'pusers_status', 'pusers_acf_pwz', '_acf_shadow', $wpdb->prefix . 'capabilities', 'wpmem_pwz' ) as $key ) {
			update_user_meta( $id, $key, 'x' );
		}

		$this->assertSame( array( 'wpmem_pwz' ), $this->keys( MetaKeyScanner::scan() ) );
	}

	public function test_podkreslenie_w_przedrostku_nie_dziala_jak_wildcard(): void {
		$id = $this->account();
		// Gdyby przedrostek nie przechodził przez esc_like(), "_" pasowałoby do dowolnego znaku, a "pusersXstatus" zniknęłoby ze skanu.
		update_user_meta( $id, 'pusersXstatus', 'x' );
		update_user_meta( $id, 'wpmem_pwz', 'x' );

		$this->assertSame( array( 'pusersXstatus', 'wpmem_pwz' ), $this->keys( MetaKeyScanner::scan() ) );
	}

	public function test_sortuje_po_liczbie_kont_a_przy_rownej_alfabetycznie(): void {
		$a = $this->account();
		$b = $this->account();
		$c = $this->account();
		// Kolejność wstawiania: zz (1 konto), mm (2), aa (1), bb (3) — oczekiwana: bb, mm, aa, zz.
		update_user_meta( $a, 'zz_key', '1' );
		update_user_meta( $a, 'mm_key', '1' );
		update_user_meta( $b, 'mm_key', '1' );
		update_user_meta( $c, 'aa_key', '1' );
		update_user_meta( $a, 'bb_key', '1' );
		update_user_meta( $b, 'bb_key', '1' );
		update_user_meta( $c, 'bb_key', '1' );

		$stats = MetaKeyScanner::scan();

		$this->assertSame( array( 'bb_key', 'mm_key', 'aa_key', 'zz_key' ), $this->keys( $stats ) );
		$this->assertSame( array( 3, 2, 1, 1 ), array_map( static fn( MetaKeyStat $s ): int => $s->accounts, $stats ) );
	}

	public function test_limit_obcina_liste(): void {
		$ids = array( $this->account(), $this->account(), $this->account(), $this->account(), $this->account() );
		// k1 ma 1 konto, k2 ma 2, ... k5 ma 5; najliczniejsze to k5 i k4.
		for ( $n = 1; $n <= 5; $n++ ) {
			for ( $i = 0; $i < $n; $i++ ) {
				update_user_meta( $ids[ $i ], 'k' . $n, 'v' );
			}
		}

		$this->assertSame( array( 'k5', 'k4' ), $this->keys( MetaKeyScanner::scan( 2 ) ) );
	}

	public function test_probki_pochodza_z_tego_klucza_i_jest_ich_najwyzej_trzy(): void {
		$ids = array( $this->account(), $this->account(), $this->account(), $this->account(), $this->account() );
		foreach ( $ids as $i => $id ) {
			update_user_meta( $id, 'wpmem_pwz', 'pwz-' . $i );
		}
		update_user_meta( $ids[0], 'inny_klucz', 'obca-wartosc' );

		$by_key = array();
		foreach ( MetaKeyScanner::scan() as $stat ) {
			$by_key[ $stat->key ] = $stat;
		}

		$this->assertCount( MetaKeyScanner::SAMPLE_PER_KEY, $by_key['wpmem_pwz']->samples );
		foreach ( $by_key['wpmem_pwz']->samples as $sample ) {
			$this->assertStringStartsWith( 'pwz-', $sample );
		}
		$this->assertSame( array( 'obca-wartosc' ), $by_key['inny_klucz']->samples );
	}

	public function test_pusty_portal_daje_pusta_liste(): void {
		$this->account();

		$this->assertSame( array(), MetaKeyScanner::scan() );
	}
}
