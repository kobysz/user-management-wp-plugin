<?php
/**
 * Strażnik sesji: utrata stanu „zatwierdzony” odbiera także dostęp już przyznany.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Persistence\EventLog;
use WP_Session_Tokens;

/**
 * Brama logowania działa na uwierzytelnianiu, więc konto zalogowane przed odrzuceniem miałoby
 * ciasteczko sesji aż do jego wygaśnięcia (do dwóch tygodni przy „zapamiętaj mnie”). Dla portalu,
 * którego cała treść jest za logowaniem, to znaczy, że odrzucenie nie odbiera dostępu.
 *
 * Niszczenie sesji następuje WYŁĄCZNIE przy przejściu z „zatwierdzony” do innego stanu:
 * zdobycie zatwierdzenia nie może nikogo wylogować (przy wyłączonej akceptacji konto idzie
 * `email_unconfirmed` -> `approved` w jednym przebiegu), a sesje traci tylko konto, którego
 * stan się zmienił, nigdy redaktor, który właśnie podjął decyzję.
 *
 * Hasło aplikacyjne odrzuconego konta nie jest unieważniane: bramę na REST i tak odmawia jego
 * użycia, więc poświadczenie jest martwe.
 */
final class SessionGuard {

	/**
	 * Zdarzenie w logu: strażnik zniszczył sesje konta.
	 */
	public const LOG_ACTION = 'sessions_destroyed';

	/**
	 * Podpina strażnika pod akcję zmiany stanu konta.
	 */
	public static function register(): void {
		add_action( 'pusers_account_status_changed', array( self::class, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Niszczy wszystkie sesje konta, które straciło stan „zatwierdzony”.
	 *
	 * @param int                $userId    Konto, którego stan się zmienił.
	 * @param AccountStatus|null $oldStatus Stan sprzed zmiany (null: konto bez rozpoznanego stanu).
	 * @param AccountStatus      $newStatus Stan po zmianie.
	 */
	public static function on_status_changed( int $userId, ?AccountStatus $oldStatus, AccountStatus $newStatus ): void {
		if ( AccountStatus::Approved !== $oldStatus || AccountStatus::Approved === $newStatus ) {
			return;
		}

		WP_Session_Tokens::get_instance( $userId )->destroy_all();

		EventLog::write(
			self::LOG_ACTION,
			$userId,
			get_current_user_id(),
			array(
				'from' => $oldStatus->value,
				'to'   => $newStatus->value,
			)
		);
	}
}
