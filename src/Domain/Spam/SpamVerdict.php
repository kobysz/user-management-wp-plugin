<?php
/**
 * Werdykt antyspamu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Spam;

/**
 * Przepustka albo odmowa z powodem. Powody wtyczki to stałe poniżej; rozszerzenie (np. captcha)
 * może podać własny.
 */
final class SpamVerdict {

	public const HONEYPOT    = 'honeypot';
	public const TOO_FAST    = 'too_fast';
	public const IP_LIMIT    = 'ip_limit';
	public const EMAIL_LIMIT = 'email_limit';

	/**
	 * Konstruktor.
	 *
	 * @param bool   $passed Czy zgłoszenie przechodzi.
	 * @param string $reason Powód odmowy; dla przepustki pusty.
	 */
	private function __construct( private bool $passed, private string $reason ) {}

	/**
	 * Przepustka.
	 */
	public static function pass(): self {
		return new self( true, '' );
	}

	/**
	 * Odmowa.
	 *
	 * @param string $reason Powód.
	 */
	public static function reject( string $reason ): self {
		return new self( false, $reason );
	}

	/**
	 * Czy zgłoszenie przechodzi.
	 */
	public function passed(): bool {
		return $this->passed;
	}

	/**
	 * Powód odmowy; pusty dla przepustki.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
