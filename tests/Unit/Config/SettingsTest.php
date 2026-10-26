<?php
/**
 * Testy: SettingsTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Config;

use PortalUsers\Domain\Config\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_match_the_specification(): void {
		$s = Settings::defaults();
		$this->assertTrue( $s->approvalEnabled );
		$this->assertSame( 48, $s->confirmationTokenHours );
		$this->assertSame( 7, $s->unconfirmedPurgeDays );
		$this->assertSame( 3, $s->timeTrapSeconds );
		$this->assertSame( 10, $s->rateLimitPerHour );
		$this->assertFalse( $s->deleteDataOnUninstall );
		$this->assertSame( '', $s->trustedProxyHeader );
		$this->assertSame( array(), $s->notifyEmails );
	}

	public function test_missing_keys_fall_back_to_defaults(): void {
		$s = Settings::fromArray( array( 'approval_enabled' => false ) );
		$this->assertFalse( $s->approvalEnabled );
		$this->assertSame( 48, $s->confirmationTokenHours );
	}

	public function test_round_trips_through_array(): void {
		$s = Settings::defaults();
		$this->assertSame( $s->toArray(), Settings::fromArray( $s->toArray() )->toArray() );
	}

	public function test_keeps_only_syntactically_valid_emails_and_drops_the_rest(): void {
		$s = Settings::fromArray( array( 'notify_emails' => array( 'a@example.test', 'nie-adres', '', 'b@example.test' ) ) );
		$this->assertSame( array( 'a@example.test', 'b@example.test' ), $s->notifyEmails );
	}

	public function test_deduplicates_and_trims_emails_case_insensitively(): void {
		$s = Settings::fromArray( array( 'notify_emails' => array( ' A@example.test ', 'a@example.test' ) ) );
		$this->assertSame( array( 'a@example.test' ), $s->notifyEmails );
	}

	public function test_clamps_numeric_settings_to_sane_ranges(): void {
		$s = Settings::fromArray(
			array(
				'confirmation_token_hours' => 0,
				'unconfirmed_purge_days'   => -5,
				'rate_limit_per_hour'      => 0,
				'time_trap_seconds'        => -1,
			)
		);
		$this->assertSame( 1, $s->confirmationTokenHours );
		$this->assertSame( 1, $s->unconfirmedPurgeDays );
		$this->assertSame( 1, $s->rateLimitPerHour );
		$this->assertSame( 0, $s->timeTrapSeconds );
	}

	public function test_non_numeric_values_fall_back_to_defaults(): void {
		$s = Settings::fromArray(
			array(
				'confirmation_token_hours' => 'bzdura',
				'login_page_id'            => array(),
			)
		);
		$this->assertSame( 48, $s->confirmationTokenHours );
		$this->assertSame( 0, $s->loginPageId );
	}

	public function test_page_ids_are_never_negative(): void {
		$this->assertSame( 0, Settings::fromArray( array( 'login_page_id' => -3 ) )->loginPageId );
	}

	public function test_rejection_reasons_are_trimmed_and_emptied_entries_dropped(): void {
		$s = Settings::fromArray( array( 'rejection_reasons' => array( ' numer nieprawidłowy ', '', '   ' ) ) );
		$this->assertSame( array( 'numer nieprawidłowy' ), $s->rejectionReasons );
	}

	public function test_trusted_proxy_header_is_normalised_to_a_header_name(): void {
		$this->assertSame( 'HTTP_X_FORWARDED_FOR', Settings::fromArray( array( 'trusted_proxy_header' => ' x-forwarded-for ' ) )->trustedProxyHeader );
	}
}
