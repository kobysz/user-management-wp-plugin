<?php
/**
 * Decyzja bramy logowania.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Account;

/**
 * Niesie kod odmowy (nie tekst dla użytkownika); decyzja pozwalająca ma pusty kod.
 */
final class LoginDecision {

	/**
	 * Konstruktor prywatny: decyzje tworzą wyłącznie `allow()` i `deny()`.
	 *
	 * @param bool   $allowed Czy logowanie jest dozwolone.
	 * @param string $code    Kod odmowy.
	 */
	private function __construct(
		private readonly bool $allowed,
		private readonly string $code
	) {}

	/**
	 * Decyzja wpuszczająca.
	 *
	 * @return self
	 */
	public static function allow(): self {
		return new self( true, '' );
	}

	/**
	 * Decyzja odmowna z kodem.
	 *
	 * @param string $code Kod odmowy.
	 * @return self
	 */
	public static function deny( string $code ): self {
		return new self( false, $code );
	}

	/**
	 * Czy logowanie jest dozwolone.
	 *
	 * @return bool
	 */
	public function allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Kod odmowy; pusty dla decyzji wpuszczającej.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}
}
