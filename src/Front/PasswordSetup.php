<?php
/**
 * Ustawienie hasła przez osobę, która dowiodła władzy nad adresem e-mail.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Spam\IpAddress;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

/**
 * Rejestracja NIE pyta o hasło: konto powstaje z hasłem losowym, którego nikt nie zna, a hasło
 * ustawia dopiero ten, kto otworzył link potwierdzający z własnej skrzynki. Bez tego osoba trzecia
 * zgłaszała cudzy adres z własnym hasłem i po zatwierdzeniu wniosku przez redakcję miała działające
 * dane logowania (znalezisko I1).
 *
 * Bilet na ustawienie hasła jest OSOBNY od tokenu potwierdzenia i jedzie CIASTECZKIEM:
 *
 * - osobny, bo token potwierdzenia jest zużywany atomowo, więc nie da się go użyć drugi raz;
 * - w ciasteczku, nie w adresie, z tego samego powodu co klucz do danych po przekierowaniu:
 *   adres trafia do historii przeglądarki, nagłówka referer i logu każdego proxy po drodze,
 *   a razem z nim trafiłby tam bilet otwierający ustawienie hasła;
 * - w metadanych użytkownika zostaje WYŁĄCZNIE skrót biletu i termin ważności (dokładnie jak
 *   w `Confirmation::issue()`), więc wyciek bazy nie daje biletu;
 * - ważność jedna godzina: bilet wystawia się na ruchu człowieka, który właśnie kliknął link.
 *
 * Bilet powstaje WYŁĄCZNIE na ścieżce PIERWSZEGO udanego potwierdzenia (patrz `Confirmation::handle()`).
 *
 * Zgubiony albo przeterminowany bilet kieruje do odzyskiwania hasła i NIC więcej: drugiego
 * mechanizmu wysyłającego link do hasła tu nie ma i mieć nie będzie, bo odzyskiwanie hasła już
 * istnieje (dziś `wp-login.php`, świadomie zostawione jako droga zapasowa dla redakcji).
 *
 * Po ustawieniu hasła NIE logujemy automatycznie: konto jest w stanie `pending` i czeka na decyzję
 * redakcji, więc automatyczne logowanie obiecywałoby dostęp, którego nie ma.
 */
final class PasswordSetup {

	/**
	 * Akcja formularza (admin-post) i zarazem akcja nonce'a.
	 */
	public const ACTION = 'pusers_set_password';

	public const NONCE_ACTION   = 'pusers_set_password';
	public const NONCE_FIELD    = 'pusers_setpass_nonce';
	public const PASSWORD_FIELD = 'pusers_new_password';

	/**
	 * Ciasteczko z biletem (`<identyfikator>.<bilet>`), ograniczone do strony rejestracji.
	 */
	public const COOKIE = 'pusers_pwticket';

	/**
	 * Szew wysyłania ciasteczka (testy go przechwytują i zwracają false); argumenty: nazwa, wartość, opcje.
	 */
	public const COOKIE_FILTER = 'pusers_password_send_cookie';

	/**
	 * Argument adresu otwierający stronę ustawienia hasła; jego wartość to RESULT_*.
	 * NIE niesie biletu ani żadnego innego sekretu.
	 */
	public const PAGE_ARG = 'pusers_setpass';

	/**
	 * Minimalna długość hasła (znaków).
	 */
	public const MIN_LENGTH = 12;

	/**
	 * Ważność biletu, w godzinach.
	 */
	public const TICKET_HOURS = 1;

	public const RESULT_FORM     = '1';
	public const RESULT_DONE     = 'done';
	public const RESULT_EXPIRED  = 'expired';
	public const RESULT_STALE    = 'stale';
	public const RESULT_SHORT    = 'short';
	public const RESULT_EMAIL    = 'email';
	public const RESULT_TOO_MANY = 'too_many';

	/**
	 * Zdarzenie w logu: hasło zostało ustawione z biletu.
	 */
	public const LOG_ACTION = 'password_set';

	/**
	 * Kubełek progu prób per adres IP. OSOBNY od kubełków rejestracji i ponownej wysyłki: wspólny
	 * pozwalałby wyczerpać jednym przebiegiem budżet drugiego i mieszałby dwa niezależne progi.
	 */
	private const BUCKET_SETPASS_IP = 'setpass_ip:';

	/**
	 * Podpina obsługę wysłanego formularza (także dla niezalogowanych: konto czeka na decyzję redakcji).
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Wystawia bilet, wysyła ciasteczko i zwraca adres strony ustawienia hasła (bez biletu
	 * w adresie). Null znaczy: biletu NIE udało się wystawić — wołający musi wtedy powiedzieć
	 * prawdę o operacji, która się udała (adres jest potwierdzony), i wskazać drogę dalej.
	 *
	 * @param int $userId Identyfikator użytkownika, który właśnie potwierdził adres.
	 */
	public static function start( int $userId ): ?string {
		$token = self::issue( $userId );
		if ( '' === $token ) {
			return null;
		}
		self::send_cookie( $userId . '.' . $token, time() + self::TICKET_HOURS * HOUR_IN_SECONDS );

		return add_query_arg( self::PAGE_ARG, self::RESULT_FORM, Confirmation::base_url() );
	}

	/**
	 * Wystawia bilet: zwraca jawny (do ciasteczka), zapisuje wyłącznie skrót i termin ważności.
	 * Kolejne wywołanie nadpisuje poprzedni skrót, więc poprzedni bilet przestaje działać.
	 *
	 * Zwraca `''`, gdy zapisu NIE udało się wykonać. Ignorowanie wyniku `update_user_meta()`
	 * dawało bilet, którego nie ma w bazie, a człowiekowi — komunikat „Link wygasł” po
	 * potwierdzeniu, które się UDAŁO (stan konta zmieniony, mail do redakcji wysłany).
	 * `false` nie może tu znaczyć „wartość bez zmian”: skrót jest losowy.
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	public static function issue( int $userId ): string {
		$token = bin2hex( random_bytes( 32 ) );

		if ( false === update_user_meta( $userId, UserMeta::PASSWORD_TICKET_HASH, self::hash( $token ) ) ) {
			return '';
		}
		if ( false === update_user_meta( $userId, UserMeta::PASSWORD_TICKET_EXPIRES, time() + self::TICKET_HOURS * HOUR_IN_SECONDS ) ) {
			// Bilet bez terminu ważności byłby biletem bezterminowym, więc skrót sprzątamy.
			delete_user_meta( $userId, UserMeta::PASSWORD_TICKET_HASH );
			return '';
		}

		return $token;
	}

	/**
	 * Obsługuje wysłany formularz ustawienia hasła.
	 *
	 * Kolejność bramek: nonce, metoda POST, próg per IP, bilet, reguły hasła, zużycie biletu, zapis.
	 * Próg stoi PRZED biletem, żeby zgadywanie wartości ciasteczka też się liczyło.
	 */
	public static function handle(): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- to jest weryfikacja nonce'a.
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::redirect( self::RESULT_STALE );
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		if ( ! self::within_ip_limit() ) {
			self::redirect( self::RESULT_TOO_MANY );
			return;
		}

		$ticket = self::ticket();
		if ( null === $ticket ) {
			self::redirect( self::RESULT_EXPIRED );
			return;
		}
		$user = get_userdata( $ticket['user'] );
		if ( false === $user ) {
			self::redirect( self::RESULT_EXPIRED );
			return;
		}

		// Hasła NIE sanityzujemy ani nie obcinamy: nie jedzie do bazy jako tekst, tylko do
		// `wp_set_password()`, które liczy z niego skrót. `sanitize_text_field()` zjadłby znaki,
		// których człowiek użył, i hasło przestałoby się zgadzać przy logowaniu. Sanityzacja
		// wartości pól zgłoszenia (`RegistrationHandler::sanitized()`) tej ścieżki nie dotyczy.
		$password = isset( $_POST[ self::PASSWORD_FIELD ] ) && is_string( $_POST[ self::PASSWORD_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
			? (string) wp_unslash( $_POST[ self::PASSWORD_FIELD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- hasło nie podlega sanityzacji (patrz komentarz wyżej).
			: '';

		if ( mb_strlen( $password ) < self::MIN_LENGTH ) {
			self::redirect( self::RESULT_SHORT );
			return;
		}
		// Hasło równe adresowi e-mail jest publicznie znane każdemu, kto widział zgłoszenie.
		// Porównanie bez względu na wielkość liter: `Jan@Szpital.pl` jest tym samym hasłem.
		if ( strtolower( $password ) === strtolower( $user->user_email ) ) {
			self::redirect( self::RESULT_EMAIL );
			return;
		}

		// Zużycie biletu jest zarazem rezerwacją: `delete_user_meta()` z wartością zwraca true tylko
		// jednemu z równoległych żądań, więc bilet nie ustawia hasła dwa razy.
		if ( ! self::consume( $ticket['user'], $ticket['hash'] ) ) {
			self::redirect( self::RESULT_EXPIRED );
			return;
		}

		wp_set_password( $password, $ticket['user'] );
		self::forget_cookie();
		EventLog::write( self::LOG_ACTION, $ticket['user'], 0, array() );

		self::redirect( self::RESULT_DONE );
	}

	/**
	 * Strona ustawienia hasła albo pusty łańcuch, gdy adres nie niesie znacznika tej strony
	 * (wtedy strona pokazuje zwykły formularz rejestracji).
	 *
	 * Strona niesie nonce i otwiera ustawienie hasła, więc nie może trafić do żadnego cache'a stron.
	 * Wyłącza ją z cache TEN SAM zestaw, którym wyłączona jest strona formularza
	 * (`RegistrationHandler::no_cache()`): jedno miejsce, bez drugiej kopii, i idempotentnie, więc
	 * wywołanie obok tego z hooka przed renderem daje jeden zestaw.
	 */
	public static function render(): string {
		// Odczyt znacznika po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		$code = isset( $_GET[ self::PAGE_ARG ] ) && is_string( $_GET[ self::PAGE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::PAGE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return '';
		}

		RegistrationHandler::no_cache();

		if ( self::RESULT_DONE === $code ) {
			return '<p class="pusers-notice" role="status">' . esc_html( self::message_for( self::RESULT_DONE ) ) . '</p>' . self::login_link();
		}

		// Bez ważnego biletu nie ma co pokazywać formularza: droga dalej prowadzi do odzyskiwania hasła.
		if ( null === self::ticket() ) {
			return '<p class="pusers-notice" role="alert">' . esc_html( self::message_for( self::RESULT_EXPIRED ) ) . '</p>' . self::recovery_link();
		}

		$notice = in_array( $code, array( self::RESULT_SHORT, self::RESULT_EMAIL, self::RESULT_TOO_MANY, self::RESULT_STALE ), true )
			? '<p class="pusers-notice" role="alert">' . esc_html( self::message_for( $code ) ) . '</p>'
			: '';

		return $notice . self::form();
	}

	/**
	 * Tekst komunikatu dla wyniku (RESULT_*).
	 *
	 * Komunikat po ustawieniu hasła mówi, w jakim stanie jest wniosek: przy włączonej akceptacji
	 * czeka na decyzję redakcji, przy wyłączonej można się już zalogować. Automatycznego logowania
	 * nie ma, więc komunikat nie może obiecywać dostępu, którego konto jeszcze nie dostało.
	 *
	 * @param string $code Wynik (RESULT_*).
	 */
	public static function message_for( string $code ): string {
		switch ( $code ) {
			case self::RESULT_DONE:
				return SettingsRepository::get()->approvalEnabled
					? __( 'Hasło zostało ustawione. Wniosek czeka na decyzję redakcji; dostaniesz informację o decyzji.', 'portal-users' )
					: __( 'Hasło zostało ustawione. Możesz się już zalogować.', 'portal-users' );
			case self::RESULT_SHORT:
				return sprintf(
					/* translators: %d: minimalna liczba znaków hasła. */
					__( 'Hasło musi mieć co najmniej %d znaków.', 'portal-users' ),
					self::MIN_LENGTH
				);
			case self::RESULT_EMAIL:
				return __( 'Hasło nie może być takie samo jak adres e-mail.', 'portal-users' );
			case self::RESULT_TOO_MANY:
				return __( 'Zbyt wiele prób ustawienia hasła. Spróbuj ponownie później.', 'portal-users' );
			case self::RESULT_STALE:
				return __( 'Formularz jest nieaktualny. Odśwież stronę i wypełnij go ponownie.', 'portal-users' );
			default:
				return __( 'Link wygasł. Hasło do konta ustawisz przez odzyskiwanie hasła.', 'portal-users' );
		}
	}

	/**
	 * Formularz ustawienia hasła.
	 */
	private static function form(): string {
		return '<p class="pusers-notice">' . esc_html__( 'Adres e-mail został potwierdzony. Ustaw teraz hasło do konta.', 'portal-users' ) . '</p>'
			. '<form class="pusers-set-password" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false )
			. '<p><label for="' . esc_attr( self::PASSWORD_FIELD ) . '">' . esc_html__( 'Hasło', 'portal-users' ) . '</label> '
			. '<input type="password" id="' . esc_attr( self::PASSWORD_FIELD ) . '" name="' . esc_attr( self::PASSWORD_FIELD ) . '" autocomplete="new-password" minlength="' . esc_attr( (string) self::MIN_LENGTH ) . '" required="required" /></p>'
			. '<p><button type="submit">' . esc_html__( 'Ustaw hasło', 'portal-users' ) . '</button></p>'
			. '</form>';
	}

	/**
	 * Odnośnik do logowania; pusty przy włączonej akceptacji, bo konto jeszcze nie ma dostępu.
	 */
	private static function login_link(): string {
		if ( SettingsRepository::get()->approvalEnabled ) {
			return '';
		}

		return '<p class="pusers-login"><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Przejdź do logowania', 'portal-users' ) . '</a></p>';
	}

	/**
	 * Odnośnik do odzyskiwania hasła: jedyna droga po zgubionym albo przeterminowanym bilecie.
	 * Drugiego mechanizmu wysyłającego link do hasła wtyczka nie ma (patrz docblok klasy).
	 */
	private static function recovery_link(): string {
		return '<p class="pusers-recovery"><a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Odzyskaj hasło', 'portal-users' ) . '</a></p>';
	}

	/**
	 * Bilet z ciasteczka, o ile jest ważny: identyfikator użytkownika i skrót zapisany w metadanej
	 * (skrót wraca, bo zużycie biletu podaje go jako wartość do usunięcia). Null, gdy ciasteczka nie
	 * ma, ma zły kształt, bilet nie zgadza się ze skrótem albo termin ważności minął.
	 *
	 * @return array{user: int, hash: string}|null
	 */
	private static function ticket(): ?array {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 1 !== preg_match( '/^([1-9][0-9]{0,9})\.([0-9a-f]{64})$/', $raw, $parts ) ) {
			return null;
		}

		$userId = (int) $parts[1];
		$hash   = get_user_meta( $userId, UserMeta::PASSWORD_TICKET_HASH, true );
		if ( ! is_string( $hash ) || '' === $hash || ! hash_equals( $hash, self::hash( $parts[2] ) ) ) {
			return null;
		}

		$expires = get_user_meta( $userId, UserMeta::PASSWORD_TICKET_EXPIRES, true );
		if ( ! is_numeric( $expires ) || (int) $expires < time() ) {
			return null;
		}

		return array(
			'user' => $userId,
			'hash' => $hash,
		);
	}

	/**
	 * Zużywa bilet. Zwraca false, gdy zużył go już ktoś inny (żądanie równoległe).
	 *
	 * @param int    $userId Identyfikator użytkownika.
	 * @param string $hash   Skrót biletu zapisany w metadanej.
	 */
	private static function consume( int $userId, string $hash ): bool {
		if ( ! delete_user_meta( $userId, UserMeta::PASSWORD_TICKET_HASH, $hash ) ) {
			return false;
		}
		delete_user_meta( $userId, UserMeta::PASSWORD_TICKET_EXPIRES );

		return true;
	}

	/**
	 * Skrót biletu zapisywany w metadanych. Bilet ma 256 bitów losowości, więc zwykły SHA-256 wystarcza.
	 *
	 * @param string $token Bilet jawny.
	 */
	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Próg prób per adres IP, w OSOBNYM kubełku (patrz BUCKET_SETPASS_IP). Bez ustalonego adresu
	 * klienta progu IP się nie liczy, jak w SpamGuard i w ponownej wysyłce.
	 */
	private static function within_ip_limit(): bool {
		$settings = SettingsRepository::get();
		$ip       = IpAddress::from( $_SERVER, $settings->trustedProxyHeader );

		return '' === $ip || RateLimiter::hit( self::BUCKET_SETPASS_IP . $ip ) <= $settings->rateLimitPerHour;
	}

	/**
	 * Kasuje ciasteczko z biletem: bilet jest zużyty albo nieważny, a martwa wartość żyłaby
	 * jeszcze godzinę i przy każdym wejściu na stronę kazałaby sprawdzać nieistniejący bilet.
	 */
	private static function forget_cookie(): void {
		self::send_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * Ścieżka ciasteczka z biletem. `constant()` zamiast odwołania wprost, bo stałych ciasteczek
	 * witryny nie ma w stubach analizy statycznej; `defined()` daje przy okazji sensowne
	 * zachowanie, gdyby ciasteczko wysyłał kod pracujący przed `wp-settings.php`.
	 */
	private static function cookie_path(): string {
		$path = defined( 'COOKIEPATH' ) ? constant( 'COOKIEPATH' ) : '/';

		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * Wysyła (albo, przy pustej wartości i dacie z przeszłości, kasuje) ciasteczko z biletem.
	 *
	 * Ścieżka to `COOKIEPATH`, czyli ścieżka całej witryny, a NIE ścieżka strony ustawienia
	 * hasła. Przeglądarka musi wysłać bilet pod DWA adresy: na stronę (czyta go `render()`)
	 * i pod `wp-admin/admin-post.php` (czyta go `handle()`). Ścieżka strony nie pokrywa tego
	 * drugiego (RFC 6265 §5.1.4), więc zacieśniona ścieżka sprawiała, że hasła nie ustawił
	 * nikt — a nie chroniła przed niczym: bilet jest jednorazowy, krótkotrwały, `HttpOnly`,
	 * `SameSite=Lax` i zweryfikowany skrótem na konkretnym koncie. `COOKIEPATH` obsługuje też
	 * instalację w podkatalogu (`/podkatalog/` pokrywa `/podkatalog/wp-admin/admin-post.php`).
	 *
	 * @param string $value   Wartość.
	 * @param int    $expires Moment wygaśnięcia.
	 */
	private static function send_cookie( string $value, int $expires ): void {
		$options = array(
			'expires'  => $expires,
			'path'     => self::cookie_path(),
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		/**
		 * Szew wysyłania ciasteczka z biletem; zwrócenie false pomija `setcookie()`.
		 *
		 * @param bool                 $send    Czy wysłać.
		 * @param string               $name    Nazwa ciasteczka.
		 * @param string               $value   Wartość.
		 * @param array<string, mixed> $options Opcje.
		 */
		if ( apply_filters( 'pusers_password_send_cookie', true, self::COOKIE, $value, $options ) && ! headers_sent() ) {
			setcookie( self::COOKIE, $value, $options );
		}
	}

	/**
	 * Przekierowuje na stronę ustawienia hasła ze znacznikiem wyniku i kończy żądanie
	 * (przekierowanie anulowane filtrem `wp_redirect` nie kończy go: szew testowy).
	 *
	 * @param string $code Wynik (RESULT_*).
	 */
	private static function redirect( string $code ): void {
		if ( wp_safe_redirect( add_query_arg( self::PAGE_ARG, $code, Confirmation::base_url() ) ) ) {
			exit;
		}
	}
}
