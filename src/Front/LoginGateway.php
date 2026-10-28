<?php
/**
 * Brama logowania: stan konta decyduje o wejściu, ale dopiero po sprawdzeniu hasła.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Account\LoginGate;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use WP_Error;
use WP_User;

/**
 * Konsument `LoginGate::decide()`: bez tej klasy stan konta nie znaczy nic, bo konto z
 * niepotwierdzonym adresem logowało się normalnie.
 *
 * Brama siedzi na filtrze `authenticate` na priorytecie 30, czyli ZA weryfikatorami rdzenia
 * (`wp_authenticate_username_password`, `wp_authenticate_email_password`
 * i `wp_authenticate_application_password` siedzą na 20 — patrz `wp-includes/default-filters.php`).
 * Dostaje więc albo `WP_Error` od rdzenia (hasło złe — oddajemy bez dotykania i bez wpisu do logu,
 * bo to nie nasza odmowa), albo `WP_User` z już sprawdzonym hasłem.
 *
 * Tego NIE wolno przenieść na filtr `wp_authenticate_user`: on odpala się PRZED
 * `wp_check_password()` (patrz `wp-includes/user.php`), więc odmowa spod niego trafiałaby do
 * każdego, kto zna sam adres — i brama sama stawałaby się sondą ujawniającą, że adres jest zajęty.
 * Na priorytecie 30 do bramy nie dochodzi nikt, kto nie zna hasła, więc każdy stan może mieć
 * WŁASNY, PRAWDZIWY komunikat: człowiek wie, czy ma szukać maila, czy czekać na decyzję redakcji.
 * Priorytet 30 stoi też za hasłem aplikacyjnym, więc konto bez stanu `approved` nie wejdzie także
 * przez REST.
 *
 * Konto BEZ stanu brama wpuszcza (tak rozstrzyga `LoginGate::decide( null )`): administrator
 * dodany ręcznie w panelu stanu nie ma, a odmowa zamknęłaby właściciela portalu poza portalem.
 * Dokładnie dlatego wartość metadanej, której nikt nie rozpoznaje (`'Approved'`, `' pending'`),
 * sprawdza się OSOBNO i PRZED decyzją domeny: czyta się ona jako brak stanu, więc przekazana
 * do `decide()` WPUSZCZAŁABY — czyli fail-open po ręcznej edycji bazy.
 */
final class LoginGateway {

	/**
	 * Priorytet filtra `authenticate`. Rdzeń trzyma weryfikatory hasła na 20, więc 30 znaczy:
	 * „po wyroku rdzenia". Obniżenie tej liczby do 20 albo niżej jest zmianą bezpieczeństwa.
	 */
	public const PRIORITY = 30;

	/**
	 * Zdarzenie w logu: brama odmówiła wejścia.
	 */
	public const LOG_ACTION = 'login_denied';

	public const CODE_UNCONFIRMED  = 'pusers_email_unconfirmed';
	public const CODE_PENDING      = 'pusers_pending';
	public const CODE_REJECTED     = 'pusers_rejected';
	public const CODE_UNRECOGNISED = 'pusers_status_unrecognised';

	/**
	 * Podpina bramę za weryfikatory hasła rdzenia.
	 *
	 * Ostatni argument (liczba przyjmowanych argumentów) to świadome 1: filtr `authenticate`
	 * podaje też login i HASŁO JAWNE, a brama patrzy wyłącznie na stan konta, więc hasło nie ma
	 * po co do niej docierać.
	 */
	public static function register(): void {
		add_filter( 'authenticate', array( self::class, 'gate' ), self::PRIORITY, 1 );
	}

	/**
	 * Wpuszcza albo odmawia na podstawie stanu konta.
	 *
	 * Kolejność jest wymuszona: najpierw „czy to w ogóle użytkownik" (inaczej oddajemy wyrok
	 * rdzenia bez zmian), potem wartość nierozpoznana, a na końcu decyzja domeny.
	 *
	 * @param mixed $user Wynik poprzednich filtrów: `WP_User`, `WP_Error` albo null.
	 * @return mixed `WP_User` przy wejściu, `WP_Error` przy odmowie, w pozostałych razach wejście bez zmian.
	 */
	public static function gate( mixed $user ): mixed {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if ( AccountStatusRepository::isUnrecognised( $user->ID ) ) {
			return self::refuse( $user->ID, self::CODE_UNRECOGNISED );
		}

		$decision = LoginGate::decide( AccountStatusRepository::get( $user->ID ) );
		if ( $decision->allowed() ) {
			return $user;
		}

		return self::refuse( $user->ID, self::code_for( $decision->code() ) );
	}

	/**
	 * Tekst odmowy dla kodu. Kod spoza czwórki `CODE_*` daje `''`: mapa nie ma komunikatu
	 * domyślnego, bo taki worek odpowiadałby na kod, którego wtyczka nie zna. Odmowa z bramy
	 * zawsze niesie jeden z czterech kodów (patrz `code_for()`), więc pusty tekst nie wychodzi
	 * do człowieka.
	 *
	 * @param string $code Kod odmowy (`CODE_*`).
	 */
	public static function message_for( string $code ): string {
		switch ( $code ) {
			case self::CODE_UNCONFIRMED:
				return __( 'Adres e-mail tego konta nie został potwierdzony. Sprawdź skrzynkę; link potwierdzający przyszedł przy rejestracji.', 'portal-users' );
			case self::CODE_PENDING:
				return __( 'Zgłoszenie czeka na rozpatrzenie przez redakcję. Dostaniesz maila z decyzją.', 'portal-users' );
			case self::CODE_REJECTED:
				return __( 'Zgłoszenie zostało odrzucone. Powód przyszedł mailem.', 'portal-users' );
			case self::CODE_UNRECOGNISED:
				// Treść NIE mówi o uszkodzonej metadanej: to informacja o wnętrzu instalacji,
				// a dla człowieka bezużyteczna. Przyczynę widzi redakcja w logu zdarzeń.
				return __( 'Konto wymaga uwagi redakcji. Napisz do nas.', 'portal-users' );
			default:
				return '';
		}
	}

	/**
	 * Tłumaczy kod odmowy domeny (surowa wartość `AccountStatus`) na kod bramy.
	 *
	 * Wartość, której ta mapa nie zna — np. stan dołożony do enuma w przyszłości — daje
	 * `CODE_UNRECOGNISED`, czyli ODMOWĘ. Przepuszczenie jej byłoby cichym fail-open, a to
	 * dokładnie ten błąd, przed którym broni się cała ta klasa.
	 *
	 * @param string $raw Kod z `LoginDecision::code()`, czyli wartość stanu konta.
	 */
	private static function code_for( string $raw ): string {
		switch ( $raw ) {
			case AccountStatus::EmailUnconfirmed->value:
				return self::CODE_UNCONFIRMED;
			case AccountStatus::Pending->value:
				return self::CODE_PENDING;
			case AccountStatus::Rejected->value:
				return self::CODE_REJECTED;
			default:
				return self::CODE_UNRECOGNISED;
		}
	}

	/**
	 * Odmowa: zapisuje ją w logu zdarzeń i oddaje błąd z komunikatem dla człowieka.
	 *
	 * Wykonawcą jest 0, nie konto odmówione: żądanie logowania przychodzi od odwiedzającego,
	 * który w tym momencie nikim jeszcze nie jest.
	 *
	 * @param int    $userId Konto, któremu odmówiono.
	 * @param string $code   Kod odmowy (`CODE_*`).
	 */
	private static function refuse( int $userId, string $code ): WP_Error {
		EventLog::write( self::LOG_ACTION, $userId, 0, array( 'code' => $code ) );

		return new WP_Error( $code, self::message_for( $code ) );
	}
}
