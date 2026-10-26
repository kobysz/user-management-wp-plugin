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
			'field_not_configured',
			'matrix_field_not_in_source',
			'matrix_profession_unknown',
			'page_missing',
			'page_not_published',
		);
		$messages = array();
		foreach ( $codes as $code ) {
			$text = IssueMessages::text( SettingsIssue::error( $code, array( 'profession' => 'student', 'field' => 'uczelnia', 'page' => 'Strona logowania' ) ) );
			$this->assertStringNotContainsString( $code, $text, $code . ': komunikat nie może wyciekać kodem.' );
			$messages[ $text ] = true;
		}
		$this->assertCount( count( $codes ), $messages, 'Każdy kod musi mieć inny komunikat.' );
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

	public function test_unknown_code_falls_back_to_a_generic_message(): void {
		$text = IssueMessages::text( SettingsIssue::warning( 'cos_nowego' ) );
		$this->assertNotSame( '', $text );
	}
}
