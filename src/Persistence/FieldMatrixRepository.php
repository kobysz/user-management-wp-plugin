<?php
/**
 * Repozytorium macierzy pól.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Fields\FieldMatrix;

/**
 * Repozytorium macierzy pól.
 */
final class FieldMatrixRepository {

	/**
	 * Wczytuje macierz pól; braki i śmieci normalizuje domena.
	 */
	public static function get(): FieldMatrix {
		return FieldMatrix::fromArray( OptionStore::read( OptionStore::OPTION_MATRIX ) );
	}

	/**
	 * Zapisuje macierz pól.
	 *
	 * @param FieldMatrix $matrix Wartość do zapisu.
	 */
	public static function save( FieldMatrix $matrix ): bool {
		return OptionStore::write( OptionStore::OPTION_MATRIX, $matrix->toArray() );
	}
}
