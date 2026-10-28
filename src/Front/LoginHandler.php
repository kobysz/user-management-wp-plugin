<?php
/**
 * Obsługa wysłanego formularza logowania: bramki, progi i przekierowanie.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Spam\IpAddress;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;
use WP_Error;
use WP_User;

/**
 * Kolejność bramek jest nienaruszalna:
 * nonce, metoda POST, pułapka na boty wraz z punktem rozszerzenia, próg per adres IP, odnalezienie
 * konta, próg per konto, `wp_signon()`, przekierowanie (PRG albo adres docelowy).
 *
 * Trzy reguły tej klasy są jej powodem istnienia:
 *
 * 1. LICZNIK PRÓB ROŚNIE WYŁĄCZNIE NA PORAŻKACH. Progi są tu tylko CZYTANE (`RateLimiter::count()`),
 *    a zliczane (`RateLimiter::hit()`) dopiero po odmowie z `wp_signon()`. Przychodnia za jednym
 *    adresem wyjściowym generuje dziesiątki udanych logowań dziennie, więc próg zużywany przez każde
 *    żądanie odcinałby ją przy normalnej pracy. Formularz rejestracji przeszedł dokładnie tę pomyłkę
 *    i próg trzeba było potem podnosić pięciokrotnie.
 *
 * 2. KUBEŁEK PER KONTO POWSTAJE WYŁĄCZNIE DLA KONTA ODNALEZIONEGO. Kubełek kluczowany surowym
 *    łańcuchem z żądania pozwoliłby botowi wysypać do `wp_options` dowolną liczbę transientów, więc
 *    gdy identyfikatora nie da się przypisać do konta, liczy się tylko kubełek per adres IP. Próg
 *    per konto jest przy tym zużywany PO odnalezieniu konta i tylko na porażce, żeby osoba trzecia
 *    nie wyczerpała właścicielowi budżetu samym wysyłaniem jego adresu; jej ruch absorbuje kubełek IP
 *    (ta sama reguła, co w `Confirmation::handle_resend()` i `PasswordSetup::handle()`).
 *
 * 3. JEDEN KOMUNIKAT OGÓLNY DLA ZŁYCH DANYCH. Nieznany adres i złe hasło dostają ten sam tekst.
 *    Rdzeń na `wp-login.php` rozróżnia `invalid_username` od `incorrect_password`, czyli sam
 *    enumeruje konta; na stronie wtyczki tego nie powtarzamy. Odmowy stanu z `LoginGateway`
 *    przechodzą WŁASNĄ treścią, bo na priorytecie 30 dochodzi do nich wyłącznie ktoś, kto podał
 *    poprawne hasło — i to jest jedyny powód, dla którego wolno im mówić prawdę o stanie konta.
 *
 * Odmowa stanu NIE zużywa progu i NIE pisze drugiego wiersza do logu: hasło było poprawne, więc nie
 * jest to zgadywanie, a własny wiersz zapisała już brama (`LoginGateway::LOG_ACTION`). To ta sama
 * granica, którą brama trzyma po swojej stronie: wyrok rdzenia nie jest jej odmową, a odmowa bramy
 * nie jest odmową tej klasy.
 *
 * Klasa rejestruje się pod `pusers_register_features`, czyli za sprawdzeniem zależności — inaczej
 * niż brama logowania i strażnik sesji, które są ZAMKAMI i nie mogą zniknąć razem z ACF Pro.
 * Obsługa formularza zamkiem nie jest: gdy wtyczka bezczynnie czeka na zależność, formularz się nie
 * renderuje i ta klasa nie ma czego odebrać. Wyjątku bramy nie wolno tu generalizować.
 */
final class LoginHandler {

	/**
	 * Akcja formularza (admin-post). Nazwa akcji należy do OBSŁUGI żądania, nie do widoku: widok
	 * (`LoginForm::render()`) czyta ją właśnie tutaj, tak samo jak formularz rejestracji czyta
	 * `RegistrationHandler::ACTION`.
	 */
	public const ACTION = 'pusers_login';

	/**
	 * Zdarzenie w logu: odmowa z powodu złych danych logowania.
	 */
	public const LOG_ACTION = 'login_failed';

	/**
	 * Punkt rozszerzenia (Turnstile, hCaptcha): filtr dostaje przepustkę własnych reguł i dane
	 * żądania, a zwrócenie fałszu zamienia ją na odmowę. Odmowy na przepustkę NIE zmieni.
	 * Stoi PRZED sprawdzeniem hasła, więc rozszerzenie nie płaci za żądania botów.
	 */
	public const SPAM_FILTER = 'pusers_login_spam_check';

	/**
	 * Wynik: złe dane logowania (nieznany identyfikator ALBO złe hasło — jeden kod na oba przypadki).
	 */
	public const RESULT_BAD = 'bad';

	/**
	 * Wynik: próg prób wyczerpany (per adres IP albo per konto).
	 */
	public const RESULT_TOO_MANY = 'too_many';

	/**
	 * Wynik: nonce przedawniony albo nieobecny.
	 */
	public const RESULT_STALE = 'stale';

	/**
	 * Kubełek progu nieudanych logowań per adres IP. OSOBNY od kubełków rejestracji, ponownej wysyłki
	 * i ustawienia hasła: wspólny pozwalałby wyczerpać jednym przebiegiem budżet drugiej ścieżki.
	 */
	public const BUCKET_IP = 'login_ip:';

	/**
	 * Kubełek progu nieudanych logowań per KONTO; klucz to identyfikator konta ODNALEZIONEGO
	 * (patrz reguła 2 w dokumentacji klasy).
	 */
	public const BUCKET_USER = 'login_user:';

	/**
	 * Podpina obsługę wysłanego formularza. Oba warianty akcji: zalogowany też wyśle ten formularz
	 * (otwarta karta, powrót z historii przeglądarki), a `admin_post_nopriv_` jego żądania nie widzi.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Obsługuje wysłany formularz logowania (kolejność bramek: patrz dokumentacja klasy).
	 */
	public static function handle(): void {
		// 1. Nonce. Nonce starzeje się razem z sesją, więc człowiek z kartą otwartą od doby trafia
		// właśnie tu: dostaje przez PRG komunikat „odśwież stronę”, a nie 403.
		$nonce = isset( $_POST[ LoginForm::NONCE_FIELD ] ) && is_string( $_POST[ LoginForm::NONCE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- to jest weryfikacja nonce'a.
			? sanitize_text_field( wp_unslash( $_POST[ LoginForm::NONCE_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, LoginForm::NONCE_ACTION ) ) {
			self::refuse( self::RESULT_STALE );
			return;
		}

		// 2. Metoda POST (admin-post odpala się dla każdej metody).
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		// 3. Pułapka na boty i punkt rozszerzenia, PRZED sprawdzeniem hasła: odmowa nic nie kosztuje
		// i nie liczy się do progu, więc bot nie zabiera budżetu nikomu.
		if ( ! self::looks_human() ) {
			self::refuse( self::RESULT_BAD );
			return;
		}

		$settings = SettingsRepository::get();

		// 4. Próg per adres IP, tylko CZYTANY (patrz reguła 1). Bez ustalonego adresu klienta progu
		// się nie liczy: ruch bez adresu zlany w jeden kubełek odciąłby cały portal.
		$ip = IpAddress::from( $_SERVER, $settings->trustedProxyHeader );
		if ( '' !== $ip && RateLimiter::count( self::BUCKET_IP . $ip ) >= $settings->loginLimitPerHour ) {
			self::refuse( self::RESULT_TOO_MANY );
			return;
		}

		$identity = self::identity();

		// 5. Odnalezienie konta: adres e-mail po adresie, cokolwiek innego po loginie.
		$user   = self::account_for( $identity );
		$userId = null === $user ? 0 : $user->ID;

		// 6. Próg per konto — wyłącznie dla konta odnalezionego (patrz reguła 2), też tylko czytany.
		if ( 0 !== $userId && RateLimiter::count( self::BUCKET_USER . $userId ) >= $settings->loginLimitPerAccountPerHour ) {
			self::refuse( self::RESULT_TOO_MANY );
			return;
		}

		// 7. Hasło i stan konta: `wp_signon()` woła `wp_authenticate()`, czyli weryfikatory rdzenia
		// (priorytet 20) i bramę stanu konta (priorytet 30).
		$signed = wp_signon(
			array(
				'user_login'    => $identity,
				'user_password' => self::password(),
				'remember'      => self::remembered(),
			)
		);
		if ( $signed instanceof WP_Error ) {
			self::refuse_signon( $signed, $identity, $ip, $userId );
			return;
		}

		self::succeed( $signed, $settings );
	}

	/**
	 * Tekst komunikatu dla wyniku.
	 *
	 * Obsługuje WYŁĄCZNIE kody własne; o wszystko inne pyta `LoginGateway::message_for()`, więc
	 * mapa komunikatów stanu konta istnieje w jednym miejscu. Druga kopia rozjechałaby się z
	 * pierwszą przy pierwszej zmianie tekstu. Kod, którego nie zna ani ta klasa, ani brama, daje
	 * `''` — strona ma wtedy nie pokazywać nic, a nie puste okienko alertu.
	 *
	 * @param string $code Wynik (`RESULT_*`) albo kod odmowy bramy (`LoginGateway::CODE_*`).
	 */
	public static function message_for( string $code ): string {
		switch ( $code ) {
			case self::RESULT_BAD:
				// JEDEN tekst dla nieznanego identyfikatora i dla złego hasła (patrz reguła 3).
				return __( 'Nieprawidłowy adres e-mail lub hasło.', 'portal-users' );
			case self::RESULT_TOO_MANY:
				return __( 'Zbyt wiele nieudanych prób logowania. Spróbuj ponownie później.', 'portal-users' );
			case self::RESULT_STALE:
				return __( 'Formularz jest nieaktualny. Odśwież stronę i wypełnij go ponownie.', 'portal-users' );
			default:
				return LoginGateway::message_for( $code );
		}
	}

	/**
	 * Konto dla identyfikatora z żądania albo null, gdy identyfikatora nie da się przypisać do konta.
	 * Adres e-mail szukamy po adresie, cokolwiek innego po loginie.
	 *
	 * @param string $identity Identyfikator z formularza.
	 */
	private static function account_for( string $identity ): ?WP_User {
		if ( '' === $identity ) {
			return null;
		}

		$user = is_email( $identity ) ? get_user_by( 'email', $identity ) : get_user_by( 'login', $identity );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Odmowa z `wp_signon()`.
	 *
	 * Odmowa stanu konta (kod, który zna brama) przechodzi WŁASNĄ treścią i nie zużywa progu ani nie
	 * pisze wiersza do logu: hasło było poprawne, więc nie jest to zgadywanie, a brama zapisała już
	 * swój wiersz. Wszystko pozostałe to złe dane logowania: jeden kod ogólny, oba kubełki w górę
	 * i jeden wiersz w logu.
	 *
	 * @param WP_Error $error    Odmowa z `wp_signon()`.
	 * @param string   $identity Identyfikator z formularza.
	 * @param string   $ip       Adres klienta (`''` = nieustalony).
	 * @param int      $userId   Konto odnalezione dla identyfikatora (0 = brak).
	 */
	private static function refuse_signon( WP_Error $error, string $identity, string $ip, int $userId ): void {
		$state = self::state_code( $error );
		if ( '' !== $state ) {
			self::refuse( $state );
			return;
		}

		if ( '' !== $ip ) {
			RateLimiter::hit( self::BUCKET_IP . $ip );
		}
		if ( 0 !== $userId ) {
			RateLimiter::hit( self::BUCKET_USER . $userId );
		}

		// W logu NIE MA hasła ani jego skrótu, a identyfikator wyłącznie skrótem — tak samo jak
		// w cronie czyszczącym konta. Log zdarzeń jest śladem decyzji redakcji, nie magazynem
		// poświadczeń: wyciek tej tabeli nie może dawać niczego, czym da się zalogować.
		EventLog::write(
			self::LOG_ACTION,
			$userId,
			0,
			array(
				'code'          => (string) $error->get_error_code(),
				'ip'            => $ip,
				'identity_hash' => wp_hash( strtolower( $identity ) ),
			)
		);

		self::refuse( self::RESULT_BAD );
	}

	/**
	 * Kod odmowy stanu konta albo `''`, gdy odmowa nie pochodzi z bramy.
	 *
	 * O przynależności kodu rozstrzyga SAMA brama (pusty komunikat znaczy „nie mój kod”), więc
	 * druga lista kodów stanu tu nie istnieje i nie ma czemu się rozjechać.
	 *
	 * @param WP_Error $error Odmowa z `wp_signon()`.
	 */
	private static function state_code( WP_Error $error ): string {
		$code = (string) $error->get_error_code();

		return '' === LoginGateway::message_for( $code ) ? '' : $code;
	}

	/**
	 * Udane logowanie: przekierowanie na adres docelowy.
	 *
	 * Adres z pola formularza to DANE Z ŻĄDANIA dokładnie tak samo jak argument adresu, więc
	 * przechodzi przez `wp_validate_redirect()`, a samo przekierowanie idzie wyłącznie przez
	 * `wp_safe_redirect()`. Filtr `login_redirect` rdzenia jest tu wołany świadomie: motyw albo
	 * wtyczka kierująca po zalogowaniu musi działać także na tym formularzu. Jego wynik i tak
	 * przechodzi przez kontrolę hosta, więc filtr nie wyprowadzi nikogo z witryny.
	 *
	 * @param WP_User  $user     Zalogowane konto.
	 * @param Settings $settings Ustawienia odczytane już przez `handle()`.
	 */
	private static function succeed( WP_User $user, Settings $settings ): void {
		$fallback  = PageUrl::for( $settings->afterLoginPageId );
		$requested = self::requested_redirect();

		/**
		 * Filtr rdzenia: adres po zalogowaniu.
		 *
		 * @param string  $target    Adres, na który pójdzie przekierowanie.
		 * @param string  $requested Adres z żądania po kontroli hosta (`''` = brak).
		 * @param WP_User $user      Zalogowane konto.
		 */
		$target = apply_filters( 'login_redirect', '' === $requested ? $fallback : $requested, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtr rdzenia, nie nasz hook.

		self::redirect( wp_validate_redirect( is_string( $target ) ? $target : $fallback, $fallback ) );
	}

	/**
	 * Przekierowuje na stronę logowania ze znacznikiem wyniku (PRG).
	 *
	 * @param string $code Wynik (`RESULT_*`) albo kod odmowy bramy.
	 */
	private static function refuse( string $code ): void {
		self::redirect( add_query_arg( LoginForm::RESULT_ARG, $code, LoginForm::base_url() ) );
	}

	/**
	 * Przekierowuje i kończy żądanie. Przekierowanie anulowane filtrem `wp_redirect` nie kończy
	 * żądania (szew testowy), więc wywołujący zawsze wraca.
	 *
	 * @param string $url Adres docelowy.
	 */
	private static function redirect( string $url ): void {
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * Czy żądanie wygląda na ludzkie: pusta pułapka i przepustka z punktu rozszerzenia.
	 *
	 * Pułapki CZASOWEJ formularz logowania nie ma świadomie (patrz `LoginForm`): menedżer haseł
	 * wysyła go w ułamku sekundy.
	 */
	private static function looks_human(): bool {
		$honeypot = $_POST[ LoginForm::HONEYPOT ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
		// Pole, które nie jest tekstem (np. tablica), też jest wypełnione: człowiek takiego nie wyśle.
		// Białe znaki obcinamy: autouzupełnianie przeglądarki potrafi dorzucić spację uczciwemu użytkownikowi.
		if ( ! is_string( $honeypot ) || '' !== trim( $honeypot ) ) {
			return false;
		}

		$unslashed = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
		$request   = is_array( $unslashed ) ? $unslashed : array();
		// HASŁA ani NONCE'A filtrowi NIE podajemy. Rozszerzenie antyspamowe sprawdza swoje pole
		// (captcha), a nie poświadczenia, i typowo wysyła to, co dostanie, do usługi zewnętrznej:
		// hasło jest poświadczeniem, a nonce żetonem, i żadne z nich nie ma po co opuszczać tej
		// metody. Identyfikator zostaje — reguła antyspamowa może go sensownie potrzebować.
		unset( $request[ LoginForm::PASSWORD ], $request[ LoginForm::NONCE_FIELD ] );

		/**
		 * Punkt rozszerzenia antyspamu logowania (pełny opis przy stałej `SPAM_FILTER`, nazwa hooka:
		 * pusers_login_spam_check). Zwrócenie fałszu zamienia przepustkę na odmowę.
		 *
		 * @param bool                 $passed  Przepustka własnych reguł wtyczki.
		 * @param array<string, mixed> $request Dane żądania bez ukośników, bez hasła i bez nonce'a.
		 */
		return false !== apply_filters( self::SPAM_FILTER, true, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej SPAM_FILTER, która ma prefiks pusers_.
	}

	/**
	 * Identyfikator konta z żądania (adres e-mail albo login).
	 */
	private static function identity(): string {
		return isset( $_POST[ LoginForm::IDENTITY ] ) && is_string( $_POST[ LoginForm::IDENTITY ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
			? sanitize_text_field( trim( wp_unslash( $_POST[ LoginForm::IDENTITY ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
	}

	/**
	 * Hasło z żądania.
	 *
	 * Hasła NIE sanityzujemy ani nie obcinamy: nie jedzie do bazy jako tekst, tylko do
	 * `wp_check_password()`, które porównuje je ze skrótem. `sanitize_text_field()` zjadłoby znaki,
	 * których człowiek użył przy ustawianiu hasła, i hasło przestałoby się zgadzać — czyli
	 * „naprawa” tej linii sanityzacją zamknęłaby ludziom dostęp do własnych kont. Dokładnie tak
	 * samo postępuje `PasswordSetup::handle()` przy ustawianiu hasła.
	 */
	private static function password(): string {
		return isset( $_POST[ LoginForm::PASSWORD ] ) && is_string( $_POST[ LoginForm::PASSWORD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
			? (string) wp_unslash( $_POST[ LoginForm::PASSWORD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- hasło nie podlega sanityzacji (patrz komentarz wyżej).
			: '';
	}

	/**
	 * Czy zaznaczono „zapamiętaj mnie”.
	 */
	private static function remembered(): bool {
		$raw = isset( $_POST[ LoginForm::REMEMBER ] ) && is_string( $_POST[ LoginForm::REMEMBER ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
			? sanitize_text_field( wp_unslash( $_POST[ LoginForm::REMEMBER ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		return '' !== $raw && '0' !== $raw;
	}

	/**
	 * Adres docelowy z pola formularza po kontroli hosta; `''`, gdy go brak albo wskazuje inny serwis.
	 */
	private static function requested_redirect(): string {
		if ( ! isset( $_POST[ LoginForm::REDIRECT ] ) || ! is_string( $_POST[ LoginForm::REDIRECT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
			return '';
		}

		return wp_validate_redirect( wp_unslash( $_POST[ LoginForm::REDIRECT ] ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanityzacja i kontrola hosta w wp_validate_redirect().
	}
}
