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

	public function test_klucze_rdzenia_sa_pomijane_z_nazwy_a_klucze_wtyczek_pod_tym_samym_przedrostkiem_nie(): void {
		global $wpdb;
		$id = $this->account();
		foreach ( array( 'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id' ) as $core ) {
			update_user_meta( $id, $wpdb->prefix . $core, 'x' );
		}
		update_user_meta( $id, $wpdb->prefix . 's2member_custom_fields', 'x' );
		update_user_meta( $id, $wpdb->prefix . 'capabilities_backup', 'x' );

		$this->assertSame(
			array( $wpdb->prefix . 'capabilities_backup', $wpdb->prefix . 's2member_custom_fields' ),
			$this->keys( MetaKeyScanner::scan() )
		);
	}

	public function test_probki_sa_posortowane_niezaleznie_od_kolejnosci_w_bazie(): void {
		$ids = array( $this->account(), $this->account(), $this->account() );
		update_user_meta( $ids[0], 'wpmem_pwz', 'c-trzecia' );
		update_user_meta( $ids[1], 'wpmem_pwz', 'a-pierwsza' );
		update_user_meta( $ids[2], 'wpmem_pwz', 'b-druga' );

		$stats = MetaKeyScanner::scan();

		$this->assertSame( array( 'a-pierwsza', 'b-druga', 'c-trzecia' ), $stats[0]->samples );
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
		// Kolejność wstawiania: zz (1 konto), cc (1), mm (2), aa (1), bb (3) — oczekiwana: bb, mm, aa, cc, zz.
		update_user_meta( $a, 'zz_key', '1' );
		update_user_meta( $b, 'cc_key', '1' );
		update_user_meta( $a, 'mm_key', '1' );
		update_user_meta( $b, 'mm_key', '1' );
		update_user_meta( $c, 'aa_key', '1' );
		update_user_meta( $a, 'bb_key', '1' );
		update_user_meta( $b, 'bb_key', '1' );
		update_user_meta( $c, 'bb_key', '1' );

		$stats = MetaKeyScanner::scan();

		$this->assertSame( array( 'bb_key', 'mm_key', 'aa_key', 'cc_key', 'zz_key' ), $this->keys( $stats ) );
		$this->assertSame( array( 3, 2, 1, 1, 1 ), array_map( static fn( MetaKeyStat $s ): int => $s->accounts, $stats ) );
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

	/**
	 * Wykonuje skan z zapytaniami przepisanymi na błędne (bez DDL: tabela zostaje nietknięta).
	 *
	 * @param callable(string): bool $matches Czy dane zapytanie ma zostać zepsute.
	 * @return list<MetaKeyStat>
	 */
	private function scan_with_broken_queries( callable $matches ): array {
		global $wpdb;
		$filter   = static fn( string $query ): string => $matches( $query ) ? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje' : $query;
		$previous = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			return MetaKeyScanner::scan();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
	}

	public function test_porazka_zapytania_o_liczniki_rzuca_zamiast_udawac_pusty_portal(): void {
		update_user_meta( $this->account(), 'wpmem_pwz', '1' );

		$this->expectException( \RuntimeException::class );
		$this->scan_with_broken_queries( static fn( string $query ): bool => str_contains( $query, 'COUNT(DISTINCT' ) );
	}

	public function test_porazka_probek_jednego_klucza_zostawia_klucz_z_licznikiem_bez_przykladow(): void {
		$id = $this->account();
		update_user_meta( $id, 'aa_key', 'wartosc-a' );
		update_user_meta( $id, 'bb_key', 'wartosc-b' );

		$stats = $this->scan_with_broken_queries( static fn( string $query ): bool => str_contains( $query, 'SELECT DISTINCT meta_value' ) && str_contains( $query, "'aa_key'" ) );

		$this->assertSame( array( 'aa_key', 'bb_key' ), $this->keys( $stats ) );
		$this->assertSame( 1, $stats[0]->accounts );
		$this->assertSame( array(), $stats[0]->samples );
		$this->assertSame( array( 'wartosc-b' ), $stats[1]->samples );
	}
}
