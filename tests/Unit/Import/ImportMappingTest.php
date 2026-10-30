<?php
/**
 * Testy konfiguracji mapowania importu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Import\ImportMapping;

final class ImportMappingTest extends TestCase {

	public function test_mapowanie_na_stan_niepotwierdzonego_maila_jest_odrzucane(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'statusKey'    => 'wpmem_status',
				'statusValues' => array(
					'new' => 'email_unconfirmed',
					'ok'  => 'approved',
				),
			)
		);

		$this->assertNull( $mapping->statusFor( 'new' ) );
		$this->assertSame( AccountStatus::Approved, $mapping->statusFor( 'ok' ) );
		$this->assertSame( array( 'ok' => 'approved' ), $mapping->toArray()['statusValues'] );
	}

	public function test_dozwolone_stany_to_dokladnie_trzy_bez_niepotwierdzonego_maila(): void {
		$this->assertSame( array( 'pending', 'approved', 'rejected' ), ImportMapping::ALLOWED_STATUSES );
	}

	public function test_dozwolone_stany_sa_zgodne_z_enumem(): void {
		$this->assertSame(
			array(
				AccountStatus::Pending->value,
				AccountStatus::Approved->value,
				AccountStatus::Rejected->value,
			),
			ImportMapping::ALLOWED_STATUSES
		);
	}

	public function test_kazdy_dozwolony_stan_jest_przyjmowany(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'statusValues' => array(
					'a' => 'pending',
					'b' => 'approved',
					'c' => 'rejected',
				),
			)
		);

		$this->assertSame( AccountStatus::Pending, $mapping->statusFor( 'a' ) );
		$this->assertSame( AccountStatus::Approved, $mapping->statusFor( 'b' ) );
		$this->assertSame( AccountStatus::Rejected, $mapping->statusFor( 'c' ) );
	}

	public function test_nieznany_kod_stanu_jest_odrzucany(): void {
		$mapping = ImportMapping::fromArray( array( 'statusValues' => array( 'x' => 'cokolwiek' ) ) );

		$this->assertNull( $mapping->statusFor( 'x' ) );
		$this->assertSame( array(), $mapping->toArray()['statusValues'] );
	}

	public function test_nieznany_fallback_daje_none(): void {
		$this->assertSame( ImportMapping::FALLBACK_NONE, ImportMapping::fromArray( array( 'fallback' => 'delete' ) )->fallback() );
		$this->assertSame( ImportMapping::FALLBACK_NONE, ImportMapping::fromArray( array( 'fallback' => 5 ) )->fallback() );
		$this->assertSame( ImportMapping::FALLBACK_NONE, ImportMapping::fromArray( array() )->fallback() );
	}

	public function test_znane_fallbacki_sa_zachowane(): void {
		$this->assertSame( ImportMapping::FALLBACK_PENDING, ImportMapping::fromArray( array( 'fallback' => 'pending' ) )->fallback() );
		$this->assertSame( ImportMapping::FALLBACK_APPROVED, ImportMapping::fromArray( array( 'fallback' => 'approved' ) )->fallback() );
	}

	public function test_source_keys_sa_unikalne_i_posortowane(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'        => array(
					'field_b' => 'wpmem_zeta',
					'field_a' => 'wpmem_alfa',
					'field_c' => 'wpmem_zeta',
				),
				'professionKey' => 'wpmem_alfa',
				'statusKey'     => 'wpmem_mid',
			)
		);

		$this->assertSame( array( 'wpmem_alfa', 'wpmem_mid', 'wpmem_zeta' ), $mapping->sourceKeys() );
	}

	public function test_is_empty_gdy_nic_nie_zmapowano(): void {
		$this->assertTrue( ImportMapping::fromArray( array() )->isEmpty() );
		$this->assertFalse( ImportMapping::fromArray( array( 'fields' => array( 'field_a' => 'wpmem_a' ) ) )->isEmpty() );
		$this->assertFalse( ImportMapping::fromArray( array( 'statusKey' => 'wpmem_status' ) )->isEmpty() );
	}

	public function test_odcisk_zalezy_od_tresci_a_nie_od_kolejnosci_kluczy(): void {
		// Pierwsze mapowanie ma klucze w porządku rosnącym, drugie w malejącym.
		$one     = ImportMapping::fromArray(
			array(
				'fields'           => array(
					'field_a' => 'wpmem_a',
					'field_m' => 'wpmem_m',
					'field_z' => 'wpmem_z',
				),
				'professionKey'    => 'wpmem_prof',
				'professionValues' => array(
					'lekarz'   => 'lekarz',
					'ratownik' => 'ratownik',
				),
				'statusKey'        => 'wpmem_status',
				'statusValues'     => array(
					'a' => 'approved',
					'm' => 'pending',
					'z' => 'rejected',
				),
				'fallback'         => 'pending',
			)
		);
		$two     = ImportMapping::fromArray(
			array(
				'fallback'         => 'pending',
				'statusValues'     => array(
					'z' => 'rejected',
					'm' => 'pending',
					'a' => 'approved',
				),
				'statusKey'        => 'wpmem_status',
				'professionValues' => array(
					'ratownik' => 'ratownik',
					'lekarz'   => 'lekarz',
				),
				'professionKey'    => 'wpmem_prof',
				'fields'           => array(
					'field_z' => 'wpmem_z',
					'field_m' => 'wpmem_m',
					'field_a' => 'wpmem_a',
				),
			)
		);
		$changed = ImportMapping::fromArray(
			array(
				'fields'           => array(
					'field_a' => 'wpmem_a',
					'field_m' => 'wpmem_INNY',
					'field_z' => 'wpmem_z',
				),
				'professionKey'    => 'wpmem_prof',
				'professionValues' => array(
					'lekarz'   => 'lekarz',
					'ratownik' => 'ratownik',
				),
				'statusKey'        => 'wpmem_status',
				'statusValues'     => array(
					'a' => 'approved',
					'm' => 'pending',
					'z' => 'rejected',
				),
				'fallback'         => 'pending',
			)
		);

		$this->assertSame( $one->hash(), $two->hash() );
		$this->assertNotSame( $one->hash(), $changed->hash() );

		// Każda sekcja z osobna: zmiana tylko jej musi zmienić odcisk.
		$base = array(
			'fields'           => array( 'field_a' => 'wpmem_a' ),
			'professionKey'    => 'wpmem_prof',
			'professionValues' => array( 'lekarz' => 'lekarz' ),
			'statusKey'        => 'wpmem_status',
			'statusValues'     => array( 'a' => 'approved' ),
			'fallback'         => 'pending',
		);
		$hash = ImportMapping::fromArray( $base )->hash();

		$variants = array(
			'fields'           => array( 'field_a' => 'wpmem_inne' ),
			'professionKey'    => 'wpmem_inna_prof',
			'professionValues' => array( 'lekarz' => 'ratownik' ),
			'statusKey'        => 'wpmem_inny_status',
			'statusValues'     => array( 'a' => 'rejected' ),
			'fallback'         => 'approved',
		);

		foreach ( $variants as $section => $value ) {
			$this->assertNotSame(
				$hash,
				ImportMapping::fromArray( array_merge( $base, array( $section => $value ) ) )->hash(),
				'Zmiana sekcji ' . $section . ' nie zmienia odcisku.'
			);
		}
	}

	public function test_pruned_to_usuwa_pola_i_profesje_ktorych_nie_ma(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'           => array(
					'pwz'           => 'wpmem_pwz',
					'nieistniejace' => 'x',
				),
				'professionKey'    => 'wpmem_prof',
				'professionValues' => array(
					'Lekarz'  => 'lekarz',
					'Kucharz' => 'kucharz',
				),
				'statusKey'        => 'wpmem_status',
				'statusValues'     => array( 'ok' => 'approved' ),
				'fallback'         => 'pending',
			)
		);

		$pruned = $mapping->prunedTo( array( 'pwz' ), array( 'lekarz' ) );

		$this->assertSame( array( 'pwz' => 'wpmem_pwz' ), $pruned->fields() );
		$this->assertSame( 'lekarz', $pruned->professionFor( 'lekarz' ) );
		$this->assertNull( $pruned->professionFor( 'kucharz' ) );
		$this->assertSame( 'wpmem_prof', $pruned->professionKey() );
		$this->assertSame( AccountStatus::Approved, $pruned->statusFor( 'ok' ) );
		$this->assertSame( ImportMapping::FALLBACK_PENDING, $pruned->fallback() );
		// Oryginał pozostaje nietknięty.
		$this->assertCount( 2, $mapping->fields() );
	}

	public function test_wartosci_zrodlowe_sa_porownywane_po_obcieciu_i_bez_wielkosci_liter(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'professionValues' => array( 'Lekarz' => 'lekarz' ),
				'statusValues'     => array( ' ZATWIERDZONY ' => 'approved' ),
			)
		);

		$this->assertSame( 'lekarz', $mapping->professionFor( '  lekarz ' ) );
		$this->assertSame( 'lekarz', $mapping->professionFor( 'LEKARZ' ) );
		$this->assertSame( AccountStatus::Approved, $mapping->statusFor( 'zatwierdzony' ) );
		$this->assertNull( $mapping->professionFor( 'ratownik' ) );

		// Polskie znaki wymagają wielobajtowego zmniejszania liter.
		$polish = ImportMapping::fromArray( array( 'professionValues' => array( 'ŻAK' => 'zak' ) ) );
		$this->assertSame( 'zak', $polish->professionFor( 'żak' ) );
	}

	public function test_to_array_i_from_array_robia_pelna_runde(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'           => array(
					'pwz'  => 'wpmem_pwz',
					'city' => 'wpmem_city',
				),
				'professionKey'    => 'wpmem_prof',
				'professionValues' => array( 'Lekarz' => 'lekarz' ),
				'statusKey'        => 'wpmem_status',
				'statusValues'     => array(
					'ok'  => 'approved',
					'bad' => 'rejected',
				),
				'fallback'         => 'approved',
			)
		);

		$copy = ImportMapping::fromArray( $mapping->toArray() );

		$this->assertSame( $mapping->toArray(), $copy->toArray() );
		$this->assertSame( $mapping->hash(), $copy->hash() );
		$this->assertSame(
			array(
				'pwz'  => 'wpmem_pwz',
				'city' => 'wpmem_city',
			),
			$copy->fields()
		);
		$this->assertSame( 'wpmem_prof', $copy->professionKey() );
		$this->assertSame( 'wpmem_status', $copy->statusKey() );
		$this->assertSame( 'lekarz', $copy->professionFor( 'lekarz' ) );
		$this->assertSame( AccountStatus::Rejected, $copy->statusFor( 'bad' ) );
		$this->assertSame( ImportMapping::FALLBACK_APPROVED, $copy->fallback() );
	}

	public function test_pary_o_kluczach_lub_wartosciach_nie_bedacych_tekstem_sa_odrzucane(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'           => array(
					'pwz' => 'wpmem_pwz',
					'bad' => 5,
					7     => 'wpmem_seven',
				),
				'professionValues' => array( 'x' => array( 'lekarz' ) ),
			)
		);

		$this->assertSame( array( 'pwz' => 'wpmem_pwz' ), $mapping->fields() );
		$this->assertNull( $mapping->professionFor( 'x' ) );
	}

	public function test_liczbowe_wartosci_zrodlowe_nie_gina_przy_wczytaniu(): void {
		// PHP zamienia klucz "1" na liczbę; stare wtyczki zapisują stan właśnie tak.
		$mapping = ImportMapping::fromArray(
			array(
				'statusValues' => array(
					'1' => 'approved',
					'0' => 'rejected',
				),
			)
		);

		$this->assertSame( AccountStatus::Approved, $mapping->statusFor( '1' ) );
		$this->assertSame( AccountStatus::Rejected, $mapping->statusFor( '0' ) );

		$copy = ImportMapping::fromArray( $mapping->toArray() );

		$this->assertSame( AccountStatus::Approved, $copy->statusFor( '1' ) );
		$this->assertSame( AccountStatus::Rejected, $copy->statusFor( '0' ) );
		$this->assertSame( $mapping->hash(), $copy->hash() );
	}

	public function test_liczbowe_kody_profesji_nie_gina_przy_wczytaniu(): void {
		$mapping = ImportMapping::fromArray( array( 'professionValues' => array( '1' => 'lekarz' ) ) );
		$copy    = ImportMapping::fromArray( $mapping->toArray() );

		$this->assertSame( 'lekarz', $mapping->professionFor( '1' ) );
		$this->assertSame( 'lekarz', $copy->professionFor( '1' ) );
	}

	public function test_zle_ksztaltowane_wejscie_nie_wywala_wczytania(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'        => 'nie-tablica',
				'professionKey' => array( 'x' ),
				'statusValues'  => 3,
			)
		);

		$this->assertTrue( $mapping->isEmpty() );
		$this->assertSame( '', $mapping->professionKey() );
		$this->assertSame( '', $mapping->statusKey() );
	}
}
