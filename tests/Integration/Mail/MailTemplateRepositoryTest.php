<?php
/**
 * Testy domyślnych szablonów maili i ich nadpisań.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Mail;

use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;
use PortalUsers\Mail\DefaultTemplates;
use PortalUsers\Persistence\MailTemplateRepository;
use PortalUsers\Persistence\OptionStore;
use WP_UnitTestCase;

final class MailTemplateRepositoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionStore::OPTION_MAIL_TEMPLATES );
	}

	public function test_without_an_override_the_default_is_returned(): void {
		$default = DefaultTemplates::for( MailKind::Confirmation );
		$this->assertSame( $default->toArray(), MailTemplateRepository::get( MailKind::Confirmation )->toArray() );
	}

	public function test_an_override_replaces_only_the_field_it_sets(): void {
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto gotowe', '' ) );
		$this->assertSame( 'Konto gotowe', MailTemplateRepository::get( MailKind::Approved )->subject );
		$this->assertSame( DefaultTemplates::for( MailKind::Approved )->body, MailTemplateRepository::get( MailKind::Approved )->body );
	}

	public function test_clearing_a_field_restores_the_default_instead_of_sending_an_empty_mail(): void {
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto gotowe', 'Treść.' ) );
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( '   ', 'Treść.' ) );
		$this->assertSame( DefaultTemplates::for( MailKind::Approved )->subject, MailTemplateRepository::get( MailKind::Approved )->subject );
		$this->assertTrue( MailTemplateRepository::override( MailKind::Approved )->subjectIsEmpty() );
	}

	public function test_a_kind_left_with_no_override_is_removed_from_the_option(): void {
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto gotowe', '' ) );
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( ' ', "\n" ) );
		$this->assertSame( array(), OptionStore::read( OptionStore::OPTION_MAIL_TEMPLATES ) );
	}

	public function test_a_body_keeps_its_trailing_newline(): void {
		MailTemplateRepository::save( MailKind::Rejected, new MailTemplate( '', "Treść.\n" ) );
		$this->assertSame( "Treść.\n", MailTemplateRepository::override( MailKind::Rejected )->body );
	}

	public function test_override_does_not_fall_back_to_the_default(): void {
		$this->assertTrue( MailTemplateRepository::override( MailKind::Rejected )->isEmpty() );
	}

	public function test_overrides_of_different_kinds_do_not_leak_into_each_other(): void {
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'A', 'B' ) );
		$this->assertTrue( MailTemplateRepository::override( MailKind::Rejected )->isEmpty() );
	}

	public function test_a_corrupted_stored_value_yields_the_default(): void {
		update_option( OptionStore::OPTION_MAIL_TEMPLATES, array( 'approved' => 'bzdura' ) );
		$this->assertSame(
			DefaultTemplates::for( MailKind::Approved )->toArray(),
			MailTemplateRepository::get( MailKind::Approved )->toArray()
		);
	}

	public function test_every_kind_has_a_non_empty_default(): void {
		foreach ( MailKind::cases() as $kind ) {
			$this->assertFalse( DefaultTemplates::for( $kind )->isEmpty(), $kind->value );
			$this->assertFalse( DefaultTemplates::for( $kind )->subjectIsEmpty(), $kind->value );
			$this->assertFalse( DefaultTemplates::for( $kind )->bodyIsEmpty(), $kind->value );
		}
	}

	public function test_every_placeholder_used_in_a_default_is_declared_by_its_kind(): void {
		// Szablon domyślny niosący zmienną, której rodzaj nie zna, wyszedłby do lekarza z klamrami w treści.
		foreach ( MailKind::cases() as $kind ) {
			$template = DefaultTemplates::for( $kind );
			preg_match_all( '/\{([a-z0-9_]+)\}/i', $template->subject . ' ' . $template->body, $m );
			foreach ( array_unique( $m[1] ) as $used ) {
				$this->assertContains( $used, $kind->variables(), $kind->value . ' => {' . $used . '}' );
			}
		}
	}

	public function test_the_defaults_carry_the_placeholders_their_purpose_needs(): void {
		$needs = array(
			'confirmation'         => array( 'link_potwierdzenia' ),
			'editorial'            => array( 'pola_zgloszenia', 'link_wniosku' ),
			'editorial_auto'       => array( 'pola_zgloszenia' ),
			'application_received' => array( 'link_logowania' ),
			'approved'             => array( 'link_logowania' ),
			'rejected'             => array( 'powod_odrzucenia' ),
			'password_reset'       => array( 'link_hasla', 'login' ),
		);
		foreach ( $needs as $value => $placeholders ) {
			$body = DefaultTemplates::for( MailKind::from( $value ) )->body;
			foreach ( $placeholders as $placeholder ) {
				$this->assertStringContainsString( '{' . $placeholder . '}', $body, $value );
			}
		}
	}

	public function test_the_deliberate_sentences_of_the_confirmation_and_existing_account_mails_survive(): void {
		$confirmation = DefaultTemplates::for( MailKind::Confirmation )->body;
		$this->assertStringContainsString( 'Hasła nie ustawia się w formularzu zgłoszenia', $confirmation );
		$this->assertStringContainsString( 'Jeżeli to nie Ty zakładałeś konto, zignoruj tę wiadomość.', $confirmation );

		$existing = DefaultTemplates::for( MailKind::ExistingAccount )->body;
		$this->assertStringContainsString( 'Nic się w nim nie zmieniło.', $existing );
		$this->assertStringContainsString( 'Jeżeli to nie Ty, zignoruj tę wiadomość. Nie musisz nic robić.', $existing );
	}

	public function test_the_option_is_listed_for_uninstall(): void {
		$this->assertContains( OptionStore::OPTION_MAIL_TEMPLATES, OptionStore::all() );
	}
}
