<?php
/**
 * Testy rodzajów maili i ich zmiennych.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Mail\MailKind;

final class MailKindTest extends TestCase {

	public function test_every_kind_offers_the_shared_variables(): void {
		foreach ( MailKind::cases() as $kind ) {
			foreach ( array( 'email', 'nazwa_portalu', 'link_logowania' ) as $shared ) {
				$this->assertContains( $shared, $kind->variables(), $kind->value );
			}
		}
	}

	public function test_the_reason_variable_belongs_to_the_rejection_only(): void {
		$this->assertContains( 'powod_odrzucenia', MailKind::Rejected->variables() );
		$this->assertNotContains( 'powod_odrzucenia', MailKind::Approved->variables() );
	}

	public function test_the_recovery_link_belongs_to_the_existing_account_mail_only(): void {
		// Mail do osoby, której adres ktoś zgłosił, ma dawać bezpośredni odnośnik do odzyskania hasła
		// (asercja z Planu 3). W pozostałych rodzajach byłby szumem.
		$this->assertContains( 'link_odzyskiwania', MailKind::ExistingAccount->variables() );
		$this->assertNotContains( 'link_odzyskiwania', MailKind::Editorial->variables() );
		$this->assertNotContains( 'link_odzyskiwania', MailKind::PasswordReset->variables() );
	}

	public function test_the_three_kinds_that_already_have_a_public_filter_value_keep_it(): void {
		// Wartości 'confirmation', 'editorial', 'existing_account' są od Planu 3 CZĘŚCIĄ KONTRAKTU
		// filtrów pusers_mail_body / pusers_mail_subject. Zmiana którejkolwiek zepsułaby cudzy kod.
		$this->assertSame( 'confirmation', MailKind::Confirmation->value );
		$this->assertSame( 'editorial', MailKind::Editorial->value );
		$this->assertSame( 'existing_account', MailKind::ExistingAccount->value );
	}
}
