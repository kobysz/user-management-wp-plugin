<?php
/**
 * Testy repozytorium mapowania importu i jego podglądu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Import\ImportReport;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ImportMappingRepository;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

final class ImportMappingRepositoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'PWZ',
				),
				array(
					'name'  => 'miasto',
					'label' => 'Miasto',
				),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'   => 'lekarz',
						'label' => 'Lekarz',
					),
				)
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_groups' );
		parent::tear_down();
	}

	public function test_zapis_i_odczyt_robia_runde(): void {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'           => array(
					'pwz'    => 'stary_pwz',
					'miasto' => 'stare_miasto',
				),
				'professionKey'    => 'stara_profesja',
				'professionValues' => array( 'Lekarz' => 'lekarz' ),
				'statusKey'        => 'stary_stan',
				'statusValues'     => array( 'ok' => 'approved' ),
				'fallback'         => 'pending',
			)
		);
		$this->assertFalse( $mapping->isEmpty(), 'Stan wyjściowy: mapowanie niepuste.' );

		$this->assertTrue( ImportMappingRepository::save( $mapping ) );

		$this->assertSame( $mapping->toArray(), ImportMappingRepository::get()->toArray() );
	}

	public function test_odczyt_przycina_pola_ktorych_nie_ma_w_acf(): void {
		ImportMappingRepository::save(
			ImportMapping::fromArray(
				array( 'fields' => array( 'pwz' => 'stary_pwz', 'nieistniejace' => 'stare_x' ) )
			)
		);

		$this->assertSame( array( 'pwz' => 'stary_pwz' ), ImportMappingRepository::get()->fields() );
		$stored = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array( 'pwz' => 'stary_pwz', 'nieistniejace' => 'stare_x' ), $stored['fields'] );
	}

	public function test_odczyt_przycina_profesje_ktorych_nie_ma_w_ustawieniach(): void {
		ImportMappingRepository::save(
			ImportMapping::fromArray(
				array(
					'professionKey'    => 'stara_profesja',
					'professionValues' => array(
						'lek'  => 'lekarz',
						'inny' => 'usunieta',
					),
				)
			)
		);

		$mapping = ImportMappingRepository::get();

		$this->assertSame( 'lekarz', $mapping->professionFor( 'lek' ) );
		$this->assertNull( $mapping->professionFor( 'inny' ) );
		$this->assertSame( 'stara_profesja', $mapping->professionKey() );
		$stored = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array( 'lek' => 'lekarz', 'inny' => 'usunieta' ), $stored['professionValues'] );
	}

	public function test_brak_opcji_daje_puste_mapowanie(): void {
		$this->assertFalse( get_option( OptionStore::OPTION_IMPORT_MAPPING ), 'Stan wyjściowy: brak opcji.' );

		$this->assertTrue( ImportMappingRepository::get()->isEmpty() );
	}

	public function test_brak_podgladu_daje_null(): void {
		$this->assertNull( ImportMappingRepository::preview() );
	}

	public function test_podglad_niesie_odcisk_mapowania_i_czas(): void {
		$mapping = ImportMapping::fromArray( array( 'fields' => array( 'pwz' => 'stary_pwz' ) ) );
		$report  = ImportReport::empty();

		$this->assertTrue( ImportMappingRepository::save_preview( $mapping, $report ) );

		$preview = ImportMappingRepository::preview();
		$this->assertNotNull( $preview );
		$this->assertSame( $mapping->hash(), $preview['hash'] );
		$this->assertSame( $report->toArray(), $preview['report'] );
		$this->assertGreaterThan( 0, $preview['at'] );
		$this->assertLessThanOrEqual( time(), $preview['at'] );
	}

	public function test_inne_mapowanie_ma_inny_odcisk_w_podgladzie(): void {
		$first  = ImportMapping::fromArray( array( 'fields' => array( 'pwz' => 'a' ) ) );
		$second = ImportMapping::fromArray( array( 'fields' => array( 'pwz' => 'b' ) ) );

		ImportMappingRepository::save_preview( $first, ImportReport::empty() );
		$preview = ImportMappingRepository::preview();

		$this->assertNotNull( $preview );
		$this->assertNotSame( $second->hash(), $preview['hash'] );
	}

	public function test_czyszczenie_podgladu_usuwa_opcje(): void {
		ImportMappingRepository::save_preview( ImportMapping::fromArray( array( 'fields' => array( 'pwz' => 'a' ) ) ), ImportReport::empty() );
		$this->assertNotFalse( get_option( OptionStore::OPTION_IMPORT_PREVIEW ), 'Stan wyjściowy: podgląd zapisany.' );

		$this->assertTrue( ImportMappingRepository::clear_preview() );

		$this->assertFalse( get_option( OptionStore::OPTION_IMPORT_PREVIEW ) );
		$this->assertNull( ImportMappingRepository::preview() );
	}
}
