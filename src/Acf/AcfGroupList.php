<?php
/**
 * Lista grup pól ACF.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Acf;

defined( 'ABSPATH' ) || exit;

/**
 * Cienki adapter: czyta grupy pól z ACF i mapuje je na proste tablice.
 */
final class AcfGroupList {

	/**
	 * Grupy pól ACF.
	 *
	 * Filtr `pusers_acf_groups` pełni dwie role: jest szwem testowym (ACF Pro jest
	 * licencjonowany i nie ma go w środowisku testowym, więc testy podstawiają
	 * grupy tym filtrem) oraz punktem rozszerzeń dla integracji, które dostarczają
	 * grupy z innego źródła niż ACF.
	 *
	 * @return array<string, string> Klucz grupy => tytuł; pusta, gdy ACF jest niedostępny.
	 */
	public static function groups(): array {
		$groups = array();

		if ( function_exists( 'acf_get_field_groups' ) ) {
			$raw = acf_get_field_groups();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $group ) {
					if ( ! is_array( $group ) || ! isset( $group['key'] ) || ! is_string( $group['key'] ) || '' === $group['key'] ) {
						continue;
					}
					$title                   = isset( $group['title'] ) && is_string( $group['title'] ) ? $group['title'] : $group['key'];
					$groups[ $group['key'] ] = $title;
				}
			}
		}

		/**
		 * Filtruje grupy pól ACF.
		 *
		 * @param array<string, string> $groups Klucz grupy => tytuł.
		 */
		$filtered = apply_filters( 'pusers_acf_groups', $groups );

		return is_array( $filtered ) ? $filtered : array();
	}
}
