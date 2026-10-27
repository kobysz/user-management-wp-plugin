<?php
/**
 * Testy mapowania kodów problemów i powodów na komunikaty.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\IssueMessages;
use PortalUsers\Domain\Config\SettingsIssue;
use WP_UnitTestCase;

final class IssueMessagesTest extends WP_UnitTestCase {

	public function test_every_known_code_has_its_own_message(): void {
		$codes    = array(
			'profession_without_matrix_row',
			'credential_field_not_required',
			'credential_field_wrong_type',
			'field_not_configured',
			'matrix_field_not_in_source',
			'matrix_profession_unknown',
			'page_missing',
			'page_not_published',
			'default_role_dangerous_capability',
			'default_role_unknown',
			'purge_window_too_long',
			'purge_window_shorter_than_link',
		);
		$messages = array();
		foreach ( $codes as $code ) {
			$text = IssueMessages::text( SettingsIssue::error( $code, array( 'profession' => 'student', 'field' => 'uczelnia', 'page' => 'Strona logowania', 'role' => 'editor', 'capability' => 'unfiltered_html', 'type' => 'checkbox' ) ) );
			$this->assertStringNotContainsString( $code, $text, $code . ': komunikat nie może wyciekać kodem.' );
			$messages[ $text ] = true;
		}
		$this->assertCount( count( $codes ), $messages, 'Każdy kod musi mieć inny komunikat.' );
	}

	public function test_the_default_role_message_names_the_role_and_the_capability(): void {
		// Bez nazwy uprawnienia administrator nie wie, co odebrać ani dlaczego rejestracja nie działa.
		$text = IssueMessages::text( SettingsIssue::error( 'default_role_dangerous_capability', array( 'role' => 'editor', 'capability' => 'unfiltered_html' ) ) );
		$this->assertStringContainsString( 'editor', $text );
		$this->assertStringContainsString( 'unfiltered_html', $text );

		$unknown = IssueMessages::text( SettingsIssue::error( 'default_role_unknown', array( 'role' => 'czytelnik' ) ) );
		$this->assertStringContainsString( 'czytelnik', $unknown );
		$this->assertStringNotContainsString( 'uprawnienie', $unknown, 'Brak roli to inna przyczyna niż uprawnienie niebezpieczne.' );
	}

	public function test_the_credential_field_type_message_names_the_field_and_its_type(): void {
		// Bez nazwy pola i jego typu administrator nie wie, co poprawić.
		$text = IssueMessages::text( SettingsIssue::error( 'credential_field_wrong_type', array( 'profession' => 'lekarz', 'field' => 'pwz', 'type' => 'checkbox' ) ) );

		$this->assertStringContainsString( 'lekarz', $text );
		$this->assertStringContainsString( 'pwz', $text );
		$this->assertStringContainsString( 'checkbox', $text );
	}

	public function test_the_credential_field_type_message_claims_no_certainty_that_is_false_for_some_types(): void {
		// Reguła zgłasza KAŻDY typ spoza listy, także `email` i jednowartościowy `select`/`radio`,
		// przy których rejestracja działa. Komunikat kłamiący o skutku operacji odbiera
		// administratorowi zaufanie do raportu spójności, a raport jest tu jedynym kanałem dla
		// złej konfiguracji.
		foreach ( array( 'email', 'select', 'checkbox' ) as $type ) {
			$text = IssueMessages::text( SettingsIssue::error( 'credential_field_wrong_type', array( 'profession' => 'lekarz', 'field' => 'pwz', 'type' => $type ) ) );
			$this->assertStringNotContainsString( 'nigdy', $text, $type );
			$this->assertStringContainsString( $type, $text, $type );
		}
	}

	public function test_message_names_the_profession_and_field(): void {
		$text = IssueMessages::text( SettingsIssue::warning( 'field_not_configured', array( 'profession' => 'student', 'field' => 'uczelnia' ) ) );
		$this->assertStringContainsString( 'student', $text );
		$this->assertStringContainsString( 'uczelnia', $text );
	}

	public function test_reasons_are_mapped_separately_from_codes(): void {
		// Powód REASON_CREDENTIAL_FIELD_NOT_REQUIRED ma tę samą wartość co kod
		// credential_field_not_required: wspólna tablica pomyliłaby oba pojęcia.
		$context  = array( 'profession' => 'lekarz', 'field' => 'pwz' );
		$unset    = IssueMessages::text( SettingsIssue::error( 'credential_field_not_required', $context + array( 'reason' => SettingsIssue::REASON_CREDENTIAL_FIELD_UNSET ) ) );
		$notReq   = IssueMessages::text( SettingsIssue::error( 'credential_field_not_required', $context + array( 'reason' => SettingsIssue::REASON_CREDENTIAL_FIELD_NOT_REQUIRED ) ) );
		$noReason = IssueMessages::text( SettingsIssue::error( 'credential_field_not_required', $context ) );

		$this->assertNotSame( $unset, $notReq );
		$this->assertStringContainsString( 'lekarz', $unset );
		$this->assertStringContainsString( 'lekarz', $notReq );
		// Pole nazwane w powodzie "niewymagane" nie występuje w powodzie "niewskazane".
		$this->assertStringContainsString( 'pwz', $notReq );
		$this->assertStringNotContainsString( 'pwz', $unset );
		$this->assertNotSame( '', $noReason );
	}

	public function test_required_field_outside_the_source_says_registration_is_blocked(): void {
		$context = array( 'profession' => 'lekarz', 'field' => 'pwz_stare' );
		$blocked = IssueMessages::text( SettingsIssue::error( 'matrix_required_field_not_in_source', $context ) );
		$plain   = IssueMessages::text( SettingsIssue::warning( 'matrix_field_not_in_source', $context ) );
		$this->assertStringContainsString( 'zablokowana', $blocked );
		$this->assertStringContainsString( 'lekarz', $blocked );
		$this->assertStringContainsString( 'pwz_stare', $blocked );
		$this->assertStringNotContainsString( 'zablokowana', $plain );
		$this->assertStringNotContainsString( 'matrix_required_field_not_in_source', $blocked );
	}

	public function test_missing_field_messages_mention_unsupported_field_types(): void {
		$context = array( 'profession' => 'lekarz', 'field' => 'dane' );
		foreach ( array( SettingsIssue::error( 'matrix_required_field_not_in_source', $context ), SettingsIssue::warning( 'matrix_field_not_in_source', $context ) ) as $issue ) {
			$text = IssueMessages::text( $issue );
			$this->assertStringContainsString( 'typ nieobsługiwany przez wtyczkę', $text, $issue->code );
			$this->assertStringContainsString( 'kontener', $text, $issue->code );
		}
	}

	public function test_unknown_code_falls_back_to_a_generic_message(): void {
		$text = IssueMessages::text( SettingsIssue::warning( 'cos_nowego' ) );
		$this->assertNotSame( '', $text );
	}
}
