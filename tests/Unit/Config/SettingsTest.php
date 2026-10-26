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

	public function test_round_trip_preserves_non_default_values_in_every_field(): void {
		$s = new Settings(
			false,
			11,
			12,
			13,
			array( 'redakcja@example.test' ),
			24,
			14,
			20,
			5,
			'HTTP_X_REAL_IP',
			array( 'numer nieprawidłowy' ),
			true,
			'group_abc'
		);

		$this->assertSame(
			array(
				'approval_enabled',
				'login_page_id',
				'register_page_id',
				'reset_page_id',
				'notify_emails',
				'confirmation_token_hours',
				'unconfirmed_purge_days',
				'rate_limit_per_hour',
				'time_trap_seconds',
				'trusted_proxy_header',
				'rejection_reasons',
				'delete_data_on_uninstall',
				'acf_group_key',
			),
			array_keys( $s->toArray() )
		);
		$this->assertSame( $s->toArray(), Settings::fromArray( $s->toArray() )->toArray() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function delete_data_flag_provider(): array {
		return array(
			'true'                      => array( true, true ),
			'string one'                => array( '1', true ),
			'int one'                   => array( 1, true ),
			'false'                     => array( false, false ),
			'string zero'               => array( '0', false ),
			'int zero'                  => array( 0, false ),
			'empty string'              => array( '', false ),
			'unrecognised false string' => array( 'false', false ),
			'unrecognised yes string'   => array( 'yes', false ),
		);
	}

	/**
	 * @dataProvider delete_data_flag_provider
	 *
	 * @param mixed $input    Wartość z zapisu.
	 * @param bool  $expected Oczekiwana flaga.
	 */
	public function test_delete_data_flag_converts_values_including_unrecognised_ones( mixed $input, bool $expected ): void {
		$this->assertSame( $expected, Settings::fromArray( array( 'delete_data_on_uninstall' => $input ) )->deleteDataOnUninstall );
	}

	public function test_delete_data_flag_missing_key_defaults_to_not_deleting(): void {
		$this->assertFalse( Settings::fromArray( array() )->deleteDataOnUninstall );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function approval_flag_provider(): array {
		return array(
			'true'                      => array( true, true ),
			'string one'                => array( '1', true ),
			'int one'                   => array( 1, true ),
			'false'                     => array( false, false ),
			'string zero'               => array( '0', false ),
			'int zero'                  => array( 0, false ),
			'empty string'              => array( '', false ),
			'unrecognised false string' => array( 'false', true ),
			'unrecognised yes string'   => array( 'yes', true ),
		);
	}

	/**
	 * @dataProvider approval_flag_provider
	 *
	 * @param mixed $input    Wartość z zapisu.
	 * @param bool  $expected Oczekiwana flaga.
	 */
	public function test_approval_flag_converts_values_including_unrecognised_ones( mixed $input, bool $expected ): void {
		$this->assertSame( $expected, Settings::fromArray( array( 'approval_enabled' => $input ) )->approvalEnabled );
	}

	public function test_approval_flag_missing_key_defaults_to_enabled(): void {
		$this->assertTrue( Settings::fromArray( array() )->approvalEnabled );
	}
}
