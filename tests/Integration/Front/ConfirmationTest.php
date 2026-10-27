<?php
/**
 * Testy potwierdzenia adresu: token, przejście stanu, powiadomienie redakcji i ponowna wysyłka.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\Confirmation;
use PortalUsers\Front\Mailer;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class ConfirmationTest extends WP_UnitTestCase {

	private const GLOBAL_RECIPIENT = 'redakcja@example.test';
	private const NURSE_RECIPIENT  = 'oddzial-pielegniarek@example.test';

	/**
	 * Wiadomości przechwycone zamiast wysłania.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $mails = array();

	private string $redirected = '';
	private int $registerPage  = 0;
	private int $sequence      = 0;

	/**
	 * @var callable
	 */
	private $cancelRedirect;

	private string $originalMethod = 'GET';
	private string $originalAddr   = '';

	public function set_up(): void {
		parent::set_up();
		$this->mails          = array();
		$this->redirected     = '';
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->originalAddr   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
		$this->cancelRedirect   = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->mails[] = (array) $atts;
				return true;
			},
			10,
			2
		);

		$this->registerPage = (int) self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Rejestracja',
			)
		);
		update_option( 'admin_email', 'admin-witryny@example.test' );
		$this->mails = array(); // Zmiana adresu administratora sama wysyła mail.
		$this->configure( true );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
					array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka', 'notify_email' => self::NURSE_RECIPIENT ),
				)
			)
		);
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
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
						'approval_enabled'         => $approval,
						'notify_emails'            => array( self::GLOBAL_RECIPIENT ),
						'confirmation_token_hours' => 48,
						'register_page_id'         => $this->registerPage,
					),
					$over
				)
			)
		);
	}

	/**
	 * Konto w stanie niepotwierdzonym.
	 *
	 * @param string $profession Klucz profesji.
	 */
	private function account( string $profession = 'lekarz', string $pwz = '5425740' ): int {
		$id = (int) self::factory()->user->create( array( 'user_email' => 'osoba' . ++$this->sequence . '@example.test' ) );
		update_user_meta( $id, UserMeta::PROFESSION, $profession );
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'pwz', $pwz );
		AccountStatusRepository::set( $id, AccountStatus::EmailUnconfirmed );
		return $id;
	}

	/**
	 * Wiadomości do danego adresu.
	 *
	 * @param string $address Adres odbiorcy.
	 * @return list<array<string, mixed>>
	 */
	private function mails_to( string $address ): array {
		return array_values(
			array_filter(
				$this->mails,
				static fn ( array $mail ): bool => in_array( $address, (array) $mail['to'], true )
			)
		);
	}

	/**
	 * Token jawny z ostatniego maila z linkiem.
	 */
	private function token_from_last_mail(): string {
		$last = end( $this->mails );
		$this->assertIsArray( $last, 'Spodziewano się maila z linkiem.' );
		$this->assertSame( 1, preg_match( '/' . Confirmation::QUERY_TOKEN . '=([0-9a-f]{64})/', (string) $last['message'], $m ) );
		return $m[1];
	}

	private function status( int $id ): ?AccountStatus {
		return AccountStatusRepository::get( $id );
	}

	/**
	 * @return list<object> Wiersze logu zdarzeń.
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_only_the_token_hash_is_stored(): void {
		$id    = $this->account();
		$token = Confirmation::issue( $id );

		$stored = get_user_meta( $id, UserMeta::CONFIRM_HASH, true );
		$this->assertNotEmpty( $stored );
		$this->assertNotSame( $token, $stored );
		foreach ( get_user_meta( $id ) as $name => $values ) {
			foreach ( $values as $value ) {
				$this->assertStringNotContainsString( $token, (string) $value, 'Token jawny w metadanej ' . $name );
			}
		}
		$this->assertTrue( Confirmation::verify( $id, $token ), 'Skrót musi odpowiadać tokenowi.' );
		foreach ( $this->log_rows() as $row ) {
			$this->assertStringNotContainsString( $token, (string) $row->context, 'Token jawny w logu zdarzeń.' );
		}
	}

	public function test_the_expiry_is_counted_from_the_configured_hours(): void {
		$this->configure( true, array( 'confirmation_token_hours' => 6 ) );
		$id = $this->account();
		Confirmation::issue( $id );

		$expires = (int) get_user_meta( $id, UserMeta::CONFIRM_EXPIRES, true );
		$this->assertEqualsWithDelta( time() + 6 * HOUR_IN_SECONDS, $expires, 5 );
	}

	public function test_a_correct_token_moves_the_account_to_pending(): void {
		$id    = $this->account();
		$token = Confirmation::issue( $id );

		$this->assertTrue( Confirmation::verify( $id, $token ) );
		$this->assertSame( AccountStatus::Pending, $this->status( $id ) );
		$this->assertSame( '', get_user_meta( $id, UserMeta::CONFIRM_HASH, true ), 'Token jest jednorazowy.' );
	}

	public function test_a_wrong_token_changes_nothing(): void {
		$id = $this->account();
		Confirmation::issue( $id );

		$this->assertSame( Confirmation::RESULT_INVALID, Confirmation::confirm( $id, str_repeat( 'a', 64 ) ) );
		$this->assertFalse( Confirmation::verify( $id, '' ) );
		$this->assertSame( AccountStatus::EmailUnconfirmed, $this->status( $id ) );
		$this->assertSame( array(), $this->mails, 'Redakcja nie dostaje maila przy złym tokenie.' );
	}

	public function test_a_token_issued_for_another_account_does_not_confirm_this_one(): void {
		$first  = $this->account();
		$second = $this->account();
		$token  = Confirmation::issue( $first );
		Confirmation::issue( $second );

		$this->assertFalse( Confirmation::verify( $second, $token ) );
		$this->assertSame( AccountStatus::EmailUnconfirmed, $this->status( $second ) );
	}

	public function test_an_expired_token_changes_nothing_and_says_the_link_expired(): void {
		$id    = $this->account();
		$token = Confirmation::issue( $id );
		update_user_meta( $id, UserMeta::CONFIRM_EXPIRES, time() - 60 );

		$this->assertSame( Confirmation::RESULT_EXPIRED, Confirmation::confirm( $id, $token ) );
		$this->assertFalse( Confirmation::verify( $id, $token ) );
		$this->assertSame( AccountStatus::EmailUnconfirmed, $this->status( $id ) );
		$this->assertSame( array(), $this->mails );
		$this->assertStringContainsString( 'wygasł', Confirmation::message_for( Confirmation::RESULT_EXPIRED ) );
	}

	public function test_an_expired_account_with_a_wrong_token_does_not_say_it_expired(): void {
		// „Wygasł” wolno powiedzieć dopiero komuś, kto zna token: inaczej wynik zdradzałby stan cudzego konta.
		$id = $this->account();
		Confirmation::issue( $id );
		update_user_meta( $id, UserMeta::CONFIRM_EXPIRES, time() - 60 );

		$this->assertSame( Confirmation::RESULT_INVALID, Confirmation::confirm( $id, str_repeat( 'b', 64 ) ) );
	}

	/**
	 * @return array<string, array{0: AccountStatus}>
	 */
	public static function decided_statuses(): array {
		return array(
			'approved' => array( AccountStatus::Approved ),
			'rejected' => array( AccountStatus::Rejected ),
			'pending'  => array( AccountStatus::Pending ),
		);
	}

	/**
	 * @dataProvider decided_statuses
	 */
	public function test_a_still_valid_token_does_not_move_a_decided_account_back_to_pending( AccountStatus $decided ): void {
		// Review Focus 5: token zostaje w metadanych, a redakcja zdążyła zdecydować. Klik w stary link nie cofa decyzji.
		$id    = $this->account();
		$token = Confirmation::issue( $id );
		AccountStatusRepository::set( $id, $decided );

		$this->assertFalse( Confirmation::verify( $id, $token ) );
		$this->assertSame( Confirmation::RESULT_ALREADY, Confirmation::confirm( $id, $token ) );
		$this->assertSame( $decided, $this->status( $id ) );
		$this->assertSame( array(), $this->mails, 'Redakcja nie dostaje drugiego maila.' );
	}

	public function test_a_token_used_twice_does_not_move_an_approved_account_back_to_pending(): void {
		$id    = $this->account();
		$token = Confirmation::issue( $id );
		$this->assertTrue( Confirmation::verify( $id, $token ) );
		AccountStatusRepository::set( $id, AccountStatus::Approved );
		$this->mails = array();

		$this->assertSame( Confirmation::RESULT_INVALID, Confirmation::confirm( $id, $token ), 'Token zużyty przy pierwszym potwierdzeniu.' );
		$this->assertSame( AccountStatus::Approved, $this->status( $id ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_with_approval_disabled_confirmation_moves_the_account_straight_to_approved(): void {
		$this->configure( false );
		$id    = $this->account();
		$token = Confirmation::issue( $id );

		$this->assertTrue( Confirmation::verify( $id, $token ) );
		$this->assertSame( AccountStatus::Approved, $this->status( $id ) );
	}

	public function test_editors_are_notified_on_the_transition_in_both_approval_modes(): void {
		$id = $this->account();
		Confirmation::verify( $id, Confirmation::issue( $id ) );
		$pendingMails = $this->mails_to( self::GLOBAL_RECIPIENT );
		$this->assertCount( 1, $pendingMails );
		$this->assertStringContainsString( 'czeka na decyzję', (string) $pendingMails[0]['message'] );
		$this->assertStringContainsString( 'user_id=' . $id, (string) $pendingMails[0]['message'], 'Odnośnik do wniosku.' );

		$this->mails = array();
		$this->configure( false );
		$other = $this->account();
		Confirmation::verify( $other, Confirmation::issue( $other ) );
		$infoMails = $this->mails_to( self::GLOBAL_RECIPIENT );
		$this->assertCount( 1, $infoMails );
		$this->assertStringContainsString( 'informacyjny', (string) $infoMails[0]['message'] );
		$this->assertStringNotContainsString( 'czeka na decyzję', (string) $infoMails[0]['message'] );
		$this->assertStringNotContainsString( 'user-edit.php', (string) $infoMails[0]['message'], 'Bez wezwania do działania.' );
	}

	public function test_notification_goes_to_the_configured_recipients_not_to_the_site_admin(): void {
		$id = $this->account();
		Confirmation::verify( $id, Confirmation::issue( $id ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( self::GLOBAL_RECIPIENT ), (array) $this->mails[0]['to'] );
		$this->assertCount( 0, $this->mails_to( 'admin-witryny@example.test' ) );
	}

	public function test_all_configured_global_recipients_are_notified(): void {
		$this->configure( true, array( 'notify_emails' => array( self::GLOBAL_RECIPIENT, 'druga@example.test' ) ) );
		$id = $this->account();
		Confirmation::verify( $id, Confirmation::issue( $id ) );

		$this->assertSame( array( self::GLOBAL_RECIPIENT, 'druga@example.test' ), (array) $this->mails[0]['to'] );
	}

	public function test_profession_specific_recipient_overrides_the_global_list(): void {
		$id = $this->account( 'pielegniarka', '' );
		Confirmation::verify( $id, Confirmation::issue( $id ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( self::NURSE_RECIPIENT ), (array) $this->mails[0]['to'], 'Adres profesji zastępuje listę globalną, nie dopisuje się do niej.' );
	}

	public function test_without_any_recipient_nobody_is_mailed_and_the_gap_is_logged(): void {
		$this->configure( true, array( 'notify_emails' => array() ) );
		$id = $this->account();
		Confirmation::verify( $id, Confirmation::issue( $id ) );

		$this->assertSame( array(), $this->mails, 'Brak odbiorców nie przechodzi na adres administratora witryny.' );
		$actions = array_map( static fn ( object $row ): string => (string) $row->action, $this->log_rows() );
		$this->assertContains( Mailer::NO_RECIPIENTS_LOG_ACTION, $actions );
		$this->assertSame( AccountStatus::Pending, $this->status( $id ), 'Przejście stanu nie zależy od powodzenia maila.' );
	}

	public function test_the_transition_is_written_to_the_event_log_without_the_token(): void {
		$id    = $this->account();
		$token = Confirmation::issue( $id );
		Confirmation::verify( $id, $token );

		$rows = array_values( array_filter( $this->log_rows(), static fn ( object $row ): bool => Confirmation::LOG_ACTION === $row->action ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, (int) $rows[0]->subject_user_id );
		$this->assertStringContainsString( 'pending', (string) $rows[0]->context );
		$this->assertStringNotContainsString( $token, (string) $rows[0]->context );
	}

	public function test_the_registered_action_sends_the_confirmation_link_with_a_working_token(): void {
		Confirmation::register();
		$id = $this->account();

		do_action( RegistrationHandler::REGISTERED_HOOK, $id );

		$user = get_userdata( $id );
		$this->assertCount( 1, $this->mails_to( $user->user_email ) );
		$token = $this->token_from_last_mail();
		$this->assertStringContainsString( Confirmation::QUERY_USER . '=' . $id, (string) $this->mails[0]['message'] );
		$this->assertNotSame( $token, get_user_meta( $id, UserMeta::CONFIRM_HASH, true ) );
		$this->assertTrue( Confirmation::verify( $id, $token ) );
	}

	public function test_the_link_in_the_mail_confirms_through_the_request_and_redirects_without_the_token(): void {
		Confirmation::register();
		$id = $this->account();
		do_action( RegistrationHandler::REGISTERED_HOOK, $id );
		$token = $this->token_from_last_mail();

		$_GET = array(
			Confirmation::QUERY_USER  => (string) $id,
			Confirmation::QUERY_TOKEN => $token,
		);
		Confirmation::handle();

		$this->assertSame( AccountStatus::Pending, $this->status( $id ) );
		$this->assertStringNotContainsString( $token, $this->redirected, 'Token nie zostaje w adresie po odświeżeniu.' );
		$this->assertStringNotContainsString( Confirmation::QUERY_TOKEN, $this->redirected );
		$this->assertStringContainsString( Confirmation::RESULT_ARG . '=' . Confirmation::RESULT_OK, $this->redirected );
		$this->assertStringContainsString( 'page_id=' . $this->registerPage, $this->redirected );
	}

	public function test_a_request_without_the_token_parameters_does_nothing(): void {
		$id = $this->account();
		Confirmation::issue( $id );
		$_GET = array( Confirmation::QUERY_USER => (string) $id );

		Confirmation::handle();

		$this->assertSame( '', $this->redirected );
		$this->assertSame( AccountStatus::EmailUnconfirmed, $this->status( $id ) );
	}

	public function test_an_expired_link_shows_the_message_and_a_resend_form(): void {
		$_GET = array( Confirmation::RESULT_ARG => Confirmation::RESULT_EXPIRED );
		$html = Confirmation::notice_html();

		$this->assertStringContainsString( 'wygasł', $html );
		$this->assertStringContainsString( 'name="' . Confirmation::EMAIL_FIELD . '"', $html );

		$_GET = array();
		$this->assertSame( '', Confirmation::notice_html() );
	}

	/**
	 * Składa żądanie ponownej wysyłki i wykonuje je.
	 *
	 * @param string $email Adres z formularza.
	 * @param bool   $nonce Czy dołączyć ważny nonce.
	 */
	private function resend( string $email, bool $nonce = true ): string {
		$this->redirected = '';
		$_POST            = wp_slash(
			array(
				Confirmation::EMAIL_FIELD => $email,
				Confirmation::NONCE_FIELD => $nonce ? wp_create_nonce( Confirmation::NONCE_ACTION ) : 'zly',
			)
		);
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Confirmation::handle_resend();
		return $this->redirected;
	}

	public function test_resend_issues_a_new_token_and_invalidates_the_previous_one(): void {
		$id  = $this->account();
		$old = Confirmation::issue( $id );

		$this->resend( get_userdata( $id )->user_email );

		$this->assertCount( 1, $this->mails );
		$new = $this->token_from_last_mail();
		$this->assertNotSame( $old, $new );
		$this->assertFalse( Confirmation::verify( $id, $old ), 'Poprzedni token przestaje działać.' );
		$this->assertTrue( Confirmation::verify( $id, $new ) );
	}

	public function test_resend_does_not_mail_an_account_that_is_already_confirmed(): void {
		$id = $this->account();
		AccountStatusRepository::set( $id, AccountStatus::Approved );

		$this->resend( get_userdata( $id )->user_email );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( AccountStatus::Approved, $this->status( $id ) );
	}

	public function test_resend_is_rate_limited(): void {
		$id    = $this->account();
		$email = get_userdata( $id )->user_email;

		for ( $i = 0; $i < 6; $i++ ) {
			$this->resend( $email );
		}

		$this->assertCount( 3, $this->mails, 'Próg wiadomości na adres na godzinę (registration_limit_per_email_per_hour).' );
	}

	public function test_resend_is_limited_per_client_address_too(): void {
		$this->configure( true, array( 'rate_limit_per_hour' => 2 ) );
		$ids = array( $this->account(), $this->account(), $this->account() );

		foreach ( $ids as $id ) {
			$this->resend( get_userdata( $id )->user_email );
		}

		$this->assertCount( 2, $this->mails, 'Trzeci adres z tego samego IP przekracza próg IP.' );
	}

	public function test_resend_does_not_reveal_whether_the_address_exists(): void {
		$unconfirmed = $this->account();
		$approved    = $this->account();
		AccountStatusRepository::set( $approved, AccountStatus::Approved );

		$known     = $this->resend( get_userdata( $unconfirmed )->user_email );
		$decided   = $this->resend( get_userdata( $approved )->user_email );
		$unknown   = $this->resend( 'nikt-taki@example.test' );
		$malformed = $this->resend( 'to-nie-adres' );

		$this->assertNotSame( '', $known );
		$this->assertSame( $known, $decided );
		$this->assertSame( $known, $unknown );
		$this->assertSame( $known, $malformed );
		$this->assertStringContainsString( Confirmation::RESENT_ARG . '=1', $known );
	}

	public function test_resend_answers_the_same_after_the_limit_is_exceeded(): void {
		$id    = $this->account();
		$email = get_userdata( $id )->user_email;

		$locations = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$locations[] = $this->resend( $email );
		}
		$unknown = $this->resend( 'nikt-taki@example.test' );

		$this->assertCount( 1, array_unique( $locations ) );
		$this->assertSame( $locations[0], $unknown );
	}

	public function test_resend_with_a_bad_nonce_mails_nothing_and_asks_to_refresh(): void {
		$id = $this->account();

		$location = $this->resend( get_userdata( $id )->user_email, false );

		$this->assertSame( array(), $this->mails );
		$this->assertStringContainsString( RegistrationHandler::FAILED_ARG . '=' . RegistrationHandler::STALE, $location );
	}

	public function test_the_unknown_address_resend_counts_in_the_same_bucket_as_a_known_one(): void {
		// Licznik rośnie niezależnie od istnienia konta: inaczej próg zdradzałby istnienie adresu.
		$this->resend( 'nikt-taki@example.test' );
		$this->resend( 'nikt-taki@example.test' );
		$this->resend( 'nikt-taki@example.test' );
		$this->assertFalse( Mailer::may_send_to( 'nikt-taki@example.test' ), 'Czwarta próba przekracza próg także dla nieistniejącego adresu.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function kinds(): array {
		return array(
			'confirmation' => array( Mailer::KIND_CONFIRMATION ),
			'editorial'    => array( Mailer::KIND_EDITORIAL ),
			'existing'     => array( Mailer::KIND_EXISTING ),
		);
	}

	/**
	 * @dataProvider kinds
	 */
	public function test_mail_body_can_be_replaced_through_the_filter( string $kind ): void {
		$seen = array();
		add_filter(
			Mailer::FILTER_BODY,
			static function ( $body, $filterKind, $context ) use ( &$seen ): string {
				$seen[] = array( $filterKind, $context['user_id'] ?? null );
				return 'SZABLON-ZEWNETRZNY';
			},
			10,
			3
		);
		$id = $this->account();
		Confirmation::issue( $id );

		match ( $kind ) {
			Mailer::KIND_CONFIRMATION => Mailer::confirmation( $id, 'https://example.test/?x=1' ),
			Mailer::KIND_EDITORIAL    => Mailer::editorial( $id ),
			default                   => Mailer::existing_account( $id ),
		};

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'SZABLON-ZEWNETRZNY', $this->mails[0]['message'] );
		$this->assertSame( array( array( $kind, $id ) ), $seen );
	}
}
