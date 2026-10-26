<?php
/**
 * Odczyt i zapis stanu konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

use PortalUsers\Domain\Account\AccountStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Stan konta przechowywany w metadanej użytkownika.
 */
final class AccountStatusRepository {

	/**
	 * Czyta stan konta. Brak metadanej, wartość nierozpoznana i wartość nietekstowa dają null
	 * i nigdy wyjątek; null oznacza dla bramy logowania konto bez stanu.
	 *
	 * @param int $user_id Identyfikator użytkownika.
	 * @return AccountStatus|null
	 */
	public static function get( int $user_id ): ?AccountStatus {
		$raw = get_user_meta( $user_id, UserMeta::STATUS, true );
		return AccountStatus::tryFromStored( is_string( $raw ) ? $raw : null );
	}

	/**
	 * Zapisuje stan konta.
	 *
	 * @param int           $user_id Identyfikator użytkownika.
	 * @param AccountStatus $status  Nowy stan.
	 */
	public static function set( int $user_id, AccountStatus $status ): void {
		update_user_meta( $user_id, UserMeta::STATUS, $status->value );
	}
}
