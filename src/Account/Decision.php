<?php
/**
 * Rozstrzygnięcie wniosku: zatwierdzenie i odrzucenie konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Account;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Account\StatusTransition;
use PortalUsers\Front\Mailer;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\UserMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Jedyne miejsce, w którym panel daje kontu albo odbiera możliwość wejścia na portal.
 *
 * Kolejność kroków jest wiążąca: istnienie konta, odczyt stanu, dozwolone przejście, (przy odrzuceniu)
 * powód, zapis stanu, odczyt zwrotny, wiersz logu, mail. Mail idzie ostatni, bo wiadomość „konto
 * zatwierdzone" dla konta, które dalej nie wpuszcza, jest szkodą, a brak maila po udanej decyzji
 * da się nadrobić ręcznie. Aktor przychodzi z zewnątrz, żeby serwis nie zależał od zalogowanej sesji.
 */
final class Decision {

	public const RESULT_OK             = 'ok';
	public const RESULT_NOT_FOUND      = 'not_found';
	public const RESULT_NO_STATUS      = 'no_status';
	public const RESULT_WRONG_STATE    = 'wrong_state';
	public const RESULT_UNCHANGED      = 'unchanged';
	public const RESULT_EMPTY_REASON   = 'empty_reason';
	public const RESULT_STORAGE_FAILED = 'storage_failed';
	public const RESULT_MAIL_FAILED    = 'mail_failed';

	public const LOG_APPROVED       = 'application_approved';
	public const LOG_REJECTED       = 'application_rejected';
	public const LOG_STORAGE_FAILED = 'application_decision_failed';

	public const REASON_MAX = 500;

	/**
	 * Zatwierdza wniosek.
	 *
	 * @param int $userId  Konto wnioskodawcy.
	 * @param int $actorId Administrator podejmujący decyzję.
	 * @return string Jedna ze stałych RESULT_*.
	 */
	public static function approve( int $userId, int $actorId ): string {
		$guard = self::guard( $userId, AccountStatus::Approved );
		if ( ! $guard instanceof AccountStatus ) {
			return $guard;
		}

		if ( ! self::store( $userId, $actorId, $guard, AccountStatus::Approved ) ) {
			return self::RESULT_STORAGE_FAILED;
		}

		EventLog::write( self::LOG_APPROVED, $userId, $actorId, array( 'previous_status' => $guard->value ) );

		return Mailer::approved( $userId ) ? self::RESULT_OK : self::RESULT_MAIL_FAILED;
	}

	/**
	 * Odrzuca wniosek z powodem.
	 *
	 * @param int    $userId  Konto wnioskodawcy.
	 * @param int    $actorId Administrator podejmujący decyzję.
	 * @param string $reason  Powód wpisany przez redakcję (trafia do maila, metadanej i logu).
	 * @return string Jedna ze stałych RESULT_*.
	 */
	public static function reject( int $userId, int $actorId, string $reason ): string {
		$current = self::guard( $userId, AccountStatus::Rejected );
		if ( ! $current instanceof AccountStatus ) {
			return $current;
		}

		$clean = self::clean_reason( $reason );
		if ( '' === $clean ) {
			return self::RESULT_EMPTY_REASON;
		}

		if ( ! self::store( $userId, $actorId, $current, AccountStatus::Rejected ) ) {
			return self::RESULT_STORAGE_FAILED;
		}

		// Dwa różne fakty, nie jeden zapisany dwa razy: metadana mówi, dlaczego konto jest odrzucone
		// dziś (przy ponownym odrzuceniu jest nadpisywana), log mówi, kto i z jakim powodem to zrobił.
		// Metadana idzie dopiero po potwierdzeniu stanu, żeby nieudana decyzja nie zostawiła powodu.
		update_user_meta( $userId, UserMeta::REJECTION_REASON, $clean );
		EventLog::write(
			self::LOG_REJECTED,
			$userId,
			$actorId,
			array(
				'previous_status' => $current->value,
				'reason'          => $clean,
			)
		);

		return Mailer::rejected( $userId, $clean ) ? self::RESULT_OK : self::RESULT_MAIL_FAILED;
	}

	/**
	 * Sprawdza, czy konto wolno rozstrzygnąć na dany stan.
	 *
	 * @param int           $userId Konto wnioskodawcy.
	 * @param AccountStatus $target Stan docelowy.
	 * @return AccountStatus|string Obecny stan, gdy wolno; w przeciwnym razie stała RESULT_*.
	 */
	private static function guard( int $userId, AccountStatus $target ): AccountStatus|string {
		if ( $userId <= 0 || false === get_userdata( $userId ) ) {
			return self::RESULT_NOT_FOUND;
		}

		// Konto bez stanu to np. administrator dodany ręcznie: brama logowania wpuszcza je właśnie
		// dlatego, że stanu nie ma, więc nadanie mu stanu tą drogą odebrałoby mu dostęp.
		$current = AccountStatusRepository::get( $userId );
		if ( null === $current ) {
			return self::RESULT_NO_STATUS;
		}

		if ( ! StatusTransition::allowed( $current, $target ) ) {
			return self::RESULT_WRONG_STATE;
		}

		// Powtórka nie robi nic: kolejka dedupuje maile sama, ale log nie dedupuje niczego, więc
		// zatrzymać ją musi ten warunek.
		if ( $current === $target ) {
			return self::RESULT_UNCHANGED;
		}

		return $current;
	}

	/**
	 * Zapisuje stan i dowodzi zapisu odczytem zwrotnym; przy niezgodności zostawia ślad w logu.
	 *
	 * `AccountStatusRepository::set()` nic nie zwraca, więc odczyt jest jedynym dowodem.
	 *
	 * @param int           $userId  Konto wnioskodawcy.
	 * @param int           $actorId Administrator.
	 * @param AccountStatus $from    Stan sprzed zapisu.
	 * @param AccountStatus $to      Stan docelowy.
	 */
	private static function store( int $userId, int $actorId, AccountStatus $from, AccountStatus $to ): bool {
		AccountStatusRepository::set( $userId, $to );

		if ( AccountStatusRepository::get( $userId ) === $to ) {
			return true;
		}

		EventLog::write(
			self::LOG_STORAGE_FAILED,
			$userId,
			$actorId,
			array(
				'previous_status' => $from->value,
				'target_status'   => $to->value,
			)
		);

		return false;
	}

	/**
	 * Powód po sanityzacji i obcięciu do REASON_MAX znaków.
	 *
	 * Znak „<" zamienia się tu na encję (`Wiek < 30` daje `Wiek &lt; 30`), bo rdzeń woła
	 * `wp_pre_kses_less_than()` przed usunięciem znaczników. To zachowanie jest przyjęte: mail i panel
	 * eskejpują wyjście, a test je przypina.
	 *
	 * @param string $reason Powód surowy.
	 */
	private static function clean_reason( string $reason ): string {
		return mb_substr( trim( sanitize_textarea_field( $reason ) ), 0, self::REASON_MAX );
	}
}
