<?php
/**
 * Testy żądania linku do ustawienia nowego hasła: jedna odpowiedź, progi, mail, log i widok.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\PasswordReset;
use PortalUsers\Front\RateLimiter;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use ReflectionClass;
use WPDieException;
use WP_UnitTestCase;

final class PasswordResetTest extends WP_UnitTestCase {

	private const IP = '10.0.0.91';

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
		$_POST                     = array();
		$_GET                      = array();
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
	 * @return array{id: int, login: string, email: string}
	 */
	private function account(): array {
		++self::$sequence;
		$login = 'odzysk' . self::$sequence;
		$email = $login . '@przyklad.test';
		$id    = (int) self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $email,
			)
		);

		return array(
			'id'    => $id,
			'login' => $login,
			'email' => $email,
		);
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
		// w jednej gałęzi zostawiałaby ten test zielonym.
		$this->assertSame( $unknown, $known, 'Znany i nieznany adres muszą dostać ten sam kod wyniku.' );
		$this->assertSame( PasswordReset::message_for( $known ), PasswordReset::message_for( $unknown ), 'Komunikaty muszą być identyczne.' );
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

		// Temat jest nasz, nie rdzeniowy: inaczej filtr tematu nie działa.
		$this->assertNotSame( '', (string) $mail['subject'] );
		$this->assertStringNotContainsString( 'Password Reset', (string) $mail['subject'] );
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
}
