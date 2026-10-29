<?php
/**
 * Skan kluczy user_meta starego systemu członkowskiego.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

use PortalUsers\Domain\Import\MetaKeyStat;

defined( 'ABSPATH' ) || exit;

/**
 * Pokazuje administratorowi, jakie klucze `user_meta` istnieją na portalu, w ilu kontach i z jakimi wartościami.
 *
 * Koszt jest świadomy: jedno zapytanie z licznikami i po jednym na pokazywany klucz (przy `MAX_KEYS` to 51).
 * Skan to akcja administratora uruchamiana ręcznie, nie coś na każdym wczytaniu strony.
 */
final class MetaKeyScanner {

	public const MAX_KEYS       = 50;
	public const SAMPLE_PER_KEY = 3;

	/**
	 * Skanuje `usermeta`, pomijając klucze wtyczki (`pusers_`), prywatne (`_`) i systemowe (przedrostek tabel).
	 *
	 * @param int $limit Najwyżej tyle kluczy; najliczniejsze pierwsze.
	 * @return list<MetaKeyStat> Malejąco po liczbie kont, przy równej liczbie alfabetycznie po kluczu.
	 * @throws \RuntimeException Gdy zapytanie o liczniki kont się nie powiodło. Porażka zapytania o próbki jednego klucza
	 *                           nie rzuca: klucz zostaje na liście z licznikiem i bez przykładów.
	 */
	public static function scan( int $limit = self::MAX_KEYS ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, COUNT(DISTINCT user_id) AS accounts FROM %i WHERE meta_key NOT LIKE %s AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s GROUP BY meta_key ORDER BY accounts DESC, meta_key ASC LIMIT %d',
				$wpdb->usermeta,
				$wpdb->esc_like( 'pusers_' ) . '%',
				$wpdb->esc_like( '_' ) . '%',
				$wpdb->esc_like( $wpdb->prefix ) . '%',
				max( 0, $limit )
			),
			ARRAY_A
		);

		// get_results() zwraca null przy błędzie SQL; pusta lista oznaczałaby „portal bez metadanych" i administrator nic by nie zmapował.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'Nie udało się odczytać kluczy user_meta z bazy danych.', 'portal-users' ) );
		}

		$stats = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];

			$samples = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT meta_value FROM %i WHERE meta_key = %s ORDER BY meta_value ASC LIMIT %d',
					$wpdb->usermeta,
					$key,
					self::SAMPLE_PER_KEY
				)
			);

			$stats[] = MetaKeyStat::fromRow(
				$key,
				(int) $row['accounts'],
				array_map( 'strval', is_array( $samples ) ? $samples : array() )
			);
		}

		return $stats;
	}
}
