<?php
/**
 * Testy ustawienia hasła z linku potwierdzającego: bilet, strona, reguły hasła i próg prób.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Account\PasswordPolicy;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Domain\Spam\SpamVerdict;
use PortalUsers\Front\Confirmation;
use PortalUsers\Front\PasswordSetup;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Front\SpamGuard;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class PasswordSetupTest extends WP_UnitTestCase {

	/**
	 * Hasło spełniające wszystkie reguły, ze znakami, które sanityzacja tekstu by zjadła.
	 */
	private const GOOD_PASSWORD = 'Dlug!e\\Haslo"12';

	private string $redirected = '';
	private int $registerPage  = 0;
	private int $sequence      = 0;
	private int $noCacheFired  = 0;

	/**
	 * Ciasteczka przechwycone przez szew wysyłania.
	 *
	 * @var list<array{value: string, options: array<string, mixed>}>
	 */
	private array $cookies = array();

	/**
	 * @var callable
	 */
	private $cancelRedirect;

	private string $originalMethod = 'GET';
	private string $originalAddr   = '';

	public function set_up(): void {
		parent::set_up();
		$this->redirected     = '';
		$this->cookies        = array();
		$this->noCacheFired   = 0;
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->originalAddr   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		$_SERVER['REMOTE_ADDR'] = '10.0.0.11';
		$this->cancelRedirect   = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
		add_filter( 'pre_wp_mail', static fn (): bool => true, 10, 2 );
		add_filter(
			PasswordSetup::COOKIE_FILTER,
			function ( $send, $name, $value, $options ) {
				$this->cookies[] = array(
					'value'   => (string) $value,
					'options' => (array) $options,
				);
				return false;
			},
			10,
			4
		);

		$this->registerPage = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Rejestracja',
				'post_content' => '[' . RegistrationForm::SHORTCODE . ']',
			)
		);
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false', 'key' => 'field_8f20b5d1ac' ),
			)
		);
		$this->configure( true );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array( array( 'key' => 'lekarz', 'label' => 'Lekarz' ) )
			)
		);
		FieldMatrixRepository::save(
			FieldMatrix::fromArray( array( 'lekarz' => array( 'zgoda' => 'required' ) ) )
		);
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_all_filters( PasswordSetup::COOKIE_FILTER );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_actions( 'litespeed_control_set_nocache' );
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_COOKIE                   = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		$_SERVER['REMOTE_ADDR']    = $this->originalAddr;
		parent::tear_down();
	}

	/**
	 * @param bool                 $approval Czy akceptacja jest włączona.
	 * @param array<string, mixed> $over     Pozostałe ustawienia.
	 */
	private function configure( bool $approval, array $over = array() ): void {
		SettingsRepository::save(
			Settings::fromArray(
				array_merge(
					array(
						'approval_enabled'  => $approval,
						'notify_emails'     => array( 'redakcja@example.test' ),
						'register_page_id'  => $this->registerPage,
						'acf_group_key'     => 'group_abc',
						'time_trap_seconds' => 3,
					),
					$over
				)
			)
		);
	}

	/**
	 * Konto z niepotwierdzonym adresem i hasłem losowym, jak po rejestracji.
	 */
	private function account(): int {
		$id = (int) self::factory()->user->create(
			array(
				'user_email' => 'osoba' . ++$this->sequence . '@example.test',
				'user_pass'  => wp_generate_password( 64, true, true ),
			)
		);
		update_user_meta( $id, UserMeta::PROFESSION, 'lekarz' );
		AccountStatusRepository::set( $id, AccountStatus::EmailUnconfirmed );
		return $id;
	}

	/**
	 * Przechodzi pełną ścieżką pierwszego kliknięcia linku potwierdzającego.
	 *
	 * @return array{0: int, 1: string} Identyfikator konta i wartość ciasteczka z biletem.
	 */
	private function confirmed(): array {
		$id    = $this->account();
		$token = Confirmation::issue( $id );

		$this->redirected = '';
		$this->cookies    = array();
		$_GET             = array(
			Confirmation::QUERY_USER  => (string) $id,
			Confirmation::QUERY_TOKEN => $token,
		);
		Confirmation::handle();
		$_GET = array();

		return array( $id, $this->lastCookie() );
	}

	/**
	 * Wartość ostatnio wysłanego niepustego ciasteczka z biletem.
	 */
	private function lastCookie(): string {
		$value = '';
		foreach ( $this->cookies as $sent ) {
			if ( '' !== $sent['value'] ) {
				$value = $sent['value'];
			}
		}
		return $value;
	}

	/**
	 * Wysyła formularz ustawienia hasła i zwraca znacznik wyniku z adresu przekierowania.
	 *
	 * @param string $password Hasło z formularza.
	 * @param string $cookie   Wartość ciasteczka z biletem (puste = brak ciasteczka).
	 * @param bool   $nonce    Czy dołączyć ważny nonce.
	 */
	private function submit( string $password, string $cookie, bool $nonce = true ): string {
		$this->redirected = '';
		$this->cookies    = array();
		$_COOKIE          = '' === $cookie ? array() : array( PasswordSetup::COOKIE => wp_slash( $cookie ) );
		$_POST            = wp_slash(
			array(
				PasswordSetup::PASSWORD_FIELD => $password,
				PasswordSetup::NONCE_FIELD    => $nonce ? wp_create_nonce( PasswordSetup::NONCE_ACTION ) : 'zly',
			)
		);
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		PasswordSetup::handle();

		return $this->resultOf( $this->redirected );
	}

	/**
	 * Znacznik wyniku z adresu.
	 *
	 * @param string $location Adres przekierowania.
	 */
	private function resultOf( string $location ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		return isset( $query[ PasswordSetup::PAGE_ARG ] ) && is_string( $query[ PasswordSetup::PAGE_ARG ] ) ? $query[ PasswordSetup::PAGE_ARG ] : '';
	}

	/**
	 * Renderuje stronę ze znacznikiem w adresie i podanym ciasteczkiem.
	 *
	 * @param string $code   Znacznik w adresie.
	 * @param string $cookie Wartość ciasteczka z biletem.
	 */
	private function page( string $code, string $cookie ): string {
		$_GET    = array( PasswordSetup::PAGE_ARG => $code );
		$_COOKIE = '' === $cookie ? array() : array( PasswordSetup::COOKIE => wp_slash( $cookie ) );

		return PasswordSetup::render();
	}

	/**
	 * Żądanie rejestracji na tyle poprawne, by `SpamGuard::check()` doszedł do progów.
	 *
	 * @return array<string, mixed>
	 */
	private function registration_request(): array {
		return array(
			RegistrationForm::HONEYPOT  => '',
			RegistrationForm::TIMESTAMP => SpamGuard::stamp( time() - 30 ),
		);
	}

	/**
	 * @return list<object> Wiersze logu zdarzeń.
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_only_the_ticket_hash_is_stored_never_the_ticket_itself(): void {
		$id    = $this->account();
		$token = PasswordSetup::issue( $id );

		$stored = get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true );
		$this->assertNotEmpty( $stored );
		$this->assertNotSame( $token, $stored );
		foreach ( get_user_meta( $id ) as $name => $values ) {
			foreach ( $values as $value ) {
				$this->assertStringNotContainsString( $token, (string) $value, 'Bilet jawny w metadanej ' . $name );
			}
		}
		$this->assertEqualsWithDelta( time() + PasswordSetup::TICKET_HOURS * HOUR_IN_SECONDS, (int) get_user_meta( $id, UserMeta::PASSWORD_TICKET_EXPIRES, true ), 5 );
	}

	public function test_the_ticket_is_valid_for_one_hour(): void {
		// Bilet wystawia się na ruchu człowieka, który właśnie kliknął link w mailu.
		$this->assertSame( 1, PasswordSetup::TICKET_HOURS );
	}

	public function test_confirmation_redirects_to_the_password_page_without_any_token_in_the_url(): void {
		$id    = $this->account();
		$token = Confirmation::issue( $id );

		$_GET = array(
			Confirmation::QUERY_USER  => (string) $id,
			Confirmation::QUERY_TOKEN => $token,
		);
		Confirmation::handle();

		$this->assertStringContainsString( PasswordSetup::PAGE_ARG . '=' . PasswordSetup::RESULT_FORM, $this->redirected );
		$this->assertStringNotContainsString( $token, $this->redirected, 'Token potwierdzenia nie zostaje w adresie.' );
		$this->assertStringNotContainsString( Confirmation::QUERY_TOKEN, $this->redirected );
		$ticket = $this->lastCookie();
		$this->assertNotSame( '', $ticket, 'Bilet jedzie ciasteczkiem.' );
		$this->assertStringNotContainsString( $ticket, $this->redirected, 'Biletu nie ma w adresie.' );
		$this->assertSame( $id . '.' . substr( $ticket, strpos( $ticket, '.' ) + 1 ), $ticket );
	}

	public function test_the_ticket_cookie_is_httponly_and_lax(): void {
		$this->confirmed();

		$options = $this->cookies[0]['options'];
		$this->assertTrue( (bool) $options['httponly'], 'Bilet nie może być widoczny dla JavaScriptu.' );
		$this->assertSame( 'Lax', $options['samesite'], 'To, a nie nonce, blokuje wymuszenie ustawienia hasła z cudzej witryny.' );
		$this->assertSame( is_ssl(), (bool) $options['secure'], 'Secure wyłącznie po HTTPS.' );
	}

	/**
	 * Dopasowanie ścieżki ciasteczka do ścieżki żądania według RFC 6265 §5.1.4.
	 *
	 * @param string $cookiePath  Ścieżka z ciasteczka.
	 * @param string $requestPath Ścieżka żądania.
	 */
	private function pathMatches( string $cookiePath, string $requestPath ): bool {
		if ( $cookiePath === $requestPath ) {
			return true;
		}
		if ( ! str_starts_with( $requestPath, $cookiePath ) ) {
			return false;
		}

		return str_ends_with( $cookiePath, '/' ) || '/' === substr( $requestPath, strlen( $cookiePath ), 1 );
	}

	public function test_the_ticket_cookie_reaches_both_the_page_and_the_address_the_form_posts_to(): void {
		// Test sprawdza RELACJĘ, nie wartość: ścieżka ciasteczka musi pokrywać oba adresy, pod
		// które przeglądarka ma bilet wysłać — stronę (czyta ją `render()`) i cel formularza
		// (`admin-post.php`, czyta go `handle()`). Zacieśnienie ścieżki do strony rejestracji
		// nie chroniło przed niczym, a sprawiało, że hasła nie ustawił nikt.
		//
		// Struktura odnośników jest tu konieczna: bez niej `get_permalink()` oddaje
		// `?page_id=N`, ścieżka wychodzi `/`, a `/` pokrywa wszystko — i właśnie dlatego ten
		// błąd był dla całej suity niewidoczny.
		$this->set_permalink_structure( '/%postname%/' );
		$this->configure( true );
		$this->confirmed();

		$cookiePath = (string) $this->cookies[0]['options']['path'];
		$pagePath   = (string) wp_parse_url( (string) get_permalink( $this->registerPage ), PHP_URL_PATH );
		$formPath   = (string) wp_parse_url( admin_url( 'admin-post.php' ), PHP_URL_PATH );

		$this->assertNotSame( '/', $pagePath, 'Fixtura nie zadziałała: ścieżka strony musi być węższa niż /.' );
		$this->assertTrue(
			$this->pathMatches( $cookiePath, $formPath ),
			'Przeglądarka nie wyśle biletu pod adres formularza: cookie path=' . $cookiePath . ', action path=' . $formPath
		);
		$this->assertTrue(
			$this->pathMatches( $cookiePath, $pagePath ),
			'Przeglądarka nie wyśle biletu na stronę ustawienia hasła: cookie path=' . $cookiePath . ', page path=' . $pagePath
		);
	}

	public function test_a_second_click_on_the_confirmation_link_issues_no_new_ticket(): void {
		$id    = $this->account();
		$token = Confirmation::issue( $id );
		$_GET  = array(
			Confirmation::QUERY_USER  => (string) $id,
			Confirmation::QUERY_TOKEN => $token,
		);
		Confirmation::handle();
		$firstHash = (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true );
		$this->assertNotSame( '', $firstHash );

		$this->cookies    = array();
		$this->redirected = '';
		Confirmation::handle();

		$this->assertSame( $firstHash, (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ), 'Drugie kliknięcie nie wystawia nowego biletu.' );
		$this->assertSame( array(), $this->cookies, 'Drugie kliknięcie nie wysyła ciasteczka z biletem.' );
		$this->assertStringContainsString( Confirmation::RESULT_ARG . '=' . Confirmation::RESULT_INVALID, $this->redirected );
		$this->assertStringNotContainsString( PasswordSetup::PAGE_ARG, $this->redirected );
	}

	public function test_a_confirmation_whose_ticket_could_not_be_issued_tells_the_truth_about_the_confirmation(): void {
		// Potwierdzenie się UDAŁO: stan konta zmieniony, mail do redakcji wysłany. Jeżeli zapis
		// biletu odmówi, człowiek nie może przeczytać „Link wygasł” o operacji, która się udała.
		$block = static fn ( $check, $object_id, $meta_key ) => UserMeta::PASSWORD_TICKET_HASH === $meta_key ? false : $check;
		add_filter( 'update_user_metadata', $block, 10, 3 );
		$id    = $this->account();
		$token = Confirmation::issue( $id );
		$_GET  = array(
			Confirmation::QUERY_USER  => (string) $id,
			Confirmation::QUERY_TOKEN => $token,
		);
		Confirmation::handle();
		remove_filter( 'update_user_metadata', $block, 10 );

		$this->assertSame( AccountStatus::Pending, AccountStatusRepository::get( $id ), 'Potwierdzenie się udało.' );
		$this->assertSame( '', (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ), 'Fixtura nie zadziałała: bilet jednak się zapisał.' );
		$this->assertStringContainsString( Confirmation::RESULT_ARG . '=' . Confirmation::RESULT_OK, $this->redirected );
		$this->assertStringNotContainsString( PasswordSetup::PAGE_ARG, $this->redirected, 'Bez biletu strona hasła nie ma czego pokazać.' );
		$this->assertSame( array(), $this->cookies, 'Bez biletu nie ma po co wysyłać ciasteczka.' );

		$_GET = array( Confirmation::RESULT_ARG => Confirmation::RESULT_OK );
		$html = Confirmation::notice_html();
		$this->assertStringContainsString( 'potwierdzony', $html, 'Komunikat mówi prawdę o skutku.' );
		$this->assertStringNotContainsString( 'wygasł', $html );
		$this->assertStringContainsString( wp_lostpassword_url(), $html, 'I wskazuje drogę dalej do hasła.' );
		$this->assertStringContainsString( 'odzyskiwanie hasła', Confirmation::message_for( Confirmation::RESULT_OK ) );
	}

	public function test_reissuing_a_ticket_in_the_same_second_is_not_mistaken_for_a_failed_write(): void {
		// `update_user_meta()` zwraca false także wtedy, gdy wartość jest identyczna z zapisaną,
		// a termin ważności wyliczony w tej samej sekundzie jest identyczny. Wzięty za awarię
		// zapisu kasowałby właśnie wystawiony, dobry bilet.
		$id = $this->account();

		$first  = PasswordSetup::issue( $id );
		$second = PasswordSetup::issue( $id );

		$this->assertNotSame( '', $second, 'Ten sam termin ważności to nie awaria zapisu.' );
		$this->assertNotSame( $first, $second, 'Nowy bilet jest nowy.' );
		$this->assertSame( self::hashOf( $second ), (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ) );
		$this->assertNotSame( '', (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_EXPIRES, true ) );
	}

	/**
	 * Skrót biletu tak, jak liczy go wtyczka.
	 *
	 * @param string $token Bilet jawny.
	 */
	private static function hashOf( string $token ): string {
		return hash( 'sha256', $token );
	}

	public function test_a_ticket_without_an_expiry_is_not_issued_at_all(): void {
		// Bilet bez terminu ważności byłby biletem bezterminowym, więc skrót nie może zostać.
		$block = static fn ( $check, $object_id, $meta_key ) => UserMeta::PASSWORD_TICKET_EXPIRES === $meta_key ? false : $check;
		add_filter( 'update_user_metadata', $block, 10, 3 );
		$id = $this->account();

		$token = PasswordSetup::issue( $id );

		remove_filter( 'update_user_metadata', $block, 10 );
		$this->assertSame( '', $token );
		$this->assertSame( '', (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ), 'Skrót bez terminu ważności jest sprzątany.' );
	}

	public function test_a_valid_ticket_sets_the_password_exactly_as_typed_and_clears_the_cookie(): void {
		list( $id, $ticket ) = $this->confirmed();

		$this->assertSame( PasswordSetup::RESULT_DONE, $this->submit( self::GOOD_PASSWORD, $ticket ) );

		$this->assertNotFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $id )->user_pass, $id ), 'Hasła nie wolno sanityzować.' );
		$this->assertSame( '', get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ), 'Bilet jest jednorazowy.' );
		$this->assertSame( '', get_user_meta( $id, UserMeta::PASSWORD_TICKET_EXPIRES, true ) );
		$this->assertSame( array( array( 'value' => '', 'options' => $this->cookies[0]['options'] ) ), $this->cookies, 'Ciasteczko z biletem jest kasowane po zużyciu.' );
		$this->assertLessThan( time(), (int) $this->cookies[0]['options']['expires'] );
		$actions = array_map( static fn ( object $row ): string => (string) $row->action, $this->log_rows() );
		$this->assertContains( PasswordSetup::LOG_ACTION, $actions );
	}

	public function test_a_used_ticket_does_not_work_a_second_time(): void {
		list( $id, $ticket ) = $this->confirmed();
		$this->assertSame( PasswordSetup::RESULT_DONE, $this->submit( self::GOOD_PASSWORD, $ticket ) );

		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( 'Zupelnie!Inne123', $ticket ) );

		$this->assertFalse( wp_check_password( 'Zupelnie!Inne123', get_userdata( $id )->user_pass, $id ), 'Zużyty bilet nie ustawia hasła drugi raz.' );
		$this->assertNotFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $id )->user_pass, $id ) );
	}

	public function test_a_ticket_consumed_by_a_parallel_request_sets_no_password(): void {
		// Test sekwencyjny dowodzi tylko „bilet jest zużywany”. Tu chodzi o ATOMOWOŚĆ: drugi
		// konsument odtworzony w locie, dokładnie MIĘDZY odczytem biletu a jego usunięciem
		// (technika szwu z crona czyszczącego). Zużycie z dopasowaniem wartości daje wtedy
		// `false`; sprawdź-potem-usuń przepuszcza oba żądania i ustawia hasło.
		global $wpdb;
		list( $id, $ticket ) = $this->confirmed();
		get_user_meta( $id ); // Zasilenie cache: odczyt w ticket() ma widzieć bilet, jak żądanie, które zdążyło pierwsze.
		$raced = false;
		$race  = function ( $check, $object_id, $meta_key ) use ( $id, &$raced, $wpdb ) {
			if ( ! $raced && $id === (int) $object_id && UserMeta::PASSWORD_TICKET_HASH === $meta_key ) {
				$raced = true;
				// Żądanie równoległe zużywa bilet w bazie, poza cache tego procesu.
				$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $id, 'meta_key' => UserMeta::PASSWORD_TICKET_HASH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixtura: zużycie biletu poza cache.
			}
			return $check;
		};
		add_filter( 'get_user_metadata', $race, 10, 3 );

		$result = $this->submit( self::GOOD_PASSWORD, $ticket );

		remove_filter( 'get_user_metadata', $race, 10 );
		$this->assertTrue( $raced, 'Fixtura nie zadziałała: bilet nie został zużyty w trakcie żądania.' );
		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $result, 'Bilet zużyty przez żądanie równoległe nie ustawia hasła drugi raz.' );
		$this->assertFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $id )->user_pass, $id ) );
	}

	public function test_a_malformed_ticket_is_refused(): void {
		list( $id, $ticket ) = $this->confirmed();
		$hash                = (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true );

		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( self::GOOD_PASSWORD, $id . '.' . str_repeat( 'a', 64 ) ), 'Zły bilet.' );
		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( self::GOOD_PASSWORD, (string) $id ), 'Bilet bez losowej części.' );
		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( self::GOOD_PASSWORD, '' ), 'Brak ciasteczka.' );
		// Skrót z metadanej NIE jest biletem: podstawiony jako bilet nie otwiera niczego.
		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( self::GOOD_PASSWORD, $id . '.' . $hash ), 'Skrót z metadanej nie jest biletem.' );

		$this->assertFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $id )->user_pass, $id ) );
		$this->assertSame( $hash, (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ), 'Bilet właściciela zostaje nietknięty.' );
		$this->assertNotSame( '', $ticket );
	}

	public function test_an_expired_ticket_is_refused(): void {
		list( $id, $ticket ) = $this->confirmed();
		update_user_meta( $id, UserMeta::PASSWORD_TICKET_EXPIRES, time() - 60 );

		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( self::GOOD_PASSWORD, $ticket ) );
		$this->assertFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $id )->user_pass, $id ) );
	}

	public function test_a_ticket_issued_for_another_account_does_not_set_this_ones_password(): void {
		list( $victim )        = $this->confirmed();
		list( , $ownTicket )   = $this->confirmed();
		$foreign               = $victim . '.' . substr( $ownTicket, strpos( $ownTicket, '.' ) + 1 );

		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( self::GOOD_PASSWORD, $foreign ) );
		$this->assertFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $victim )->user_pass, $victim ) );
	}

	public function test_a_password_shorter_than_the_minimum_is_refused_and_the_account_keeps_its_random_password(): void {
		list( $id, $ticket ) = $this->confirmed();
		$before              = get_userdata( $id )->user_pass;
		$short               = str_repeat( 'a', PasswordPolicy::MIN_LENGTH - 1 );

		$this->assertSame( PasswordSetup::RESULT_SHORT, $this->submit( $short, $ticket ) );

		$this->assertSame( $before, get_userdata( $id )->user_pass, 'Konto zachowuje hasło losowe.' );
		$this->assertFalse( wp_check_password( $short, get_userdata( $id )->user_pass, $id ) );
		$this->assertNotSame( '', (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ), 'Odrzucone hasło nie zużywa biletu.' );
		$this->assertStringContainsString( (string) PasswordPolicy::MIN_LENGTH, PasswordSetup::message_for( PasswordSetup::RESULT_SHORT ) );
	}

	public function test_a_password_equal_to_the_email_is_refused_regardless_of_letter_case(): void {
		list( $id, $ticket ) = $this->confirmed();
		$before              = get_userdata( $id )->user_pass;
		$email               = (string) get_userdata( $id )->user_email;
		$this->assertGreaterThanOrEqual( PasswordPolicy::MIN_LENGTH, mb_strlen( $email ), 'Adres musi przejść próg długości, inaczej test dowodziłby czegoś innego.' );

		$this->assertSame( PasswordSetup::RESULT_EMAIL, $this->submit( $email, $ticket ) );
		$this->assertSame( PasswordSetup::RESULT_EMAIL, $this->submit( strtoupper( $email ), $ticket ), 'Porównanie bez względu na wielkość liter.' );

		$this->assertSame( $before, get_userdata( $id )->user_pass, 'Konto zachowuje hasło losowe.' );
		$this->assertNotSame( '', (string) get_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, true ) );
	}

	public function test_a_bad_nonce_sets_nothing(): void {
		list( $id, $ticket ) = $this->confirmed();

		$this->assertSame( PasswordSetup::RESULT_STALE, $this->submit( self::GOOD_PASSWORD, $ticket, false ) );
		$this->assertFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $id )->user_pass, $id ) );
	}

	public function test_the_attempt_limit_works_and_does_not_use_up_the_registration_bucket(): void {
		// Kubełek jest OSOBNY: wspólny pozwalałby jednym przebiegiem wyczerpać drugi.
		$this->configure( true, array( 'rate_limit_per_hour' => 2 ) );
		list( $id, $ticket ) = $this->confirmed();

		$this->assertSame( PasswordSetup::RESULT_SHORT, $this->submit( 'krotkie', $ticket ) );
		$this->assertSame( PasswordSetup::RESULT_SHORT, $this->submit( 'krotkie', $ticket ) );
		$this->assertSame( PasswordSetup::RESULT_TOO_MANY, $this->submit( self::GOOD_PASSWORD, $ticket ), 'Trzecia próba z tego samego IP przekracza próg.' );
		$this->assertFalse( wp_check_password( self::GOOD_PASSWORD, get_userdata( $id )->user_pass, $id ) );

		$verdict = SpamGuard::check( $_SERVER, $this->registration_request(), 'ktos-inny@example.test' );
		$this->assertNotSame( SpamVerdict::IP_LIMIT, $verdict->reason(), 'Próby ustawienia hasła nie zużywają kubełka rejestracji.' );
	}

	public function test_the_account_dimension_of_the_limit_is_keyed_by_account(): void {
		// Specyfikacja żąda progu per adres IP I per konto. Wymiar konta liczy się osobno dla
		// każdego konta: wyczerpanie budżetu jednego nie może odciąć drugiego. Adres IP zmieniam
		// przy każdej próbie, żeby to wymiar konta, a nie IP, rozstrzygał o odmowie.
		$this->configure( true, array( 'rate_limit_per_hour' => 2 ) );
		list( $id, $ticket )      = $this->confirmed();
		list( $other, $otherTkt ) = $this->confirmed();

		$results = array();
		foreach ( array( '10.1.0.1', '10.1.0.2', '10.1.0.3' ) as $address ) {
			$_SERVER['REMOTE_ADDR'] = $address;
			$results[]              = $this->submit( 'krotkie', $ticket );
		}

		$this->assertSame( array( PasswordSetup::RESULT_SHORT, PasswordSetup::RESULT_SHORT, PasswordSetup::RESULT_TOO_MANY ), $results, 'Trzecia próba tego konta przekracza próg per konto.' );
		$this->assertFalse( wp_check_password( 'krotkie', get_userdata( $id )->user_pass, $id ) );

		$_SERVER['REMOTE_ADDR'] = '10.1.0.4';
		$this->assertSame( PasswordSetup::RESULT_SHORT, $this->submit( 'krotkie', $otherTkt ), 'Inne konto ma własny budżet.' );
		$this->assertNotSame( '', (string) get_user_meta( $other, UserMeta::PASSWORD_TICKET_HASH, true ) );
	}

	public function test_the_ip_dimension_of_the_limit_is_keyed_by_address_and_separate_from_the_account_one(): void {
		// Odwrotnie: wyczerpanie budżetu adresu IP nie jest wyczerpaniem budżetu konta. Konto
		// odrzucone progiem IP musi przejść z adresu, który budżet jeszcze ma.
		$this->configure( true, array( 'rate_limit_per_hour' => 2 ) );
		$tickets = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$tickets[] = $this->confirmed();
		}

		$_SERVER['REMOTE_ADDR'] = '10.2.0.1';
		$results                = array();
		foreach ( $tickets as $pair ) {
			$results[] = $this->submit( 'krotkie', $pair[1] );
		}

		$this->assertSame( array( PasswordSetup::RESULT_SHORT, PasswordSetup::RESULT_SHORT, PasswordSetup::RESULT_TOO_MANY ), $results, 'Trzecie konto z tego samego adresu przekracza próg per IP.' );

		$_SERVER['REMOTE_ADDR'] = '10.2.0.2';
		$this->assertSame( PasswordSetup::RESULT_SHORT, $this->submit( 'krotkie', $tickets[2][1] ), 'To konto nie zużyło własnego budżetu, bo odmowa padła na progu IP.' );
	}

	public function test_setting_the_password_does_not_log_the_user_in(): void {
		// Konto po potwierdzeniu czeka na decyzję redakcji: automatyczne logowanie obiecywałoby dostęp, którego nie ma.
		list( $id, $ticket ) = $this->confirmed();

		$this->assertSame( PasswordSetup::RESULT_DONE, $this->submit( self::GOOD_PASSWORD, $ticket ) );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertFalse( is_user_logged_in() );
		$this->assertSame( AccountStatus::Pending, AccountStatusRepository::get( $id ) );
	}

	public function test_the_message_after_setting_the_password_says_what_state_the_application_is_in(): void {
		$withApproval = PasswordSetup::message_for( PasswordSetup::RESULT_DONE );
		$this->assertStringContainsString( 'decyzję redakcji', $withApproval );
		$this->assertStringNotContainsString( 'zalogować', $withApproval );
		$pendingPage = $this->page( PasswordSetup::RESULT_DONE, '' );
		$this->assertStringContainsString( 'decyzję redakcji', $pendingPage );
		$this->assertStringNotContainsString( wp_login_url(), $pendingPage, 'Przy włączonej akceptacji konto jeszcze nie ma dostępu.' );

		$this->configure( false );
		$withoutApproval = PasswordSetup::message_for( PasswordSetup::RESULT_DONE );
		$this->assertStringContainsString( 'zalogować', $withoutApproval );
		$this->assertStringNotContainsString( 'decyzję redakcji', $withoutApproval );
		$activePage = $this->page( PasswordSetup::RESULT_DONE, '' );
		$this->assertStringContainsString( 'zalogować', $activePage );
		$this->assertStringContainsString( wp_login_url(), $activePage, 'Odnośnik do logowania.' );
	}

	public function test_a_lost_or_expired_ticket_points_to_password_recovery_and_offers_no_second_mechanism(): void {
		list( $id, $ticket ) = $this->confirmed();
		update_user_meta( $id, UserMeta::PASSWORD_TICKET_EXPIRES, time() - 60 );

		foreach ( array( '' => 'bez ciasteczka', $ticket => 'bilet przeterminowany' ) as $cookie => $case ) {
			$html = $this->page( PasswordSetup::RESULT_FORM, (string) $cookie );

			$this->assertStringContainsString( 'wygasł', $html, $case );
			$this->assertStringContainsString( wp_lostpassword_url(), $html, $case );
			$this->assertStringNotContainsString( 'name="' . PasswordSetup::PASSWORD_FIELD . '"', $html, $case . ': bez formularza hasła' );
			$this->assertStringNotContainsString( 'name="' . Confirmation::EMAIL_FIELD . '"', $html, $case . ': bez drugiego mechanizmu wysyłającego link' );
		}
	}

	public function test_the_password_page_replaces_the_registration_form_and_carries_a_nonce(): void {
		list( , $ticket ) = $this->confirmed();

		$html = $this->page( PasswordSetup::RESULT_FORM, $ticket );

		$this->assertStringContainsString( 'name="' . PasswordSetup::PASSWORD_FIELD . '"', $html );
		$this->assertStringContainsString( 'name="' . PasswordSetup::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( 'value="' . PasswordSetup::ACTION . '"', $html );

		// Ta sama strona: shortcode oddaje ustawienie hasła ZAMIAST formularza zgłoszenia.
		$rendered = RegistrationForm::render();
		$this->assertStringContainsString( 'name="' . PasswordSetup::PASSWORD_FIELD . '"', $rendered );
		$this->assertStringNotContainsString( 'name="' . RegistrationForm::PROFESSION . '"', $rendered );
	}

	public function test_the_expired_page_keeps_the_registration_form_so_the_visitor_has_a_way_on(): void {
		// Znacznik w adresie jest niesekretny: trafia tu także ktoś, kto chciał się zarejestrować.
		// Komunikat o wygasłym linku nie może zostawić strony bez formularza i bez drogi dalej.
		$_GET    = array( PasswordSetup::PAGE_ARG => PasswordSetup::RESULT_FORM );
		$_COOKIE = array();

		$this->assertFalse( PasswordSetup::takes_over(), 'Bez biletu strona hasła nie zabiera strony.' );
		$html = RegistrationForm::render();
		$this->assertStringContainsString( 'wygasł', $html );
		$this->assertStringContainsString( wp_lostpassword_url(), $html );
		$this->assertStringContainsString( 'name="' . RegistrationForm::PROFESSION . '"', $html, 'Formularz zgłoszenia zostaje.' );
	}

	public function test_the_page_with_a_valid_ticket_takes_over_and_the_done_page_too(): void {
		list( , $ticket ) = $this->confirmed();

		$_GET    = array( PasswordSetup::PAGE_ARG => PasswordSetup::RESULT_FORM );
		$_COOKIE = array( PasswordSetup::COOKIE => wp_slash( $ticket ) );
		$this->assertTrue( PasswordSetup::takes_over(), 'Z ważnym biletem jest co ustawiać.' );

		$_GET    = array( PasswordSetup::PAGE_ARG => PasswordSetup::RESULT_DONE );
		$_COOKIE = array();
		$this->assertTrue( PasswordSetup::takes_over(), 'Po ustawieniu hasła formularz zgłoszenia byłby szumem.' );

		$_GET = array();
		$this->assertFalse( PasswordSetup::takes_over() );
	}

	public function test_a_dead_ticket_cookie_is_cleared_instead_of_living_on_for_an_hour(): void {
		list( $id, $ticket ) = $this->confirmed();
		update_user_meta( $id, UserMeta::PASSWORD_TICKET_EXPIRES, time() - 60 );

		$this->assertSame( PasswordSetup::RESULT_EXPIRED, $this->submit( self::GOOD_PASSWORD, $ticket ) );
		$this->assertSame( array( '' ), array_map( static fn ( array $sent ): string => $sent['value'], $this->cookies ), 'Martwy bilet wychodzi z przeglądarki.' );
		$this->assertLessThan( time(), (int) $this->cookies[0]['options']['expires'] );

		// Odmowa z reguły hasła ciasteczka NIE kasuje: tam bilet jest dalej dobry.
		list( , $good ) = $this->confirmed();
		$this->assertSame( PasswordSetup::RESULT_SHORT, $this->submit( 'krotkie', $good ) );
		$this->assertSame( array(), $this->cookies, 'Dobry bilet zostaje w przeglądarce.' );
	}

	public function test_a_page_without_the_marker_renders_the_registration_form_as_before(): void {
		$_GET    = array();
		$_COOKIE = array();

		$this->assertSame( '', PasswordSetup::render() );
		$this->assertStringContainsString( 'name="' . RegistrationForm::PROFESSION . '"', RegistrationForm::render() );
	}

	public function test_the_password_page_is_excluded_from_page_caching_by_the_shared_set(): void {
		// Strona niesie nonce i otwiera ustawienie hasła: zapisana w cache podawałaby każdemu
		// ten sam martwy nonce. Zestaw jest JEDEN (RegistrationHandler::no_cache()), nie kopia.
		list( , $ticket ) = $this->confirmed();
		RegistrationHandler::reset_no_cache();
		add_action(
			'litespeed_control_set_nocache',
			function (): void {
				++$this->noCacheFired;
			}
		);

		$this->page( PasswordSetup::RESULT_FORM, $ticket );
		$this->assertSame( 1, $this->noCacheFired, 'Strona ustawienia hasła musi być wyłączona z cache.' );

		// Idempotencja: zestaw w obrębie żądania idzie raz.
		RegistrationHandler::no_cache();
		$this->assertSame( 1, $this->noCacheFired );

		// Strona wyniku też: niesie komunikat o stanie wniosku.
		RegistrationHandler::reset_no_cache();
		$this->page( PasswordSetup::RESULT_DONE, '' );
		$this->assertSame( 2, $this->noCacheFired );
	}

	public function test_formularz_ustawienia_hasla_ma_klasy_bootstrapa(): void {
		list( , $cookie ) = $this->confirmed();

		$html = $this->page( PasswordSetup::RESULT_FORM, $cookie );

		$this->assertStringContainsString( 'class="pusers-set-password pusers-form"', $html );
		$this->assertMatchesRegularExpression( '/<label class="form-label" for="' . PasswordSetup::PASSWORD_FIELD . '"/', $html );
		$this->assertMatchesRegularExpression( '/<input class="form-control" type="password" id="' . PasswordSetup::PASSWORD_FIELD . '"/', $html );
		$this->assertStringContainsString( 'class="btn btn-primary"', $html );
	}
}
