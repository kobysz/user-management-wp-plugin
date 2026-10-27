<?php
/**
 * Testy obsługi zgłoszenia rejestracji: bramki, utworzenie konta i przekierowanie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\AcfFields;
use PortalUsers\Front\Mailer;
use PortalUsers\Front\RateLimiter;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Front\SpamGuard;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Tests\Integration\Support\AcfRenderRecorder;
use WP_UnitTestCase;
use WPDieException;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class RegistrationHandlerTest extends WP_UnitTestCase {

	private const PASSWORD = 'S3kret\\ne"Haslo!';

	/**
	 * Adres ostatniego przekierowania (przekierowanie jest anulowane, żeby test przeżył).
	 *
	 * @var string
	 */
	private string $redirected = '';

	/**
	 * Anulujący filtr przekierowania.
	 *
	 * @var callable
	 */
	private $cancelRedirect;

	private string $originalMethod = 'GET';
	private string $originalAddr   = '';
	private int $sequence          = 0;

	/**
	 * Wiadomości przechwycone zamiast wysłania.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * Ciasteczka przechwycone przez szew wysyłania.
	 *
	 * @var list<array{value: string, options: array<string, mixed>}>
	 */
	private array $cookies = array();

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		RateLimiter::reset_failure_report();
		AcfRenderRecorder::$fields = array();
		$this->mails = array();
		remove_all_actions( RegistrationHandler::REGISTERED_HOOK );
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->mails[] = (array) $atts;
				return true;
			},
			10,
			2
		);
		$this->redirected          = '';
		$this->originalMethod      = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->originalAddr        = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$_SERVER['REMOTE_ADDR']    = '10.0.0.7';
		$this->cancelRedirect      = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
		add_filter(
			RegistrationHandler::COOKIE_FILTER,
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

		$cap = $this->capabilitiesKey();
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
				array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ),
				array( 'name' => 'newsletter', 'label' => 'Newsletter', 'type' => 'true_false' ),
				array( 'name' => 'notatka', 'label' => 'Notatka', 'type' => 'text' ),
				array( 'name' => $cap, 'label' => 'Podstęp', 'type' => 'text' ),
			)
		);
		SettingsRepository::save(
			Settings::fromArray(
				array(
					'acf_group_key'                         => 'group_abc',
					'rate_limit_per_hour'                   => 50,
					'registration_limit_per_email_per_hour' => 3,
					'time_trap_seconds'                     => 3,
				)
			)
		);
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
					array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka' ),
					// Numer uprawnień wymagany typem, ale w macierzy ukryty: wycofana z oferty.
					array( 'key' => 'dentysta', 'label' => 'Dentysta', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				)
			)
		);
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					// Pole `ghost` jest wymagane w macierzy, ale nie ma go w grupie ACF.
					'lekarz'       => array( 'pwz' => 'required', 'zgoda' => 'required', 'newsletter' => 'visible', 'ghost' => 'required' ),
					'pielegniarka' => array( 'pwz' => 'hidden', 'zgoda' => 'required', 'newsletter' => 'visible', 'notatka' => 'visible', $cap => 'visible' ),
					'dentysta'     => array( 'pwz' => 'hidden', 'zgoda' => 'required' ),
				)
			)
		);
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( SpamGuard::FILTER );
		remove_all_filters( RegistrationHandler::COOKIE_FILTER );
		$_COOKIE = array();
		remove_all_actions( RegistrationHandler::REGISTERED_HOOK );
		RateLimiter::reset_failure_report();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		$_SERVER['REMOTE_ADDR']    = $this->originalAddr;
		parent::tear_down();
	}

	/**
	 * Prawdziwy klucz metadanej z rolami konta na tej witrynie.
	 */
	private function capabilitiesKey(): string {
		global $wpdb;
		return $wpdb->get_blog_prefix() . 'capabilities';
	}

	private function email(): string {
		return 'jan' . ++$this->sequence . '@example.test';
	}

	/**
	 * Składa żądanie zgłoszenia. Wartość null usuwa pole.
	 *
	 * @param array<string, mixed> $over   Pola formularza nadpisujące domyślne.
	 * @param array<string, mixed> $fields Pola ACF nadpisujące domyślne.
	 * @param string               $method Metoda żądania.
	 */
	private function post( array $over = array(), array $fields = array(), string $method = 'POST' ): void {
		$post = array_merge(
			array(
				RegistrationForm::PROFESSION      => 'lekarz',
				RegistrationForm::EMAIL           => $this->email(),
				RegistrationForm::PASSWORD        => self::PASSWORD,
				RegistrationForm::PASSWORD_REPEAT => self::PASSWORD,
				RegistrationForm::HONEYPOT        => '',
				RegistrationForm::TIMESTAMP       => SpamGuard::stamp( time() - 30 ),
				RegistrationForm::NONCE_FIELD     => wp_create_nonce( RegistrationForm::NONCE_ACTION ),
				AcfFields::INPUT                  => array_merge( array( 'pwz' => '5425740', 'zgoda' => '1' ), $fields ),
			),
			$over
		);
		foreach ( $post as $key => $value ) {
			if ( null === $value ) {
				unset( $post[ $key ] );
			}
		}
		if ( isset( $post[ AcfFields::INPUT ] ) && is_array( $post[ AcfFields::INPUT ] ) ) {
			$post[ AcfFields::INPUT ] = array_filter( $post[ AcfFields::INPUT ], static fn ( $v ): bool => null !== $v );
		}
		// WordPress dodaje ukośniki do danych żądania; obsługa musi je zdjąć.
		$_POST                     = wp_slash( $post );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = $method;
	}

	/**
	 * Wysyła przygotowane żądanie i zwraca adres przekierowania.
	 */
	private function submit(): string {
		$this->redirected = '';
		$this->cookies    = array();
		RegistrationHandler::handle();
		return $this->redirected;
	}

	/**
	 * Przenosi klucz z adresu przekierowania do $_GET, jak zrobiłaby to przeglądarka po PRG.
	 *
	 * @param string $location Adres przekierowania.
	 * @return array{profession: string, email: string, fields: array<string, mixed>, errors: array<string, string>, warnings: array<string, string>, notice: string}
	 */
	private function follow( string $location ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET    = $query;
		$_COOKIE = array();
		foreach ( $this->cookies as $sent ) {
			if ( '' !== $sent['value'] ) {
				$_COOKIE[ RegistrationHandler::COOKIE ] = $sent['value'];
			}
		}
		return RegistrationHandler::flash();
	}

	/**
	 * Wartość ostatnio wysłanego niepustego ciasteczka z kluczem.
	 */
	private function sentToken(): string {
		$token = '';
		foreach ( $this->cookies as $sent ) {
			if ( '' !== $sent['value'] ) {
				$token = $sent['value'];
			}
		}
		return $token;
	}

	/**
	 * @return list<int> Identyfikatory wszystkich użytkowników.
	 */
	private function user_ids(): array {
		return array_map( 'intval', get_users( array( 'fields' => 'ID' ) ) );
	}

	/**
	 * @return list<object> Wiersze logu zdarzeń.
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * Zgłoszenie lekarza, które powinno przejść, i identyfikator konta.
	 *
	 * @param array<string, mixed> $over   Pola formularza.
	 * @param array<string, mixed> $fields Pola ACF.
	 */
	private function register_ok( array $over = array(), array $fields = array() ): int {
		$this->post( $over, $fields );
		$this->submit();
		$email = (string) ( $over[ RegistrationForm::EMAIL ] ?? $this->lastEmail() );
		$id    = email_exists( $email );
		$this->assertNotFalse( $id, 'Konto powinno powstać.' );
		return (int) $id;
	}

	private function lastEmail(): string {
		$posted = wp_unslash( $_POST );
		return is_array( $posted ) && is_string( $posted[ RegistrationForm::EMAIL ] ?? null ) ? $posted[ RegistrationForm::EMAIL ] : '';
	}

	public function test_a_valid_submission_creates_an_account_in_the_unconfirmed_state(): void {
		$email = $this->email();
		$this->post( array( RegistrationForm::EMAIL => $email, RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );
		$location = $this->submit();

		$id = email_exists( $email );
		$this->assertNotFalse( $id );
		$this->assertSame( AccountStatus::EmailUnconfirmed, AccountStatusRepository::get( (int) $id ) );
		$this->assertSame( 'pielegniarka', get_user_meta( (int) $id, UserMeta::PROFESSION, true ) );
		$this->assertSame( array( 'subscriber' ), get_userdata( (int) $id )->roles );
		$this->assertNotFalse( wp_check_password( self::PASSWORD, get_userdata( (int) $id )->user_pass ), 'Hasło ma zostać zapisane dokładnie tak, jak wpisano.' );
		$this->assertStringContainsString( RegistrationHandler::DONE_ARG . '=1', $location );
		$this->assertStringNotContainsString( RegistrationHandler::COOKIE, $location );
	}

	public function test_the_account_gets_the_site_default_role(): void {
		add_role( 'pusers_reader', 'Czytelnik', array( 'read' => true, 'level_0' => true ) );
		update_option( 'default_role', 'pusers_reader' );
		$id = $this->register_ok( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );

		$this->assertSame( array( 'pusers_reader' ), get_userdata( $id )->roles );
		remove_role( 'pusers_reader' );
	}

	/**
	 * Każde uprawnienie, którego publiczny formularz nie może nadać samorejestrującemu się kontu,
	 * jako osobny przypadek: eskalacja (konta, kod, aktualizacje, sieć), wstrzyknięcie skryptu
	 * i wyprowadzenie albo zniszczenie danych. Guard pracuje tylko dla roli własnej albo podmienionej,
	 * więc „domyślne role WordPressa tego nie mają” niczego tu nie usprawiedliwia.
	 *
	 * @return array<string, array{0: array<string, bool>}>
	 */
	public static function dangerous_roles(): array {
		$capabilities = array(
			'manage_options',
			'promote_users',
			'edit_users',
			'create_users',
			'delete_users',
			'add_users',
			'remove_users',
			'list_users',
			'install_plugins',
			'activate_plugins',
			'edit_plugins',
			'delete_plugins',
			'update_plugins',
			'install_themes',
			'edit_themes',
			'switch_themes',
			'delete_themes',
			'update_themes',
			'update_core',
			'edit_files',
			'unfiltered_upload',
			'unfiltered_html',
			'edit_theme_options',
			'import',
			'export',
			'export_others_personal_data',
			'erase_others_personal_data',
			'delete_site',
			'manage_network',
			'manage_network_options',
			'manage_network_plugins',
			'manage_network_themes',
			'manage_network_users',
			'manage_sites',
		);

		$cases = array();
		foreach ( $capabilities as $capability ) {
			$cases[ $capability ] = array( array( 'read' => true, $capability => true ) );
		}

		return $cases;
	}

	/**
	 * @dataProvider dangerous_roles
	 *
	 * @param array<string, bool> $capabilities Uprawnienia roli domyślnej.
	 */
	public function test_a_default_role_with_a_dangerous_capability_creates_no_account( array $capabilities ): void {
		add_role( 'pusers_strong', 'Mocna', $capabilities );
		update_option( 'default_role', 'pusers_strong' );
		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) ) );
		remove_role( 'pusers_strong' );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'registration_closed', $flash['errors']['registration'] ?? '' );
		$this->assertStringNotContainsString( 'profesji', RegistrationHandler::message_for( 'registration_closed' ), 'Przyczyną jest rola witryny, nie profesja.' );
	}

	public function test_a_role_guard_refusal_names_the_capability_in_the_event_log_at_most_once_an_hour(): void {
		// Odwiedzający widzi tylko „rejestracja chwilowo niedostępna”; administrator musi mieć skąd
		// się dowiedzieć, dlaczego. Usterka trwa do jego interwencji, więc wiersz na każde żądanie
		// zapchałby tabelę audytu, która jest śladem decyzji redakcji o zatwierdzaniu kont.
		delete_option( RegistrationHandler::ROLE_REFUSAL_OPTION );
		add_role( 'pusers_strong', 'Mocna', array( 'read' => true, 'list_users' => true ) );
		update_option( 'default_role', 'pusers_strong' );

		$this->post_and_submit( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );
		$this->post_and_submit( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );
		$rows = $this->log_rows();

		$this->assertCount( 1, $rows, 'Trwająca usterka konfiguracji pisze najwyżej jeden wiersz na godzinę.' );
		$this->assertSame( RegistrationHandler::ROLE_REFUSAL_LOG_ACTION, $rows[0]->action );
		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( 'list_users', $context['capability'] ?? null, 'Bez nazwy uprawnienia wpis nie mówi, co poprawić.' );
		$this->assertSame( 'pusers_strong', $context['role'] ?? null );

		// Po upływie okna kolejna odmowa znów trafia do logu: usterka nie przestaje być widoczna.
		update_option( RegistrationHandler::ROLE_REFUSAL_OPTION, time() - HOUR_IN_SECONDS - 1 );
		$this->post_and_submit( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );
		$this->assertCount( 2, $this->log_rows() );

		update_option( 'default_role', 'subscriber' );
		remove_role( 'pusers_strong' );
	}

	public function test_a_default_role_that_does_not_exist_creates_no_account_and_is_logged_as_well(): void {
		// WordPress pilnuje obu swoich dróg zapisu (`sanitize_option()` podmienia nieznaną rolę na
		// subscribera, `remove_role()` resetuje opcję), więc taki stan bierze się z zapisu poza nimi:
		// zapytania do bazy, przywróconej kopii albo wtyczki filtrującej listę ról. Guard jest
		// fail-closed, a administrator ma się dowiedzieć, dlaczego rejestracja nie działa.
		delete_option( RegistrationHandler::ROLE_REFUSAL_OPTION );
		$missing = static fn (): string => 'pusers_nie_ma_takiej_roli';
		add_filter( 'pre_option_default_role', $missing );
		$this->assertNull( get_role( (string) get_option( 'default_role' ) ) );

		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) ) );
		remove_filter( 'pre_option_default_role', $missing );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'registration_closed', $flash['errors']['registration'] ?? '' );
		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( 'role_unknown', $context['reason'] ?? null );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function content_roles(): array {
		return array(
			'subscriber'  => array( 'subscriber' ),
			'contributor' => array( 'contributor' ),
			'author'      => array( 'author' ),
		);
	}

	/**
	 * @dataProvider content_roles
	 *
	 * @param string $role Rola domyślna witryny.
	 */
	public function test_registration_works_with_content_roles_as_the_default_role( string $role ): void {
		update_option( 'default_role', $role );
		$id = $this->register_ok( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );

		$this->assertSame( array( $role ), get_userdata( $id )->roles );
	}

	public function test_the_login_is_random_and_does_not_contain_the_address_part(): void {
		$first  = $this->register_ok( array( RegistrationForm::EMAIL => 'anna.nowak@a.example.test' ) );
		$second = $this->register_ok( array( RegistrationForm::EMAIL => 'anna.nowak@b.example.test' ) );

		$logins = array( get_userdata( $first )->user_login, get_userdata( $second )->user_login );
		$this->assertNotSame( $logins[0], $logins[1] );
		foreach ( $logins as $login ) {
			$this->assertStringNotContainsString( 'anna', $login );
			$this->assertStringNotContainsString( 'nowak', $login );
			$this->assertDoesNotMatchRegularExpression( '/-\d+$/', $login );
		}
		$this->assertSame( $logins[0], get_userdata( $first )->display_name, 'Nazwa wyświetlana (publiczna) to login, nie część adresu.' );
		$this->assertStringNotContainsString( 'anna', get_userdata( $first )->display_name );
	}

	public function test_a_logged_in_user_cannot_register_another_account(): void {
		wp_set_current_user( self::factory()->user->create() );
		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit() );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'logged_in', $flash['errors']['registration'] ?? '' );
	}

	public function test_a_password_shorter_than_the_minimum_creates_no_account(): void {
		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit( array( RegistrationForm::PASSWORD => 'a', RegistrationForm::PASSWORD_REPEAT => 'a' ) ) );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'password_short', $flash['errors'][ RegistrationForm::PASSWORD ] ?? '' );
	}

	public function test_an_acf_error_on_a_field_named_like_a_plugin_field_is_not_lost(): void {
		remove_all_filters( 'pusers_acf_fields' );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
				array( 'name' => RegistrationForm::EMAIL, 'label' => 'Kolizja', 'type' => 'text' ),
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', RegistrationForm::EMAIL => 'required' ) ) ) );

		$flash = $this->follow( $this->post_and_submit( array( RegistrationForm::EMAIL => 'to-nie-adres' ), array( 'zgoda' => null ) ) );
		$this->assertSame( 'email_invalid', $flash['errors'][ RegistrationForm::EMAIL ] ?? '' );
		$this->assertSame( 'required', $flash['errors'][ RegistrationHandler::ACF_ERROR_PREFIX . RegistrationForm::EMAIL ] ?? '' );

		// Klucz z przedrostkiem musi mieć w renderze etykietę pola ACF: inaczej człowiek czyta
		// „To pole jest wymagane.” bez nazwy pola i nie wie, co poprawić.
		$html = RegistrationForm::render();
		$this->assertStringContainsString( 'Kolizja: To pole jest wymagane.', $html );
		$this->assertStringContainsString( 'Adres e-mail: Podaj poprawny adres e-mail.', $html );
		$this->assertStringNotContainsString( RegistrationHandler::ACF_ERROR_PREFIX, $html, 'Przedrostek jest wewnętrzny, nie pokazujemy go człowiekowi.' );
	}

	public function test_registration_fires_the_registered_action_with_the_new_user_id(): void {
		$seen = array();
		add_action(
			RegistrationHandler::REGISTERED_HOOK,
			static function ( $userId ) use ( &$seen ): void {
				$seen[] = $userId;
			}
		);
		$id = $this->register_ok();

		$this->assertSame( array( $id ), $seen );
	}

	public function test_profession_outside_the_collection_creates_no_account(): void {
		$before = $this->user_ids();
		$this->post( array( RegistrationForm::PROFESSION => 'nie-ma-takiej' ) );
		$flash = $this->follow( $this->submit() );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'unknown_profession', $flash['errors']['__profession'] ?? '' );
	}

	public function test_a_profession_withdrawn_from_the_offer_creates_no_account(): void {
		// Dentysta wymaga numeru typem, ale jego pole w macierzy jest ukryte: wymogu nie da się wyegzekwować,
		// więc konto bez weryfikacji numeru nie może powstać, choć profesja istnieje w kolekcji.
		$before = $this->user_ids();
		$this->post( array( RegistrationForm::PROFESSION => 'dentysta' ), array( 'pwz' => null ) );
		$this->submit();

		$this->assertSame( $before, $this->user_ids() );
	}

	public function test_a_field_hidden_for_the_chosen_profession_is_not_saved_even_when_present_in_the_request(): void {
		$id = $this->register_ok( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => '5425740', 'notatka' => 'widoczna' ) );

		$this->assertFalse( metadata_exists( 'user', $id, RegistrationHandler::ACF_META_PREFIX . 'pwz' ) );
		$this->assertSame( 'widoczna', get_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'notatka', true ) );
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function bad_nonces(): array {
		return array(
			'brak'      => array( null ),
			'zly'       => array( 'zlynonce' ),
		);
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param string|null $nonce Nonce w żądaniu.
	 */
	public function test_a_missing_or_expired_nonce_creates_no_account_and_asks_to_refresh_instead_of_a_403( ?string $nonce ): void {
		// Nonce i znacznik czasu starzeją się razem, więc człowiek z kartą otwartą od doby trafia właśnie tu.
		$before = $this->user_ids();
		$this->post( array( RegistrationForm::NONCE_FIELD => $nonce ) );
		$location = $this->submit();

		$this->assertNotSame( '', $location, 'Przekierowanie, nie wp_die.' );
		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( array(), $this->cookies, 'Nic z niezweryfikowanego żądania nie trafia do magazynu.' );
		$flash = $this->follow( $location );
		$this->assertSame( 'stale_form', $flash['notice'] );
		$this->assertStringContainsString( 'Odśwież stronę', RegistrationHandler::message_for( $flash['notice'] ) );
		$this->assertSame( array(), $flash['fields'] );
		$this->assertStringContainsString( 'Odśwież stronę', RegistrationForm::render() );
	}

	public function test_a_get_request_creates_no_account(): void {
		// Nonce i dane są poprawne: jedyną przeszkodą jest metoda.
		$before = $this->user_ids();
		$this->post( array(), array(), 'GET' );

		try {
			$this->submit();
			$this->fail( 'Żądanie GET powinno zakończyć się odmową.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, $this->user_ids() );
		}
	}

	public function test_a_submission_rejected_by_the_spam_guard_creates_no_account_and_is_not_validated(): void {
		// Zgłoszenie jest też nieważne (brak zgody): gdyby walidacja szła przed antyspamem albo
		// mimo odmowy, wśród błędów byłaby zgoda, a bot dostałby informację o polach.
		$before = $this->user_ids();
		$this->post( array( RegistrationForm::HONEYPOT => 'http://spam.example' ), array( 'zgoda' => null ) );
		$flash = $this->follow( $this->submit() );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'rejected', $flash['notice'] );
		$this->assertSame( array(), $flash['errors'] );
	}

	public function test_the_spam_guard_runs_before_the_profession_is_resolved(): void {
		$this->post( array( RegistrationForm::HONEYPOT => 'x', RegistrationForm::PROFESSION => 'nie-ma-takiej' ) );
		$flash = $this->follow( $this->submit() );

		$this->assertSame( 'rejected', $flash['notice'] );
		$this->assertSame( array(), $flash['errors'] );
	}

	public function test_a_stale_form_tells_the_user_to_refresh_not_that_they_were_too_fast(): void {
		$this->post( array( RegistrationForm::TIMESTAMP => SpamGuard::stamp( time() - 3 * DAY_IN_SECONDS ) ) );
		$flash = $this->follow( $this->submit() );

		$this->assertSame( 'stale_form', $flash['notice'] );
		$this->assertStringContainsString( 'Odśwież stronę', RegistrationHandler::message_for( $flash['notice'] ) );
		$this->assertStringNotContainsString( 'zbyt szybko', RegistrationHandler::message_for( $flash['notice'] ) );
	}

	public function test_an_invalid_submission_creates_no_account_and_redirects_back_with_errors(): void {
		$before   = $this->user_ids();
		$email    = $this->email();
		$location = $this->post_and_submit( array( RegistrationForm::EMAIL => $email ), array( 'zgoda' => null ) );
		$flash    = $this->follow( $location );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'required', $flash['errors']['zgoda'] ?? '' );
		$this->assertSame( $email, $flash['email'] );
		$this->assertSame( 'lekarz', $flash['profession'] );
		$this->assertSame( '5425740', $flash['fields']['pwz'] ?? '' );
		$this->assertStringNotContainsString( RegistrationHandler::DONE_ARG, $location );
	}

	/**
	 * @param array<string, mixed> $over   Pola formularza.
	 * @param array<string, mixed> $fields Pola ACF.
	 */
	private function post_and_submit( array $over = array(), array $fields = array() ): string {
		$this->post( $over, $fields );
		return $this->submit();
	}

	public function test_an_invalid_email_or_mismatched_passwords_create_no_account(): void {
		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit( array( RegistrationForm::EMAIL => 'to-nie-adres' ) ) );
		$this->assertSame( 'email_invalid', $flash['errors'][ RegistrationForm::EMAIL ] ?? '' );

		$flash = $this->follow( $this->post_and_submit( array( RegistrationForm::PASSWORD_REPEAT => 'inne' ) ) );
		$this->assertSame( 'password_mismatch', $flash['errors'][ RegistrationForm::PASSWORD_REPEAT ] ?? '' );

		$flash = $this->follow( $this->post_and_submit( array( RegistrationForm::PASSWORD => '', RegistrationForm::PASSWORD_REPEAT => '' ) ) );
		$this->assertSame( 'password_missing', $flash['errors'][ RegistrationForm::PASSWORD ] ?? '' );

		$this->assertSame( $before, $this->user_ids() );
	}

	public function test_an_email_already_registered_creates_no_second_account_and_gets_exactly_the_accepted_response(): void {
		// Odpowiedź nie może zdradzać, kto ma konto w portalu medycznym: identyczna z przyjętym zgłoszeniem.
		$accepted = $this->email();
		$this->post_and_submit( array( RegistrationForm::EMAIL => $accepted ) );
		$acceptedLocation = $this->redirected;
		$acceptedCookies  = $this->cookies;

		$email  = $this->email();
		$first  = $this->register_ok( array( RegistrationForm::EMAIL => $email ) );
		$before = $this->user_ids();
		$again  = $this->post_and_submit( array( RegistrationForm::EMAIL => strtoupper( $email ) ) );

		$this->assertSame( $acceptedLocation, $again, 'Ta sama odpowiedź co dla zgłoszenia przyjętego.' );
		$this->assertSame( $acceptedCookies, $this->cookies, 'Brak ciasteczka z błędem, tak jak przy przyjętym.' );
		$this->assertSame( array(), $this->follow( $again )['errors'] );
		$this->assertSame( $before, $this->user_ids(), 'Konto nie powstaje.' );
		$this->assertSame( array( $first ), array_values( array_filter( $this->user_ids(), static fn ( int $id ): bool => get_userdata( $id )->user_email === $email ) ) );
	}

	public function test_a_submission_for_an_existing_address_changes_nothing_in_the_existing_account(): void {
		$email = $this->email();
		$id    = $this->register_ok( array( RegistrationForm::EMAIL => $email ) );
		AccountStatusRepository::set( $id, AccountStatus::Approved );
		$passwordHash = get_userdata( $id )->user_pass;
		$meta         = get_user_meta( $id );
		$fired        = 0;
		add_action(
			RegistrationHandler::REGISTERED_HOOK,
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->post_and_submit( array( RegistrationForm::EMAIL => $email, RegistrationForm::PASSWORD => 'Zupelnie!Inne1', RegistrationForm::PASSWORD_REPEAT => 'Zupelnie!Inne1' ), array( 'pwz' => '1234567' ) );

		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ) );
		$this->assertSame( $passwordHash, get_userdata( $id )->user_pass );
		$this->assertSame( $meta, get_user_meta( $id ) );
		$this->assertSame( 0, $fired, 'Akcja utworzenia konta nie odpala się dla istniejącego adresu.' );
	}

	public function test_the_owner_of_an_existing_address_gets_a_mail_about_the_attempt(): void {
		$email = $this->email();
		$this->register_ok( array( RegistrationForm::EMAIL => $email ) );
		$this->mails = array();

		$this->post_and_submit( array( RegistrationForm::EMAIL => $email ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( $email ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'już istnieje', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'zignoruj', (string) $this->mails[0]['message'] );
	}

	public function test_an_address_taken_between_the_check_and_the_insert_gets_the_accepted_response_too(): void {
		// Wyścig: sprawdzenie email_exists() widzi adres wolnym, a wp_insert_user() zgłasza już zajęty.
		$email    = $this->email();
		$existing = $this->register_ok( array( RegistrationForm::EMAIL => $email ) );
		$this->mails = array();
		$calls       = 0;
		add_filter(
			'email_exists',
			static function ( $id ) use ( &$calls ) {
				return 1 === ++$calls ? false : $id;
			}
		);
		$before = $this->user_ids();

		$location = $this->post_and_submit( array( RegistrationForm::EMAIL => $email ) );

		$this->assertGreaterThan( 1, $calls, 'Wyścig musiał zajść między sprawdzeniem a zapisem.' );
		$this->assertStringContainsString( RegistrationHandler::DONE_ARG . '=1', $location );
		$this->assertSame( $before, $this->user_ids() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( $existing, (int) email_exists( $email ) );
	}

	public function test_the_attempt_mail_is_not_sent_once_its_limit_is_used_up_and_the_response_stays_the_same(): void {
		$email = $this->email();
		$this->register_ok( array( RegistrationForm::EMAIL => $email ) );
		$this->mails = array();
		for ( $i = 0; $i < 3; $i++ ) {
			Mailer::may_send_to( $email, Mailer::PURPOSE_ATTEMPT );
		}

		$location = $this->post_and_submit( array( RegistrationForm::EMAIL => $email ) );

		$this->assertSame( array(), $this->mails, 'Próg maili o próbie rejestracji wyczerpany.' );
		$this->assertStringContainsString( RegistrationHandler::DONE_ARG . '=1', $location, 'Odpowiedź ta sama co dla przyjętego zgłoszenia.' );
		$this->assertTrue( Mailer::may_send_to( $email, Mailer::PURPOSE_RESEND ), 'Cudze zgłoszenia nie odbierają właścicielowi ponownej wysyłki.' );
	}

	public function test_with_the_per_address_limit_set_to_one_a_taken_and_a_free_address_answer_identically(): void {
		SettingsRepository::save(
			Settings::fromArray(
				array(
					'acf_group_key'                         => 'group_abc',
					'rate_limit_per_hour'                   => 50,
					'registration_limit_per_email_per_hour' => 1,
					'time_trap_seconds'                     => 3,
				)
			)
		);
		$taken = $this->email();
		$free  = $this->email();
		self::factory()->user->create( array( 'user_email' => $taken ) );

		$answers = array();
		foreach ( array( 'taken' => $taken, 'free' => $free ) as $label => $address ) {
			$first                      = $this->post_and_submit( array( RegistrationForm::EMAIL => $address ) );
			$second                     = $this->post_and_submit( array( RegistrationForm::EMAIL => $address ) );
			$answers[ $label ]          = array( $first, $second, $this->follow( $second )['notice'] );
		}

		$this->assertSame( $answers['free'], $answers['taken'], 'Drugie zgłoszenie dostaje ten sam wynik dla adresu zajętego i wolnego.' );
		$this->assertSame( 'too_many', $answers['free'][2], 'Próg faktycznie zadziałał.' );
	}

	public function test_other_field_errors_are_reported_the_same_for_an_existing_address_without_an_email_error(): void {
		$email = $this->email();
		$this->register_ok( array( RegistrationForm::EMAIL => $email ) );

		$flash = $this->follow( $this->post_and_submit( array( RegistrationForm::EMAIL => $email ), array( 'zgoda' => null ) ) );

		$this->assertSame( array( 'zgoda' => 'required' ), $flash['errors'], 'Błąd pola jest, błędu adresu nie ma.' );
	}

	public function test_an_array_in_the_credential_field_creates_no_account(): void {
		// Tablica w polu numeru spełniała `required`, a miękka walidacja dawała tylko ostrzeżenie: konto lekarza
		// powstawało bez numeru. Wartość o złym typie to błąd twardy.
		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit( array(), array( 'pwz' => array( '5425740' ) ) ) );

		$this->assertSame( $before, $this->user_ids() );
		$this->assertSame( 'invalid_type', $flash['errors']['pwz'] ?? '' );
		$this->assertArrayNotHasKey( 'pwz', $flash['warnings'] );
	}

	public function test_a_profession_requiring_a_credential_never_gets_an_account_without_one(): void {
		$before = $this->user_ids();
		foreach ( array( null, '', '   ', '---', array(), array( '' ), array( '5425740' ), array( 'a' => array( 'b' ) ) ) as $bad ) {
			$this->post_and_submit( array(), array( 'pwz' => $bad ) );
			$this->assertSame( $before, $this->user_ids(), var_export( $bad, true ) );
		}

		$id = $this->register_ok();
		$this->assertSame( '5425740', get_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'pwz', true ) );
	}

	public function test_values_are_stored_as_sanitised_text_with_the_acf_prefix(): void {
		// Tablica w polu INNYM niż pole numeru: w polu numeru jest dziś błędem twardym, więc konta nie ma
		// i zapytanie o zserializowaną wartość nie miałoby czego sprawdzać. Metadane czyta później kod
		// deklarujący `string`, a zserializowana tablica wywaliłaby go na TypeError.
		global $wpdb;
		$id = $this->register_ok(
			array( RegistrationForm::PROFESSION => 'pielegniarka' ),
			array( 'pwz' => null, 'notatka' => array( 'kardiologia', 'alergologia' ) )
		);

		$this->assertSame( 'kardiologia, alergologia', get_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'notatka', true ) );
		$serialized = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key NOT LIKE %s AND meta_value LIKE %s", $id, '%capabilities', 'a:%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', (string) $serialized );
	}

	public function test_markup_in_a_text_field_is_stripped_when_saved(): void {
		// Sanityzacja przy zapisie; eskejpowania przy renderze ten test nie dowodzi (atrapa ACF nie renderuje wartości).
		$id = $this->register_ok( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null, 'notatka' => '<script>alert(1)</script><b>Tekst</b>' ) );

		$this->assertSame( 'Tekst', get_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'notatka', true ) );
	}

	public function test_a_field_named_like_account_capabilities_is_stored_under_the_prefix_and_cannot_change_the_role(): void {
		$id = $this->register_ok(
			array( RegistrationForm::PROFESSION => 'pielegniarka' ),
			array( 'pwz' => null, $this->capabilitiesKey() => 'a:1:{s:13:"administrator";b:1;}' )
		);

		$this->assertSame( array( 'subscriber' ), get_userdata( $id )->roles );
		$this->assertFalse( user_can( $id, 'manage_options' ) );
		$this->assertSame( 'a:1:{s:13:"administrator";b:1;}', get_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . $this->capabilitiesKey(), true ) );
	}

	public function test_credential_warning_does_not_block_the_submission(): void {
		// Walidacja numeru jest miękka: zła suma kontrolna przechodzi, redakcja zweryfikuje.
		$email = $this->email();
		$this->post( array( RegistrationForm::EMAIL => $email ), array( 'pwz' => '1234567' ) );
		$this->submit();

		$createdUserId = email_exists( $email );
		$this->assertNotFalse( $createdUserId );
		$this->assertNotSame( 0, $createdUserId );
		$context = json_decode( (string) $this->log_rows()[0]->context, true );
		$this->assertSame( 'invalid_checksum', $context['credential_warning'] ?? null );
	}

	public function test_required_error_does_not_also_surface_an_empty_warning_for_the_same_field(): void {
		// Same separatory: numer pusty po normalizacji. Wymagane pole dostaje błąd, nie błąd i ostrzeżenie.
		$flash = $this->follow( $this->post_and_submit( array(), array( 'pwz' => '---' ) ) );
		$this->assertSame( 'required', $flash['errors']['pwz'] ?? '' );
		$this->assertArrayNotHasKey( 'pwz', $flash['warnings'] );

		// Ostrzeżenie o innym polu niż błąd zostaje: człowiek może poprawić literówkę w numerze.
		$flash = $this->follow( $this->post_and_submit( array(), array( 'pwz' => '1234567', 'zgoda' => null ) ) );
		$this->assertSame( 'required', $flash['errors']['zgoda'] ?? '' );
		$this->assertSame( 'invalid_checksum', $flash['warnings']['pwz'] ?? '' );
	}

	public function test_registration_is_written_to_the_event_log(): void {
		$id   = $this->register_ok();
		$rows = $this->log_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'registration_created', $rows[0]->action );
		$this->assertSame( $id, (int) $rows[0]->subject_user_id );
		$this->assertStringNotContainsString( '@', (string) $rows[0]->context, 'Adres e-mail nie trafia do logu.' );
	}

	public function test_an_unchecked_required_consent_does_not_satisfy_required(): void {
		// Pole ACF true_false wysyła ukryte "0" także przy odznaczonym checkboksie. Droga end-to-end:
		// żądanie, AcfFields::fromRequest(), BooleanNormalizer z booleanKeys(), walidator.
		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit( array(), array( 'zgoda' => '0' ) ) );

		$this->assertSame( 'required', $flash['errors']['zgoda'] ?? '' );
		$this->assertSame( $before, $this->user_ids() );

		$id = $this->register_ok( array(), array( 'zgoda' => '1' ) );
		$this->assertSame( '1', get_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'zgoda', true ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function not_a_consent(): array {
		return array(
			'nie'      => array( 'nie' ),
			'tablica'  => array( array( '1' ) ),
			'dwa'      => array( '2' ),
			'true'     => array( 'true' ),
			'zero'     => array( '0' ),
			'pusty'    => array( '' ),
		);
	}

	/**
	 * @dataProvider not_a_consent
	 *
	 * @param mixed $value Wartość pola zgody w żądaniu.
	 */
	public function test_only_a_checked_box_satisfies_a_required_consent( mixed $value ): void {
		$before = $this->user_ids();
		$flash  = $this->follow( $this->post_and_submit( array(), array( 'zgoda' => $value ) ) );

		$this->assertSame( 'required', $flash['errors']['zgoda'] ?? '' );
		$this->assertSame( $before, $this->user_ids() );
	}

	public function test_boolean_keys_come_from_the_field_types_not_from_a_name(): void {
		// Wymagana zgoda o innej nazwie niż w pozostałych testach: lista pól logicznych musi pochodzić
		// z typów w definicji ACF, a nie z listy wpisanej na sztywno.
		remove_all_filters( 'pusers_acf_fields' );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
				array( 'name' => 'akceptuje_rodo', 'label' => 'RODO', 'type' => 'true_false' ),
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'akceptuje_rodo' => 'required' ) ) ) );

		$flash = $this->follow( $this->post_and_submit( array(), array( 'zgoda' => null, 'akceptuje_rodo' => '0' ) ) );
		$this->assertSame( 'required', $flash['errors']['akceptuje_rodo'] ?? '' );
	}

	public function test_a_matrix_requirement_for_a_field_missing_from_the_group_does_not_block_registration(): void {
		// Macierz lekarza wymaga pola `ghost`, którego nie ma w grupie ACF. Walidator zbudowany na
		// surowej macierzy z repozytorium zażądałby pola, którego formularz nie ma jak wypełnić.
		$this->assertContains( 'ghost', FieldMatrixRepository::get()->requiredFields( 'lekarz' ) );
		$id = $this->register_ok();

		$this->assertGreaterThan( 0, $id );
	}

	public function test_n_valid_submissions_pass_and_the_next_one_is_refused_for_the_email_limit(): void {
		$limit = SettingsRepository::get()->registrationLimitPerEmailPerHour;
		$email = $this->email();

		// Pomyłki walidacyjne nie zużywają limitu człowiekowi.
		for ( $i = 0; $i < $limit + 2; $i++ ) {
			$this->post_and_submit( array( RegistrationForm::EMAIL => $email ), array( 'zgoda' => null ) );
		}
		$this->assertFalse( email_exists( $email ) );

		for ( $i = 0; $i < $limit; $i++ ) {
			$id = $this->register_ok( array( RegistrationForm::EMAIL => $email ) );
			wp_delete_user( $id );
			$this->assertFalse( email_exists( $email ) );
		}

		$flash = $this->follow( $this->post_and_submit( array( RegistrationForm::EMAIL => $email ) ) );
		$this->assertFalse( email_exists( $email ), 'Zgłoszenie ponad limit nie zakłada konta.' );
		$this->assertSame( 'too_many', $flash['notice'] );

		$unslashed = wp_unslash( $_POST );
		$request   = is_array( $unslashed ) ? $unslashed : array();
		$this->assertSame( 'email_limit', SpamGuard::check( $_SERVER, $request, $email )->reason() );
	}

	public function test_the_redirect_data_carries_values_to_the_form_but_never_a_password(): void {
		$email    = $this->email();
		$location = $this->post_and_submit( array( RegistrationForm::EMAIL => $email ), array( 'zgoda' => null ) );
		$token    = $this->sentToken();
		$flash    = $this->follow( $location );

		$this->assertStringNotContainsString( 'Haslo', $location );
		$this->assertStringNotContainsString( $email, $location, 'Adres nie trafia do adresu przekierowania.' );
		$this->assertStringNotContainsString( 'S3kret', (string) wp_json_encode( $flash ) );

		// Szew powtórnego wypełnienia formularza jest zasilony, a wyrenderowany formularz hasła nie niesie.
		RegistrationHandler::register();
		$old = apply_filters( RegistrationForm::OLD_INPUT_FILTER, array() );
		$this->assertSame( 'lekarz', $old['profession'] );
		$this->assertSame( $email, $old['email'] );
		$this->assertSame( '5425740', $old['fields']['pwz'] );
		$html = RegistrationForm::render();
		$this->assertStringContainsString( 'value="' . $email . '"', $html );
		$this->assertStringNotContainsString( 'S3kret', $html );
		$this->assertStringContainsString( 'pusers-errors', $html );
		$this->assertNotSame( '', $token );
	}

	public function test_the_password_is_not_in_the_stored_redirect_data(): void {
		$this->post_and_submit( array(), array( 'zgoda' => null ) );
		$stored = get_transient( 'pusers_flash_' . $this->sentToken() );

		$this->assertIsArray( $stored );
		$this->assertStringNotContainsString( 'S3kret', serialize( $stored ) );
	}

	public function test_the_redirect_url_carries_no_key_or_identifier_of_the_stored_data(): void {
		$location = $this->post_and_submit( array(), array( 'zgoda' => null ) );
		$token    = $this->sentToken();

		$this->assertNotSame( '', $token );
		$this->assertStringNotContainsString( $token, $location );
		$this->assertSame( array( RegistrationHandler::FAILED_ARG => '1' ), $this->query_of( $location ) );
	}

	/**
	 * @param string $location Adres.
	 * @return array<string, mixed>
	 */
	private function query_of( string $location ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		return $query;
	}

	public function test_the_key_cookie_is_http_only_lax_and_short_lived(): void {
		$this->post_and_submit( array(), array( 'zgoda' => null ) );

		$this->assertCount( 1, $this->cookies );
		$options = $this->cookies[0]['options'];
		$this->assertTrue( $options['httponly'] );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertSame( is_ssl(), $options['secure'] );
		$this->assertEqualsWithDelta( time() + 10 * MINUTE_IN_SECONDS, $options['expires'], 5 );
		$this->assertSame( '/', $options['path'], 'Ścieżka to strona formularza (tu: strona główna z fallbacku).' );
	}

	public function test_the_redirect_data_is_consumed_on_first_read(): void {
		$location = $this->post_and_submit( array(), array( 'zgoda' => null ) );
		$token    = $this->sentToken();
		$this->assertIsArray( get_transient( 'pusers_flash_' . $token ) );

		$this->follow( $location );

		$this->assertFalse( get_transient( 'pusers_flash_' . $token ), 'Dane znikają po odczycie.' );
	}

	public function test_the_key_cookie_is_cleared_before_output_not_from_the_page_content(): void {
		$location = $this->post_and_submit( array(), array( 'zgoda' => null ) );
		// Liczba ciasteczek PRZED odczytem: asercja po `follow()` liczyłaby kasowanie wysłane z `flash()`
		// jako „wysłane w trakcie renderu” i mutacja „kasuj w flash()” nie zapaliłaby testu.
		$sent = count( $this->cookies );

		$this->follow( $location );
		$this->assertCount( $sent, $this->cookies, 'Sam odczyt danych (flash()) nie wysyła nagłówka: wołają go szablony, już po wyjściu treści.' );

		RegistrationForm::render();
		$this->assertCount( $sent, $this->cookies, 'Render formularza (treść strony) niczego nie wysyła: nagłówki już poszły.' );

		// template_redirect: jeszcze przed treścią.
		RegistrationHandler::on_template_redirect();
		$cleared = end( $this->cookies );
		$this->assertIsArray( $cleared );
		$this->assertSame( '', $cleared['value'] );
		$this->assertLessThan( time(), $cleared['options']['expires'] );
		$this->assertTrue( $cleared['options']['httponly'] );
		$this->assertSame( 'Lax', $cleared['options']['samesite'] );
	}

	public function test_the_key_cookie_is_cleared_after_a_successful_registration_but_not_by_a_stale_marker(): void {
		// Po udanej rejestracji nikt już danych nie przeczyta, a martwe ciasteczko żyłoby dziesięć minut.
		$_COOKIE = array( RegistrationHandler::COOKIE => str_repeat( 'a', 32 ) );
		$_GET    = array( RegistrationHandler::DONE_ARG => '1' );
		$this->cookies = array();
		RegistrationHandler::on_template_redirect();
		$cleared = end( $this->cookies );
		$this->assertIsArray( $cleared, 'Znacznik udanej rejestracji kasuje ciasteczko.' );
		$this->assertSame( '', $cleared['value'] );
		$this->assertLessThan( time(), $cleared['options']['expires'] );

		// Przy znaczniku `stale` nic nie zapisaliśmy, a podrzucony komuś odnośnik z `stale`
		// wyrzucałby mu wpisane wartości, których jeszcze nie zobaczył.
		$_GET          = array( RegistrationHandler::FAILED_ARG => RegistrationHandler::STALE );
		$this->cookies = array();
		RegistrationHandler::on_template_redirect();
		$this->assertSame( array(), $this->cookies );
	}

	public function test_the_page_after_a_submission_is_marked_uncacheable(): void {
		$fired = 0;
		add_action(
			'litespeed_control_set_nocache',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		RegistrationHandler::register();
		$this->assertNotFalse( has_action( 'template_redirect', array( RegistrationHandler::class, 'on_template_redirect' ) ) );

		$_GET = array();
		RegistrationHandler::on_template_redirect();
		$this->assertSame( 0, $fired, 'Zwykła strona formularza może być w cache.' );

		foreach ( array( RegistrationHandler::FAILED_ARG => '1', RegistrationHandler::DONE_ARG => '1' ) as $arg => $value ) {
			$_GET  = array( $arg => $value );
			$fired = 0;
			RegistrationHandler::on_template_redirect();
			$this->assertSame( 1, $fired, $arg );
		}
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		remove_all_actions( 'litespeed_control_set_nocache' );
	}

	public function test_stored_redirect_data_is_size_limited(): void {
		$location = $this->post_and_submit( array(), array( 'zgoda' => null, 'notatka' => str_repeat( 'x', 100000 ), 'pwz' => array_fill( 0, 500, 'y' ) ) );
		$stored   = get_transient( 'pusers_flash_' . $this->sentToken() );

		$this->assertIsArray( $stored );
		$this->assertLessThanOrEqual( 1000, strlen( (string) ( $stored['fields']['notatka'] ?? '' ) ) );
		$this->assertLessThanOrEqual( 50, count( (array) ( $stored['fields']['pwz'] ?? array() ) ) );
		$this->assertLessThan( 20000, strlen( serialize( $stored ) ) );
		$this->assertNotSame( '', $location );
	}

	public function test_the_size_limit_counts_bytes_not_characters(): void {
		// Polska litera to dwa bajty, emoji cztery: limit znakowy przepuszczał dwu- i czterokrotność
		// zadeklarowanego rozmiaru do wp_options (emoji w teście pomijam, bo o jego zapis spiera się
		// jeszcze kodowanie tabeli).
		$this->post_and_submit( array(), array( 'zgoda' => null, 'notatka' => str_repeat( 'ą', 900 ) ) );
		$stored = get_transient( 'pusers_flash_' . $this->sentToken() );

		$this->assertIsArray( $stored );
		$value = (string) ( $stored['fields']['notatka'] ?? '' );
		$this->assertNotSame( '', $value, 'Wartość ma zostać zachowana, tylko przycięta.' );
		$this->assertLessThanOrEqual( 1000, strlen( $value ), '900 polskich znaków to 1800 bajtów: limit musi liczyć bajty.' );
		$this->assertTrue( mb_check_encoding( $value, 'UTF-8' ), 'Przycięcie nie rozcina znaku w połowie.' );
	}

	public function test_stored_redirect_data_truncates_array_keys_too(): void {
		// Klucz tablicy był tylko sanityzowany, nigdy obcinany: cały rozmiar żądania mógł wejść kluczami.
		$this->post_and_submit( array(), array( 'zgoda' => null, 'notatka' => array( str_repeat( 'k', 5000 ) => 'x', str_repeat( 'ą', 400 ) => 'y' ) ) );
		$stored = get_transient( 'pusers_flash_' . $this->sentToken() );

		$this->assertIsArray( $stored );
		$keys = array_keys( (array) ( $stored['fields']['notatka'] ?? array() ) );
		$this->assertNotSame( array(), $keys );
		foreach ( $keys as $key ) {
			$this->assertLessThanOrEqual( 100, strlen( (string) $key ), 'Klucze też podlegają limitowi bajtów.' );
		}
	}

	public function test_stored_redirect_data_has_a_total_size_limit(): void {
		// Limit na pojedynczą wartość nie wystarczy: pięćdziesiąt wartości po kilobajcie to nadal
		// transient rozmiaru żądania w tabeli opcji.
		$this->post_and_submit( array(), array( 'zgoda' => null, 'notatka' => array_fill( 0, 50, str_repeat( 'x', 1000 ) ) ) );
		$stored = get_transient( 'pusers_flash_' . $this->sentToken() );

		$this->assertIsArray( $stored );
		$this->assertLessThan( 12000, strlen( serialize( $stored ) ), 'Limit obowiązuje całą zapisywaną strukturę, nie pojedynczą wartość.' );
		$this->assertNotSame( array(), (array) ( $stored['fields']['notatka'] ?? array() ), 'Część wartości ma przejść: limit przycina, nie wyrzuca wszystkiego.' );
	}

	public function test_stored_redirect_data_is_depth_limited(): void {
		$this->post_and_submit( array(), array( 'zgoda' => null, 'notatka' => array( 'a' => array( 'b' => array( 'c' => 'glebokosc' ) ) ) ) );
		$stored = get_transient( 'pusers_flash_' . $this->sentToken() );

		$this->assertIsArray( $stored );
		$this->assertSame( array(), $stored['fields']['notatka']['a']['b'] ?? null, 'Trzeci poziom zagnieżdżenia jest ucinany.' );
		$this->assertStringNotContainsString( 'glebokosc', serialize( $stored ) );
	}

	public function test_without_the_cookie_the_form_is_empty_and_says_the_submission_failed(): void {
		$location = $this->post_and_submit( array(), array( 'zgoda' => null ) );
		$_GET     = $this->query_of( $location );
		$_COOKIE  = array();

		$flash = RegistrationHandler::flash();
		$this->assertSame( 'lost', $flash['notice'] );
		$this->assertSame( '', $flash['email'] );
		$this->assertSame( array(), $flash['errors'] );

		$html = RegistrationForm::render();
		$this->assertStringContainsString( 'Zgłoszenie nie zostało przyjęte', $html );
		$this->assertStringContainsString( 'name="pusers_profession"', $html );
	}

	public function test_a_forged_or_malformed_cookie_shows_nothing_but_the_failure_notice(): void {
		$_GET = array( RegistrationHandler::FAILED_ARG => '1' );
		foreach ( array( str_repeat( 'a', 32 ), '../../etc' ) as $value ) {
			$_COOKIE = array( RegistrationHandler::COOKIE => $value );
			$flash   = RegistrationHandler::flash();
			$this->assertSame( array(), $flash['errors'] );
			$this->assertSame( 'lost', $flash['notice'] );
		}
		$_GET    = array();
		$_COOKIE = array( RegistrationHandler::COOKIE => str_repeat( 'a', 32 ) );
		$this->assertSame( '', RegistrationHandler::flash()['notice'], 'Bez znacznika w adresie nic się nie czyta.' );
	}

	public function test_spam_refusals_do_not_keep_the_fields(): void {
		foreach ( array(
			array( RegistrationForm::HONEYPOT => 'http://spam.example' ),
			array( RegistrationForm::TIMESTAMP => SpamGuard::stamp( time() ) ),
			array( RegistrationForm::TIMESTAMP => SpamGuard::stamp( time() - 3 * DAY_IN_SECONDS ) ),
		) as $over ) {
			$flash = $this->follow( $this->post_and_submit( $over ) );
			$this->assertNotSame( '', $flash['notice'] );
			$this->assertSame( array(), $flash['fields'] );
		}
	}

	public function test_the_rendered_form_posts_to_a_registered_handler(): void {
		RegistrationHandler::register();
		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match( '/<form[^>]* action="([^"]*admin-post\.php)"/', $html, $form ) );
		$this->assertSame( 1, preg_match( '/<input type="hidden" name="action" value="([^"]+)"/', $html, $m ) );
		$this->assertSame( RegistrationHandler::ACTION, $m[1] );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . $m[1], array( RegistrationHandler::class, 'handle' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . $m[1], array( RegistrationHandler::class, 'handle' ) ) );
	}
}
