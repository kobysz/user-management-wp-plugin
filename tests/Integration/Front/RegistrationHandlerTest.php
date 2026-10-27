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

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		RateLimiter::reset_failure_report();
		AcfRenderRecorder::$fields = array();
		$this->redirected          = '';
		$this->originalMethod      = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->originalAddr        = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$_SERVER['REMOTE_ADDR']    = '10.0.0.7';
		$this->cancelRedirect      = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );

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
		$_GET = $query;
		return RegistrationHandler::flash();
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
		$this->assertStringNotContainsString( RegistrationHandler::FLASH_ARG . '=', $location );
	}

	public function test_the_account_gets_the_site_default_role(): void {
		update_option( 'default_role', 'contributor' );
		$id = $this->register_ok( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );

		$this->assertSame( array( 'contributor' ), get_userdata( $id )->roles );
	}

	public function test_a_default_role_with_administrative_capabilities_creates_no_account(): void {
		update_option( 'default_role', 'administrator' );
		$before = $this->user_ids();
		$this->post( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null ) );
		$this->submit();

		$this->assertSame( $before, $this->user_ids() );
	}

	public function test_the_login_is_derived_from_the_address_and_does_not_expose_it(): void {
		$first  = $this->register_ok( array( RegistrationForm::EMAIL => 'anna.nowak@a.example.test' ) );
		$second = $this->register_ok( array( RegistrationForm::EMAIL => 'anna.nowak@b.example.test' ) );

		$this->assertSame( 'anna.nowak', get_userdata( $first )->user_login );
		$this->assertSame( 'anna.nowak-2', get_userdata( $second )->user_login );
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

		$this->assertFalse( metadata_exists( 'user', $id, 'pwz' ) );
		$this->assertSame( 'widoczna', get_user_meta( $id, 'notatka', true ) );
	}

	public function test_missing_nonce_creates_no_account(): void {
		$before = $this->user_ids();
		$this->post( array( RegistrationForm::NONCE_FIELD => null ) );

		try {
			$this->submit();
			$this->fail( 'Brak nonce\'a powinien zakończyć żądanie.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, $this->user_ids() );
		}
	}

	public function test_a_wrong_nonce_creates_no_account(): void {
		$before = $this->user_ids();
		$this->post( array( RegistrationForm::NONCE_FIELD => 'zlynonce' ) );

		try {
			$this->submit();
			$this->fail( 'Zły nonce powinien zakończyć żądanie.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, $this->user_ids() );
		}
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

	public function test_an_email_already_registered_creates_no_second_account_and_says_so(): void {
		$email = $this->email();
		$first = $this->register_ok( array( RegistrationForm::EMAIL => $email ) );
		$flash = $this->follow( $this->post_and_submit( array( RegistrationForm::EMAIL => strtoupper( $email ) ) ) );

		$this->assertSame( 'email_exists', $flash['errors'][ RegistrationForm::EMAIL ] ?? '' );
		$this->assertSame( array( $first ), array_values( array_filter( $this->user_ids(), static fn ( int $id ): bool => get_userdata( $id )->user_email === $email ) ) );
	}

	public function test_values_are_sanitised_before_they_reach_user_meta(): void {
		global $wpdb;
		// Tablica w polu numeru nie wchodzi do metadanych zserializowana (konsument deklarujący string
		// wywaliłby TypeError), a znaczniki w polu tekstowym nie przechodzą.
		$id = $this->register_ok( array(), array( 'pwz' => array( '5425740' ) ) );
		$this->assertFalse( metadata_exists( 'user', $id, 'pwz' ) );
		$serialized = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key NOT LIKE %s AND meta_value LIKE %s", $id, '%capabilities', 'a:%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', (string) $serialized );

		$other = $this->register_ok( array( RegistrationForm::PROFESSION => 'pielegniarka' ), array( 'pwz' => null, 'notatka' => '<script>alert(1)</script><b>Tekst</b>' ) );
		$this->assertSame( 'Tekst', get_user_meta( $other, 'notatka', true ) );
	}

	public function test_a_field_named_like_account_capabilities_cannot_change_the_role(): void {
		$id = $this->register_ok(
			array( RegistrationForm::PROFESSION => 'pielegniarka' ),
			array( 'pwz' => null, $this->capabilitiesKey() => 'a:1:{s:13:"administrator";b:1;}' )
		);

		$this->assertSame( array( 'subscriber' ), get_userdata( $id )->roles );
		$this->assertFalse( user_can( $id, 'manage_options' ) );
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
		$this->assertSame( '1', get_user_meta( $id, 'zgoda', true ) );
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
		$flash    = $this->follow( $location );

		$this->assertStringNotContainsString( 'Haslo', $location );
		$this->assertStringNotContainsString( $email, $location, 'Adres nie trafia do adresu przekierowania.' );
		$this->assertStringNotContainsString( 'S3kret', (string) wp_json_encode( $flash ) );

		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$stored = get_transient( 'pusers_flash_' . $query[ RegistrationHandler::FLASH_ARG ] );
		$this->assertIsArray( $stored );
		$this->assertStringNotContainsString( 'S3kret', serialize( $stored ), 'Hasło nie trafia do danych na czas przekierowania.' );

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
	}

	public function test_redirect_data_is_not_available_without_the_one_time_key(): void {
		$location = $this->post_and_submit( array(), array( 'zgoda' => null ) );
		$this->follow( $location );
		$this->assertNotSame( array(), RegistrationHandler::flash()['errors'] );

		// Inna przeglądarka: bez klucza albo ze zmyślonym kluczem nic nie dostaje.
		$_GET = array();
		$this->assertSame( array(), RegistrationHandler::flash()['errors'] );
		$_GET = array( RegistrationHandler::FLASH_ARG => str_repeat( 'a', 32 ) );
		$this->assertSame( array(), RegistrationHandler::flash()['errors'] );
		$_GET = array( RegistrationHandler::FLASH_ARG => '../../etc' );
		$this->assertSame( array(), RegistrationHandler::flash()['errors'] );
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
