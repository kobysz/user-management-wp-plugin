<?php
/**
 * Testy bramy logowania podpiętej za sprawdzeniem hasła.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\LoginGateway;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use ReflectionClass;
use WP_Application_Passwords;
use WP_Error;
use WP_Session_Tokens;
use WP_UnitTestCase;
use WP_User;

final class LoginGatewayTest extends WP_UnitTestCase {

	/**
	 * Hasło konta testowego; długie i z nieoczywistymi znakami, żeby żadne „zgadnięcie” nie trafiło.
	 */
	private const PASSWORD = 'Dlug!e\\Haslo"12';

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		$this->sequence = 0;
		// Podpięcie jest idempotentne (ten sam callback i ten sam priorytet), więc wołamy je
		// niezależnie od tego, czy wtyczka zarejestrowała bramę już przy starcie suity.
		LoginGateway::register();
	}

	public function tear_down(): void {
		// Nagłówki uwierzytelnienia hasłem aplikacyjnym nie mogą przeciekać do innych testów.
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		// Ciasteczko uwierzytelnienia też nie: kolejny test zaczynałby z cudzą, ważną sesją.
		unset( $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ], $_COOKIE[ LOGGED_IN_COOKIE ] );
		parent::tear_down();
	}

	/**
	 * Tworzy konto o znanym haśle i zwraca jego identyfikator oraz login.
	 *
	 * @param AccountStatus|null $status Stan konta albo null, gdy konto ma zostać bez stanu.
	 * @return array{id: int, login: string}
	 */
	private function account( ?AccountStatus $status ): array {
		++$this->sequence;
		$login = 'lekarz' . $this->sequence;
		$id    = self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $login . '@przyklad.test',
				'user_pass'  => self::PASSWORD,
			)
		);
		if ( null !== $status ) {
			AccountStatusRepository::set( $id, $status );
		}

		return array(
			'id'    => $id,
			'login' => $login,
		);
	}

	/**
	 * Wiersze logu zdarzeń, od najstarszego.
	 *
	 * @return list<object>
	 */
	private function logRows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_an_approved_account_logs_in(): void {
		$account = $this->account( AccountStatus::Approved );

		$user = wp_authenticate( $account['login'], self::PASSWORD );

		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $account['id'], $user->ID );
		$this->assertSame( array(), $this->logRows(), 'Wpuszczenie konta nie jest odmową, więc nie ma czego zapisywać w logu.' );
	}

	public function test_an_account_with_no_status_logs_in(): void {
		// Konto dodane ręcznie w panelu; odmowa zamknęłaby właściciela portalu poza portalem.
		$account = $this->account( null );
		$this->assertSame( '', get_user_meta( $account['id'], UserMeta::STATUS, true ), 'Konto testowe musi być bez metadanej stanu.' );

		$user = wp_authenticate( $account['login'], self::PASSWORD );

		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $account['id'], $user->ID );
	}

	public function test_each_state_before_approval_gets_its_own_error_code(): void {
		$expected = array(
			'email_unconfirmed' => array( AccountStatus::EmailUnconfirmed, LoginGateway::CODE_UNCONFIRMED ),
			'pending'           => array( AccountStatus::Pending, LoginGateway::CODE_PENDING ),
			'rejected'          => array( AccountStatus::Rejected, LoginGateway::CODE_REJECTED ),
		);

		$seen = array();
		foreach ( $expected as $label => $pair ) {
			list( $status, $code ) = $pair;
			$account               = $this->account( $status );

			$result = wp_authenticate( $account['login'], self::PASSWORD );

			$this->assertInstanceOf( WP_Error::class, $result, 'Stan ' . $label . ' musi dostać odmowę.' );
			$this->assertSame( $code, $result->get_error_code(), 'Zły kod odmowy dla stanu ' . $label . '.' );
			$this->assertSame( LoginGateway::message_for( $code ), $result->get_error_message(), 'Odmowa dla stanu ' . $label . ' nie niesie komunikatu z mapy.' );
			$seen[] = $code;
		}

		// Każdy stan ma WŁASNY komunikat, żeby użytkownik wiedział, czy ma szukać maila.
		$this->assertSame( $seen, array_unique( $seen ), 'Dwa stany przed zatwierdzeniem dzielą jeden kod odmowy.' );

		$rows = $this->logRows();
		$this->assertCount( 3, $rows, 'Każda odmowa bramy zostawia wpis w logu zdarzeń.' );
		foreach ( $rows as $index => $row ) {
			$this->assertSame( LoginGateway::LOG_ACTION, $row->action );
			$this->assertSame(
				array(
					'code'    => $seen[ $index ],
					'channel' => LoginGateway::CHANNEL_LOGIN,
				),
				json_decode( (string) $row->context, true )
			);
		}
	}

	public function test_an_unrecognised_stored_status_is_refused_and_logged(): void {
		// Metadana po ręcznej edycji bazy: `isUnrecognised()` daje prawdę, a przekazanie wyniku
		// `get()` (null) do `LoginGate::decide()` WPUSZCZA — czyli fail-open.
		foreach ( array( 'Approved', ' pending', 'cokolwiek' ) as $raw ) {
			$account = $this->account( null );
			update_user_meta( $account['id'], UserMeta::STATUS, $raw );

			$this->assertTrue( AccountStatusRepository::isUnrecognised( $account['id'] ), 'Wartość ' . $raw . ' miała być nierozpoznana.' );
			$this->assertNull( AccountStatusRepository::get( $account['id'] ), 'Wartość ' . $raw . ' miała czytać się jako brak stanu.' );

			$result = wp_authenticate( $account['login'], self::PASSWORD );

			$this->assertInstanceOf( WP_Error::class, $result, 'Wartość ' . $raw . ' musi dostać odmowę, nie wejście.' );
			$this->assertSame( LoginGateway::CODE_UNRECOGNISED, $result->get_error_code() );
			$message = $result->get_error_message();
			$this->assertSame( LoginGateway::message_for( LoginGateway::CODE_UNRECOGNISED ), $message );
			// Komunikat nie mówi o uszkodzonej metadanej: przyczynę widzi redakcja w logu zdarzeń.
			$this->assertStringNotContainsString( $raw, $message );
			$this->assertStringNotContainsStringIgnoringCase( UserMeta::STATUS, $message );
		}

		$rows = $this->logRows();
		$this->assertCount( 3, $rows, 'Każda odmowa z nierozpoznanym stanem zostawia wpis w logu.' );
		foreach ( $rows as $row ) {
			$this->assertSame( LoginGateway::LOG_ACTION, $row->action );
			$this->assertSame(
				array(
					'code'    => LoginGateway::CODE_UNRECOGNISED,
					'channel' => LoginGateway::CHANNEL_LOGIN,
				),
				json_decode( (string) $row->context, true )
			);
		}
	}

	public function test_the_gate_does_not_touch_a_core_error(): void {
		// Na priorytecie 40 $user bywa WP_Error ze złym hasłem; brama oddaje go BEZ ZMIAN
		// i NIE pisze do logu, bo to nie jej odmowa.
		$error = new WP_Error( 'incorrect_password', 'Podane hasło jest nieprawidłowe.' );

		$this->assertSame( $error, LoginGateway::gate( $error ), 'Brama oddała inny obiekt niż błąd rdzenia.' );
		$this->assertNull( LoginGateway::gate( null ), 'Brak kandydata na użytkownika brama oddaje bez zmian.' );
		$this->assertSame( array(), $this->logRows(), 'Brama zapisała do logu odmowę, która nie jest jej odmową.' );
	}

	public function test_the_gate_is_unreachable_without_the_correct_password(): void {
		// Sedno projektu: brama siedzi na `authenticate` na priorytecie 40, czyli ZA sprawdzeniem
		// hasła przez rdzeń (rdzeń trzyma swoje weryfikatory na 20). Kto hasła nie zna, do bramy
		// nie dochodzi, więc stan konta nie wycieka do nikogo, kto zna sam adres. Gdyby brama
		// siedziała na filtrze `wp_authenticate_user` (odpala się PRZED `wp_check_password()`),
		// ten test padłby — i o to w nim chodzi.
		$account = $this->account( AccountStatus::EmailUnconfirmed );

		$result = wp_authenticate( $account['login'], 'zle-haslo' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'incorrect_password', $result->get_error_code(), 'Odmowa ze złym hasłem musi być odmową RDZENIA, nie bramy.' );
		$this->assertNotSame( LoginGateway::CODE_UNCONFIRMED, $result->get_error_code() );
		$this->assertStringNotContainsString(
			LoginGateway::message_for( LoginGateway::CODE_UNCONFIRMED ),
			$result->get_error_message(),
			'Komunikat dla złego hasła zdradził stan konta.'
		);
		$this->assertSame( array(), $this->logRows(), 'Brama zapisała odmowę, choć w ogóle nie została osiągnięta.' );
	}

	public function test_an_unapproved_account_holding_a_valid_cookie_cannot_renew_its_session_with_an_empty_form(): void {
		// `wp_signon()` dokłada PIĄTY callback na filtr `authenticate`, z wnętrza swojego ciała:
		// `wp-includes/user.php:107` trzyma tam `wp_authenticate_cookie` na priorytecie 30. Jego
		// ciało wychodzi wcześnie TYLKO dla `WP_User`, a przy pustym loginie I pustym haśle woła
		// `wp_validate_auth_cookie()` i oddaje `new WP_User( $id )`. Brama rejestrowana przy
		// ładowaniu wtyczki idzie WEWNĄTRZ priorytetu 30 pierwsza, więc na 30 widziałaby wyłącznie
		// rdzeniowy `empty_username`, oddawałaby go nietkniętego — a uwierzytelniacz ciasteczka
		// podnosiłby ten błąd do `WP_User`, którego brama już nie ogląda, i `wp_signon()`
		// wystawiałby na niego świeże ciasteczko, z „zapamiętaj mnie” włącznie. `wp-login.php`
		// specyfikacja zostawia otwarte dla redakcji, a rdzeniowa akcja logowania nonce'a nie ma,
		// więc konto BEZ zatwierdzenia odnawiałoby sesję bez końca pustym POST-em. Dlatego brama
		// stoi na 40: za weryfikatorami hasła (20), za ciasteczkiem (30) i przed
		// `wp_authenticate_spam_check` (99). Na 30 ten test PADA.
		$account = $this->account( AccountStatus::Pending );
		add_filter( 'send_auth_cookies', '__return_false' );

		$token                  = WP_Session_Tokens::get_instance( $account['id'] )->create( time() + DAY_IN_SECONDS );
		$_COOKIE[ AUTH_COOKIE ] = wp_generate_auth_cookie( $account['id'], time() + DAY_IN_SECONDS, 'auth', $token );
		// Przesłanka testu: ciasteczko jest NAPRAWDĘ ważne. Bez tej asercji test przechodziłby
		// także wtedy, gdyby ciasteczko było martwe — czyli z całkiem innego powodu niż priorytet.
		$this->assertSame( $account['id'], wp_validate_auth_cookie(), 'Konto testowe musi mieć ważne ciasteczko sesji.' );

		$signed = wp_signon(
			array(
				'user_login'    => '',
				'user_password' => '',
				'remember'      => true,
			)
		);

		remove_filter( 'send_auth_cookies', '__return_false' );

		$this->assertInstanceOf( WP_Error::class, $signed, 'Puste pola plus ważne ciasteczko wystawiły świeżą sesję kontu bez zatwierdzenia.' );
		$this->assertNotInstanceOf( WP_User::class, $signed );
		$this->assertSame( LoginGateway::CODE_PENDING, $signed->get_error_code(), 'Odmowa musi pochodzić z bramy, nie z rdzenia.' );
		$rows = $this->logRows();
		$this->assertCount( 1, $rows, 'Odmowa bramy zostawia wpis w logu zdarzeń.' );
		$this->assertSame( LoginGateway::LOG_ACTION, $rows[0]->action );
	}

	public function test_an_unapproved_account_is_refused_rest_even_with_a_valid_application_password(): void {
		// Filtr `authenticate` NIE pokrywa REST: `wp_validate_application_password()` siedzi na
		// `determine_current_user` i woła `wp_authenticate_application_password()` wprost, bez
		// `apply_filters( 'authenticate' )`. Hasło aplikacyjne jest przy tym poświadczeniem
		// TRWAŁYM, więc konto odrzucone po jego wystawieniu czytałoby REST bez końca.
		$account = $this->account( AccountStatus::Pending );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$created = WP_Application_Passwords::create_new_application_password( $account['id'], array( 'name' => 'klient-rest' ) );
		$this->assertIsArray( $created );

		$_SERVER['PHP_AUTH_USER'] = $account['login'];
		$_SERVER['PHP_AUTH_PW']   = (string) $created[0];

		// Hasło aplikacyjne jest POPRAWNE: rdzeń ustala z niego konto...
		$determined = apply_filters( 'determine_current_user', false );
		$this->assertSame( $account['id'], $determined, 'Hasło aplikacyjne miało uwierzytelnić konto przez determine_current_user.' );
		// ...i do tego momentu brama z `authenticate` nie odezwała się ani razu.
		$this->assertSame( array(), $this->logRows(), 'Brama z filtra authenticate w ogóle nie bierze udziału w tej ścieżce.' );

		wp_set_current_user( (int) $determined );
		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertInstanceOf( WP_Error::class, $result, 'Konto `pending` z ważnym hasłem aplikacyjnym musi dostać odmowę na REST.' );
		$this->assertSame( LoginGateway::CODE_PENDING, $result->get_error_code() );
		$this->assertSame( LoginGateway::message_for( LoginGateway::CODE_PENDING ), $result->get_error_message() );
		// Bez statusu w danych błędu REST odpowiedziałby 500 zamiast 403.
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] ?? 0 );

		$rows = $this->logRows();
		$this->assertCount( 1, $rows, 'Odmowa na REST zostawia wpis w logu zdarzeń.' );
		$this->assertSame( LoginGateway::LOG_ACTION, $rows[0]->action );
		// Obie drogi piszą tę samą akcję, więc wiersz musi NAZWAĆ swoją drogę — inaczej redakcja
		// nie odróżni odmowy z formularza logowania od odmowy na REST.
		$this->assertNotSame( LoginGateway::CHANNEL_LOGIN, LoginGateway::CHANNEL_REST, 'Dwie drogi muszą mieć dwie różne nazwy.' );
		$this->assertSame(
			array(
				'code'    => LoginGateway::CODE_PENDING,
				'channel' => LoginGateway::CHANNEL_REST,
			),
			json_decode( (string) $rows[0]->context, true )
		);
	}

	public function test_an_approved_account_passes_rest_authentication(): void {
		$account = $this->account( AccountStatus::Approved );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$created = WP_Application_Passwords::create_new_application_password( $account['id'], array( 'name' => 'klient-rest' ) );
		$this->assertIsArray( $created );

		$_SERVER['PHP_AUTH_USER'] = $account['login'];
		$_SERVER['PHP_AUTH_PW']   = (string) $created[0];

		$determined = apply_filters( 'determine_current_user', false );
		$this->assertSame( $account['id'], $determined );

		wp_set_current_user( (int) $determined );

		$result = apply_filters( 'rest_authentication_errors', null );
		$this->assertNotInstanceOf( WP_Error::class, $result, 'Konto zatwierdzone musi dalej wchodzić na REST.' );
		// Rdzeń oddaje dla uwierzytelnionego hasła aplikacyjnego `true`
		// (`rest_application_password_check_errors`), a brama przepuszcza tę wartość bez zmian.
		$this->assertTrue( $result );
		$this->assertSame( array(), $this->logRows(), 'Wpuszczenie nie jest odmową, więc nie ma czego zapisywać w logu.' );
	}

	public function test_the_rest_gate_does_not_touch_an_anonymous_request_or_a_core_error(): void {
		$account = $this->account( AccountStatus::Pending );

		wp_set_current_user( 0 );
		// Anonim: o dostępie decydują uprawnienia trasy, nie stan konta. Oba wejścia rdzenia
		// (null — nikt nie sprawdzał, true — rdzeń nie ma zastrzeżeń) wracają bez zmian.
		$this->assertNull( LoginGateway::gate_rest( null ) );
		$this->assertTrue( LoginGateway::gate_rest( true ) );

		// Gotowa odmowa rdzenia (np. zły nonce ciasteczka) wraca bez zmian i bez wpisu do logu,
		// bo to nie nasza odmowa — mimo że konto jest niezatwierdzone.
		wp_set_current_user( $account['id'] );
		$coreError = new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed.' );
		$this->assertSame( $coreError, LoginGateway::gate_rest( $coreError ), 'Brama REST oddała inny obiekt niż błąd rdzenia.' );

		$this->assertSame( array(), $this->logRows(), 'Brama REST zapisała do logu odmowę, która nie jest jej odmową.' );
	}

	public function test_the_rest_refusal_log_has_a_budget_and_reports_its_exhaustion_exactly_once(): void {
		// Konto odrzucone zachowuje hasło aplikacyjne świadomie (jego użycie jest odmawiane, więc
		// poświadczenie jest martwe), a zalogowany subskrybent może je sobie wystawić przez
		// `/wp/v2/users/me/application-passwords`. Każde odmówione żądanie pisało wiersz do tabeli,
		// której nic nie przycina — bez końca, bo poświadczenia nic nie wygasza. Budżet przycina
		// ZAPIS, nie dostęp: odmowa przychodzi dalej na każde żądanie.
		$account                        = $this->account( AccountStatus::Rejected );
		$budget                         = 3;
		$raised                         = SettingsRepository::get()->toArray();
		$raised['login_limit_per_hour'] = $budget;
		SettingsRepository::save( Settings::fromArray( $raised ) );
		wp_set_current_user( $account['id'] );

		$requests = $budget + 4;
		for ( $i = 1; $i <= $requests; $i++ ) {
			$this->assertInstanceOf( WP_Error::class, apply_filters( 'rest_authentication_errors', null ), 'Żądanie nr ' . $i . ' musi dostać odmowę niezależnie od budżetu wierszy.' );
		}

		$rows = $this->logRows();
		$this->assertCount( $budget + 1, $rows, 'Wiersze: budżet plus DOKŁADNIE jedno zgłoszenie wyczerpania na okno licznika.' );
		$this->assertLessThan( $requests, count( $rows ), 'Budżet nie przyciął niczego.' );

		$overflow = json_decode( (string) $rows[ $budget ]->context, true );
		$this->assertIsArray( $overflow );
		$this->assertSame( 'log_limit', $overflow['refused'] ?? null, 'Ostatni wiersz ma mówić o wyczerpanym budżecie.' );
		$this->assertSame( LoginGateway::CHANNEL_REST, $overflow['channel'] ?? null );
	}

	public function test_every_code_has_a_message_and_no_message_is_orphaned(): void {
		$codes = array();
		foreach ( ( new ReflectionClass( LoginGateway::class ) )->getConstants() as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'CODE_' ) ) {
				$codes[ (string) $name ] = (string) $value;
			}
		}
		$this->assertCount( 4, $codes, 'Brama ma dokładnie cztery kody odmowy.' );

		$messages = array();
		foreach ( $codes as $name => $code ) {
			$message = LoginGateway::message_for( $code );
			$this->assertNotSame( '', $message, 'Kod ' . $name . ' nie ma komunikatu.' );
			$messages[ $code ] = $message;
		}
		$this->assertCount( 4, array_unique( $messages ), 'Dwa kody dzielą jeden komunikat.' );

		// Nic ponad cztery kody: mapa komunikatów nie jest workiem z komunikatem domyślnym,
		// bo taki worek odpowiadałby na kod, którego wśród stałych CODE_* nie ma.
		$this->assertSame( '', LoginGateway::message_for( 'pusers_kod_ktorego_nie_ma' ), 'Mapa komunikatów odpowiada na kod, którego nie ma wśród stałych CODE_*.' );
	}
}
