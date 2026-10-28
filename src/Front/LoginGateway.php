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
use PortalUsers\Domain\Spam\IpAddress;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;
use WP_Error;
use WP_User;

/**
 * Konsument `LoginGate::decide()`: bez tej klasy stan konta nie znaczy nic, bo konto z
 * niepotwierdzonym adresem logowało się normalnie.
 *
 * Brama siedzi na filtrze `authenticate` na priorytecie 40, czyli ZA KAŻDYM wyrokiem rdzenia.
 * Rdzeń trzyma na tym filtrze PIĘĆ callbacków, nie cztery:
 * `wp_authenticate_username_password`, `wp_authenticate_email_password`
 * i `wp_authenticate_application_password` na 20 oraz `wp_authenticate_spam_check` na 99
 * (`wp-includes/default-filters.php:516-519`), a PIĄTY dokłada `wp_signon()` z wnętrza swojego
 * ciała: `wp-includes/user.php:107` — `wp_authenticate_cookie` na priorytecie 30.
 * Brama dostaje więc albo `WP_Error` od rdzenia (hasło złe — oddajemy bez dotykania i bez wpisu
 * do logu, bo to nie nasza odmowa), albo `WP_User` z już sprawdzonym hasłem ALBO z ważnego
 * ciasteczka sesji.
 *
 * DLACZEGO 40, A NIE 30. Ciało `wp_authenticate_cookie()` wychodzi wcześnie TYLKO dla `WP_User`:
 * przy pustym loginie I pustym haśle woła `wp_validate_auth_cookie()` i oddaje
 * `new WP_User( $user_id )`. Brama rejestruje się przy ładowaniu wtyczki, więc WEWNĄTRZ priorytetu
 * 30 szłaby pierwsza — widziałaby rdzeniowy `empty_username`, słusznie oddawała go nietkniętego,
 * a uwierzytelniacz ciasteczka podnosiłby ten błąd do `WP_User`, którego brama już nie ogląda,
 * i `wp_signon()` wystawiałby na niego świeże ciasteczko. Konto BEZ zatwierdzenia, mające ważne
 * ciasteczko sesji, odnawiałoby je przez to bez końca pustym POST-em na `wp-login.php` (które
 * specyfikacja celowo zostawia otwarte dla redakcji, a rdzeniowa akcja logowania nonce'a nie ma),
 * z „zapamiętaj mnie” włącznie, czyli do dwóch tygodni na raz. Czterdzieści stoi za
 * weryfikatorami hasła (20), za uwierzytelniaczem ciasteczka (30) i przed
 * `wp_authenticate_spam_check` (99).
 *
 * Tego NIE wolno przenieść na filtr `wp_authenticate_user`: on odpala się PRZED
 * `wp_check_password()` (patrz `wp-includes/user.php`), więc odmowa spod niego trafiałaby do
 * każdego, kto zna sam adres — i brama sama stawałaby się sondą ujawniającą, że adres jest zajęty.
 * Na priorytecie 40 do bramy nie dochodzi nikt, kto nie zna hasła ani nie ma ważnej sesji, więc
 * każdy stan może mieć WŁASNY, PRAWDZIWY komunikat: człowiek wie, czy ma szukać maila, czy czekać
 * na decyzję redakcji.
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
 * KAŻDY WIERSZ W LOGU NAZYWA SWOJĄ DROGĘ (`channel`). Obie drogi piszą tę samą akcję
 * (`LOG_ACTION`), więc bez tego klucza redakcja nie odróżniała odmowy z formularza logowania od
 * odmowy na REST — a to dwie zupełnie różne diagnozy.
 *
 * ODMOWA NA REST MA BUDŻET WIERSZY W LOGU (`BUCKET_LOG_IP`). Konto odrzucone zachowuje hasło
 * aplikacyjne świadomie (jego użycie jest odmawiane, więc poświadczenie jest martwe), ale
 * zalogowany subskrybent MOŻE je sobie wystawić przez `/wp/v2/users/me/application-passwords` —
 * `RestUsers::restrict()` zdejmuje trasy użytkowników tylko obcym. Lekarz zatwierdzony mógł więc
 * wystawić hasło aplikacyjne, zostać odrzucony, stracić sesje i dalej trzymać poświadczenie, które
 * uwierzytelnia, dostaje odmowę i pisze JEDEN `INSERT` na żądanie, bez końca, do tabeli, której
 * nic nie przycina. To ta sama awaria, którą budżet wierszy zamknął na ścieżce odzyskiwania hasła
 * (`PasswordReset::log_refusal()`), i naprawa ma ten sam kształt: wiersze dopóki klient jest w
 * budżecie, potem DOKŁADNIE jeden wiersz o wyczerpaniu na okno licznika. Budżet ma WŁASNY licznik,
 * bo odczyt licznika bramki nie działa tam, gdzie żadna bramka progu się nie odpaliła — a na REST
 * nie odpala się żadna.
 *
 * Droga formularza budżetu nie potrzebuje: żeby do niej dojść, trzeba znać hasło (albo mieć ważną
 * sesję), a `LoginHandler` liczy swoje progi przed `wp_signon()`. Budżet dołożony także tam
 * zabierałby wiersze odmowom, których jest kilka na godzinę.
 *
 * Konto BEZ stanu brama wpuszcza (tak rozstrzyga `LoginGate::decide( null )`): administrator
 * dodany ręcznie w panelu stanu nie ma, a odmowa zamknęłaby właściciela portalu poza portalem.
 * Dokładnie dlatego wartość metadanej, której nikt nie rozpoznaje (`'Approved'`, `' pending'`),
 * sprawdza się OSOBNO i PRZED decyzją domeny: czyta się ona jako brak stanu, więc przekazana
 * do `decide()` WPUSZCZAŁABY — czyli fail-open po ręcznej edycji bazy.
 */
final class LoginGateway {

	/**
	 * Priorytet filtra `authenticate`. Rdzeń trzyma weryfikatory hasła na 20, uwierzytelniacz
	 * ciasteczka na 30 (`wp-includes/user.php:107`, dokładany z wnętrza `wp_signon()`), a
	 * `wp_authenticate_spam_check` na 99 — więc 40 znaczy: „po KAŻDYM wyroku rdzenia".
	 * Obniżenie tej liczby do 30 albo niżej jest zmianą bezpieczeństwa (patrz dokumentacja klasy).
	 */
	public const PRIORITY = 40;

	/**
	 * Priorytet filtra `rest_authentication_errors`. Rdzeń trzyma swoje weryfikatory na 90 i 100,
	 * więc 110 znaczy: „po tym, jak rdzeń ustalił, kto pyta".
	 */
	public const REST_PRIORITY = 110;

	/**
	 * Zdarzenie w logu: brama odmówiła wejścia.
	 */
	public const LOG_ACTION = 'login_denied';

	/**
	 * Droga odmowy zapisywana w wierszu logu: formularz logowania (filtr `authenticate`) albo REST
	 * (filtr `rest_authentication_errors`). Obie drogi piszą tę samą akcję, więc bez tej danej
	 * wiersze są nieodróżnialne.
	 */
	public const CHANNEL_LOGIN = 'login';
	public const CHANNEL_REST  = 'rest';

	/**
	 * Kubełek BUDŻETU WIERSZY W LOGU dla odmów na REST, per adres IP (patrz dokumentacja klasy).
	 *
	 * Osobny od kubełków progów logowania: ten licznik niczego nie bramkuje, tylko przycina zapis
	 * do tabeli, a wspólny z progiem odbierałby budżet żądań komuś, kto nic złego nie zrobił.
	 *
	 * Pusty adres klienta daje JEDEN kubełek dla całego ruchu bez adresu — odwrotnie niż przy
	 * progach, gdzie wspólny kubełek odciąłby wszystkich. Tu wspólny kubełek nikomu nie odbiera
	 * dostępu, a nieograniczony `INSERT` byłby gorszy od utraty części wierszy (ta sama reguła,
	 * co w `PasswordReset::BUCKET_LOG_IP`).
	 */
	public const BUCKET_LOG_IP = 'restlog_ip:';

	/**
	 * Znacznik „powiedziano już, że budżet wierszy się skończył” dla okna `BUCKET_LOG_IP`; dzięki
	 * niemu wyczerpanie budżetu zgłasza się w logu DOKŁADNIE raz na okno licznika, a nie na każde
	 * kolejne żądanie (ten sam wzór, co w `PasswordReset`).
	 */
	private const MARKER_LOG_IP = 'restlog_said:';

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

		return null === $code ? $user : self::refuse( $user->ID, $code, self::CHANNEL_LOGIN );
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

		return null === $code ? $errors : self::refuse( $userId, $code, self::CHANNEL_REST, self::REST_STATUS );
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
	 * @param string   $channel    Droga odmowy (`CHANNEL_*`).
	 * @param int|null $httpStatus Status HTTP dołączany do błędu (tylko REST) albo null.
	 */
	private static function refuse( int $userId, string $code, string $channel, ?int $httpStatus = null ): WP_Error {
		self::log_refusal( $userId, $code, $channel );

		$message = self::message_for( $code );
		if ( '' === $message ) {
			$message = self::message_for( self::CODE_UNRECOGNISED );
		}

		return null === $httpStatus
			? new WP_Error( $code, $message )
			: new WP_Error( $code, $message, array( 'status' => $httpStatus ) );
	}

	/**
	 * Dopisuje wiersz odmowy. Odmowa z formularza idzie wprost, odmowa na REST — w budżecie
	 * wierszy (patrz dokumentacja klasy i `BUCKET_LOG_IP`). Po wyczerpaniu budżetu wiersze
	 * przestają powstawać, a samo wyczerpanie zgłasza się DOKŁADNIE raz na okno licznika.
	 *
	 * Budżet liczy WŁASNY licznik, nie licznik progu: na REST żadna bramka progu się nie odpala,
	 * więc odczyt cudzego licznika nie ograniczyłby niczego — a zwiększanie progu logowania przez
	 * sam zapis do logu zamieniłoby wiersz w logu w bramkę.
	 *
	 * Wiersz o wyczerpanym budżecie nie dotyczy już konta, tylko samego logu, więc niesie drogę,
	 * adres klienta i powód, a nie kod odmowy.
	 *
	 * @param int    $userId  Konto, któremu odmówiono.
	 * @param string $code    Kod odmowy (`CODE_*`).
	 * @param string $channel Droga odmowy (`CHANNEL_*`).
	 */
	private static function log_refusal( int $userId, string $code, string $channel ): void {
		$row = array(
			'code'    => $code,
			'channel' => $channel,
		);

		if ( self::CHANNEL_REST !== $channel ) {
			EventLog::write( self::LOG_ACTION, $userId, 0, $row );
			return;
		}

		$settings = SettingsRepository::get();
		$ip       = IpAddress::from( $_SERVER, $settings->trustedProxyHeader );
		$bucket   = self::BUCKET_LOG_IP . $ip;

		if ( RateLimiter::hit( $bucket ) > $settings->loginLimitPerHour ) {
			if ( RateLimiter::once_per_window( self::MARKER_LOG_IP . $ip, $bucket ) ) {
				EventLog::write(
					self::LOG_ACTION,
					$userId,
					0,
					array(
						'channel' => $channel,
						'ip'      => $ip,
						'refused' => 'log_limit',
					)
				);
			}

			return;
		}

		EventLog::write( self::LOG_ACTION, $userId, 0, $row );
	}
}
