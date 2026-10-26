<?php
/**
 * Repozytorium listy profesji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Domain\Profession\ProfessionCollection;

/**
 * Repozytorium listy profesji.
 */
final class ProfessionRepository {

	/**
	 * Wczytuje profesje; braki i śmieci normalizuje domena.
	 */
	public static function get(): ProfessionCollection {
		return ProfessionCollection::fromArray( OptionStore::read( OptionStore::OPTION_PROFESSIONS ) );
	}

	/**
	 * Zapisuje profesje.
	 *
	 * @param ProfessionCollection $professions Wartość do zapisu.
	 */
	public static function save( ProfessionCollection $professions ): bool {
		$rows = array_values(
			array_map(
				static fn ( Profession $profession ): array => $profession->toArray(),
				$professions->all()
			)
		);
		return OptionStore::write( OptionStore::OPTION_PROFESSIONS, $rows );
	}
}
