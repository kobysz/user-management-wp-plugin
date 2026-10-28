<?php
/**
 * Odzyskiwanie hasła: formularz adresu, wysłanie linku i ustawienie nowego hasła z klucza.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\PasswordPolicy;
use PortalUsers\Domain\Spam\IpAddress;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;
use WP_Error;
use WP_Post;

/**
 * MECHANIZM JEST RDZENIOWY. `retrieve_password()` generuje klucz, zapisuje go w
 * `user_activation_key` i wysyła wiadomość; filtry `retrieve_password_title` i
 * `retrieve_password_message` podmieniają treść, a `lostpassword_url` (patrz `Redirects`) kieruje
 * odnośniki rdzenia na stronę wtyczki. Drugiego magazynu tokenów ta klasa NIE buduje — jest jeden
 * mechanizm, nie dwa (ta sama zasada, którą `PasswordSetup` zapisał przy zgubionym bilecie).
 *
 * Cztery reguły są powodem istnienia tej klasy:
 *
 * 1. ODPOWIEDŹ JEST TA SAMA, NIEZALEŻNIE OD ISTNIENIA ADRESU. `RESULT_SENT` wychodzi zawsze, a
 *    `WP_Error` z rdzenia (`invalid_email`, gdy adresu nie zna) jest POCHŁANIANY. Rozróżnienie
 *    zamieniłoby formularz w czytnik „czy ten adres ma konto na portalu” — to ta sama enumeracja,
 *    którą zamknęła rejestracja i strona logowania. Ta sama ODPOWIEDŹ nie znaczy ta sama PRACA:
 *    maila do nikogo nie wysyłamy, bo nie ma komu.
 *
 * 2. KUBEŁEK PER ADRES JEST KLUCZOWANY SKRÓTEM ADRESU, nie adresem jawnym. Klucz transientu trafia
 *    do `wp_options`, a adres e-mail lekarza jest daną osobową; wzór `wp_hash( strtolower( ... ) )`
 *    jest ten sam, co w cronie czyszczącym konta i w kontekście logu `LoginHandler`. Ta sama reguła
 *    obowiązuje wiersz w logu: adresu jawnego nie ma NIGDZIE.
 *
 * 3. PROGI ZUŻYWA KAŻDE ŻĄDANIE, nie tylko nieudane. Tu jest to właściwy kierunek, odwrotnie niż
 *    w `LoginHandler`: wysłanie maila jest akcją wychodzącą, kosztowną i nadającą się do zasypania
 *    cudzej skrzynki, więc liczy się samo poproszenie, a nie dopiero porażka. Próg per adres IP
 *    stoi PRZED progiem per adres e-mail, żeby obcy ruch z jednego adresu wyjściowego nie zabierał
 *    właścicielowi skrzynki jego własnych żądań.
 *
 * 4. ODMOWA ŻĄDANIA O LINK ZOSTAWIA WIERSZ W LOGU, z powodem odmowy. Odpowiedź tej strony jest
 *    ZAWSZE ta sama (reguła 1), więc wiersz w logu jest JEDYNYM śladem: bez niego człowiek,
 *    któremu menedżer haseł wypełnił pułapkę, słyszy „wysłaliśmy link”, maila nie dostaje i nie
 *    zostawia po sobie niczego, po czym administrator by go znalazł. Adres klienta ustalamy
 *    dlatego PRZED bramkami — nie jest bramką, nic nie zmienia, a wiersz ma go nieść także przy
 *    odmowie z nonce'a. Higiena z reguły 2 obowiązuje ten wiersz tak samo.
 *
 * Kolejność bramek jest nienaruszalna. Żądanie o link: nonce, metoda POST, pułapka na boty, próg
 * per adres IP, próg per adres e-mail, akcja rdzenia, przekierowanie (PRG). Ustawienie hasła:
 * nonce, metoda POST, próg per adres IP, klucz rdzenia, próg per konto, reguły hasła, zapis
 * (patrz `handle_set()`).
 *
 * Pułapki CZASOWEJ ten formularz nie ma świadomie, dokładnie jak formularz logowania: pole adresu
 * wypełnia menedżer haseł i wysyła w ułamku sekundy, więc pułapka odcinałaby człowieka, nie bota.
 *
 * Formularz pokazujemy TAKŻE zalogowanemu. `Redirects` odcięło kontom bez uprawnień redakcyjnych
 * cały `wp-admin`, w tym `profile.php`, więc odzyskiwanie hasła jest dla lekarza jedyną drogą
 * zmiany hasła; notka „jesteś zalogowany” zamiast formularza zamykałaby mu tę drogę.
 *
 * Klasa rejestruje się pod `pusers_register_features`, czyli za sprawdzeniem zależności — inaczej
 * niż brama logowania i strażnik sesji, które są ZAMKAMI. Formularz odzyskiwania hasła zamkiem nie
 * jest: gdy wtyczka bezczynnie czeka na ACF Pro, strona się nie renderuje, a odzyskiwanie hasła
 * działa dalej przez `wp-login.php` (świadomie zostawione otwarte) z treścią rdzenia.
 *
 * ROZDZIELENIE WIDOKÓW: strona oddaje JEDEN z dwóch formularzy. Przy ŻYWYM kluczu w adresie —
 * formularz nowego hasła; w KAŻDYM innym przypadku — formularz adresu. Człowiek z kluczem pustym,
 * martwym, przeterminowanym albo należącym do innego konta dostaje formularz adresu I KOMUNIKAT.
 * Argument adresu jest NIESEKRETNY, więc na tę stronę trafia się z podrzuconego odnośnika, zakładki
 * i starego maila; widok, który zabiera stronę i mówi tylko „link wygasł”, jest ślepym zaułkiem
 * (lekcja z Planu 3). O ważność klucza pyta `check_password_reset_key()`, czyli TEN SAM mechanizm
 * rdzenia, który klucz wystawił — drugiej reguły ważności tu nie ma.
 */
final class PasswordReset {

	public const SHORTCODE = 'portal_users_password_reset';

	/**
	 * Akcja formularza (admin-post) i zarazem akcja nonce'a; pole nonce'a nosi tę samą nazwę,
	 * co akcja nonce'a (`NONCE_REQUEST`), i nie koliduje z polem `action`.
	 */
	public const ACTION_REQUEST = 'pusers_reset_request';
	public const NONCE_REQUEST  = 'pusers_reset_request';

	public const EMAIL_FIELD = 'pusers_reset_email';

	/**
	 * Akcja formularza nowego hasła (admin-post) i zarazem akcja nonce'a. OSOBNA od akcji
	 * formularza adresu: to dwa różne formularze na jednej stronie i jeden handler nie mógłby
	 * rozstrzygnąć, który z nich przyszedł.
	 */
	public const ACTION_SET = 'pusers_reset_set';
	public const NONCE_SET  = 'pusers_reset_set';

	public const PASSWORD_FIELD = 'pusers_reset_password';

	/**
	 * Nazwa pułapki na boty, odmagnesowana tak jak w `LoginForm::HONEYPOT`: żadnego `website`,
	 * `url`, `email`, `name`, `phone` ani `address`, bo menedżery haseł wypełniają takie pola wbrew
	 * `autocomplete="off"`, a wypełniona pułapka to odmowa.
	 */
	public const HONEYPOT = 'pusers_pole_odwrotne';

	/**
	 * Argument adresu z wynikiem żądania (wartości RESULT_*), po przekierowaniu.
	 */
	public const RESULT_ARG = 'pusers_reset_result';

	/**
	 * Argumenty adresu z odnośnika z maila: klucz rdzenia i konto, do którego klucz należy.
	 * Rdzeń weryfikuje klucz WYŁĄCZNIE w parze z loginem (`check_password_reset_key()`).
	 */
	public const KEY_ARG  = 'pusers_reset_key';
	public const USER_ARG = 'pusers_reset_user';

	/**
	 * Wynik: żądanie przyjęte. Wychodzi ZAWSZE, gdy żądanie przeszło bramki — także wtedy, gdy
	 * adresu nikt nie ma (patrz reguła 1 w dokumentacji klasy).
	 */
	public const RESULT_SENT = 'sent';

	/**
	 * Wynik: próg żądań wyczerpany (per adres IP albo per adres e-mail).
	 */
	public const RESULT_TOO_MANY = 'too_many';

	/**
	 * Wynik: nonce przedawniony albo nieobecny.
	 */
	public const RESULT_STALE = 'stale';

	/**
	 * Wynik: hasło zostało ustawione.
	 */
	public const RESULT_DONE = 'done';

	/**
	 * Wynik: klucz z odnośnika nie działa — nie ma go, jest zużyty, przeterminowany albo należy do
	 * innego konta. JEDEN kod dla wszystkich tych przypadków: rozróżnienie nie dałoby człowiekowi
	 * nic, czego nie wie (odnośnik ma poprosić o nowy), a zdradzałoby, czy login z adresu istnieje.
	 */
	public const RESULT_INVALID = 'invalid';

	/**
	 * Wyniki z reguł hasła. Wartości są TE SAME, co `PasswordPolicy::ERROR_*` i `PasswordSetup`:
	 * kod odmowy jedzie z domeny do argumentu adresu bez tłumaczenia, a trzecia wartość dla tej
	 * samej odmowy znaczyłaby trzecią mapę komunikatów.
	 */
	public const RESULT_SHORT = 'short';
	public const RESULT_EMAIL = 'email';

	/**
	 * Zdarzenie w logu: ktoś poprosił o link do ustawienia hasła.
	 */
	public const LOG_ACTION_REQUEST = 'password_reset_requested';

	/**
	 * Zdarzenie w logu: hasło zostało ustawione z klucza odzyskiwania.
	 */
	public const LOG_ACTION_SET = 'password_reset';

	/**
	 * Kubełek progu żądań per adres IP. OSOBNY od kubełków rejestracji, logowania, ponownej wysyłki
	 * i ustawienia hasła: wspólny pozwalałby wyczerpać jednym przebiegiem budżet drugiej ścieżki.
	 */
	public const BUCKET_IP = 'reset_ip:';

	/**
	 * Kubełek progu żądań per adres e-mail; klucz to SKRÓT adresu (patrz reguła 2).
	 */
	public const BUCKET_EMAIL = 'reset_email:';

	/**
	 * Kubełek progu prób ustawienia hasła per adres IP, OSOBNY od kubełka żądań o link: wspólny
	 * pozwalałby jednym przebiegiem wyczerpać budżet drugiego kroku i mieszałby dwa niezależne
	 * progi. WARTOŚĆ progu jest ta sama, `resetLimitPerHour` — rozdzielenie wartości na kroki stoi
	 * w backlogu jako granulacja progów i tu go nie ruszamy.
	 */
	public const BUCKET_SET_IP = 'resetset_ip:';

	/**
	 * Kubełek progu prób per KONTO, kluczowany identyfikatorem konta USTALONEGO Z KLUCZA (patrz
	 * `handle_set()`).
	 *
	 * Wartość progu to `resetLimitPerHour`, a NIE `resetLimitPerEmailPerHour`: ta druga jest
	 * skalibrowana na WYSYŁANIE maila — akcję wychodzącą, kosztowną i nadającą się do zasypania
	 * cudzej skrzynki — a nie na wpisanie hasła przez człowieka, który trzyma w ręku ważny klucz.
	 * Przy jej domyślnych trzech próbach dwie pomyłki w nowym haśle zamykałyby lekarzowi godzinę
	 * z działającym odnośnikiem w ręku, czyli byłaby to kolejna obrona trafiająca w człowieka.
	 */
	public const BUCKET_SET_USER = 'resetset_user:';

	/**
	 * Podpina shortcode, zakaz cache przed renderem, obsługę OBU wysłanych formularzy i treść maila.
	 *
	 * Oba warianty akcji admin-post: zalogowany też wyśle te formularze, a `admin_post_nopriv_`
	 * jego żądania nie widzi.
	 *
	 * Filtry treści maila stoją tu, a nie w obsłudze żądania, i obejmują przez to także żądania
	 * z `wp-login.php` (wejście zapasowe redakcji): mail ma wyglądać tak samo niezależnie od tego,
	 * z którego formularza poszedł, a odnośnik ma prowadzić na stronę wtyczki.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_REQUEST, array( self::class, 'handle_request' ) );
		add_action( 'admin_post_' . self::ACTION_REQUEST, array( self::class, 'handle_request' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_SET, array( self::class, 'handle_set' ) );
		add_action( 'admin_post_' . self::ACTION_SET, array( self::class, 'handle_set' ) );
		add_filter( 'retrieve_password_title', array( self::class, 'mail_title' ) );
		add_filter( 'retrieve_password_message', array( self::class, 'mail_message' ), 10, 3 );
	}

	/**
	 * Wyłącza z cache stronę, której treść niesie shortcode odzyskiwania hasła — PRZED renderem
	 * (powód jak w `LoginForm::on_template_redirect()`: z `the_content` nagłówki wychodzą za późno).
	 */
	public static function on_template_redirect(): void {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}

		RegistrationHandler::no_cache();
	}

	/**
	 * Strona odzyskiwania hasła z ustawień albo strona główna.
	 */
	public static function base_url(): string {
		return PageUrl::for( SettingsRepository::get()->resetPageId );
	}

	/**
	 * Zwraca jeden z dwóch formularzy (patrz „rozdzielenie widoków” w dokumentacji klasy),
	 * poprzedzony komunikatem o wyniku poprzedniego żądania (PRG) i — gdy adres niesie odnośnik,
	 * który nie działa — komunikatem o nieaktualnym odnośniku.
	 *
	 * Druga warstwa zakazu cache: pierwsza jedzie z `on_template_redirect()`, tu pokrywamy
	 * umieszczenie formularza poza treścią wpisu. Wywołanie jest idempotentne.
	 */
	public static function render(): string {
		RegistrationHandler::no_cache();

		$notice = self::result_notice();
		$key    = self::query_text( self::KEY_ARG );
		$login  = self::query_text( self::USER_ARG );

		if ( '' === $key && '' === $login ) {
			return $notice . self::form();
		}

		// Klucz ŻYWY: strona oddaje formularz nowego hasła. Pytamy raz, a wynik rozstrzyga oba
		// widoki — dwa pytania mogłyby dać dwie różne odpowiedzi w jednym żądaniu.
		if ( ! is_wp_error( check_password_reset_key( $key, $login ) ) ) {
			return $notice . self::password_form( $key, $login );
		}

		return $notice . self::link_notice() . self::form();
	}

	/**
	 * Obsługuje wysłany formularz adresu (kolejność bramek: patrz dokumentacja klasy).
	 */
	public static function handle_request(): void {
		$settings = SettingsRepository::get();

		// Adres klienta ustalamy PRZED bramkami: nie jest bramką i nic nie zmienia, a wiersz
		// odmowy ma go nieść także wtedy, gdy odmowa pada na nonce'ie (patrz reguła 4).
		$ip = IpAddress::from( $_SERVER, $settings->trustedProxyHeader );

		// 1. Nonce. Nonce starzeje się razem z sesją, więc człowiek z kartą otwartą od doby trafia
		// właśnie tu: dostaje przez PRG komunikat „odśwież stronę”, a nie 403.
		$nonce = isset( $_POST[ self::NONCE_REQUEST ] ) && is_string( $_POST[ self::NONCE_REQUEST ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- to jest weryfikacja nonce'a.
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_REQUEST ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_REQUEST ) ) {
			self::refuse( 'stale_nonce', self::RESULT_STALE, $ip, '' );
			return;
		}

		// 2. Metoda POST (admin-post odpala się dla każdej metody).
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		// 3. Pułapka na boty, PRZED progami: odmowa nic nie kosztuje i nie liczy się do progu, więc
		// bot nie zabiera budżetu nikomu. Odpowiedź dostaje tę samą, co człowiek (patrz reguła 1).
		if ( ! self::looks_human() ) {
			self::refuse( 'honeypot', self::RESULT_SENT, $ip, self::email() );
			return;
		}

		// 4. Próg per adres IP, zużywany przez KAŻDE żądanie (patrz reguła 3). Bez ustalonego
		// adresu klienta progu się nie liczy: ruch bez adresu zlany w jeden kubełek odciąłby
		// wszystkich (ta sama reguła, co w `SpamGuard` i `PasswordSetup`).
		if ( '' !== $ip && RateLimiter::hit( self::BUCKET_IP . $ip ) > $settings->resetLimitPerHour ) {
			self::refuse( 'ip_limit', self::RESULT_TOO_MANY, $ip, self::email() );
			return;
		}

		$email = self::email();
		if ( '' === $email ) {
			// Puste pole: odpowiedź ta sama, pracy żadnej — nie ma komu wysłać maila. Kubełka per
			// adres przy pustej wartości nie zakładamy, bo byłby to jeden kubełek dla wszystkich.
			self::refuse( 'empty_email', self::RESULT_SENT, $ip, '' );
			return;
		}

		// 5. Próg per adres e-mail, w kubełku kluczowanym SKRÓTEM adresu (patrz reguła 2). Licznik
		// rośnie dla KAŻDEGO adresu, także nieistniejącego: inaczej „zbyt wiele żądań” zdradzałoby,
		// że adres ma konto. Kubełków nie zasypie bot, bo każde żądanie zużywa najpierw próg per IP.
		if ( RateLimiter::hit( self::BUCKET_EMAIL . self::email_key( $email ) ) > $settings->resetLimitPerEmailPerHour ) {
			self::refuse( 'email_limit', self::RESULT_TOO_MANY, $ip, $email );
			return;
		}

		// 6. Akcja: rdzeń generuje klucz, zapisuje go i wysyła maila (treść podmieniają filtry tej
		// klasy). Jego `WP_Error` POCHŁANIAMY — zwłaszcza `invalid_email` dla adresu, którego rdzeń
		// nie zna (patrz reguła 1). Kod błędu zostaje w logu, więc awaria wysyłki jest widoczna
		// administratorowi, choć nie jest widoczna pytającemu.
		$result = retrieve_password( $email );

		// W logu NIE MA adresu jawnego, tylko jego skrót — tak samo jak w logu nieudanych logowań
		// i w cronie czyszczącym konta. Identyfikatora konta tu NIE ustalamy: rozstrzyganie, czy
		// adres należy do konta, jest w rdzeniu i druga kopia tej reguły rozjechałaby się z nią.
		// Skrót jest wyliczalny, więc wiersze dla jednego adresu i tak się korelują.
		EventLog::write(
			self::LOG_ACTION_REQUEST,
			0,
			0,
			array(
				'ip'         => $ip,
				'email_hash' => self::email_key( $email ),
				'code'       => $result instanceof WP_Error ? (string) $result->get_error_code() : '',
			)
		);

		// 7. Przekierowanie (PRG) z tą samą odpowiedzią dla każdego wyniku rdzenia.
		self::finish( self::RESULT_SENT );
	}

	/**
	 * Obsługuje wysłany formularz nowego hasła.
	 *
	 * Kolejność bramek: nonce, metoda POST, próg per adres IP, klucz rdzenia, próg per konto,
	 * reguły hasła, zapis.
	 *
	 * Próg per adres IP stoi PRZED sprawdzeniem klucza, żeby zgadywanie klucza na TEJ ścieżce też
	 * się liczyło. Próg per KONTO stoi PO sprawdzeniu klucza i tylko dlatego: `USER_ARG` jest
	 * NIESPRAWDZONYM łańcuchem z żądania, więc liczony wcześniej pozwalałby osobie trzeciej
	 * wyczerpać właścicielowi budżet samą podmianą loginu w adresie. Kubełek kluczowany kontem
	 * powstaje WYŁĄCZNIE dla konta, które naprawdę zostało ustalone, a nieudane sprawdzenie klucza
	 * liczy się tylko w kubełku per adres IP (ta sama reguła, co w `PasswordSetup::handle()`
	 * i w `Confirmation::handle_resend()`).
	 *
	 * ODMOWA Z REGUŁ HASŁA NIE ZUŻYWA KLUCZA: klucz czyści `reset_password()` i tylko ono. Zbyt
	 * krótkie hasło spalające odnośnik byłoby odmową trafiającą w człowieka, który nic złego nie
	 * zrobił — dlatego każda odmowa poza samym kluczem wraca na stronę Z ODNOŚNIKIEM i formularz
	 * pokazuje się ponownie.
	 *
	 * O stan konta `handle_set()` nie pyta świadomie, tak samo jak `PasswordSetup::handle()`:
	 * dostępem rządzi stan konta, nie istnienie hasła, więc hasło na koncie odrzuconym niczego nie
	 * otwiera, a odmowa zdradzałaby stan konta temu, kto zna sam odnośnik.
	 */
	public static function handle_set(): void {
		// Odnośnik z żądania: wraca w adresie odmowy, żeby formularz dał się wysłać ponownie.
		$key   = self::posted( self::KEY_ARG );
		$login = self::posted( self::USER_ARG );

		// 1. Nonce (powód jak w `handle_request()`). Odmowa zostawia odnośnik, bo klucz z maila
		// żyje dobę i przeżywa przedawnienie nonce'a.
		$nonce = self::posted( self::NONCE_SET );
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_SET ) ) {
			self::finish_with_link( self::RESULT_STALE, $key, $login );
			return;
		}

		// 2. Metoda POST (admin-post odpala się dla każdej metody).
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		$settings = SettingsRepository::get();

		// 3. Próg per adres IP, w OSOBNYM kubełku (patrz BUCKET_SET_IP). Bez ustalonego adresu
		// klienta progu się nie liczy (ta sama reguła, co w `handle_request()`).
		$ip = IpAddress::from( $_SERVER, $settings->trustedProxyHeader );
		if ( '' !== $ip && RateLimiter::hit( self::BUCKET_SET_IP . $ip ) > $settings->resetLimitPerHour ) {
			self::finish_with_link( self::RESULT_TOO_MANY, $key, $login );
			return;
		}

		// 4. Klucz. Pyta o niego `check_password_reset_key()`, czyli TEN SAM mechanizm rdzenia,
		// który klucz wystawił: weryfikuje go w parze z loginem, pilnuje terminu ważności i
		// oddaje konto. Drugiej reguły ważności tu nie ma. Odmowa NIE niesie odnośnika dalej:
		// ten konkretny odnośnik już nie zadziała nigdy, a droga dalej jest przez formularz adresu.
		$user = check_password_reset_key( $key, $login );
		if ( $user instanceof WP_Error ) {
			self::finish( self::RESULT_INVALID );
			return;
		}

		// 5. Próg per konto, w osobnym kubełku (patrz BUCKET_SET_USER).
		if ( RateLimiter::hit( self::BUCKET_SET_USER . $user->ID ) > $settings->resetLimitPerHour ) {
			self::finish_with_link( self::RESULT_TOO_MANY, $key, $login );
			return;
		}

		// Hasła NIE sanityzujemy ani nie obcinamy: nie jedzie do bazy jako tekst, tylko do
		// `reset_password()`, które liczy z niego skrót. `sanitize_text_field()` zjadłby znaki,
		// których człowiek użył, i hasło przestałoby się zgadzać przy logowaniu (ta sama reguła,
		// co w `PasswordSetup::handle()`).
		$password = isset( $_POST[ self::PASSWORD_FIELD ] ) && is_string( $_POST[ self::PASSWORD_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
			? (string) wp_unslash( $_POST[ self::PASSWORD_FIELD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- hasło nie podlega sanityzacji (patrz komentarz wyżej).
			: '';

		// 6. Reguły hasła żyją w domenie: WSPÓLNE z ustawieniem hasła z biletu rejestracji.
		$violation = PasswordPolicy::check( $password, (string) $user->user_email );
		if ( PasswordPolicy::ERROR_SHORT === $violation ) {
			self::finish_with_link( self::RESULT_SHORT, $key, $login );
			return;
		}
		if ( PasswordPolicy::ERROR_EMAIL === $violation ) {
			self::finish_with_link( self::RESULT_EMAIL, $key, $login );
			return;
		}

		// 7. Zapis. `reset_password()` liczy skrót, CZYŚCI `user_activation_key` (stąd
		// jednorazowość odnośnika) i odpala akcje rdzenia.
		//
		// Jawnego niszczenia pozostałych sesji tu NIE MA, choć odzyskiwania hasła używa człowiek,
		// któremu ktoś przejął konto: ciasteczko uwierzytelniające rdzenia niesie fragment skrótu
		// hasła, więc zmiana hasła unieważnia cudze ciasteczko sama. `WP_Session_Tokens::destroy_all()`
		// zostało napisane i usunięte, bo test `test_setting_a_password_destroys_other_sessions_of_that_account`
		// przechodzi bez niego — zostaje wtedy tylko wiersz w metadanej `session_tokens`, który
		// niczego nie otwiera. Linii obrony, której nic nie dowodzi, nie trzymamy.
		reset_password( $user, $password );

		// W logu NIE MA hasła ani jego skrótu — jest konto i adres klienta. Konto jest tu znane
		// (ustalił je klucz), więc wiersz wskazuje je wprost, inaczej niż wiersz żądania o link.
		EventLog::write( self::LOG_ACTION_SET, $user->ID, 0, array( 'ip' => $ip ) );

		// 8. Przekierowanie (PRG) BEZ odnośnika: klucz jest zużyty, a adres z martwym kluczem
		// kazałby stronie pokazać komunikat o nieaktualnym odnośniku po operacji, która się udała.
		self::finish( self::RESULT_DONE );
	}

	/**
	 * Tekst komunikatu dla wyniku (RESULT_*). Kod nieznany daje `''` — strona ma wtedy nie
	 * pokazywać nic, a nie puste okienko alertu.
	 *
	 * @param string $code Wynik (RESULT_*).
	 */
	public static function message_for( string $code ): string {
		switch ( $code ) {
			case self::RESULT_SENT:
				// JEDEN tekst dla adresu znanego i nieznanego (patrz reguła 1). Jest w trybie
				// warunkowym świadomie: nie potwierdza i nie zaprzecza istnieniu konta.
				return __( 'Jeśli konto o tym adresie istnieje, wysłaliśmy na nie link.', 'portal-users' );
			case self::RESULT_TOO_MANY:
				return __( 'Zbyt wiele żądań odzyskania hasła. Spróbuj ponownie później.', 'portal-users' );
			case self::RESULT_STALE:
				return __( 'Formularz jest nieaktualny. Odśwież stronę i wypełnij go ponownie.', 'portal-users' );
			case self::RESULT_DONE:
				return __( 'Hasło zostało ustawione. Możesz się teraz zalogować.', 'portal-users' );
			case self::RESULT_INVALID:
				// JEDEN tekst dla klucza martwego, zużytego, przeterminowanego i cudzego; czyta go
				// także komunikat na stronie otwartej z nieaktualnego odnośnika (`link_notice()`).
				return __( 'Ten odnośnik do ustawienia hasła jest nieaktualny. Poproś o nowy, podając adres e-mail.', 'portal-users' );
			case self::RESULT_SHORT:
				return sprintf(
					/* translators: %d: minimalna liczba znaków hasła. */
					__( 'Hasło musi mieć co najmniej %d znaków.', 'portal-users' ),
					PasswordPolicy::MIN_LENGTH
				);
			case self::RESULT_EMAIL:
				return __( 'Hasło nie może być takie samo jak adres e-mail.', 'portal-users' );
			default:
				return '';
		}
	}

	/**
	 * Temat maila z linkiem (filtr `retrieve_password_title`).
	 *
	 * Treść jest minimalna i funkcjonalna; plan szablonów podmieni ją filtrem, nie zdarzeniem.
	 *
	 * Bez wskazanej (albo po usunięciu) strony odzyskiwania hasła oddajemy temat rdzenia
	 * NIETKNIĘTY, bo wtedy nietknięta zostaje też treść (patrz `mail_message()`), a nasz temat nad
	 * treścią rdzenia byłby wiadomością złożoną z dwóch różnych maili. Wejścia, którego nie
	 * rozumiemy (wtyczka obca na tym samym filtrze), też nie tykamy — stąd typ `mixed` w obie
	 * strony, tak samo jak w `Redirects::after_login()`.
	 *
	 * @param mixed $title Temat ustalony przez rdzeń.
	 * @return mixed Nasz temat albo wejście nietknięte.
	 */
	public static function mail_title( mixed $title ): mixed {
		return '' === self::link_page() ? $title : __( 'Ustawienie nowego hasła', 'portal-users' );
	}

	/**
	 * Treść maila z linkiem (filtr `retrieve_password_message`).
	 *
	 * Odnośnik prowadzi na stronę wtyczki i niesie oba argumenty, bo rdzeń weryfikuje klucz
	 * wyłącznie w parze z loginem konta.
	 *
	 * Gdy strony odzyskiwania hasła NIE ma (nie wskazano jej w ustawieniach albo została usunięta),
	 * treść rdzenia zostaje nietknięta. Tu NIE wolno użyć `base_url()`: jego odwrót prowadzi na
	 * stronę główną, więc mail niósłby odnośnik, który nie robi tego, co obiecuje — a odnośnik
	 * rdzenia (`wp-login.php?action=rp`) działa, bo `wp-login.php` zostaje świadomie otwarte. To ta
	 * sama reguła, którą po swojej stronie trzyma `Redirects::lostpassword_url()`.
	 *
	 * Argumenty 2 i 3 mają wartości domyślne, a bez któregokolwiek z nich treść wraca NIETKNIĘTA:
	 * obca wtyczka potrafi zawołać `apply_filters( 'retrieve_password_message', $tresc )` z jednym
	 * argumentem, a wymagane argumenty zamieniały takie wywołanie w `ArgumentCountError`, czyli
	 * w błąd 500 na ścieżce, która miała tylko przepuścić treść dalej. To ta sama ostrożność, co
	 * typ `mixed` przy pierwszym argumencie.
	 *
	 * @param mixed  $message   Treść ustalona przez rdzeń.
	 * @param string $key       Klucz wygenerowany przez rdzeń.
	 * @param string $userLogin Login konta, do którego klucz należy.
	 * @return mixed Nasza treść albo wejście nietknięte.
	 */
	public static function mail_message( mixed $message, string $key = '', string $userLogin = '' ): mixed {
		if ( '' === $key || '' === $userLogin ) {
			return $message;
		}

		$page = self::link_page();
		if ( '' === $page ) {
			return $message;
		}

		return implode(
			"\n",
			array(
				__( 'Ktoś poprosił o ustawienie nowego hasła do konta na tej witrynie.', 'portal-users' ),
				__( 'Konto:', 'portal-users' ) . ' ' . $userLogin,
				'',
				__( 'Jeżeli to nie Ty, zignoruj tę wiadomość. Hasło samo się nie zmieni.', 'portal-users' ),
				'',
				__( 'Nowe hasło ustawisz, otwierając odnośnik:', 'portal-users' ),
				self::link_for( $page, $key, $userLogin ),
			)
		);
	}

	/**
	 * Adres strony odzyskiwania hasła dla odnośnika w mailu albo `''`, gdy strony nie ma.
	 * Odwrotu na stronę główną tu NIE MA (powód: patrz `mail_message()`).
	 */
	private static function link_page(): string {
		return PageUrl::existing( SettingsRepository::get()->resetPageId );
	}

	/**
	 * Odnośnik do ustawienia hasła: strona wtyczki plus oba argumenty.
	 *
	 * Klucz stoi na KOŃCU, a login przed nim, z tego samego powodu, który rdzeń opisuje przy swoim
	 * odnośniku: login potrafi kończyć się kropką, a kropka na końcu adresu bywa zjadana przez
	 * programy pocztowe zamieniające tekst w odnośnik. Klucz rdzenia jest alfanumeryczny.
	 *
	 * @param string $page      Adres strony odzyskiwania hasła.
	 * @param string $key       Klucz wygenerowany przez rdzeń.
	 * @param string $userLogin Login konta.
	 */
	private static function link_for( string $page, string $key, string $userLogin ): string {
		return add_query_arg(
			array(
				self::USER_ARG => $userLogin,
				self::KEY_ARG  => $key,
			),
			$page
		);
	}

	/**
	 * Formularz adresu.
	 */
	private static function form(): string {
		$html  = '<form class="pusers-password-reset" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REQUEST ) . '" />';

		$html .= '<p class="pusers-field-email"><label for="' . esc_attr( self::EMAIL_FIELD ) . '">' . esc_html__( 'Adres e-mail', 'portal-users' ) . '</label> ';
		$html .= '<input type="email" id="' . esc_attr( self::EMAIL_FIELD ) . '" name="' . esc_attr( self::EMAIL_FIELD ) . '" value="" autocomplete="username" required="required" /></p>';

		// Pułapka: zwykłe pole tekstowe ukryte stylem; etykieta mówi wprost, żeby pola nie wypełniać.
		$html .= '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
		$html .= '<label for="' . esc_attr( self::HONEYPOT ) . '">' . esc_html__( 'Nie wypełniaj tego pola.', 'portal-users' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( self::HONEYPOT ) . '" name="' . esc_attr( self::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off" /></div>';

		$html .= wp_nonce_field( self::NONCE_REQUEST, self::NONCE_REQUEST, true, false );
		$html .= '<p><button type="submit">' . esc_html__( 'Wyślij link do ustawienia hasła', 'portal-users' ) . '</button></p>';

		return $html . '</form>';
	}

	/**
	 * Formularz nowego hasła dla ŻYWEGO klucza.
	 *
	 * Oba argumenty odnośnika jadą w polach ukrytych, bo adresem akcji jest `admin-post.php`,
	 * a nie ta strona — argumenty z adresu strony do żądania POST same nie trafią. Sekretem
	 * żadne z nich nie jest (patrz dokumentacja klasy), a handler i tak pyta o klucz rdzeń.
	 *
	 * @param string $key   Klucz z odnośnika.
	 * @param string $login Login konta z odnośnika.
	 */
	private static function password_form( string $key, string $login ): string {
		$html  = '<form class="pusers-password-new" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SET ) . '" />';
		$html .= '<input type="hidden" name="' . esc_attr( self::USER_ARG ) . '" value="' . esc_attr( $login ) . '" />';
		$html .= '<input type="hidden" name="' . esc_attr( self::KEY_ARG ) . '" value="' . esc_attr( $key ) . '" />';

		$html .= '<p class="pusers-field-password"><label for="' . esc_attr( self::PASSWORD_FIELD ) . '">' . esc_html__( 'Nowe hasło', 'portal-users' ) . '</label> ';
		$html .= '<input type="password" id="' . esc_attr( self::PASSWORD_FIELD ) . '" name="' . esc_attr( self::PASSWORD_FIELD ) . '" autocomplete="new-password" minlength="' . esc_attr( (string) PasswordPolicy::MIN_LENGTH ) . '" required="required" /></p>';

		$html .= wp_nonce_field( self::NONCE_SET, self::NONCE_SET, true, false );
		$html .= '<p><button type="submit">' . esc_html__( 'Ustaw nowe hasło', 'portal-users' ) . '</button></p>';

		return $html . '</form>';
	}

	/**
	 * Komunikat o wyniku poprzedniego żądania (PRG) albo pusty łańcuch.
	 */
	private static function result_notice(): string {
		// Odczyt wyniku po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		$code = isset( $_GET[ self::RESULT_ARG ] ) && is_string( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return '';
		}

		$message = self::message_for( $code );
		if ( '' === $message ) {
			return '';
		}

		return '<p class="pusers-notice" role="' . ( self::RESULT_SENT === $code ? 'status' : 'alert' ) . '">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Komunikat dla człowieka, który przyszedł z odnośnikiem, a odnośnik nie działa. O to, czy
	 * odnośnik działa, pyta `render()` — ten sam jego wynik rozstrzyga oba widoki.
	 *
	 * Formularza adresu ten komunikat NIE zabiera (patrz „rozdzielenie widoków” w dokumentacji
	 * klasy): strona bez drogi dalej jest awarią trudniejszą do zgłoszenia niż zły komunikat.
	 * Tekst jest TEN SAM, co dla `RESULT_INVALID` z handlera, i czytamy go z jednej mapy
	 * komunikatów: dwie kopie tego samego zdania rozjechałyby się przy pierwszej zmianie.
	 */
	private static function link_notice(): string {
		return '<p class="pusers-notice" role="alert">' . esc_html( self::message_for( self::RESULT_INVALID ) ) . '</p>';
	}

	/**
	 * Wartość argumentu adresu albo `''`. Odczyt argumentu nic nie zmienia, więc nonce nie obowiązuje.
	 *
	 * @param string $name Nazwa argumentu.
	 */
	private static function query_text( string $name ): string {
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Wartość pola z żądania albo `''`. Czyta także pola, które jadą PRZED weryfikacją nonce'a
	 * (odnośnik wracający w adresie odmowy i samo pole nonce'a), więc weryfikację nonce'a robi
	 * wywołujący.
	 *
	 * @param string $name Nazwa pola.
	 */
	private static function posted( string $name ): string {
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce weryfikuje wywołujący (patrz opis).
	}

	/**
	 * Czy żądanie wygląda na ludzkie: pusta pułapka. Pułapki czasowej nie ma świadomie (patrz
	 * dokumentacja klasy).
	 */
	private static function looks_human(): bool {
		$honeypot = $_POST[ self::HONEYPOT ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.

		// Pole, które nie jest tekstem (np. tablica), też jest wypełnione: człowiek takiego nie wyśle.
		// Białe znaki obcinamy: autouzupełnianie przeglądarki potrafi dorzucić spację uczciwemu użytkownikowi.
		return is_string( $honeypot ) && '' === trim( $honeypot );
	}

	/**
	 * Adres e-mail z żądania.
	 *
	 * Wartości NIE sprawdzamy tu pod kątem poprawności adresu: rozstrzyganie, czy łańcuch wskazuje
	 * konto, należy do `retrieve_password()`, a odpowiedź i tak jest jedna. Druga reguła
	 * poprawności rozjechałaby się z regułą rdzenia.
	 */
	private static function email(): string {
		return isset( $_POST[ self::EMAIL_FIELD ] ) && is_string( $_POST[ self::EMAIL_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej.
			? sanitize_text_field( trim( wp_unslash( $_POST[ self::EMAIL_FIELD ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
	}

	/**
	 * Klucz kubełka i wpisu w logu dla adresu: SKRÓT adresu znormalizowanego wielkością liter
	 * (patrz reguła 2 w dokumentacji klasy).
	 *
	 * @param string $email Adres e-mail z żądania.
	 */
	private static function email_key( string $email ): string {
		return wp_hash( strtolower( $email ) );
	}

	/**
	 * Przekierowuje na stronę odzyskiwania hasła ze znacznikiem wyniku (PRG) i kończy żądanie.
	 * Przekierowanie anulowane filtrem `wp_redirect` nie kończy żądania (szew testowy), więc
	 * wywołujący zawsze wraca.
	 *
	 * Przekierowanie idzie WYŁĄCZNIE przez `wp_safe_redirect()`; adres pochodzi z ustawień, nie
	 * z żądania.
	 *
	 * @param string $code Wynik (RESULT_*).
	 */
	private static function finish( string $code ): void {
		if ( wp_safe_redirect( add_query_arg( self::RESULT_ARG, $code, self::base_url() ) ) ) {
			exit;
		}
	}

	/**
	 * Przekierowuje na stronę odzyskiwania hasła ze znacznikiem wyniku I ODNOŚNIKIEM, czyli oboma
	 * argumentami z żądania (PRG). Odmowa, która odnośnik gubi, kazałaby człowiekowi z żywym
	 * kluczem prosić o nowy mail, choć jego klucz dalej działa.
	 *
	 * Adres bierze się z ustawień, nie z żądania (jak w `finish()`); argumenty są NIESEKRETNE,
	 * a strona i tak pyta o klucz rdzeń, zanim pokaże formularz.
	 *
	 * @param string $code  Wynik (RESULT_*).
	 * @param string $key   Klucz z żądania.
	 * @param string $login Login konta z żądania.
	 */
	private static function finish_with_link( string $code, string $key, string $login ): void {
		$url = add_query_arg(
			array(
				self::RESULT_ARG => $code,
				self::USER_ARG   => $login,
				self::KEY_ARG    => $key,
			),
			self::base_url()
		);
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * Odmowa żądania o link: wiersz w logu z POWODEM odmowy i przekierowanie (PRG). Powód jest
	 * jedynym śladem, bo odpowiedź dla człowieka jest zawsze ta sama (patrz reguła 4).
	 *
	 * Adres e-mail trafia do wiersza WYŁĄCZNIE skrótem (patrz reguła 2); pusta wartość zostaje
	 * pusta, bo skrót pustego łańcucha byłby jednym skrótem dla wszystkich żądań bez adresu.
	 *
	 * @param string $reason Powód odmowy.
	 * @param string $code   Wynik dla człowieka (RESULT_*).
	 * @param string $ip     Adres klienta albo `''`.
	 * @param string $email  Adres e-mail z żądania albo `''`.
	 */
	private static function refuse( string $reason, string $code, string $ip, string $email ): void {
		EventLog::write(
			self::LOG_ACTION_REQUEST,
			0,
			0,
			array(
				'ip'         => $ip,
				'email_hash' => '' === $email ? '' : self::email_key( $email ),
				'refused'    => $reason,
			)
		);

		self::finish( $code );
	}
}
