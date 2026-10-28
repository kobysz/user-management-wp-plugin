<?php
/**
 * Testy bramy logowania podpiętej za sprawdzeniem hasła.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Front\LoginGateway;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\UserMeta;
use ReflectionClass;
use WP_Application_Passwords;
use WP_Error;
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
			$this->assertSame( array( 'code' => $seen[ $index ] ), json_decode( (string) $row->context, true ) );
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
			$this->assertSame( array( 'code' => LoginGateway::CODE_UNRECOGNISED ), json_decode( (string) $row->context, true ) );
		}
	}

	public function test_the_gate_does_not_touch_a_core_error(): void {
		// Na priorytecie 30 $user bywa WP_Error ze złym hasłem; brama oddaje go BEZ ZMIAN
		// i NIE pisze do logu, bo to nie jej odmowa.
		$error = new WP_Error( 'incorrect_password', 'Podane hasło jest nieprawidłowe.' );

		$this->assertSame( $error, LoginGateway::gate( $error ), 'Brama oddała inny obiekt niż błąd rdzenia.' );
		$this->assertNull( LoginGateway::gate( null ), 'Brak kandydata na użytkownika brama oddaje bez zmian.' );
		$this->assertSame( array(), $this->logRows(), 'Brama zapisała do logu odmowę, która nie jest jej odmową.' );
	}

	public function test_the_gate_is_unreachable_without_the_correct_password(): void {
		// Sedno projektu: brama siedzi na `authenticate` na priorytecie 30, czyli ZA sprawdzeniem
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
		$this->assertSame( array( 'code' => LoginGateway::CODE_PENDING ), json_decode( (string) $rows[0]->context, true ) );
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
