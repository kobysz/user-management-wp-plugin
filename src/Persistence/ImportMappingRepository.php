<?php
/**
 * Repozytorium mapowania importu i jego podglądu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Import\ImportReport;

/**
 * Przechowuje mapowanie importu między wczytaniami strony oraz wynik jego podglądu, po którym
 * krok zastosowania rozpozna, czy mapowanie zostało obejrzane.
 */
final class ImportMappingRepository {

	/**
	 * Wczytuje mapowanie przycięte do bieżących pól ACF i profesji.
	 *
	 * Przycięcie dotyczy wyłącznie zwracanej wartości: zapisana opcja zostaje nietknięta, więc
	 * usunięte i przywrócone pole nie gubi mapowania.
	 */
	public static function get(): ImportMapping {
		$source = new AcfFieldSource( SettingsRepository::get()->acfGroupKey );

		return ImportMapping::fromArray( OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING ) )
			->prunedTo( array_keys( $source->fields() ), ProfessionRepository::get()->keys() );
	}

	/**
	 * Zapisuje mapowanie.
	 *
	 * @param ImportMapping $mapping Wartość do zapisu.
	 */
	public static function save( ImportMapping $mapping ): bool {
		return OptionStore::write( OptionStore::OPTION_IMPORT_MAPPING, $mapping->toArray() );
	}

	/**
	 * Ostatni podgląd: odcisk podglądanego mapowania, raport i czas; null, gdy podglądu nie ma
	 * albo zapis jest uszkodzony.
	 *
	 * @return array{hash: string, report: array<mixed>, at: int}|null
	 */
	public static function preview(): ?array {
		$stored = OptionStore::read( OptionStore::OPTION_IMPORT_PREVIEW );

		if ( ! isset( $stored['hash'], $stored['report'], $stored['at'] )
			|| ! is_string( $stored['hash'] ) || ! is_array( $stored['report'] ) || ! is_int( $stored['at'] ) ) {
			return null;
		}

		return array(
			'hash'   => $stored['hash'],
			'report' => $stored['report'],
			'at'     => $stored['at'],
		);
	}

	/**
	 * Zapisuje podgląd razem z odciskiem mapowania i bieżącym czasem.
	 *
	 * @param ImportMapping $mapping Mapowanie, które podglądano.
	 * @param ImportReport  $report  Wynik podglądu.
	 */
	public static function save_preview( ImportMapping $mapping, ImportReport $report ): bool {
		return OptionStore::write(
			OptionStore::OPTION_IMPORT_PREVIEW,
			array(
				'hash'   => $mapping->hash(),
				'report' => $report->toArray(),
				'at'     => time(),
			)
		);
	}

	/**
	 * Usuwa zapisany podgląd.
	 */
	public static function clear_preview(): bool {
		return OptionStore::delete( OptionStore::OPTION_IMPORT_PREVIEW );
	}
}
