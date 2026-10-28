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
	 * Czy metadana stanu jest obecna i niepusta, ale nie da się jej rozpoznać jako stan konta.
	 *
	 * Obejmuje też wartość nietekstową (tablica, liczba). Brak metadanej i wartość pusta to
	 * "brak stanu", nie uszkodzenie, więc dają fałsz.
	 *
	 * Konsument bramy logowania MUSI przy prawdzie odmówić wejścia i zapisać wpis do logu,
	 * zamiast przekazywać wynik get() (null) do LoginGate::decide(): ta metoda przy null
	 * wpuszcza, bo null oznacza konto bez stanu (np. administratora dodanego ręcznie).
	 *
	 * @param int $user_id Identyfikator użytkownika.
	 */
	public static function isUnrecognised( int $user_id ): bool {
		$raw = get_user_meta( $user_id, UserMeta::STATUS, true );
		if ( '' === $raw ) {
			return false;
		}
		return ! is_string( $raw ) || null === AccountStatus::tryFromStored( $raw );
	}

	/**
	 * Zapisuje stan konta i, gdy stan naprawdę się zmienił, ogłasza to akcją
	 * `pusers_account_status_changed( int $userId, ?AccountStatus $old, AccountStatus $new )`.
	 *
	 * Repozytorium sesji nie rusza: warstwa przechowywania nie wylogowuje ludzi, tylko ogłasza fakt.
	 * Stan sprzed zapisu czytany jest przed zapisem; null oznacza konto bez rozpoznanego stanu.
	 * Powtórny zapis tej samej wartości nie jest zmianą stanu, więc akcji nie odpala.
	 * Powodzenia zapisu nie poznaje się po wyniku `update_user_meta()` (fałsz także przy wartości
	 * identycznej z zapisaną), tylko po odczycie zwrotnym.
	 *
	 * @param int           $user_id Identyfikator użytkownika.
	 * @param AccountStatus $status  Nowy stan.
	 */
	public static function set( int $user_id, AccountStatus $status ): void {
		$old = self::get( $user_id );

		update_user_meta( $user_id, UserMeta::STATUS, $status->value );

		if ( $old === $status || self::get( $user_id ) !== $status ) {
			return;
		}

		do_action( 'pusers_account_status_changed', $user_id, $old, $status );
	}
}
