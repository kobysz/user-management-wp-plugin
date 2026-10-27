<?php
/**
 * Testy: SpamRules i SpamVerdict.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Spam;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Spam\SpamRules;
use PortalUsers\Domain\Spam\SpamVerdict;

final class SpamRulesTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_verdict_carries_its_outcome_and_reason(): void {
		$this->assertTrue( SpamVerdict::pass()->passed() );
		$this->assertSame( '', SpamVerdict::pass()->reason() );
		$this->assertFalse( SpamVerdict::reject( 'turnstile' )->passed() );
		$this->assertSame( 'turnstile', SpamVerdict::reject( 'turnstile' )->reason() );
	}

	public function test_filled_honeypot_is_rejected(): void {
		$verdict = ( new SpamRules( 3 ) )->check( 'http://spam.example', self::NOW - 60, self::NOW );
		$this->assertFalse( $verdict->passed() );
		$this->assertSame( 'honeypot', $verdict->reason() );
	}

	public function test_honeypot_is_rejected_even_when_the_trap_is_off(): void {
		$verdict = ( new SpamRules( 0 ) )->check( 'x', self::NOW - 60, self::NOW );
		$this->assertSame( 'honeypot', $verdict->reason() );
	}

	public function test_form_sent_faster_than_the_configured_trap_is_rejected(): void {
		$verdict = ( new SpamRules( 3 ) )->check( '', self::NOW - 2, self::NOW );
		$this->assertFalse( $verdict->passed() );
		$this->assertSame( 'too_fast', $verdict->reason() );
	}

	public function test_form_sent_after_the_trap_passes(): void {
		$this->assertTrue( ( new SpamRules( 3 ) )->check( '', self::NOW - 3, self::NOW )->passed() );
		$this->assertTrue( ( new SpamRules( 3 ) )->check( '', self::NOW - 600, self::NOW )->passed() );
	}

	public function test_missing_or_future_load_timestamp_is_rejected_as_too_fast(): void {
		$rules = new SpamRules( 3 );
		foreach ( array( 'missing' => 0, 'negative' => -5, 'future' => self::NOW + 1, 'far future' => self::NOW + 100000 ) as $label => $loadedAt ) {
			$verdict = $rules->check( '', $loadedAt, self::NOW );
			$this->assertFalse( $verdict->passed(), $label );
			$this->assertSame( 'too_fast', $verdict->reason(), $label );
		}
	}

	public function test_disabled_trap_lets_any_timestamp_through(): void {
		$rules = new SpamRules( 0 );
		$this->assertTrue( $rules->check( '', 0, self::NOW )->passed() );
		$this->assertTrue( $rules->check( '', self::NOW, self::NOW )->passed() );
	}
}
