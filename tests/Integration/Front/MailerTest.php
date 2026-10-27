<?php
/**
 * Testy treści i odbiorców wiadomości rejestracji.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\Mailer;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class MailerTest extends WP_UnitTestCase {

	/**
	 * Wiadomości przechwycone zamiast wysłania.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->mails[] = (array) $atts;
				return true;
			},
			10,
			2
		);
		SettingsRepository::save( Settings::fromArray( array( 'notify_emails' => array( 'redakcja@example.test' ) ) ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'notatka' => 'visible', 'sekret' => 'hidden' ) ) ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
					array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka' ),
				)
			)
		);
	}

	private function account( string $profession, string $pwz = '' ): int {
		$id = (int) self::factory()->user->create( array( 'user_email' => 'osoba@example.test' ) );
		update_user_meta( $id, UserMeta::PROFESSION, $profession );
		if ( '' !== $pwz ) {
			update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'pwz', $pwz );
		}
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'sekret', 'TAJNE-DANE' );
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'notatka', "Pierwsza linia\nDruga linia" );
		AccountStatusRepository::set( $id, AccountStatus::Pending );
		return $id;
	}

	public function test_the_editorial_mail_summarises_the_fields_and_marks_a_valid_credential(): void {
		$id = $this->account( 'lekarz', '5425740' );

		$this->assertTrue( Mailer::editorial( $id ) );

		$body = (string) $this->mails[0]['message'];
		$this->assertStringContainsString( 'Profesja: Lekarz', $body );
		$this->assertStringContainsString( 'osoba@example.test', $body );
		$this->assertStringContainsString( '- pwz: 5425740', $body );
		$this->assertStringContainsString( '- notatka: Pierwsza linia Druga linia', $body, 'Wartość w jednej linii.' );
		$this->assertStringContainsString( '5425740 (wynik walidacji: poprawny)', $body );
		$this->assertStringContainsString( 'user_id=' . $id, $body );
	}

	public function test_a_field_hidden_for_the_profession_is_not_sent_to_the_editors(): void {
		$id = $this->account( 'lekarz', '5425740' );

		Mailer::editorial( $id );

		$this->assertStringNotContainsString( 'TAJNE-DANE', (string) $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'sekret', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( '- notatka:', (string) $this->mails[0]['message'] );
	}

	public function test_the_credential_number_reaches_the_editors_even_when_the_matrix_hides_its_field(): void {
		// Numer jest podstawą decyzji redakcji: nie zależy od widoczności pola w macierzy.
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden', 'notatka' => 'visible' ) ) ) );
		$id = $this->account( 'lekarz', '5425740' );

		Mailer::editorial( $id );

		$this->assertStringContainsString( '5425740 (wynik walidacji: poprawny)', (string) $this->mails[0]['message'] );
	}

	public function test_the_editorial_mail_marks_an_invalid_credential_with_the_reason(): void {
		$id = $this->account( 'lekarz', '1234567' );

		Mailer::editorial( $id );

		$this->assertStringContainsString( '1234567 (wynik walidacji: niepoprawny, ', (string) $this->mails[0]['message'] );
	}

	public function test_the_editorial_mail_for_a_profession_without_a_credential_says_it_does_not_apply(): void {
		$id = $this->account( 'pielegniarka' );

		Mailer::editorial( $id );

		$this->assertStringContainsString( 'Numer uprawnień: nie dotyczy', (string) $this->mails[0]['message'] );
	}

	public function test_the_confirmation_mail_goes_to_the_user_and_carries_the_link(): void {
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::confirmation( $id, 'https://example.test/rejestracja/?pusers_uid=1&pusers_token=abc' ) );

		$this->assertSame( array( 'osoba@example.test' ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'https://example.test/rejestracja/?pusers_uid=1&pusers_token=abc', (string) $this->mails[0]['message'] );
	}

	public function test_the_confirmation_mail_says_the_password_is_set_from_the_link_and_keeps_the_warning(): void {
		// Zgłoszenie o hasło nie pyta, więc link jest JEDYNĄ drogą do danych logowania; człowiek
		// musi to wiedzieć. Ostrzeżenie zostaje: zgłoszenie cudzego adresu jest nadal możliwe.
		$id = $this->account( 'lekarz' );

		Mailer::confirmation( $id, 'https://example.test/rejestracja/?pusers_uid=1&pusers_token=abc' );

		$body = (string) $this->mails[0]['message'];
		$this->assertStringContainsString( 'ustaw hasło', $body );
		$this->assertStringContainsString( 'Jeżeli to nie Ty zakładałeś konto, zignoruj tę wiadomość.', $body );
	}

	public function test_the_existing_account_mail_goes_only_to_the_owner_and_carries_no_link_to_act_on(): void {
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::existing_account( $id ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'osoba@example.test' ), (array) $this->mails[0]['to'] );
		$body = (string) $this->mails[0]['message'];
		$this->assertStringContainsString( 'już istnieje', $body );
		$this->assertStringContainsString( 'Jeżeli to nie Ty', $body );
		$this->assertStringNotContainsString( 'pusers_token', $body );
		$this->assertStringContainsString( wp_lostpassword_url(), $body );
	}

	public function test_the_subject_can_be_replaced_through_its_filter(): void {
		add_filter( Mailer::FILTER_SUBJECT, static fn (): string => 'Inny temat' );
		$id = $this->account( 'lekarz' );

		Mailer::existing_account( $id );

		$this->assertSame( 'Inny temat', $this->mails[0]['subject'] );
	}

	public function test_a_filter_returning_a_non_string_keeps_the_default_body(): void {
		add_filter( Mailer::FILTER_BODY, static fn (): array => array( 'nie tekst' ) );
		$id = $this->account( 'lekarz' );

		Mailer::existing_account( $id );

		$this->assertStringContainsString( 'już istnieje', (string) $this->mails[0]['message'] );
	}

	public function test_the_per_address_mail_limit_allows_the_configured_number_and_then_refuses(): void {
		SettingsRepository::save( Settings::fromArray( array( 'registration_limit_per_email_per_hour' => 2 ) ) );

		$this->assertTrue( Mailer::may_send_to( 'ktos@example.test', Mailer::PURPOSE_RESEND ) );
		$this->assertTrue( Mailer::may_send_to( 'KTOS@example.test ', Mailer::PURPOSE_RESEND ), 'Adres jest normalizowany.' );
		$this->assertFalse( Mailer::may_send_to( 'ktos@example.test', Mailer::PURPOSE_RESEND ) );
		$this->assertTrue( Mailer::may_send_to( 'ktos-inny@example.test', Mailer::PURPOSE_RESEND ), 'Kubełek jest per adres.' );
		$this->assertFalse( Mailer::may_send_to( '', Mailer::PURPOSE_RESEND ) );
	}
}
