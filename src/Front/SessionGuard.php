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
 * Niszczenie sesji następuje przy KAŻDYM przejściu do stanu innego niż „zatwierdzony”, nie tylko
 * przy przejściu Z „zatwierdzonego”. Warunek „był zatwierdzony i przestał” zostawiał żywe sesje
 * w trzech przejściach: `null -> rejected` i `null -> pending` (konto BEZ zapisanego stanu, które
 * stan dostaje — dokładnie to zrobi backfill Planu 7 każdemu kontu, którego stara wtyczka
 * członkowska nie zatwierdziła), `nierozpoznany -> cokolwiek` (uszkodzoną metadaną
 * `AccountStatusRepository::get()` czyta jako `null`, więc stary stan nigdy nie jest `approved`)
 * oraz każda zmiana stanu dokonana poza `AccountStatusRepository::set()`.
 *
 * Szerszy warunek jest ściśle BEZPIECZNIEJSZY i spójny z bramą: brama odmawia wejścia KAŻDEMU
 * kontu bez zatwierdzenia, niezależnie od roli, więc wylogowanie takiego konta nie odbiera niczego,
 * czego brama by mu nie odmówiła. Zdobycie zatwierdzenia dalej nie wylogowuje nikogo (przy
 * wyłączonej akceptacji konto idzie `email_unconfirmed` -> `approved` w jednym przebiegu), a sesje
 * traci tylko konto, którego stan się zmienił, nigdy redaktor, który właśnie podjął decyzję.
 *
 * Wiersz w logu powstaje WYŁĄCZNIE wtedy, gdy naprawdę było co niszczyć. Akcja nazywa się
 * `sessions_destroyed`, więc wiersz o zniszczeniu zera sesji byłby w logu nieprawdą — a przy
 * backfillu Planu 7 jeden taki wiersz na każde konto zamieniłby ślad decyzji redakcji w zalew
 * (ta sama awaria, przed którą budżet wierszy broni log odmów).
 *
 * Hasło aplikacyjne odrzuconego konta nie jest unieważniane: brama na REST i tak odmawia jego
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
	 * Niszczy wszystkie sesje konta, które weszło w stan inny niż „zatwierdzony”.
	 *
	 * @param int                $userId    Konto, którego stan się zmienił.
	 * @param AccountStatus|null $oldStatus Stan sprzed zmiany (null: konto bez rozpoznanego stanu).
	 * @param AccountStatus      $newStatus Stan po zmianie.
	 */
	public static function on_status_changed( int $userId, ?AccountStatus $oldStatus, AccountStatus $newStatus ): void {
		if ( AccountStatus::Approved === $newStatus ) {
			return;
		}

		$tokens = WP_Session_Tokens::get_instance( $userId );
		$live   = count( $tokens->get_all() );
		$tokens->destroy_all();

		if ( 0 === $live ) {
			return; // Nie było czego niszczyć, więc nie ma czego zapisywać (patrz dokumentacja klasy).
		}

		EventLog::write(
			self::LOG_ACTION,
			$userId,
			get_current_user_id(),
			array(
				// Pusta wartość znaczy „konto nie miało rozpoznanego stanu”; nazwy stanów biorą się
				// z enuma, nie z literałów.
				'from' => null === $oldStatus ? '' : $oldStatus->value,
				'to'   => $newStatus->value,
			)
		);
	}
}
