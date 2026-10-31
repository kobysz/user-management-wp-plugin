<?php
/**
 * Testy: SettingsTest.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Config;

use PortalUsers\Domain\Config\FormLayout;
use PortalUsers\Domain\Config\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_match_the_specification(): void {
		$s = Settings::defaults();
		$this->assertTrue( $s->approvalEnabled );
		$this->assertSame( 48, $s->confirmationTokenHours );
		$this->assertSame( 7, $s->unconfirmedPurgeDays );
		$this->assertSame( 3, $s->timeTrapSeconds );
		$this->assertSame( 50, $s->rateLimitPerHour );
		$this->assertSame( 3, $s->registrationLimitPerEmailPerHour );
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

	public function test_rejects_addresses_that_would_split_or_corrupt_the_recipient_list(): void {
		foreach ( array( 'a,b@x.co', 'a<b@x.co', 'a>b@x.co', 'a;b@x.co', 'a b@x.co', 'a@x,y.co', '.a@x.co', 'a..b@x.co' ) as $bad ) {
			$s = Settings::fromArray( array( 'notify_emails' => array( $bad ) ) );
			$this->assertSame( array(), $s->notifyEmails, $bad . ' nie jest poprawnym adresem odbiorcy.' );
		}
	}

	public function test_accepts_addresses_with_dot_plus_underscore_and_hyphen(): void {
		$good = array( 'jan.kowalski@x.co', 'jan+portal@x.co', 'jan_k@x.co', 'jan-k@x.co', 'a@mail.example-host.co' );
		$s    = Settings::fromArray( array( 'notify_emails' => $good ) );
		$this->assertSame( $good, $s->notifyEmails );
	}

	/**
	 * Zacieśnienie wzorca pod przecinek nie może odciąć adresów prawdziwych: placówka
	 * w domenie z polskimi znakami przestałaby po cichu dostawać powiadomienia o zgłoszeniach.
	 */
	public function test_accepts_addresses_with_non_ascii_letters(): void {
		$good = array( 'biuro@szpital-łódź.pl', 'ńowak@x.co' );
		$s    = Settings::fromArray( array( 'notify_emails' => $good ) );
		$this->assertSame( $good, $s->notifyEmails );
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
				'registration_limit_per_email_per_hour' => 0,
				'time_trap_seconds'        => -1,
			)
		);
		$this->assertSame( 1, $s->confirmationTokenHours );
		$this->assertSame( 1, $s->unconfirmedPurgeDays );
		$this->assertSame( 1, $s->rateLimitPerHour );
		$this->assertSame( 1, $s->registrationLimitPerEmailPerHour );
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

	public function test_new_keys_fall_back_to_defaults_when_absent(): void {
		// Zapisane konfiguracje nie znają nowych kluczy; dopełnienie musi zadziałać, inaczej
		// aktualizacja wtyczki zeruje progi i odcina ludzi.
		$settings = Settings::fromArray( array() );

		$this->assertSame( 200, $settings->loginLimitPerHour );
		$this->assertSame( 10, $settings->loginLimitPerAccountPerHour );
		$this->assertSame( 50, $settings->resetLimitPerHour );
		$this->assertSame( 3, $settings->resetLimitPerEmailPerHour );
		$this->assertSame( 0, $settings->afterLoginPageId );
		$this->assertEquals( $settings, Settings::defaults() );
	}

	public function test_a_threshold_of_zero_or_below_falls_back_to_the_default(): void {
		// Próg 0 znaczyłby „nikt nigdy", czyli zamknięte logowanie dla wszystkich.
		$this->assertSame( 200, Settings::fromArray( array( 'login_limit_per_hour' => 0 ) )->loginLimitPerHour );
		$this->assertSame( 200, Settings::fromArray( array( 'login_limit_per_hour' => -5 ) )->loginLimitPerHour );
		$this->assertSame( 10, Settings::fromArray( array( 'login_limit_per_account_per_hour' => 0 ) )->loginLimitPerAccountPerHour );
		$this->assertSame( 50, Settings::fromArray( array( 'reset_limit_per_hour' => -1 ) )->resetLimitPerHour );
		$this->assertSame( 3, Settings::fromArray( array( 'reset_limit_per_email_per_hour' => 'bzdura' ) )->resetLimitPerEmailPerHour );
	}

	public function test_the_after_login_page_is_never_negative(): void {
		$this->assertSame( 0, Settings::fromArray( array( 'after_login_page_id' => -3 ) )->afterLoginPageId );
	}

	public function test_the_round_trip_through_to_array_preserves_new_keys(): void {
		$settings = Settings::fromArray(
			array(
				'login_limit_per_hour'             => 77,
				'login_limit_per_account_per_hour' => 21,
				'reset_limit_per_hour'             => 33,
				'reset_limit_per_email_per_hour'   => 8,
				'after_login_page_id'              => 12,
			)
		);
		$again    = Settings::fromArray( $settings->toArray() );

		$this->assertSame( 77, $again->loginLimitPerHour );
		$this->assertSame( 21, $again->loginLimitPerAccountPerHour );
		$this->assertSame( 33, $again->resetLimitPerHour );
		$this->assertSame( 8, $again->resetLimitPerEmailPerHour );
		$this->assertSame( 12, $again->afterLoginPageId );
	}

	public function test_round_trip_preserves_non_default_values_in_every_field(): void {
		$s = new Settings(
			false,
			11,
			12,
			13,
			15,
			array( 'redakcja@example.test' ),
			24,
			14,
			20,
			4,
			301,
			11,
			61,
			6,
			5,
			'HTTP_X_REAL_IP',
			array( 'numer nieprawidłowy' ),
			true,
			'group_abc',
			'Regulamin',
			'zgoda',
			FormLayout::Card,
			true
		);

		$this->assertSame(
			array(
				'approval_enabled',
				'login_page_id',
				'register_page_id',
				'reset_page_id',
				'after_login_page_id',
				'notify_emails',
				'confirmation_token_hours',
				'unconfirmed_purge_days',
				'rate_limit_per_hour',
				'registration_limit_per_email_per_hour',
				'login_limit_per_hour',
				'login_limit_per_account_per_hour',
				'reset_limit_per_hour',
				'reset_limit_per_email_per_hour',
				'time_trap_seconds',
				'trusted_proxy_header',
				'rejection_reasons',
				'delete_data_on_uninstall',
				'acf_group_key',
				'terms_text',
				'terms_consent_field',
				'form_layout',
				'form_styles_enabled',
			),
			array_keys( $s->toArray() )
		);
		$this->assertSame( 4, Settings::fromArray( $s->toArray() )->registrationLimitPerEmailPerHour );
		$again = Settings::fromArray( $s->toArray() );
		$this->assertSame( array( 301, 11, 61, 6, 15 ), array( $again->loginLimitPerHour, $again->loginLimitPerAccountPerHour, $again->resetLimitPerHour, $again->resetLimitPerEmailPerHour, $again->afterLoginPageId ) );
		$this->assertSame( 20, Settings::fromArray( $s->toArray() )->rateLimitPerHour, 'Dwa progi to dwa osobne pola.' );
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

	public function test_domyslny_uklad_formularzy_to_goly(): void {
		$this->assertSame( FormLayout::Plain, Settings::defaults()->formLayout );
		$this->assertSame( FormLayout::Plain, Settings::fromArray( array() )->formLayout );
	}

	public function test_domyslnie_arkusz_nie_jest_doladowany(): void {
		// Skórka portalu zwykle ma już Bootstrapa; doładowanie włącza się świadomie.
		$this->assertFalse( Settings::defaults()->formStylesEnabled );
		$this->assertFalse( Settings::fromArray( array() )->formStylesEnabled );
	}

	public function test_uklad_ze_smieci_wraca_do_golego(): void {
		$this->assertSame( FormLayout::Plain, Settings::fromArray( array( 'form_layout' => 'xx' ) )->formLayout );
		$this->assertSame( FormLayout::Card, Settings::fromArray( array( 'form_layout' => 'karta' ) )->formLayout );
	}

	public function test_uklad_i_arkusz_przechodza_przez_toArray(): void {
		$saved = Settings::fromArray(
			array(
				'form_layout'         => 'karta',
				'form_styles_enabled' => '1',
			)
		)->toArray();

		$this->assertSame( 'karta', $saved['form_layout'] );
		$this->assertTrue( $saved['form_styles_enabled'] );
		$this->assertSame( FormLayout::Card, Settings::fromArray( $saved )->formLayout );
		$this->assertTrue( Settings::fromArray( $saved )->formStylesEnabled );
	}
}
