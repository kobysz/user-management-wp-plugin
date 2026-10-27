<?php
/**
 * Testy bramki antyspamu rejestracji.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Spam\SpamVerdict;
use PortalUsers\Front\RateLimiter;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\SpamGuard;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

final class SpamGuardTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		RateLimiter::reset_failure_report();
		$this->configure( array() );
	}

	public function tear_down(): void {
		remove_all_filters( SpamGuard::FILTER );
		RateLimiter::reset_failure_report();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $overrides Ustawienia nadpisujące wartości testowe.
	 */
	private function configure( array $overrides ): void {
		SettingsRepository::save(
			Settings::fromArray(
				array_merge(
					array(
						'rate_limit_per_hour'                   => 20,
						'registration_limit_per_email_per_hour' => 3,
						'time_trap_seconds'                     => 3,
						'trusted_proxy_header'                  => '',
					),
					$overrides
				)
			)
		);
	}

	/**
	 * @param string $ip Adres połączenia.
	 * @return array<string, mixed>
	 */
	private function server( string $ip = '10.0.0.1' ): array {
		return array( 'REMOTE_ADDR' => $ip );
	}

	/**
	 * @param int $age Ile sekund temu załadowano formularz.
	 * @return array<string, mixed>
	 */
	private function request( int $age = 30 ): array {
		return array(
			RegistrationForm::HONEYPOT  => '',
			RegistrationForm::TIMESTAMP => SpamGuard::stamp( time() - $age ),
		);
	}

	/**
	 * @return array<string, mixed> Żądanie z wypełnionym honeypotem.
	 */
	private function filled_honeypot(): array {
		$request                               = $this->request();
		$request[ RegistrationForm::HONEYPOT ] = 'http://spam.example';
		return $request;
	}

	/**
	 * Zgłoszenie, które przeszło walidację: check(), a po przepustce record_registration().
	 *
	 * @param string $email Adres e-mail.
	 * @param string $ip    Adres połączenia.
	 */
	private function register( string $email, string $ip = '10.0.0.1' ): SpamVerdict {
		$verdict = SpamGuard::check( $this->server( $ip ), $this->request(), $email );
		if ( $verdict->passed() ) {
			SpamGuard::record_registration( $email );
		}
		return $verdict;
	}

	/**
	 * @return list<object> Wiersze logu zdarzeń.
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_an_ordinary_registration_passes_and_leaves_no_log_row(): void {
		$verdict = SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' );

		$this->assertTrue( $verdict->passed() );
		$this->assertSame( array(), $this->log_rows() );
	}

	public function test_filled_honeypot_is_rejected(): void {
		$verdict = SpamGuard::check( $this->server(), $this->filled_honeypot(), 'jan@example.test' );

		$this->assertFalse( $verdict->passed() );
		$this->assertSame( 'honeypot', $verdict->reason() );
	}

	public function test_honeypot_sent_as_an_array_is_rejected(): void {
		$request                               = $this->request();
		$request[ RegistrationForm::HONEYPOT ] = array( '' );

		$this->assertSame( 'honeypot', SpamGuard::check( $this->server(), $request, 'jan@example.test' )->reason() );
	}

	public function test_form_sent_faster_than_the_trap_is_rejected(): void {
		$verdict = SpamGuard::check( $this->server(), $this->request( 1 ), 'jan@example.test' );

		$this->assertSame( 'too_fast', $verdict->reason() );
	}

	/**
	 * @return array<string, array{0: callable(): mixed}>
	 */
	public static function unreliable_timestamps(): array {
		return array(
			'missing'                   => array( static fn () => null ),
			'empty'                     => array( static fn () => '' ),
			'bare unsigned timestamp'   => array( static fn () => (string) ( time() - 600 ) ),
			'forged signature'          => array( static fn () => ( time() - 600 ) . '.' . str_repeat( 'a', 32 ) ),
			'signature of another time' => array(
				static function (): string {
					$other = explode( '.', SpamGuard::stamp( time() - 5 ) );
					return ( time() - 600 ) . '.' . $other[1];
				},
			),
			'valid signature, future'   => array( static fn () => SpamGuard::stamp( time() + 3600 ) ),
			'not a string'              => array( static fn () => array( SpamGuard::stamp( time() - 600 ) ) ),
			'older than 24 hours'       => array( static fn () => SpamGuard::stamp( time() - DAY_IN_SECONDS - 60 ) ),
			'signature in upper case'   => array( static fn () => strtoupper( SpamGuard::stamp( time() - 600 ) ) ),
		);
	}

	/**
	 * @dataProvider unreliable_timestamps
	 *
	 * @param callable(): mixed $make Buduje wartość pola znacznika.
	 */
	public function test_missing_forged_or_future_timestamp_is_rejected_as_too_fast( callable $make ): void {
		$request = $this->request();
		$value   = $make();
		if ( null === $value ) {
			unset( $request[ RegistrationForm::TIMESTAMP ] );
		} else {
			$request[ RegistrationForm::TIMESTAMP ] = $value;
		}

		$verdict = SpamGuard::check( $this->server(), $request, 'jan@example.test' );

		$this->assertFalse( $verdict->passed() );
		$this->assertSame( 'too_fast', $verdict->reason() );
	}

	public function test_a_disabled_trap_does_not_need_a_timestamp(): void {
		$this->configure( array( 'time_trap_seconds' => 0 ) );

		$this->assertTrue( SpamGuard::check( $this->server(), array(), 'jan@example.test' )->passed() );
	}

	public function test_many_registrations_from_one_ip_with_different_emails_are_not_blocked_before_the_ip_threshold(): void {
		// Szpital za jednym NAT-em: próg per IP 20, per adres e-mail 3.
		for ( $i = 1; $i <= 19; $i++ ) {
			$verdict = SpamGuard::check( $this->server(), $this->request(), "osoba{$i}@szpital.test" );
			$this->assertTrue( $verdict->passed(), "Rejestracja {$i} z 19 odcięta: " . $verdict->reason() );
		}
		$this->assertTrue( SpamGuard::check( $this->server(), $this->request(), 'osoba20@szpital.test' )->passed(), 'Dwudziesta próba mieści się w progu.' );
	}

	public function test_three_valid_registrations_use_up_the_email_threshold_and_the_fourth_is_rejected(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertTrue( $this->register( 'jan@example.test', "10.0.1.{$i}" )->passed(), "Rejestracja {$i}" );
		}

		$verdict = SpamGuard::check( $this->server( '10.0.1.9' ), $this->request(), 'jan@example.test' );

		$this->assertFalse( $verdict->passed() );
		$this->assertSame( 'email_limit', $verdict->reason(), 'Próg e-mail działa niezależnie od adresu IP.' );
	}

	public function test_invalid_submissions_do_not_use_up_the_email_threshold(): void {
		// Trzy zgłoszenia nieważne (check() przeszedł, walidacja odrzuciła: nikt nie woła record_registration()).
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertTrue( SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' )->passed() );
		}

		$this->assertTrue( $this->register( 'jan@example.test' )->passed(), 'Czwarte, poprawne zgłoszenie przechodzi.' );
	}

	public function test_a_rejection_by_the_extension_point_does_not_use_up_the_email_threshold(): void {
		add_filter( SpamGuard::FILTER, static fn (): SpamVerdict => SpamVerdict::reject( 'turnstile' ) );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertSame( 'turnstile', $this->register( 'jan@example.test' )->reason() );
		}
		remove_all_filters( SpamGuard::FILTER );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertTrue( $this->register( 'jan@example.test' )->passed(), "Rejestracja {$i}" );
		}
		$this->assertSame( 'email_limit', SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' )->reason() );
	}

	public function test_the_email_threshold_ignores_letter_case_and_padding(): void {
		$this->register( 'Jan@Example.test' );
		$this->register( ' jan@example.test ' );
		$this->register( 'JAN@EXAMPLE.TEST' );

		$this->assertSame( 'email_limit', SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' )->reason() );
	}

	public function test_exceeding_the_ip_threshold_is_rejected_and_written_to_the_event_log(): void {
		$this->configure( array( 'rate_limit_per_hour' => 5 ) );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( SpamGuard::check( $this->server(), $this->request(), "o{$i}@example.test" )->passed() );
		}

		$verdict = SpamGuard::check( $this->server(), $this->request(), 'o6@example.test' );

		$this->assertFalse( $verdict->passed() );
		$this->assertSame( 'ip_limit', $verdict->reason() );
		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( SpamGuard::LOG_ACTION, $rows[0]->action );
		$this->assertSame(
			array(
				'reason' => 'ip_limit',
				'ip'     => '10.0.0.1',
			),
			json_decode( (string) $rows[0]->context, true )
		);
	}

	public function test_the_ip_threshold_does_not_block_another_address(): void {
		$this->configure( array( 'rate_limit_per_hour' => 2 ) );
		SpamGuard::check( $this->server(), $this->request(), 'a@example.test' );
		SpamGuard::check( $this->server(), $this->request(), 'b@example.test' );
		$this->assertSame( 'ip_limit', SpamGuard::check( $this->server(), $this->request(), 'c@example.test' )->reason() );

		$this->assertTrue( SpamGuard::check( $this->server( '10.0.0.2' ), $this->request(), 'd@example.test' )->passed() );
	}

	public function test_a_spoofed_forwarding_header_does_not_dodge_the_ip_limit_unless_trusted(): void {
		$this->configure( array( 'rate_limit_per_hour' => 2 ) );
		$results = array();
		foreach ( array( '1.1.1.1', '2.2.2.2', '3.3.3.3' ) as $n => $spoofed ) {
			$server    = $this->server() + array( 'HTTP_X_FORWARDED_FOR' => $spoofed );
			$results[] = SpamGuard::check( $server, $this->request(), "o{$n}@example.test" );
		}

		$this->assertTrue( $results[0]->passed() );
		$this->assertTrue( $results[1]->passed() );
		$this->assertSame( 'ip_limit', $results[2]->reason(), 'Nagłówek niezaufany: liczy się adres połączenia.' );
	}

	public function test_a_trusted_forwarding_header_separates_clients_behind_the_proxy(): void {
		$this->configure(
			array(
				'rate_limit_per_hour'  => 2,
				'trusted_proxy_header' => 'X-Forwarded-For',
			)
		);
		foreach ( array( '1.1.1.1', '2.2.2.2', '3.3.3.3' ) as $n => $client ) {
			$server = $this->server() + array( 'HTTP_X_FORWARDED_FOR' => $client );
			$this->assertTrue( SpamGuard::check( $server, $this->request(), "o{$n}@example.test" )->passed(), $client );
		}
	}

	public function test_a_filled_honeypot_uses_up_the_ip_threshold_and_then_the_ip_limit_applies(): void {
		$this->configure( array( 'rate_limit_per_hour' => 3 ) );
		$reasons = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$reasons[] = SpamGuard::check( $this->server(), $this->filled_honeypot(), 'bot@example.test' )->reason();
		}

		$this->assertSame( array( 'honeypot', 'honeypot', 'honeypot', 'ip_limit', 'ip_limit' ), $reasons );
		$this->assertCount( 1, $this->log_rows(), 'Jeden wiersz na okno, nie jeden na żądanie.' );
	}

	public function test_a_too_fast_form_also_uses_up_the_ip_threshold(): void {
		$this->configure( array( 'rate_limit_per_hour' => 2 ) );
		SpamGuard::check( $this->server(), $this->request( 0 ), 'a@example.test' );
		SpamGuard::check( $this->server(), $this->request( 0 ), 'a@example.test' );

		$this->assertSame( 'ip_limit', SpamGuard::check( $this->server(), $this->request(), 'b@example.test' )->reason() );
	}

	public function test_a_rejection_below_the_threshold_writes_nothing_to_the_log(): void {
		SpamGuard::check( $this->server(), $this->filled_honeypot(), 'bot@example.test' );
		SpamGuard::check( $this->server(), $this->request( 0 ), 'bot@example.test' );

		$this->assertSame( array(), $this->log_rows() );
	}

	public function test_crossing_the_ip_threshold_writes_exactly_one_row_per_window(): void {
		$this->configure( array( 'rate_limit_per_hour' => 2 ) );
		for ( $i = 0; $i < 6; $i++ ) {
			SpamGuard::check( $this->server(), $this->request(), "o{$i}@example.test" );
		}

		$this->assertCount( 1, $this->log_rows() );
	}

	public function test_crossing_the_email_threshold_writes_exactly_one_row(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->register( 'jan@example.test' );
		}
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 'email_limit', SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' )->reason() );
		}

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'email_limit', json_decode( (string) $rows[0]->context, true )['reason'] );
	}

	public function test_a_whitespace_only_honeypot_is_not_a_bot(): void {
		$request                               = $this->request();
		$request[ RegistrationForm::HONEYPOT ] = "  \t";

		$this->assertTrue( SpamGuard::check( $this->server(), $request, 'jan@example.test' )->passed() );
	}

	public function test_a_timestamp_older_than_a_day_is_rejected_and_a_fresh_one_is_not(): void {
		$old = $this->request();
		$old[ RegistrationForm::TIMESTAMP ] = SpamGuard::stamp( time() - SpamGuard::STAMP_MAX_AGE - 60 );
		$ok  = $this->request();
		$ok[ RegistrationForm::TIMESTAMP ]  = SpamGuard::stamp( time() - SpamGuard::STAMP_MAX_AGE + 60 );

		$this->assertSame( 'too_fast', SpamGuard::check( $this->server(), $old, 'jan@example.test' )->reason() );
		$this->assertTrue( SpamGuard::check( $this->server(), $ok, 'jan@example.test' )->passed() );
	}

	public function test_requests_without_a_usable_address_are_not_pooled_into_one_ip_bucket(): void {
		$this->configure( array( 'rate_limit_per_hour' => 2 ) );
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertTrue( SpamGuard::check( array(), $this->request(), "o{$i}@example.test" )->passed(), "Żądanie {$i}" );
		}

		$this->assertSame( 'honeypot', SpamGuard::check( array(), $this->filled_honeypot(), 'x@example.test' )->reason(), 'Honeypot działa bez adresu.' );
		$this->assertSame( 'too_fast', SpamGuard::check( array(), $this->request( 0 ), 'y@example.test' )->reason() );
	}

	public function test_a_failing_counter_store_is_reported_once_per_request_without_changing_the_verdict(): void {
		$block = static function ( string $query ): string {
			return ( str_contains( $query, 'pusers_rl_' ) && 0 === stripos( ltrim( $query ), 'INSERT' ) ) ? 'SELECT 1 FROM dual WHERE 0' : $query;
		};
		add_filter( 'query', $block );
		RateLimiter::reset_failure_report();

		try {
			$first  = SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' );
			$second = SpamGuard::check( $this->server(), $this->request(), 'ola@example.test' );
		} finally {
			remove_filter( 'query', $block );
		}

		$this->assertTrue( $first->passed() );
		$this->assertTrue( $second->passed() );
		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( RateLimiter::FAILURE_LOG_ACTION, $rows[0]->action );
	}

	public function test_extension_point_can_turn_a_pass_into_a_rejection(): void {
		add_filter( SpamGuard::FILTER, static fn (): SpamVerdict => SpamVerdict::reject( 'turnstile' ) );

		$verdict = SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' );

		$this->assertFalse( $verdict->passed() );
		$this->assertSame( 'turnstile', $verdict->reason() );
		$this->assertSame( array(), $this->log_rows(), 'Odmowa rozszerzenia nie pisze do logu.' );
	}

	public function test_extension_point_cannot_turn_a_rejection_into_a_pass(): void {
		add_filter( SpamGuard::FILTER, static fn (): SpamVerdict => SpamVerdict::pass() );

		$this->assertSame( 'honeypot', SpamGuard::check( $this->server(), $this->filled_honeypot(), 'jan@example.test' )->reason() );
		$this->assertSame( 'too_fast', SpamGuard::check( $this->server(), $this->request( 0 ), 'jan@example.test' )->reason() );
		$this->assertSame( 'too_fast', SpamGuard::check( $this->server(), array(), 'jan@example.test' )->reason() );
	}

	public function test_extension_point_cannot_lift_a_threshold_rejection_either(): void {
		add_filter( SpamGuard::FILTER, static fn (): SpamVerdict => SpamVerdict::pass() );
		$this->configure( array( 'rate_limit_per_hour' => 1 ) );
		SpamGuard::check( $this->server(), $this->request(), 'a@example.test' );

		$this->assertSame( 'ip_limit', SpamGuard::check( $this->server(), $this->request(), 'b@example.test' )->reason() );
	}

	public function test_extension_point_returning_something_else_changes_nothing(): void {
		add_filter( SpamGuard::FILTER, static fn () => false );

		$this->assertTrue( SpamGuard::check( $this->server(), $this->request(), 'jan@example.test' )->passed() );
	}

	public function test_extension_point_receives_the_verdict_the_request_and_the_email(): void {
		$seen = array();
		add_filter(
			SpamGuard::FILTER,
			static function ( $verdict, $request, $email ) use ( &$seen ) {
				$seen = array( $verdict, $request, $email );
				return $verdict;
			},
			10,
			3
		);
		$request = $this->request();

		SpamGuard::check( $this->server(), $request, 'jan@example.test' );

		$this->assertInstanceOf( SpamVerdict::class, $seen[0] );
		$this->assertTrue( $seen[0]->passed() );
		$this->assertSame( $request, $seen[1] );
		$this->assertSame( 'jan@example.test', $seen[2] );
	}
}
