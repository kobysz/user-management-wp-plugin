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
 *
 * Filtr `authenticate` NIE wystarcza jednak na REST. `wp_validate_application_password()` siedzi
 * na `determine_current_user` (priorytet 20, `default-filters.php:522`) i woła
 * `wp_authenticate_application_password()` WPROST, ze swojego ciała (`user.php:521-536`) —
 * `apply_filters( 'authenticate' )` nie odpala się tam ani razu. `wp_authenticate()` wołają tylko
 * `wp_signon()` (`user.php:109`) i XML-RPC (`class-wp-xmlrpc-server.php:308`). Brama na
 * `authenticate` zamyka więc formularz logowania i XML-RPC, a REST po haśle aplikacyjnym
 * zostawiałaby otwarty — a hasło aplikacyjne jest poświadczeniem TRWAŁYM: konto zatwierdzone,
 * które je sobie wystawiło, a potem zostało odrzucone (albo dostało uszkodzoną metadaną stanu),
 * czytałoby REST bez końca, bo nic mu nie wygasa.
 *
 * Dlatego ta sama decyzja jedzie drugi raz, na filtrze `rest_authentication_errors`, na
 * priorytecie 110, czyli za weryfikatorami rdzenia (`rest_application_password_check_errors`
 * na 90 i `rest_cookie_check_errors` na 100 — `default-filters.php:345-346`). Reguła obejmuje
 * KAŻDE uwierzytelnione żądanie REST, nie tylko ścieżkę hasła aplikacyjnego: zawężenie do
 * `rest_get_authenticated_app_password()` zostawiałoby REST po ciasteczku otwarty dla tego
 * samego niezatwierdzonego konta. Żądanie anonimowe brama przepuszcza bez zmian — tam o dostępie
 * decydują uprawnienia trasy, nie stan konta. Obie drogi liczą jedną decyzją
 * (`refusal_code()`) i mają jedną mapę komunikatów; drugiej kopii reguły tu nie ma.
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
	 * Priorytet filtra `rest_authentication_errors`. Rdzeń trzyma swoje weryfikatory na 90 i 100,
	 * więc 110 znaczy: „po tym, jak rdzeń ustalił, kto pyta".
	 */
	public const REST_PRIORITY = 110;

	/**
	 * Zdarzenie w logu: brama odmówiła wejścia.
	 */
	public const LOG_ACTION = 'login_denied';

	public const CODE_UNCONFIRMED  = 'pusers_email_unconfirmed';
	public const CODE_PENDING      = 'pusers_pending';
	public const CODE_REJECTED     = 'pusers_rejected';
	public const CODE_UNRECOGNISED = 'pusers_status_unrecognised';

	/**
	 * Status HTTP odmowy na REST: konto jest rozpoznane, ale dostępu nie ma, więc 403, nie 401.
	 * Bez tej danej `WP_Error` z filtra `rest_authentication_errors` kończyłby się odpowiedzią 500.
	 */
	private const REST_STATUS = 403;

	/**
	 * Podpina bramę: za weryfikatory hasła rdzenia na logowaniu i za ustalenie użytkownika na REST.
	 *
	 * Ostatni argument (liczba przyjmowanych argumentów) to przy `authenticate` świadome 1: filtr
	 * podaje też login i HASŁO JAWNE, a brama patrzy wyłącznie na stan konta, więc hasło nie ma
	 * po co do niej docierać.
	 */
	public static function register(): void {
		add_filter( 'authenticate', array( self::class, 'gate' ), self::PRIORITY, 1 );
		add_filter( 'rest_authentication_errors', array( self::class, 'gate_rest' ), self::REST_PRIORITY, 1 );
	}

	/**
	 * Wpuszcza albo odmawia na podstawie stanu konta (filtr `authenticate`).
	 *
	 * @param mixed $user Wynik poprzednich filtrów: `WP_User`, `WP_Error` albo null.
	 * @return mixed `WP_User` przy wejściu, `WP_Error` przy odmowie, w pozostałych razach wejście bez zmian.
	 */
	public static function gate( mixed $user ): mixed {
		if ( ! $user instanceof WP_User ) {
			return $user; // Wyrok rdzenia (złe hasło) albo brak kandydata: nie nasza sprawa, nie nasz wpis w logu.
		}

		$code = self::refusal_code( $user->ID );

		return null === $code ? $user : self::refuse( $user->ID, $code );
	}

	/**
	 * Ta sama decyzja na REST (filtr `rest_authentication_errors`).
	 *
	 * Rdzeń woła ten filtr raz, z wartością null (`class-wp-rest-server.php:197`), a jego własne
	 * weryfikatory oddają null, true albo `WP_Error`. Gotową odmowę oddajemy bez dotykania i bez
	 * wpisu do logu (to nie nasza odmowa), a `true` i null znaczą „rdzeń nie ma zastrzeżeń" —
	 * wtedy pytamy o stan konta, które już zostało ustalone.
	 *
	 * @param mixed $errors Dotychczasowy wynik uwierzytelnienia REST: null, true albo `WP_Error`.
	 * @return mixed `WP_Error` przy odmowie, w pozostałych razach wejście bez zmian.
	 */
	public static function gate_rest( mixed $errors ): mixed {
		if ( is_wp_error( $errors ) ) {
			return $errors;
		}

		$userId = get_current_user_id();
		if ( 0 === $userId ) {
			return $errors; // Żądanie anonimowe: o dostępie decydują uprawnienia trasy, nie stan konta.
		}

		$code = self::refusal_code( $userId );

		return null === $code ? $errors : self::refuse( $userId, $code, self::REST_STATUS );
	}

	/**
	 * Tekst odmowy dla kodu. Kod spoza czwórki `CODE_*` daje `''`: mapa nie ma komunikatu
	 * domyślnego, bo taki worek odpowiadałby na kod, którego wtyczka nie zna. Odmowa z bramy
	 * zawsze niesie jeden z czterech kodów (patrz `code_for()`), a gdyby kiedyś nie niosła,
	 * `refuse()` podstawia komunikat zamiast wypuścić puste okienko błędu.
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
	 * Kod odmowy dla konta albo null, gdy konto ma wejść. Jedna decyzja dla obu dróg wejścia.
	 *
	 * Kolejność jest wymuszona: wartość nierozpoznana PRZED decyzją domeny, bo czyta się ona jako
	 * brak stanu, a `LoginGate::decide( null )` WPUSZCZA.
	 *
	 * @param int $userId Konto, o które pytamy.
	 */
	private static function refusal_code( int $userId ): ?string {
		if ( AccountStatusRepository::isUnrecognised( $userId ) ) {
			return self::CODE_UNRECOGNISED;
		}

		$decision = LoginGate::decide( AccountStatusRepository::get( $userId ) );

		return $decision->allowed() ? null : self::code_for( $decision->code() );
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
	 * Pusty komunikat podmienia się na komunikat `CODE_UNRECOGNISED`: `WP_Error` bez treści to
	 * logowanie kończące się pustym okienkiem błędu, czyli awaria najtrudniejsza do zgłoszenia.
	 * Umowa `message_for()` („nieznany kod daje `''`") zostaje przy tym nietknięta.
	 *
	 * @param int      $userId     Konto, któremu odmówiono.
	 * @param string   $code       Kod odmowy (`CODE_*`).
	 * @param int|null $httpStatus Status HTTP dołączany do błędu (tylko REST) albo null.
	 */
	private static function refuse( int $userId, string $code, ?int $httpStatus = null ): WP_Error {
		EventLog::write( self::LOG_ACTION, $userId, 0, array( 'code' => $code ) );

		$message = self::message_for( $code );
		if ( '' === $message ) {
			$message = self::message_for( self::CODE_UNRECOGNISED );
		}

		return null === $httpStatus
			? new WP_Error( $code, $message )
			: new WP_Error( $code, $message, array( 'status' => $httpStatus ) );
	}
}
