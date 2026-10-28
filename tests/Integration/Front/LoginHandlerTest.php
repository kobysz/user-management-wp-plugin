<?php
/**
 * Testy obsługi wysłanego formularza logowania: bramki, progi, komunikaty i log.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\LoginGateway;
use PortalUsers\Front\LoginHandler;
use PortalUsers\Front\RateLimiter;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use ReflectionClass;
use WPDieException;
use WP_UnitTestCase;

final class LoginHandlerTest extends WP_UnitTestCase {

	/**
	 * Hasło kont testowych. Niesie znaki, które `sanitize_text_field()` by zjadło: każde udane
	 * logowanie tym hasłem jest zarazem dowodem, że hasło NIE przechodzi sanityzacji.
	 */
	private const PASSWORD = 'Dlug!e<Haslo>"12';

	private const IP = '10.0.0.77';

	/**
	 * Próg nieudanych logowań per adres IP w testach (mały, żeby przebieg był krótki).
	 */
	private const IP_LIMIT = 4;

	/**
	 * Próg nieudanych logowań per konto w testach.
	 */
	private const ACCOUNT_LIMIT = 2;

	private string $redirected = '';

	/**
	 * Ile razy odpalił się filtr `authenticate`, czyli ile razy doszło do sprawdzenia hasła.
	 *
	 * @var int
	 */
	private int $passwordChecks = 0;

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
	private $countChecks;

	private string $originalMethod = 'GET';
	private string $originalAddr   = '';

	public function set_up(): void {
		parent::set_up();
		$this->redirected     = '';
		$this->passwordChecks = 0;
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->originalAddr   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		$_SERVER['REMOTE_ADDR'] = self::IP;

		$this->cancelRedirect = function ( $location ) {
			$this->redirected = (string) $location;
			return false; // Przekierowanie anulowane nie kończy żądania: szew testowy.
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );

		// Licznik sprawdzeń hasła: priorytet 1, czyli PRZED weryfikatorami rdzenia (20) i bramą (30).
		$this->countChecks = function ( $user ) {
			++$this->passwordChecks;
			return $user;
		};
		add_filter( 'authenticate', $this->countChecks, 1, 1 );

		// Ciasteczka uwierzytelnienia nie mają po co wychodzić z testu.
		add_filter( 'send_auth_cookies', '__return_false' );

		// Podpięcie bramy jest idempotentne; bez niej stan konta nie znaczyłby nic.
		LoginGateway::register();

		$this->configure();
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_filter( 'authenticate', $this->countChecks, 1 );
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_all_filters( LoginHandler::SPAM_FILTER );
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		$_SERVER['REMOTE_ADDR']    = $this->originalAddr;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Strona logowania, strona po zalogowaniu i oba progi.
	 */
	private function configure(): void {
		$login = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Logowanie',
				'post_content' => '[' . LoginForm::SHORTCODE . ']',
			)
		);
		$after = (int) self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Po zalogowaniu',
			)
		);
		SettingsRepository::save(
			Settings::fromArray(
				array(
					'login_page_id'                    => $login,
					'after_login_page_id'              => $after,
					'login_limit_per_hour'             => self::IP_LIMIT,
					'login_limit_per_account_per_hour' => self::ACCOUNT_LIMIT,
				)
			)
		);
	}

	/**
	 * Konto o znanym haśle.
	 *
	 * @param AccountStatus|null $status Stan konta albo null, gdy konto ma zostać bez stanu.
	 * @return array{id: int, login: string, email: string}
	 */
	private function account( ?AccountStatus $status = AccountStatus::Approved ): array {
		++self::$sequence;
		$login = 'lekarz' . self::$sequence;
		$email = $login . '@przyklad.test';
		$id    = (int) self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => self::PASSWORD,
			)
		);
		if ( null !== $status ) {
			AccountStatusRepository::set( $id, $status );
		}

		return array(
			'id'    => $id,
			'login' => $login,
			'email' => $email,
		);
	}

	/**
	 * Wysyła formularz logowania i zwraca znacznik wyniku z adresu przekierowania
	 * (pusty znaczy: bez znacznika, czyli logowanie się udało).
	 *
	 * @param array<string, string> $fields Pola formularza.
	 * @param bool|string           $nonce  Prawda = ważny nonce, fałsz = brak pola, łańcuch = podana wartość.
	 */
	private function submit( array $fields, bool|string $nonce = true ): string {
		$this->redirected = '';
		if ( true === $nonce ) {
			$fields[ LoginForm::NONCE_FIELD ] = wp_create_nonce( LoginForm::NONCE_ACTION );
		} elseif ( is_string( $nonce ) ) {
			$fields[ LoginForm::NONCE_FIELD ] = $nonce;
		}

		$_POST                     = wp_slash( $fields );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		LoginHandler::handle();

		return $this->resultOf( $this->redirected );
	}

	/**
	 * Pola formularza z poprawnymi danymi konta.
	 *
	 * @param array{id: int, login: string, email: string} $account  Konto.
	 * @param string                                       $password Hasło do wysłania.
	 * @return array<string, string>
	 */
	private function credentials( array $account, string $password = self::PASSWORD ): array {
		return array(
			LoginForm::IDENTITY => $account['email'],
			LoginForm::PASSWORD => $password,
		);
	}

	/**
	 * Znacznik wyniku z adresu.
	 *
	 * @param string $location Adres przekierowania.
	 */
	private function resultOf( string $location ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return isset( $query[ LoginForm::RESULT_ARG ] ) && is_string( $query[ LoginForm::RESULT_ARG ] ) ? $query[ LoginForm::RESULT_ARG ] : '';
	}

	/**
	 * Adres docelowy przeniesiony w adresie przekierowania (`''` = brak).
	 *
	 * @param string $location Adres przekierowania.
	 */
	private function redirectOf( string $location ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return isset( $query[ LoginForm::REDIRECT ] ) && is_string( $query[ LoginForm::REDIRECT ] ) ? $query[ LoginForm::REDIRECT ] : '';
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

	public function test_an_unknown_address_and_a_wrong_password_give_the_SAME_message(): void {
		// Gdyby się różniły, dwa żądania czytałyby „czy ten adres ma konto na portalu”.
		$account = $this->account();

		$unknown = $this->submit(
			array(
				LoginForm::IDENTITY => 'nikogo@przyklad.test',
				LoginForm::PASSWORD => 'cokolwiek',
			)
		);
		$wrong   = $this->submit( $this->credentials( $account, 'zupelnie-inne-haslo' ) );

		$this->assertSame( $wrong, $unknown, 'Nieznany adres i złe hasło muszą dostać ten sam kod wyniku.' );
		$this->assertSame( LoginHandler::message_for( $wrong ), LoginHandler::message_for( $unknown ), 'Komunikaty obu odmów muszą być identyczne.' );
		$this->assertNotSame( '', LoginHandler::message_for( $unknown ), 'Odmowa bez komunikatu to puste okienko błędu.' );
		$this->assertStringNotContainsString( $account['email'], LoginHandler::message_for( $wrong ) );
		$this->assertStringNotContainsString( $account['login'], LoginHandler::message_for( $wrong ) );
	}

	public function test_a_state_denial_passes_through_with_its_own_message(): void {
		// Poprawne hasło + konto pending -> komunikat o oczekiwaniu, nie komunikat ogólny.
		$account = $this->account( AccountStatus::Pending );

		$result = $this->submit( $this->credentials( $account ) );

		$this->assertSame( LoginGateway::CODE_PENDING, $result, 'Odmowa stanu musi przejść własnym kodem.' );
		// Jedna mapa komunikatów stanu: tekst przychodzi z bramy, a nie z drugiej kopii.
		$this->assertSame( LoginGateway::message_for( LoginGateway::CODE_PENDING ), LoginHandler::message_for( $result ) );
		$this->assertNotSame( LoginHandler::message_for( LoginHandler::RESULT_BAD ), LoginHandler::message_for( $result ) );

		// Odmowa stanu NIE zużywa żadnego progu: hasło było poprawne, więc nie jest to zgadywanie,
		// a zużyty próg zamieniłby lekarzowi „zgłoszenie czeka na rozpatrzenie” na „zbyt wiele prób”
		// po kilku odświeżeniach strony.
		$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_IP . self::IP ), 'Odmowa stanu zużyła próg per adres IP.' );
		$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_USER . $account['id'] ), 'Odmowa stanu zużyła próg per konto.' );
		$this->assertSame( array(), $this->limiterOptions(), 'Odmowa stanu nie tworzy żadnego licznika.' );

		// Jedno zdarzenie, jeden wiersz: ten od bramy. Drugi wiersz pod inną nazwą akcji
		// rozjechałby audyt, a brama zapisała swój, zanim żądanie tu wróciło.
		$rows = $this->logRows();
		$this->assertCount( 1, $rows, 'Odmowa stanu zostawia dokładnie jeden wiersz w logu.' );
		$this->assertSame( LoginGateway::LOG_ACTION, $rows[0]->action, 'Wiersz należy do bramy, nie do obsługi formularza.' );
		$this->assertSame( array(), array_values( array_filter( $rows, static fn ( $row ): bool => LoginHandler::LOG_ACTION === $row->action ) ), 'Odmowa bramy nie jest odmową obsługi formularza.' );
	}

	public function test_a_successful_login_does_not_consume_the_threshold(): void {
		// Przychodnia za jednym adresem wyjściowym generuje dziesiątki udanych logowań dziennie;
		// próg zużywany przez każde żądanie odciąłby ją przy normalnej pracy.
		$account  = $this->account();
		$attempts = self::IP_LIMIT + 2;

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$result = $this->submit( $this->credentials( $account ) );

			$this->assertSame( '', $result, 'Udane logowanie nr ' . $i . ' dostało odmowę.' );
			$this->assertStringContainsString( (string) get_permalink( SettingsRepository::get()->afterLoginPageId ), $this->redirected, 'Udane logowanie nr ' . $i . ' nie trafiło na stronę po zalogowaniu.' );
		}

		$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_IP . self::IP ), 'Udane logowania zużyły próg per adres IP.' );
		$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_USER . $account['id'] ), 'Udane logowania zużyły próg per konto.' );
		$this->assertSame( array(), $this->limiterOptions(), 'Udane logowanie nie tworzy żadnego licznika.' );
	}

	public function test_a_failed_login_consumes_both_the_ip_and_the_account_bucket(): void {
		$account = $this->account();

		$this->assertSame( LoginHandler::RESULT_BAD, $this->submit( $this->credentials( $account, 'zle-haslo' ) ) );

		$this->assertSame( 1, RateLimiter::count( LoginHandler::BUCKET_IP . self::IP ) );
		$this->assertSame( 1, RateLimiter::count( LoginHandler::BUCKET_USER . $account['id'] ) );
	}

	public function test_a_failed_login_for_an_unknown_address_counts_in_a_bucket_keyed_by_a_hash(): void {
		// Kubełek dla łańcucha BEZ konta musi istnieć, inaczej próg sam odpowiada na pytanie „czy
		// ten adres ma tu konto” (pilnuje tego test niżej). Klucz jest SKRÓTEM, nie wartością
		// jawną: nazwa transientu trafia do `wp_options`, a adres e-mail lekarza jest daną osobową.
		$identity = 'Nieznany@przyklad.test';

		$this->assertSame(
			LoginHandler::RESULT_BAD,
			$this->submit(
				array(
					LoginForm::IDENTITY => $identity,
					LoginForm::PASSWORD => 'cokolwiek',
				)
			)
		);

		$this->assertSame( 1, RateLimiter::count( LoginHandler::BUCKET_IP . self::IP ), 'Ruch bez konta absorbuje kubełek IP.' );
		$this->assertSame( 1, RateLimiter::count( LoginHandler::BUCKET_IDENTITY . wp_hash( strtolower( $identity ) ) ), 'Łańcuch bez konta musi mieć własny kubełek, kluczowany skrótem.' );
		foreach ( array( $identity, strtolower( $identity ) ) as $clear ) {
			$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_IDENTITY . $clear ), 'Kubełek kluczowany wartością jawną: ' . $clear );
			$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_USER . $clear ), 'Kubełek per konto kluczowany łańcuchem z żądania: ' . $clear );
		}
		// DOKŁADNIE dwa liczniki: ten per adres IP i ten per nadesłany identyfikator.
		$this->assertCount( 2, $this->limiterOptions(), 'Poza kubełkiem IP i kubełkiem identyfikatora nie wolno utworzyć żadnego licznika.' );
	}

	public function test_an_exhausted_known_address_and_an_exhausted_unknown_address_get_the_SAME_refusal(): void {
		// Sedno: próg nie może odpowiadać na pytanie „czy ten adres ma tu konto”. Kubełek tylko dla
		// kont istniejących dawał nieznanemu adresowi RESULT_BAD aż do progu per adres IP, a
		// znanemu RESULT_TOO_MANY już przy jedenastej próbie — czyli jedenaście żądań na adres
		// czytało odpowiedź, której ten plan miał nie dawać.
		$raised                         = SettingsRepository::get()->toArray();
		$raised['login_limit_per_hour'] = 1000; // Próg per IP nie może odmówić pierwszy i zatrzeć różnicy.
		SettingsRepository::save( Settings::fromArray( $raised ) );

		$known   = $this->account();
		$unknown = 'nikogo@przyklad.test';
		$blind   = array(
			LoginForm::IDENTITY => $unknown,
			LoginForm::PASSWORD => 'cokolwiek',
		);

		for ( $i = 1; $i <= self::ACCOUNT_LIMIT; $i++ ) {
			$this->assertSame( LoginHandler::RESULT_BAD, $this->submit( $this->credentials( $known, 'zle-haslo' ) ), 'Próba nr ' . $i . ' dla znanego adresu.' );
			$this->assertSame( LoginHandler::RESULT_BAD, $this->submit( $blind ), 'Próba nr ' . $i . ' dla nieznanego adresu.' );
		}

		$knownRefusal   = $this->submit( $this->credentials( $known, 'zle-haslo' ) );
		$unknownRefusal = $this->submit( $blind );

		$this->assertSame( $knownRefusal, $unknownRefusal, 'Wyczerpany próg rozróżnia adres z kontem od adresu bez konta.' );
		$this->assertSame( LoginHandler::message_for( $knownRefusal ), LoginHandler::message_for( $unknownRefusal ), 'Komunikaty obu odmów muszą być identyczne.' );
		$this->assertNotSame( '', $knownRefusal, 'Odmowa bez kodu wyniku nie jest odmową.' );
		$this->assertNotSame( '', LoginHandler::message_for( $knownRefusal ), 'Odmowa bez komunikatu to puste okienko błędu.' );
		// Zrównanie obu odpowiedzi przez ZDJĘCIE progu per konto byłoby regresją, nie naprawą.
		$this->assertNotSame( LoginHandler::RESULT_BAD, $knownRefusal, 'Po wyczerpaniu progu odmowa musi się różnić od odmowy ze złych danych.' );
	}

	public function test_the_identity_bucket_is_not_created_before_the_ip_gate_has_been_consumed(): void {
		// Ograniczenie globalne: kubełek na NIESPRAWDZONY łańcuch z żądania może powstać wyłącznie
		// za progiem per adres IP, który zużywa każde żądanie. Inaczej bot wysypałby do
		// `wp_options` dowolną liczbę transientów, po jednym na wymyślony adres.
		$identity = 'wymyslony@przyklad.test';
		for ( $i = 0; $i < self::IP_LIMIT; $i++ ) {
			RateLimiter::hit( LoginHandler::BUCKET_IP . self::IP );
		}
		$this->assertCount( 1, $this->limiterOptions(), 'Przesłanka: w bazie stoi tylko licznik per adres IP.' );

		$result = $this->submit(
			array(
				LoginForm::IDENTITY => $identity,
				LoginForm::PASSWORD => 'cokolwiek',
			)
		);

		$this->assertSame( LoginHandler::RESULT_TOO_MANY, $result );
		$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_IDENTITY . wp_hash( strtolower( $identity ) ) ), 'Kubełek identyfikatora powstał przed progiem per adres IP.' );
		$this->assertCount( 1, $this->limiterOptions(), 'Za wyczerpanym progiem per adres IP nie wolno utworzyć żadnego nowego licznika.' );
	}

	public function test_a_successful_login_clears_the_account_bucket_but_not_the_ip_bucket(): void {
		// Lekarz, który pomyli hasło kilka razy i w końcu wejdzie, nie może odejść z licznikiem
		// stojącym pod progiem: jedna wpadka w tej samej godzinie zamykałaby go na resztę okna.
		// Kubełek per adres IP zostaje — to jedyna obrona przed botem, który trafia jedno hasło na
		// wiele prób, a zerowanie go dawałoby mu świeży budżet za każde trafienie.
		$account = $this->account();
		$this->assertSame( LoginHandler::RESULT_BAD, $this->submit( $this->credentials( $account, 'zle-haslo' ) ) );
		$this->assertSame( 1, RateLimiter::count( LoginHandler::BUCKET_USER . $account['id'] ), 'Przesłanka: porażka musiała podbić kubełek konta.' );

		$this->assertSame( '', $this->submit( $this->credentials( $account ) ), 'Logowanie miało się udać.' );

		$this->assertSame( 0, RateLimiter::count( LoginHandler::BUCKET_USER . $account['id'] ), 'Udane logowanie nie wyczyściło kubełka konta.' );
		$this->assertSame( 1, RateLimiter::count( LoginHandler::BUCKET_IP . self::IP ), 'Kubełek per adres IP musi przeżyć udane logowanie.' );
	}

	public function test_a_failed_attempt_keeps_the_destination_and_a_hostile_one_still_does_not(): void {
		// `Redirects::login_url()` dokłada ten argument do KAŻDEGO rdzeniowego odnośnika logowania,
		// więc wylogowany redaktor przyniesiony przez `auth_redirect()` z `/wp-admin/edit.php` nie
		// może tracić celu przy pierwszej pomyłce w haśle i lądować na stronie po zalogowaniu.
		$account = $this->account();

		$this->assertSame( LoginHandler::RESULT_BAD, $this->submit( $this->credentials( $account, 'zle-haslo' ) + array( LoginForm::REDIRECT => '/wp-admin/edit.php' ) ) );
		$this->assertSame( '/wp-admin/edit.php', $this->redirectOf( $this->redirected ), 'Nieudana próba zgubiła adres docelowy.' );

		// Druga połowa: wartość wroga nie przeżywa odmowy tak samo jak nie przeżywa sukcesu.
		$this->assertSame( LoginHandler::RESULT_BAD, $this->submit( $this->credentials( $account, 'zle-haslo' ) + array( LoginForm::REDIRECT => 'https://zly.example/przejecie' ) ) );
		$this->assertStringNotContainsString( 'zly.example', $this->redirected );
		$this->assertSame( '', $this->redirectOf( $this->redirected ), 'Adres z obcego hosta przeżył odmowę.' );

		// Także odmowa stojąca PRZED sprawdzeniem hasła niesie cel dalej: to ta odmowa, którą
		// dostaje człowiek z kartą otwartą od doby.
		$this->assertSame( LoginHandler::RESULT_STALE, $this->submit( $this->credentials( $account ) + array( LoginForm::REDIRECT => '/wp-admin/edit.php' ), false ) );
		$this->assertSame( '/wp-admin/edit.php', $this->redirectOf( $this->redirected ), 'Odmowa z nonce\'a zgubiła adres docelowy.' );
	}

	public function test_exhausting_the_account_threshold_does_not_block_another_account_from_the_same_address(): void {
		$one = $this->account();
		$two = $this->account();
		for ( $i = 0; $i < self::ACCOUNT_LIMIT; $i++ ) {
			RateLimiter::hit( LoginHandler::BUCKET_USER . $one['id'] );
		}

		$this->assertSame( LoginHandler::RESULT_TOO_MANY, $this->submit( $this->credentials( $one ) ), 'Konto z wyczerpanym progiem musi dostać odmowę.' );
		$this->assertSame( '', $this->submit( $this->credentials( $two ) ), 'Próg jednego konta zamknął drugie konto z tego samego adresu.' );
	}

	public function test_exhausting_the_ip_threshold_refuses_before_the_password_is_checked(): void {
		// Próg stoi PRZED wp_signon(), inaczej nie blokuje niczego.
		$account = $this->account();
		for ( $i = 0; $i < self::IP_LIMIT; $i++ ) {
			RateLimiter::hit( LoginHandler::BUCKET_IP . self::IP );
		}
		$this->passwordChecks = 0;

		$result = $this->submit( $this->credentials( $account ) );

		$this->assertSame( LoginHandler::RESULT_TOO_MANY, $result );
		$this->assertSame( 0, $this->passwordChecks, 'Przy wyczerpanym progu hasło nie może być sprawdzane.' );
	}

	public function test_a_filled_honeypot_is_refused_without_checking_the_password(): void {
		$account              = $this->account();
		$this->passwordChecks = 0;

		$result = $this->submit( $this->credentials( $account ) + array( LoginForm::HONEYPOT => 'bot tu byl' ) );

		$this->assertSame( LoginHandler::RESULT_BAD, $result, 'Wypełniona pułapka to odmowa, i to ogólna.' );
		$this->assertSame( 0, $this->passwordChecks, 'Pułapka stoi przed wp_signon().' );
	}

	public function test_an_extension_can_refuse_through_the_spam_filter_and_never_sees_the_password(): void {
		$account = $this->account();
		$seen    = array();
		add_filter(
			LoginHandler::SPAM_FILTER,
			static function ( $passed, $request ) use ( &$seen ) {
				$seen = (array) $request;
				return false;
			},
			10,
			2
		);
		$this->passwordChecks = 0;

		$result = $this->submit( $this->credentials( $account ) );

		$this->assertSame( LoginHandler::RESULT_BAD, $result );
		$this->assertSame( 0, $this->passwordChecks, 'Odmowa rozszerzenia stoi przed wp_signon().' );
		// Rozszerzenie sprawdza pole captchy, nie poświadczenia, a typowo wysyła to, co dostanie,
		// do usługi zewnętrznej.
		$this->assertArrayNotHasKey( LoginForm::PASSWORD, $seen, 'Hasło nie może trafić do rozszerzenia.' );
		// Nonce jest żetonem, nie danymi, których potrzebuje reguła antyspamowa.
		$this->assertArrayNotHasKey( LoginForm::NONCE_FIELD, $seen, 'Nonce nie może trafić do rozszerzenia.' );
		$this->assertNotSame( array(), $seen, 'Filtr musi dostać dane żądania.' );
		$this->assertArrayHasKey( LoginForm::IDENTITY, $seen, 'Identyfikator zostaje: reguła antyspamowa może go potrzebować.' );
		$this->assertStringNotContainsString( self::PASSWORD, implode( '|', array_map( 'strval', $seen ) ), 'Hasło w danych podanych rozszerzeniu.' );
	}

	public function test_a_missing_or_stale_nonce_gives_the_stale_result(): void {
		$account              = $this->account();
		$this->passwordChecks = 0;

		$this->assertSame( LoginHandler::RESULT_STALE, $this->submit( $this->credentials( $account ), false ), 'Brak nonce\'a.' );
		$this->assertSame( LoginHandler::RESULT_STALE, $this->submit( $this->credentials( $account ), 'przedawniony' ), 'Nonce o złej wartości.' );
		$this->assertSame( 0, $this->passwordChecks, 'Nonce stoi przed wp_signon().' );
		$this->assertSame( array(), $this->limiterOptions(), 'Odmowa nonce\'a nie zużywa progu.' );
	}

	public function test_a_get_request_is_refused(): void {
		$account                   = $this->account();
		$_POST                     = wp_slash( $this->credentials( $account ) + array( LoginForm::NONCE_FIELD => wp_create_nonce( LoginForm::NONCE_ACTION ) ) );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->passwordChecks      = 0;

		try {
			LoginHandler::handle();
			$this->fail( 'Żądanie GET miało zostać odrzucone.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'POST', $e->getMessage() );
		}

		$this->assertSame( 0, $this->passwordChecks, 'Żądanie GET nie może dojść do sprawdzenia hasła.' );
	}

	public function test_the_redirect_after_a_successful_login_never_leaves_the_site(): void {
		// Pole formularza to dane z żądania dokładnie tak samo jak argument adresu.
		$account = $this->account();

		$result = $this->submit( $this->credentials( $account ) + array( LoginForm::REDIRECT => 'https://zly.example/przejecie' ) );

		$this->assertSame( '', $result, 'Logowanie miało się udać.' );
		$this->assertStringNotContainsString( 'zly.example', $this->redirected );
		$this->assertSame( wp_parse_url( home_url( '/' ), PHP_URL_HOST ), wp_parse_url( $this->redirected, PHP_URL_HOST ), 'Przekierowanie wyszło poza witrynę.' );

		// Adres z tej samej witryny przeżywa: bramka nie wycina wszystkiego.
		$other = $this->account();
		$this->assertSame( '', $this->submit( $this->credentials( $other ) + array( LoginForm::REDIRECT => '/artykul/' ) ) );
		$this->assertStringContainsString( '/artykul/', $this->redirected );
	}

	public function test_a_refusal_writes_one_log_entry_with_no_password_in_it(): void {
		// W logu nie wolno zapisać hasła ani jego skrótu; adres e-mail wyłącznie skrótem.
		$account  = $this->account();
		$password = 'Rozpoznawalne-Zle-Haslo-123';

		$this->assertSame( LoginHandler::RESULT_BAD, $this->submit( $this->credentials( $account, $password ) ) );

		$rows = $this->logRows();
		$this->assertCount( 1, $rows, 'Odmowa zostawia dokładnie jeden wiersz w logu.' );
		$this->assertSame( LoginHandler::LOG_ACTION, $rows[0]->action );
		$this->assertSame( $account['id'], (int) $rows[0]->subject_user_id );
		$this->assertSame( 0, (int) $rows[0]->actor_user_id, 'Żądanie logowania przychodzi od nikogo.' );

		$row = implode( '|', array_map( 'strval', (array) $rows[0] ) );
		$this->assertStringNotContainsString( $password, $row, 'Hasło jawne w logu.' );
		foreach ( array( wp_hash( $password ), md5( $password ), sha1( $password ), hash( 'sha256', $password ) ) as $digest ) {
			$this->assertStringNotContainsString( $digest, $row, 'Skrót hasła w logu.' );
		}
		$this->assertStringNotContainsString( $account['email'], $row, 'Adres e-mail jawny w logu.' );
		$this->assertStringContainsString( wp_hash( strtolower( $account['email'] ) ), $row, 'Adres e-mail musi trafić do logu skrótem, jak w cronie czyszczącym.' );
	}

	public function test_a_password_with_characters_sanitisation_would_eat_still_logs_in(): void {
		// Hasła NIE sanityzujemy: `sanitize_text_field()` zjadłoby znaki i hasło przestałoby się zgadzać.
		$account = $this->account();
		$this->assertNotSame( self::PASSWORD, sanitize_text_field( self::PASSWORD ), 'Hasło testowe musi mieć znaki, które sanityzacja zmienia.' );

		$this->assertSame( '', $this->submit( $this->credentials( $account ) ), 'Hasło ze znakami specjalnymi nie przeszło.' );
	}

	public function test_the_login_page_shows_the_message_for_the_result_in_the_url(): void {
		// Odczyt wyniku z adresu nie wymaga nonce'a: pokazanie komunikatu nic nie zmienia.
		$_GET[ LoginForm::RESULT_ARG ] = LoginHandler::RESULT_BAD;
		$html                          = LoginForm::render();
		// Pusty komunikat zawiera się w KAŻDYM łańcuchu, więc bez tej asercji sprawdzenie niżej
		// przechodziłoby samo z siebie, gdyby tekst kiedyś zniknął z mapy.
		$this->assertNotSame( '', LoginHandler::message_for( LoginHandler::RESULT_BAD ) );
		$this->assertStringContainsString( esc_html( LoginHandler::message_for( LoginHandler::RESULT_BAD ) ), $html );
		$this->assertStringContainsString( '<form', $html, 'Komunikat nie może zabrać formularza.' );

		// Odmowa stanu przechodzi na stronę WŁASNĄ treścią z mapy bramy.
		$_GET[ LoginForm::RESULT_ARG ] = LoginGateway::CODE_PENDING;
		$this->assertNotSame( '', LoginGateway::message_for( LoginGateway::CODE_PENDING ) );
		$this->assertStringContainsString( esc_html( LoginGateway::message_for( LoginGateway::CODE_PENDING ) ), LoginForm::render() );

		// Kod, którego wtyczka nie zna, nie rysuje pustego okienka alertu.
		$_GET[ LoginForm::RESULT_ARG ] = 'wymyslony-kod';
		$this->assertStringNotContainsString( 'pusers-notice', LoginForm::render() );

		unset( $_GET[ LoginForm::RESULT_ARG ] );
		$this->assertStringNotContainsString( 'pusers-notice', LoginForm::render(), 'Bez znacznika w adresie strona nie pokazuje komunikatu.' );
	}

	public function test_the_form_posts_to_the_action_owned_by_the_handler(): void {
		// Nazwa akcji ma JEDNO źródło: obsługę żądania. Druga stała w widoku rozjechałaby się z nią.
		$this->assertFalse( defined( LoginForm::class . '::ACTION' ), 'Stała z nazwą akcji w widoku to drugie źródło prawdy.' );
		$this->assertStringContainsString( 'value="' . esc_attr( LoginHandler::ACTION ) . '"', LoginForm::render() );
	}

	public function test_a_logged_in_sender_gets_the_stale_result_because_the_nonce_changes_with_the_user(): void {
		// To jest DOKŁADNA wartość drugiego hooka: nadawca z otwartej karty albo z powrotu
		// z historii przychodzi z nonce'em wybitym jako NIEZALOGOWANY, a ten po zalogowaniu się
		// nie weryfikuje (`wp_verify_nonce()` liczy z identyfikatorem użytkownika). Żądanie jest
		// więc obsłużone i odesłane z „odśwież stronę”, a nie zignorowane z pustą odpowiedzią.
		// Widok dla zalogowanego nonce'a nie wystawia wcale, więc innej drogi tu nie ma.
		$account              = $this->account();
		$nonce                = wp_create_nonce( LoginForm::NONCE_ACTION );
		$this->passwordChecks = 0;
		wp_set_current_user( $account['id'] );

		$this->assertSame( LoginHandler::RESULT_STALE, $this->submit( $this->credentials( $account ), $nonce ) );
		$this->assertSame( 0, $this->passwordChecks, 'Przedawniony nonce stoi przed wp_signon().' );
		$this->assertSame( array(), $this->limiterOptions(), 'Odmowa nonce\'a nie zużywa progu.' );
	}

	public function test_the_handler_is_registered_for_logged_out_and_logged_in_senders(): void {
		// Zalogowany też wyśle ten formularz: otwarta karta, powrót z historii.
		LoginHandler::register();

		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . LoginHandler::ACTION, array( LoginHandler::class, 'handle' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . LoginHandler::ACTION, array( LoginHandler::class, 'handle' ) ) );
		// Obsługa formularza nie jest zamkiem, więc rejestruje się pod hookiem zależności, jak widok.
		$this->assertNotFalse( has_action( 'pusers_register_features', array( LoginHandler::class, 'register' ) ) );
	}
}
