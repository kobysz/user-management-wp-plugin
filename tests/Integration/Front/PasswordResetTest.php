<?php
/**
 * Testy odzyskiwania hasła: żądanie linku (jedna odpowiedź, progi, mail, log) i ustawienie
 * nowego hasła z klucza (klucz, progi, reguły hasła, sesje).
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\PasswordReset;
use PortalUsers\Front\PasswordSetup;
use PortalUsers\Front\RateLimiter;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use ReflectionClass;
use WPDieException;
use WP_Session_Tokens;
use WP_UnitTestCase;

final class PasswordResetTest extends WP_UnitTestCase {

	private const IP = '10.0.0.91';

	/**
	 * Hasło konta sprzed odzyskania.
	 */
	private const OLD_PASSWORD = 'Stare!Haslo"12345';

	/**
	 * Nowe hasło ze znakami, które sanityzacja tekstu by zjadła.
	 */
	private const NEW_PASSWORD = 'Nowe!Haslo"<\\12345';

	/**
	 * Próg żądań per adres IP w testach (mały, żeby przebieg był krótki, ale większy od progu
	 * per adres e-mail: inaczej nie dałoby się pokazać, że progi są niezależne).
	 */
	private const IP_LIMIT = 6;

	/**
	 * Próg żądań per adres e-mail w testach.
	 */
	private const EMAIL_LIMIT = 2;

	private string $redirected = '';

	/**
	 * Wiadomości przechwycone zamiast wysłania.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $mails = array();

	private int $resetPage = 0;

	/**
	 * Liczba wywołań zestawu zakazu cache (patrz `countNoCache()`).
	 */
	private int $noCacheFired = 0;

	/**
	 * Numer kolejnego konta; wspólny dla całej klasy, żeby loginy nie powtarzały się między testami.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * @var callable
	 */
	private $cancelRedirect;

	/**
	 * @var callable
	 */
	private $captureMail;

	private string $originalMethod = 'GET';
	private string $originalAddr   = '';

	public function set_up(): void {
		parent::set_up();
		$this->redirected     = '';
		$this->mails          = array();
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->originalAddr   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		$_SERVER['REMOTE_ADDR'] = self::IP;

		$this->cancelRedirect = function ( $location ) {
			$this->redirected = (string) $location;
			return false; // Przekierowanie anulowane nie kończy żądania: szew testowy.
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );

		$this->captureMail = function ( $short, $atts ) {
			$this->mails[] = (array) $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->captureMail, 10, 2 );

		// Rejestracja jest tu wymogiem, nie wygodą: treść maila podmieniają filtry rdzenia
		// podpięte właśnie w `register()`, więc bez niej mail niósłby odnośnik do `wp-login.php`.
		PasswordReset::register();
		RegistrationHandler::reset_no_cache();

		$this->configure();
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_filter( 'pre_wp_mail', $this->captureMail, 10 );
		remove_all_actions( 'litespeed_control_set_nocache' );
		remove_all_filters( 'password_reset_expiration' );
		remove_all_filters( PasswordSetup::COOKIE_FILTER );
		$_POST                     = array();
		$_GET                      = array();
		$_COOKIE                   = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		$_SERVER['REMOTE_ADDR']    = $this->originalAddr;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Strona odzyskiwania hasła i oba progi.
	 */
	private function configure(): void {
		$this->resetPage = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Odzyskiwanie hasła',
				'post_content' => '[' . PasswordReset::SHORTCODE . ']',
			)
		);
		SettingsRepository::save(
			Settings::fromArray(
				array(
					'reset_page_id'                  => $this->resetPage,
					'reset_limit_per_hour'           => self::IP_LIMIT,
					'reset_limit_per_email_per_hour' => self::EMAIL_LIMIT,
				)
			)
		);
	}

	/**
	 * Konto lekarza.
	 *
	 * @param string $password Hasło konta; puste = losowe hasło z fabryki.
	 * @return array{id: int, login: string, email: string}
	 */
	private function account( string $password = '' ): array {
		++self::$sequence;
		$login = 'odzysk' . self::$sequence;
		$email = $login . '@przyklad.test';
		$args  = array(
			'user_login' => $login,
			'user_email' => $email,
		);
		if ( '' !== $password ) {
			$args['user_pass'] = $password;
		}
		$id = (int) self::factory()->user->create( $args );

		return array(
			'id'    => $id,
			'login' => $login,
			'email' => $email,
		);
	}

	/**
	 * Świeży klucz rdzenia dla konta. Klucz BIERZEMY Z RDZENIA, nie składamy go w teście.
	 *
	 * @param int $userId Identyfikator konta.
	 */
	private function keyFor( int $userId ): string {
		$key = get_password_reset_key( get_userdata( $userId ) );
		$this->assertNotWPError( $key, 'Rdzeń nie wystawił klucza.' );

		return (string) $key;
	}

	/**
	 * Wysyła formularz nowego hasła i zwraca znacznik wyniku z adresu przekierowania.
	 *
	 * @param string      $password Nowe hasło.
	 * @param string      $key      Klucz z odnośnika.
	 * @param string      $login    Login konta z odnośnika.
	 * @param bool|string $nonce    Prawda = ważny nonce, fałsz = brak pola, łańcuch = podana wartość.
	 */
	private function setPassword( string $password, string $key, string $login, bool|string $nonce = true ): string {
		$fields = array(
			PasswordReset::PASSWORD_FIELD => $password,
			PasswordReset::KEY_ARG        => $key,
			PasswordReset::USER_ARG       => $login,
		);
		if ( true === $nonce ) {
			$fields[ PasswordReset::NONCE_SET ] = wp_create_nonce( PasswordReset::NONCE_SET );
		} elseif ( is_string( $nonce ) ) {
			$fields[ PasswordReset::NONCE_SET ] = $nonce;
		}

		$this->redirected          = '';
		$_POST                     = wp_slash( $fields );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		PasswordReset::handle_set();

		return $this->resultOf( $this->redirected );
	}

	/**
	 * Ustawia hasło DRUGĄ ścieżką — z biletu po potwierdzeniu adresu — i zwraca jej znacznik
	 * wyniku. Służy do porównania obu ścieżek ZE SOBĄ.
	 *
	 * @param string $password Nowe hasło.
	 * @param int    $userId   Identyfikator konta.
	 */
	private function setFromTicket( string $password, int $userId ): string {
		$token = PasswordSetup::issue( $userId );
		$this->assertNotSame( '', $token, 'Bilet ustawienia hasła się nie wystawił.' );

		$this->redirected = '';
		$_COOKIE          = array( PasswordSetup::COOKIE => wp_slash( $userId . '.' . $token ) );
		$_POST            = wp_slash(
			array(
				PasswordSetup::PASSWORD_FIELD => $password,
				PasswordSetup::NONCE_FIELD    => wp_create_nonce( PasswordSetup::NONCE_ACTION ),
			)
		);
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		PasswordSetup::handle();
		$_COOKIE = array();

		$query = array();
		parse_str( (string) wp_parse_url( $this->redirected, PHP_URL_QUERY ), $query );

		return isset( $query[ PasswordSetup::PAGE_ARG ] ) && is_string( $query[ PasswordSetup::PAGE_ARG ] ) ? $query[ PasswordSetup::PAGE_ARG ] : '';
	}

	/**
	 * Skrót hasła zapisany na koncie, odczytany z pominięciem cache'u obiektów.
	 *
	 * @param int $userId Identyfikator konta.
	 */
	private function storedHash( int $userId ): string {
		clean_user_cache( $userId );

		return (string) get_userdata( $userId )->user_pass;
	}

	/**
	 * Zawartość kontekstu wiersza logu.
	 *
	 * @param object $row Wiersz logu.
	 * @return array<string, mixed>
	 */
	private function contextOf( object $row ): array {
		$context = json_decode( (string) ( $row->context ?? '' ), true );

		return is_array( $context ) ? $context : array();
	}

	/**
	 * Wysyła formularz z adresem i zwraca znacznik wyniku z adresu przekierowania.
	 *
	 * @param array<string, string> $fields Pola formularza.
	 * @param bool|string           $nonce  Prawda = ważny nonce, fałsz = brak pola, łańcuch = podana wartość.
	 */
	private function submit( array $fields, bool|string $nonce = true ): string {
		$this->redirected = '';
		if ( true === $nonce ) {
			$fields[ PasswordReset::NONCE_REQUEST ] = wp_create_nonce( PasswordReset::NONCE_REQUEST );
		} elseif ( is_string( $nonce ) ) {
			$fields[ PasswordReset::NONCE_REQUEST ] = $nonce;
		}

		$_POST                     = wp_slash( $fields );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		PasswordReset::handle_request();

		return $this->resultOf( $this->redirected );
	}

	/**
	 * Wysyła formularz z podanym adresem.
	 *
	 * @param string $email Adres e-mail.
	 */
	private function request( string $email ): string {
		return $this->submit( array( PasswordReset::EMAIL_FIELD => $email ) );
	}

	/**
	 * Znacznik wyniku z adresu.
	 *
	 * @param string $location Adres przekierowania.
	 */
	private function resultOf( string $location ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return isset( $query[ PasswordReset::RESULT_ARG ] ) && is_string( $query[ PasswordReset::RESULT_ARG ] ) ? $query[ PasswordReset::RESULT_ARG ] : '';
	}

	/**
	 * Odnośnik z treści maila, czyli pierwszy adres niosący argument z kluczem. Odnośnik CZYTAMY
	 * z wysłanej wiadomości, a nie składamy go ponownie w teście: złożony w teście zgadzałby się
	 * sam ze sobą także wtedy, gdyby mail niósł co innego.
	 *
	 * @param string $body Treść wiadomości.
	 */
	private function linkFrom( string $body ): string {
		preg_match_all( '#https?://\S+#', $body, $matches );
		foreach ( $matches[0] as $candidate ) {
			if ( str_contains( (string) $candidate, PasswordReset::KEY_ARG ) ) {
				return (string) $candidate;
			}
		}

		return '';
	}

	/**
	 * Argumenty adresu.
	 *
	 * @param string $url Adres.
	 * @return array<string, mixed>
	 */
	private function argsOf( string $url ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}

	/**
	 * @return list<object> Wiersze logu zdarzeń, od najstarszego.
	 */
	private function logRows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
	}

	/**
	 * Nazwy opcji wszystkich liczników prób, jakie istnieją w bazie.
	 *
	 * @return list<string>
	 */
	private function limiterOptions(): array {
		global $wpdb;
		$prefix = (string) ( new ReflectionClass( RateLimiter::class ) )->getConstant( 'KEY_PREFIX' );
		$like   = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) );
	}

	public function test_a_known_and_an_unknown_address_get_the_SAME_answer(): void {
		// Gdyby się różniły, formularz odzyskiwania hasła czytałby „czy ten adres ma konto na
		// portalu” — ta sama enumeracja, którą zamknęła rejestracja i strona logowania.
		$account = $this->account();

		$known   = $this->request( $account['email'] );
		$unknown = $this->request( 'nikogo@przyklad.test' );

		// Porównanie obu odpowiedzi ZE SOBĄ, nie z zapisanym tekstem: inaczej zmiana tekstu
		// w jednej gałęzi zostawiałaby ten test zielonym. Drugiego porównania — komunikatów dla
		// obu kodów — tu NIE MA: po tej asercji oba argumenty są tym samym łańcuchem, więc nie
		// mogłoby paść i udawało tylko dodatkowy ząb.
		$this->assertSame( $unknown, $known, 'Znany i nieznany adres muszą dostać ten sam kod wyniku.' );
		$this->assertNotSame( '', $known, 'Odpowiedź bez znacznika wyniku nie jest odpowiedzią.' );
		$this->assertNotSame( '', PasswordReset::message_for( $known ), 'Odpowiedź bez komunikatu to pusta strona.' );
		$this->assertStringNotContainsString( $account['email'], PasswordReset::message_for( $known ) );
		$this->assertStringNotContainsString( $account['login'], PasswordReset::message_for( $known ) );
	}

	public function test_a_known_address_receives_a_mail_whose_link_points_at_the_plugin_page(): void {
		$account = $this->account();

		$this->assertSame( PasswordReset::RESULT_SENT, $this->request( $account['email'] ) );

		$this->assertCount( 1, $this->mails, 'Znany adres dostaje dokładnie jedną wiadomość.' );
		$mail = $this->mails[0];
		$this->assertSame( $account['email'], $mail['to'] );

		$link = $this->linkFrom( (string) $mail['message'] );
		$this->assertNotSame( '', $link, 'W mailu nie ma odnośnika z kluczem.' );
		$this->assertStringNotContainsString( 'wp-login.php', (string) $mail['message'], 'Mail niesie odnośnik rdzenia, a nie strony wtyczki.' );

		$args = $this->argsOf( $link );
		$this->assertArrayHasKey( PasswordReset::KEY_ARG, $args, 'Odnośnik nie niesie klucza.' );
		$this->assertArrayHasKey( PasswordReset::USER_ARG, $args, 'Odnośnik nie niesie konta.' );
		$this->assertNotSame( '', (string) $args[ PasswordReset::KEY_ARG ] );
		$this->assertSame( $account['login'], (string) $args[ PasswordReset::USER_ARG ] );

		// Prowadzi na STRONĘ z ustawień (relacja), a nie na zapisany w teście adres.
		$this->assertSame( $this->resetPage, url_to_postid( $link ), 'Odnośnik nie prowadzi na stronę odzyskiwania hasła z ustawień.' );
		// Relacja, nie zapisany adres: `base_url()` wygląda inaczej przy odnośnikach bezpośrednich
		// (`/strona/`) i przy domyślnych (`/?page_id=5`), a odnośnik z maila musi z niego wyrastać
		// w obu wariantach.
		$this->assertStringStartsWith( PasswordReset::base_url(), $link );

		// Klucz z odnośnika jest KLUCZEM RDZENIA, czyli naprawdę otwiera ustawienie hasła.
		$checked = check_password_reset_key( (string) $args[ PasswordReset::KEY_ARG ], (string) $args[ PasswordReset::USER_ARG ] );
		$this->assertNotWPError( $checked, 'Klucz z odnośnika nie jest ważnym kluczem rdzenia.' );
		$this->assertSame( $account['id'], $checked->ID );

		// Temat jest nasz, nie rdzeniowy. Porównanie z tym, co daje filtr wtyczki (relacja), a nie
		// z angielskim tematem rdzenia: zapisany tekst rdzenia przechodziłby sam z siebie na
		// witrynie mówiącej po polsku.
		$core = 'Temat rdzenia 123';
		$this->assertNotSame( $core, (string) PasswordReset::mail_title( $core ), 'Filtr tematu nie podmienia tematu rdzenia.' );
		$this->assertSame( (string) PasswordReset::mail_title( $core ), (string) $mail['subject'], 'Temat wiadomości nie jest tym, który daje filtr wtyczki.' );
	}

	public function test_with_no_reset_page_the_mail_keeps_the_core_link_that_works(): void {
		// Bez wskazanej strony (świeża instalacja) albo po jej usunięciu nasz odnośnik prowadziłby
		// na stronę główną, czyli nigdzie. `wp-login.php` zostaje świadomie otwarte, więc wtedy
		// lepsza jest wiadomość rdzenia niż nasza z martwym odnośnikiem. Żądanie idzie przez rdzeń
		// (wejście zapasowe redakcji), bo formularza wtyczki w tym stanie nie ma gdzie postawić.
		$account = $this->account();
		SettingsRepository::save( Settings::fromArray( array( 'reset_page_id' => 0 ) ) );

		$this->assertTrue( retrieve_password( $account['email'] ) );

		$this->assertCount( 1, $this->mails );
		$message = (string) $this->mails[0]['message'];
		$this->assertStringContainsString( 'wp-login.php', $message, 'Bez strony wtyczki w mailu musi zostać działający odnośnik rdzenia.' );
		$this->assertStringNotContainsString( PasswordReset::KEY_ARG, $message, 'Nasz odnośnik prowadziłby na stronę główną.' );
		$this->assertStringNotContainsString( home_url( '/' ) . '?', $message, 'Odnośnik na stronę główną z argumentami to martwy odnośnik.' );
		// Temat też zostaje rdzeniowy: nasz temat nad treścią rdzenia byłby wiadomością złożoną
		// z dwóch różnych maili.
		$this->assertStringContainsString( 'Password Reset', (string) $this->mails[0]['subject'] );
	}

	public function test_an_unknown_address_sends_no_mail(): void {
		// Ta sama ODPOWIEDŹ nie znaczy ta sama praca.
		$this->assertSame( PasswordReset::RESULT_SENT, $this->request( 'nikogo@przyklad.test' ) );

		$this->assertSame( array(), $this->mails, 'Maila do nikogo nie wysyłamy.' );
	}

	public function test_exhausting_the_email_threshold_does_not_block_another_address_from_the_same_ip(): void {
		$one = $this->account();
		$two = $this->account();

		for ( $i = 1; $i <= self::EMAIL_LIMIT; $i++ ) {
			$this->assertSame( PasswordReset::RESULT_SENT, $this->request( $one['email'] ), 'Żądanie nr ' . $i . ' mieści się w progu.' );
		}
		$this->assertSame( PasswordReset::RESULT_TOO_MANY, $this->request( $one['email'] ), 'Żądanie poza progiem per adres musi dostać odmowę.' );

		$this->mails = array();
		$this->assertSame( PasswordReset::RESULT_SENT, $this->request( $two['email'] ), 'Próg jednego adresu zamknął drugi adres z tego samego IP.' );
		$this->assertCount( 1, $this->mails, 'Drugi adres nie dostał maila.' );
	}

	public function test_exhausting_the_ip_threshold_refuses_before_any_mail_is_sent(): void {
		$account = $this->account();
		for ( $i = 0; $i < self::IP_LIMIT; $i++ ) {
			RateLimiter::hit( PasswordReset::BUCKET_IP . self::IP );
		}

		$this->assertSame( PasswordReset::RESULT_TOO_MANY, $this->request( $account['email'] ) );

		$this->assertSame( array(), $this->mails, 'Przy wyczerpanym progu per IP mail nie może wyjść.' );
		// Odmowa progu per IP stoi PRZED progiem per adres, więc budżet adresu jest nietknięty:
		// inaczej obcy ruch z jednego IP zabierałby właścicielowi adresu jego własne żądania.
		$this->assertSame( 0, RateLimiter::count( PasswordReset::BUCKET_EMAIL . wp_hash( strtolower( $account['email'] ) ) ) );
	}

	public function test_the_email_bucket_key_does_not_contain_the_address_in_the_clear(): void {
		// Klucz transientu trafia do `wp_options`, a adres e-mail lekarza jest daną osobową.
		$account = $this->account();

		$this->assertSame( PasswordReset::RESULT_SENT, $this->request( $account['email'] ) );

		$this->assertSame(
			1,
			RateLimiter::count( PasswordReset::BUCKET_EMAIL . wp_hash( strtolower( $account['email'] ) ) ),
			'Kubełek per adres musi być kluczowany skrótem adresu.'
		);
		// Klucze, które BY powstały przy kluczowaniu adresem jawnym, nie istnieją.
		foreach ( array( $account['email'], strtolower( $account['email'] ), strtoupper( $account['email'] ) ) as $clear ) {
			$this->assertSame( 0, RateLimiter::count( PasswordReset::BUCKET_EMAIL . $clear ), 'Kubełek kluczowany adresem jawnym: ' . $clear );
		}
		// Niezależnie od sposobu kluczowania: w bazie stoją DOKŁADNIE dwa liczniki — per IP i per adres.
		$this->assertCount( 2, $this->limiterOptions(), 'Poza kubełkiem IP i kubełkiem adresu nie wolno utworzyć żadnego licznika.' );
	}

	public function test_a_filled_honeypot_is_refused_and_sends_no_mail(): void {
		$account = $this->account();

		$result = $this->submit(
			array(
				PasswordReset::EMAIL_FIELD => $account['email'],
				PasswordReset::HONEYPOT    => 'bot tu byl',
			)
		);

		$this->assertSame( PasswordReset::RESULT_SENT, $result, 'Bot dostaje tę samą odpowiedź, co człowiek.' );
		$this->assertSame( array(), $this->mails, 'Wypełniona pułapka nie wysyła maila.' );
		// Pułapka stoi PRZED progami: odmowa nic nie kosztuje, więc bot nie zabiera budżetu nikomu.
		$this->assertSame( array(), $this->limiterOptions(), 'Wypełniona pułapka zużyła próg.' );
	}

	public function test_a_missing_nonce_gives_the_stale_result(): void {
		$account = $this->account();

		$this->assertSame( PasswordReset::RESULT_STALE, $this->submit( array( PasswordReset::EMAIL_FIELD => $account['email'] ), false ), 'Brak nonce\'a.' );
		$this->assertSame( PasswordReset::RESULT_STALE, $this->submit( array( PasswordReset::EMAIL_FIELD => $account['email'] ), 'przedawniony' ), 'Nonce o złej wartości.' );

		$this->assertNotSame( '', PasswordReset::message_for( PasswordReset::RESULT_STALE ), 'Odmowa bez komunikatu to pusta strona.' );
		$this->assertSame( array(), $this->mails, 'Odmowa nonce\'a nie wysyła maila.' );
		$this->assertSame( array(), $this->limiterOptions(), 'Odmowa nonce\'a nie zużywa progu.' );
	}

	public function test_a_get_request_is_refused(): void {
		$account                   = $this->account();
		$_POST                     = wp_slash(
			array(
				PasswordReset::EMAIL_FIELD   => $account['email'],
				PasswordReset::NONCE_REQUEST => wp_create_nonce( PasswordReset::NONCE_REQUEST ),
			)
		);
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		try {
			PasswordReset::handle_request();
			$this->fail( 'Żądanie GET miało zostać odrzucone.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'POST', $e->getMessage() );
		}

		$this->assertSame( array(), $this->mails, 'Żądanie GET nie może wysłać maila.' );
	}

	public function test_the_log_entry_carries_no_address_in_the_clear(): void {
		$account = $this->account();

		$this->assertSame( PasswordReset::RESULT_SENT, $this->request( $account['email'] ) );
		$this->assertSame( PasswordReset::RESULT_SENT, $this->request( 'nikogo@przyklad.test' ) );

		$rows = $this->logRows();
		$this->assertCount( 2, $rows, 'Każde żądanie zostawia jeden wiersz w logu.' );
		foreach ( $rows as $index => $row ) {
			$this->assertSame( PasswordReset::LOG_ACTION_REQUEST, $row->action, 'Wiersz nr ' . $index );
			$this->assertSame( 0, (int) $row->actor_user_id, 'Żądanie odzyskania hasła przychodzi od nikogo.' );
		}

		$first = implode( '|', array_map( 'strval', (array) $rows[0] ) );
		$this->assertStringNotContainsString( $account['email'], $first, 'Adres e-mail jawny w logu.' );
		$this->assertStringContainsString( wp_hash( strtolower( $account['email'] ) ), $first, 'Adres e-mail musi trafić do logu skrótem, jak w logu logowania.' );

		$second = implode( '|', array_map( 'strval', (array) $rows[1] ) );
		$this->assertStringNotContainsString( 'nikogo@przyklad.test', $second, 'Adres nieznany też nie może trafić do logu jawnie.' );
		$this->assertStringContainsString( wp_hash( 'nikogo@przyklad.test' ), $second, 'Adres nieznany musi trafić do logu skrótem.' );
	}

	public function test_the_page_shows_the_message_for_the_result_in_the_url(): void {
		// Odczyt wyniku z adresu nie wymaga nonce'a: pokazanie komunikatu nic nie zmienia.
		$_GET[ PasswordReset::RESULT_ARG ] = PasswordReset::RESULT_SENT;
		// Pusty komunikat zawiera się w KAŻDYM łańcuchu, więc bez tej asercji sprawdzenie niżej
		// przechodziłoby samo z siebie, gdyby tekst kiedyś zniknął z mapy.
		$this->assertNotSame( '', PasswordReset::message_for( PasswordReset::RESULT_SENT ) );
		$html = PasswordReset::render();
		$this->assertStringContainsString( esc_html( PasswordReset::message_for( PasswordReset::RESULT_SENT ) ), $html );
		$this->assertStringContainsString( '<form', $html, 'Komunikat nie może zabrać formularza.' );

		$_GET[ PasswordReset::RESULT_ARG ] = PasswordReset::RESULT_TOO_MANY;
		$this->assertStringContainsString( esc_html( PasswordReset::message_for( PasswordReset::RESULT_TOO_MANY ) ), PasswordReset::render() );

		// Kod, którego wtyczka nie zna, nie rysuje pustego okienka alertu.
		$_GET[ PasswordReset::RESULT_ARG ] = 'wymyslony-kod';
		$this->assertStringNotContainsString( 'pusers-notice', PasswordReset::render() );

		unset( $_GET[ PasswordReset::RESULT_ARG ] );
		$this->assertStringNotContainsString( 'pusers-notice', PasswordReset::render(), 'Bez znacznika w adresie strona nie pokazuje komunikatu.' );
	}

	public function test_a_dead_link_gets_the_address_form_and_a_message_never_a_blind_alley(): void {
		// Argument adresu jest NIESEKRETNY, więc na tę stronę trafia też ktoś z podrzuconym
		// odnośnikiem, zakładką albo starym mailem. Komunikat „link wygasł” nie może zabrać mu
		// formularza i zostawić strony bez żadnej drogi dalej (lekcja z Planu 3).
		$account = $this->account();
		$other   = $this->account();

		$_GET[ PasswordReset::KEY_ARG ]  = 'martwy-klucz';
		$_GET[ PasswordReset::USER_ARG ] = $account['login'];
		$dead                            = PasswordReset::render();

		$this->assertStringContainsString( '<form', $dead, 'Martwy klucz zabrał stronę człowiekowi.' );
		$this->assertStringContainsString( 'name="' . PasswordReset::EMAIL_FIELD . '"', $dead, 'Na stronie nie ma formularza adresu.' );
		preg_match( '#<p class="pusers-notice[^"]*"[^>]*>(.*?)</p>#', $dead, $matched );
		$notice = (string) ( $matched[1] ?? '' );
		$this->assertNotSame( '', $notice, 'Martwy klucz bez komunikatu to zagadka.' );

		// Klucz WŁASNY konta, ale podany z loginem innego konta, też nie działa.
		$key = get_password_reset_key( get_userdata( $account['id'] ) );
		$this->assertNotWPError( $key );
		$_GET[ PasswordReset::KEY_ARG ]  = (string) $key;
		$_GET[ PasswordReset::USER_ARG ] = $other['login'];
		$this->assertStringContainsString( $notice, PasswordReset::render(), 'Klucz z cudzym kontem musi dostać ten sam komunikat.' );

		// Klucz ŻYWY tego komunikatu NIE dostaje: inaczej komunikat nie mówi prawdy o linku.
		$_GET[ PasswordReset::USER_ARG ] = $account['login'];
		$live                            = PasswordReset::render();
		$this->assertStringNotContainsString( $notice, $live, 'Żywy link dostał komunikat o linku nieaktualnym.' );
		$this->assertStringContainsString( '<form', $live );
	}

	public function test_a_logged_in_visitor_still_gets_the_form(): void {
		// `Redirects` odcięło kontom bez uprawnień redakcyjnych cały `wp-admin`, więc odzyskiwanie
		// hasła jest dla lekarza JEDYNĄ drogą zmiany hasła. Notka „jesteś zalogowany” zamiast
		// formularza zamykałaby mu tę drogę.
		$account = $this->account();
		wp_set_current_user( $account['id'] );

		$html = PasswordReset::render();

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="' . PasswordReset::EMAIL_FIELD . '"', $html );
	}

	public function test_the_form_posts_to_the_registered_action_and_the_shortcode_exists(): void {
		PasswordReset::register();

		$this->assertTrue( shortcode_exists( PasswordReset::SHORTCODE ) );
		preg_match( '/<input type="hidden" name="action" value="([^"]+)"/', PasswordReset::render(), $matched );
		$this->assertSame( PasswordReset::ACTION_REQUEST, $matched[1] ?? '' );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . PasswordReset::ACTION_REQUEST, array( PasswordReset::class, 'handle_request' ) ) );
		// Zalogowany też wyśle ten formularz: lekarz zmienia hasło wyłącznie tą drogą.
		$this->assertNotFalse( has_action( 'admin_post_' . PasswordReset::ACTION_REQUEST, array( PasswordReset::class, 'handle_request' ) ) );
	}

	public function test_a_refusal_leaves_a_row_in_the_log_with_its_reason(): void {
		// Odpowiedź tej strony jest ZAWSZE ta sama, więc wiersz w logu jest JEDYNYM śladem odmowy.
		// Bez niego człowiek, któremu menedżer haseł wypełnił pułapkę, słyszy „wysłaliśmy link”,
		// maila nie dostaje i nie zostawia żadnego śladu, po którym administrator by go znalazł.
		$account = $this->account();

		$this->submit(
			array(
				PasswordReset::EMAIL_FIELD => $account['email'],
				PasswordReset::HONEYPOT    => 'bot tu byl',
			)
		);
		$this->submit( array( PasswordReset::EMAIL_FIELD => $account['email'] ), false );
		for ( $i = 0; $i < self::IP_LIMIT; $i++ ) {
			RateLimiter::hit( PasswordReset::BUCKET_IP . self::IP );
		}
		$this->assertSame( PasswordReset::RESULT_TOO_MANY, $this->request( $account['email'] ) );

		$rows = $this->logRows();
		$this->assertCount( 3, $rows, 'Każda odmowa zostawia jeden wiersz w logu.' );

		$reasons = array();
		foreach ( $rows as $index => $row ) {
			$context = $this->contextOf( $row );
			$reason  = isset( $context['refused'] ) ? (string) $context['refused'] : '';
			$this->assertNotSame( '', $reason, 'Odmowa bez powodu nie mówi administratorowi, co się stało; wiersz nr ' . $index );
			$this->assertSame( self::IP, isset( $context['ip'] ) ? (string) $context['ip'] : '', 'Wiersz odmowy musi nieść adres IP; wiersz nr ' . $index );
			$reasons[] = $reason;
		}
		// Trzy różne odmowy muszą być w logu rozróżnialne: jeden powód dla wszystkich nie wskazuje,
		// której bramki szukać.
		$this->assertCount( 3, array_unique( $reasons ), 'Powody odmów muszą się od siebie różnić.' );

		// Higiena logu bez zmian: adres wyłącznie skrótem (patrz reguła 2).
		$honeypot = implode( '|', array_map( 'strval', (array) $rows[0] ) );
		$this->assertStringNotContainsString( $account['email'], $honeypot, 'Adres e-mail jawny w logu.' );
		$this->assertStringContainsString( wp_hash( strtolower( $account['email'] ) ), $honeypot, 'Adres z wypełnionej pułapki musi trafić do logu skrótem.' );
	}

	public function test_the_page_leaves_the_cache_from_the_hook_before_the_render(): void {
		// Strona niesie nonce i otwiera ustawienie hasła, więc nie może trafić do cache'a stron.
		// Z `the_content` nagłówki wychodzą za późno, dlatego zestaw jedzie z hooka PRZED renderem.
		$this->countNoCache();

		$this->go_to( (string) get_permalink( $this->resetPage ) );
		do_action( 'template_redirect' );

		$this->assertSame( 1, $this->noCacheFired, 'Zestaw zakazu cache jedzie z hooka, przed renderem.' );

		// Strona BEZ shortcode'u zostaje nietknięta: wyłączanie całej witryny z cache nie jest
		// zadaniem tej klasy.
		$other = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Strona bez shortcode\'u.',
			)
		);
		$this->countNoCache();
		$this->go_to( (string) get_permalink( $other ) );
		do_action( 'template_redirect' );

		$this->assertSame( 0, $this->noCacheFired, 'Strona bez shortcode\'u nie jest wyłączana z cache.' );
	}

	public function test_the_mail_filters_degrade_when_another_plugin_passes_fewer_arguments(): void {
		// Obca wtyczka potrafi zawołać `apply_filters( 'retrieve_password_message', $tresc )`
		// z jednym argumentem. Przy argumentach 2 i 3 wymaganych był to `ArgumentCountError`,
		// czyli błąd 500 na ścieżce, która miała tylko przepuścić treść dalej.
		$core = 'Treść rdzenia.';

		$this->assertSame( $core, apply_filters( 'retrieve_password_message', $core ) );
		$this->assertSame( $core, apply_filters( 'retrieve_password_message', $core, 'klucz' ) );
		// Wywołanie rdzenia (trzy argumenty) dalej podmienia treść: ustępstwo dotyczy TYLKO
		// wejścia, którego nie rozumiemy.
		$account = $this->account();
		$this->assertNotSame( $core, apply_filters( 'retrieve_password_message', $core, $this->keyFor( $account['id'] ), $account['login'] ) );
	}

	public function test_a_live_link_gets_the_form_for_the_new_password(): void {
		$account = $this->account();

		$_GET[ PasswordReset::KEY_ARG ]  = $this->keyFor( $account['id'] );
		$_GET[ PasswordReset::USER_ARG ] = $account['login'];
		$html                            = PasswordReset::render();

		$this->assertStringContainsString( 'name="' . PasswordReset::PASSWORD_FIELD . '"', $html, 'Żywy odnośnik musi dać formularz nowego hasła.' );
		$this->assertStringNotContainsString( 'name="' . PasswordReset::EMAIL_FIELD . '"', $html, 'Przy żywym kluczu formularz adresu nie ma po co stać.' );
		preg_match( '/<input type="hidden" name="action" value="([^"]+)"/', $html, $matched );
		$this->assertSame( PasswordReset::ACTION_SET, $matched[1] ?? '', 'Formularz nowego hasła wysyła się pod inną akcję niż formularz adresu.' );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . PasswordReset::ACTION_SET, array( PasswordReset::class, 'handle_set' ) ) );
		// Zalogowany też wyśle ten formularz: `Redirects` odcięło lekarzowi `wp-admin`.
		$this->assertNotFalse( has_action( 'admin_post_' . PasswordReset::ACTION_SET, array( PasswordReset::class, 'handle_set' ) ) );
		// Adresem akcji jest `admin-post.php`, więc odnośnik musi pojechać w polach formularza.
		$this->assertStringContainsString( 'value="' . esc_attr( (string) $_GET[ PasswordReset::KEY_ARG ] ) . '"', $html, 'Formularz nie niesie klucza.' );
		$this->assertStringContainsString( 'value="' . esc_attr( $account['login'] ) . '"', $html, 'Formularz nie niesie loginu konta.' );

		// Komunikat odmowy staje NAD formularzem, nie zamiast niego.
		$_GET[ PasswordReset::RESULT_ARG ] = PasswordReset::RESULT_SHORT;
		$short                             = PasswordReset::render();
		$this->assertNotSame( '', PasswordReset::message_for( PasswordReset::RESULT_SHORT ), 'Odmowa bez komunikatu to pusta strona.' );
		$this->assertStringContainsString( esc_html( PasswordReset::message_for( PasswordReset::RESULT_SHORT ) ), $short );
		$this->assertStringContainsString( 'name="' . PasswordReset::PASSWORD_FIELD . '"', $short, 'Komunikat zabrał formularz nowego hasła.' );
	}

	public function test_a_valid_key_sets_the_new_password_and_the_old_one_stops_working(): void {
		$account = $this->account( self::OLD_PASSWORD );
		$key     = $this->keyFor( $account['id'] );

		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'] ) );

		$hash = $this->storedHash( $account['id'] );
		$this->assertTrue( wp_check_password( self::NEW_PASSWORD, $hash, $account['id'] ), 'Nowe hasło nie działa; hasła nie wolno sanityzować.' );
		$this->assertFalse( wp_check_password( self::OLD_PASSWORD, $hash, $account['id'] ), 'Stare hasło dalej działa.' );

		// Wiersz w logu wskazuje KONTO i nie niesie ani hasła, ani jego skrótu.
		$rows = $this->logRows();
		$this->assertCount( 1, $rows, 'Ustawienie hasła zostawia jeden wiersz w logu.' );
		$this->assertSame( PasswordReset::LOG_ACTION_SET, $rows[0]->action );
		$this->assertSame( $account['id'], (int) $rows[0]->subject_user_id );
		$row = implode( '|', array_map( 'strval', (array) $rows[0] ) );
		$this->assertStringNotContainsString( self::NEW_PASSWORD, $row, 'Hasło w logu.' );
		$this->assertStringNotContainsString( $hash, $row, 'Skrót hasła w logu.' );

		// Strona po przekierowaniu mówi, co się stało, i nie zostawia człowieka bez drogi dalej.
		$_GET[ PasswordReset::RESULT_ARG ] = PasswordReset::RESULT_DONE;
		$done                              = PasswordReset::render();
		$this->assertNotSame( '', PasswordReset::message_for( PasswordReset::RESULT_DONE ) );
		$this->assertStringContainsString( esc_html( PasswordReset::message_for( PasswordReset::RESULT_DONE ) ), $done );
		$this->assertStringContainsString( '<form', $done, 'Komunikat nie może zostawić strony bez drogi dalej.' );
	}

	public function test_the_key_works_only_once(): void {
		// `reset_password()` czyści `user_activation_key`, więc drugie wysłanie tego samego
		// odnośnika odmawia — i, co ważniejsze, nie podmienia hasła ustawionego przez właściciela.
		$account = $this->account( self::OLD_PASSWORD );
		$key     = $this->keyFor( $account['id'] );

		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'] ) );
		$this->assertSame( PasswordReset::RESULT_INVALID, $this->setPassword( 'Podstawione!Haslo"99', $key, $account['login'] ) );

		$hash = $this->storedHash( $account['id'] );
		$this->assertTrue( wp_check_password( self::NEW_PASSWORD, $hash, $account['id'] ), 'Drugie użycie klucza podmieniło hasło.' );
		$this->assertFalse( wp_check_password( 'Podstawione!Haslo"99', $hash, $account['id'] ) );
	}

	public function test_a_key_belonging_to_another_account_is_refused(): void {
		// Rdzeń weryfikuje klucz WYŁĄCZNIE w parze z loginem, więc podmiana loginu w adresie nie
		// może niczego otworzyć.
		$victim = $this->account( self::OLD_PASSWORD );
		$other  = $this->account( self::OLD_PASSWORD );
		$key    = $this->keyFor( $victim['id'] );

		$this->assertSame( PasswordReset::RESULT_INVALID, $this->setPassword( self::NEW_PASSWORD, $key, $other['login'] ) );

		// Nieudane sprawdzenie klucza liczy się WYŁĄCZNIE w kubełku per adres IP: kubełek
		// kluczowany kontem powstaje dopiero dla konta USTALONEGO Z KLUCZA. Login z żądania jest
		// niesprawdzony, więc osoba trzecia wyczerpywałaby cudzy budżet samą jego podmianą.
		// Liczymy WSZYSTKIE liczniki w bazie, nie tylko kubełek po identyfikatorze: próg policzony
		// po samym loginie byłby dokładnie tą samą luką i licznik po identyfikatorze by go nie
		// pokazał.
		$this->assertCount( 1, $this->limiterOptions(), 'Poza kubełkiem per IP nie wolno utworzyć licznika dla konta, którego nie ustalono.' );
		$this->assertSame( 1, RateLimiter::count( PasswordReset::BUCKET_SET_IP . self::IP ), 'Odmowa klucza musi się policzyć w kubełku per IP.' );

		$this->assertTrue( wp_check_password( self::OLD_PASSWORD, $this->storedHash( $other['id'] ), $other['id'] ), 'Cudzy klucz ustawił hasło.' );
		$this->assertTrue( wp_check_password( self::OLD_PASSWORD, $this->storedHash( $victim['id'] ), $victim['id'] ), 'Cudza próba ustawiła hasło właścicielowi klucza.' );
		// Klucz właściciela DALEJ działa: cudza próba go nie spaliła (dowód przez użycie).
		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $key, $victim['login'] ) );
	}

	public function test_an_expired_key_is_refused(): void {
		$account = $this->account( self::OLD_PASSWORD );
		$key     = $this->keyFor( $account['id'] );

		// Termin ważności klucza należy do rdzenia (filtr `password_reset_expiration`), więc
		// przeterminowanie udajemy jego własnym mechanizmem, a nie podmianą danych w tabeli.
		add_filter( 'password_reset_expiration', static fn (): int => -1 );
		$this->assertSame( PasswordReset::RESULT_INVALID, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'] ) );
		$this->assertTrue( wp_check_password( self::OLD_PASSWORD, $this->storedHash( $account['id'] ), $account['id'] ), 'Przeterminowany klucz ustawił hasło.' );

		// Ten SAM klucz w terminie działa: dowód, że odmowa padła z powodu terminu, a nie czegoś
		// innego w żądaniu.
		remove_all_filters( 'password_reset_expiration' );
		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'] ) );
	}

	public function test_the_password_rules_are_the_SAME_as_for_the_ticket_from_registration(): void {
		// Oba miejsca wołają JEDNĄ regułę z domeny. Wyniki obu ścieżek porównujemy ZE SOBĄ, nie
		// z zapisanym kodem: druga kopia reguł, która oddaje własny kod, pada tu natychmiast.
		add_filter( PasswordSetup::COOKIE_FILTER, '__return_false' );
		$account = $this->account( self::OLD_PASSWORD );
		$key     = $this->keyFor( $account['id'] );

		$shortHere  = $this->setPassword( 'krotkie', $key, $account['login'] );
		$shortThere = $this->setFromTicket( 'krotkie', $account['id'] );
		$sameHere   = $this->setPassword( $account['email'], $key, $account['login'] );
		$sameThere  = $this->setFromTicket( $account['email'], $account['id'] );

		$this->assertSame( $shortThere, $shortHere, 'Hasło zbyt krótkie musi dać ten sam wynik na obu ścieżkach.' );
		$this->assertSame( $sameThere, $sameHere, 'Hasło równe adresowi musi dać ten sam wynik na obu ścieżkach.' );
		// Bez tego dwa naruszenia zlane w jeden kod przepuszczałyby ten test dla dowolnej stałej.
		$this->assertNotSame( $shortHere, $sameHere, 'Dwa różne naruszenia reguł muszą dawać dwa różne kody.' );
	}

	public function test_the_threshold_is_counted_per_ip_and_per_account(): void {
		$one = $this->account( self::OLD_PASSWORD );
		$two = $this->account( self::OLD_PASSWORD );

		// Wyczerpany budżet KONTA: to konto dostaje odmowę, drugie z tego samego adresu IP nie.
		for ( $i = 0; $i < self::IP_LIMIT; $i++ ) {
			RateLimiter::hit( PasswordReset::BUCKET_SET_USER . $one['id'] );
		}
		$this->assertSame( PasswordReset::RESULT_TOO_MANY, $this->setPassword( self::NEW_PASSWORD, $this->keyFor( $one['id'] ), $one['login'] ) );
		$this->assertTrue( wp_check_password( self::OLD_PASSWORD, $this->storedHash( $one['id'] ), $one['id'] ), 'Odmowa progu ustawiła hasło.' );
		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $this->keyFor( $two['id'] ), $two['login'] ), 'Próg jednego konta zamknął drugie konto z tego samego IP.' );

		// Kubełki są OSOBNE od kubełków żądania o link: wspólny pozwalałby jednym przebiegiem
		// wyczerpać budżet drugiego kroku.
		$this->assertSame( 0, RateLimiter::count( PasswordReset::BUCKET_IP . self::IP ), 'Ustawianie hasła zużyło budżet żądań o link.' );

		// Wyczerpany budżet adresu IP: odmowa pada PRZED sprawdzeniem klucza, więc budżet konta
		// zostaje nietknięty.
		$three = $this->account( self::OLD_PASSWORD );
		for ( $i = 0; $i < self::IP_LIMIT; $i++ ) {
			RateLimiter::hit( PasswordReset::BUCKET_SET_IP . self::IP );
		}
		$this->assertSame( PasswordReset::RESULT_TOO_MANY, $this->setPassword( self::NEW_PASSWORD, $this->keyFor( $three['id'] ), $three['login'] ) );
		$this->assertTrue( wp_check_password( self::OLD_PASSWORD, $this->storedHash( $three['id'] ), $three['id'] ) );
		$this->assertSame( 0, RateLimiter::count( PasswordReset::BUCKET_SET_USER . $three['id'] ), 'Odmowa progu per IP zużyła budżet konta.' );
	}

	public function test_two_typos_in_the_new_password_do_not_lock_the_account_out(): void {
		// Próg tego kroku NIE może brać wartości progu wysyłki maila (w tych testach: dwa żądania
		// na adres). Ta wartość jest skalibrowana na wysyłanie maila, a nie na wpisywanie hasła
		// przez człowieka z ważnym kluczem w ręku: przy niej dwie pomyłki zamykałyby lekarzowi
		// godzinę z działającym odnośnikiem.
		$account = $this->account( self::OLD_PASSWORD );
		$key     = $this->keyFor( $account['id'] );

		$results = array();
		for ( $i = 0; $i <= self::EMAIL_LIMIT; $i++ ) {
			$results[] = $this->setPassword( 'krotkie', $key, $account['login'] );
		}

		$this->assertSame( array_fill( 0, self::EMAIL_LIMIT + 1, PasswordReset::RESULT_SHORT ), $results, 'Pomyłka w haśle wyczerpała budżet progu wysyłki maila.' );
		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'] ) );
	}

	public function test_setting_a_password_destroys_other_sessions_of_that_account(): void {
		// Odzyskiwanie hasła jest tym, czego używa człowiek, któremu ktoś przejął konto. Nowe
		// hasło, które nie wyrzuca cudzej sesji, nie odbiera dostępu — a cały portal jest za
		// logowaniem. Sprawdzamy DOSTĘP, nie wiersz w metadanych, bo dostęp jest wymogiem.
		// Mechanizmem jest tu RDZEŃ: ciasteczko uwierzytelniające niesie fragment skrótu hasła,
		// więc zmiana hasła unieważnia cudze ciasteczko bez naszej linii kodu. Jawne
		// `WP_Session_Tokens::destroy_all()` zostało napisane i USUNIĘTE, bo ten test przechodzi
		// bez niego; różnicę widać tylko w metadanej `session_tokens`, gdzie wiersz zostaje —
		// a sam wiersz nikomu niczego nie otwiera.
		$account = $this->account( self::OLD_PASSWORD );
		$expires = time() + 2 * DAY_IN_SECONDS;
		$token   = WP_Session_Tokens::get_instance( $account['id'] )->create( $expires );
		$cookie  = wp_generate_auth_cookie( $account['id'], $expires, 'auth', $token );
		$this->assertSame( $account['id'], wp_validate_auth_cookie( $cookie, 'auth' ), 'Cudza sesja miała działać przed zmianą hasła.' );

		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $this->keyFor( $account['id'] ), $account['login'] ) );

		$this->assertFalse( wp_validate_auth_cookie( $cookie, 'auth' ), 'Cudza sesja dalej otwiera portal po ustawieniu nowego hasła.' );
	}

	public function test_a_refusal_from_the_password_rules_keeps_the_key_usable(): void {
		// Zbyt krótkie hasło nie może spalić odnośnika: to byłaby odmowa trafiająca w człowieka,
		// który nic złego nie zrobił. Klucz zużywa się WYŁĄCZNIE przy udanym ustawieniu.
		$account = $this->account( self::OLD_PASSWORD );
		$key     = $this->keyFor( $account['id'] );

		$this->assertSame( PasswordReset::RESULT_SHORT, $this->setPassword( 'krotkie', $key, $account['login'] ) );

		// Odmowa wraca na stronę Z ODNOŚNIKIEM, więc formularz nowego hasła pokazuje się ponownie.
		$args = $this->argsOf( $this->redirected );
		$this->assertSame( $key, isset( $args[ PasswordReset::KEY_ARG ] ) ? (string) $args[ PasswordReset::KEY_ARG ] : '', 'Odmowa zgubiła klucz z adresu.' );
		$this->assertSame( $account['login'], isset( $args[ PasswordReset::USER_ARG ] ) ? (string) $args[ PasswordReset::USER_ARG ] : '', 'Odmowa zgubiła login z adresu.' );

		// DOWÓD, że klucz żyje: używamy go, a nie odczytujemy metadanej.
		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'] ) );
		$this->assertTrue( wp_check_password( self::NEW_PASSWORD, $this->storedHash( $account['id'] ), $account['id'] ) );
	}

	public function test_a_set_form_with_a_stale_nonce_keeps_the_link_so_the_person_can_try_again(): void {
		// Nonce starzeje się razem z sesją, a klucz z maila żyje dobę: człowiek z kartą otwartą od
		// nocy ma dostać komunikat i ten sam formularz, nie prośbę o nowy mail.
		$account = $this->account( self::OLD_PASSWORD );
		$key     = $this->keyFor( $account['id'] );

		$this->assertSame( PasswordReset::RESULT_STALE, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'], 'przedawniony' ) );

		$this->assertTrue( wp_check_password( self::OLD_PASSWORD, $this->storedHash( $account['id'] ), $account['id'] ), 'Odmowa nonce\'a ustawiła hasło.' );
		$this->assertSame( array(), $this->limiterOptions(), 'Odmowa nonce\'a nie zużywa progu.' );
		$this->assertSame( PasswordReset::RESULT_DONE, $this->setPassword( self::NEW_PASSWORD, $key, $account['login'] ), 'Odmowa nonce\'a spaliła klucz.' );
	}

	/**
	 * Zaczyna liczyć wywołania zestawu zakazu cache od nowa (licznik: `$this->noCacheFired`).
	 */
	private function countNoCache(): void {
		remove_all_actions( 'litespeed_control_set_nocache' );
		RegistrationHandler::reset_no_cache();
		$this->noCacheFired = 0;
		add_action(
			'litespeed_control_set_nocache',
			function (): void {
				++$this->noCacheFired;
			}
		);
	}
}
