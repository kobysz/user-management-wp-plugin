<?php
/**
 * Wynik walidacji numeru uprawnień.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials;

/**
 * Niesie kod błędu (nie tekst dla użytkownika) albo znormalizowany numer.
 */
final class CredentialResult {

	public const EMPTY            = 'empty';
	public const INVALID_FORMAT   = 'invalid_format';
	public const INVALID_CHECKSUM = 'invalid_checksum';
	public const INVALID_CHAMBER  = 'invalid_chamber';

	/**
	 * Konstruktor prywatny: wynik tworzą wyłącznie `valid()` i `invalid()`.
	 *
	 * @param bool   $valid      Czy numer jest poprawny.
	 * @param string $code       Kod błędu.
	 * @param string $normalized Numer po normalizacji.
	 */
	private function __construct(
		private readonly bool $valid,
		private readonly string $code,
		private readonly string $normalized
	) {
	}

	/**
	 * Wynik poprawny.
	 *
	 * @param string $normalized Numer po normalizacji.
	 * @return self
	 */
	public static function valid( string $normalized ): self {
		return new self( true, '', $normalized );
	}

	/**
	 * Wynik niepoprawny.
	 *
	 * @param string $code Jeden z kodów błędów tej klasy.
	 * @return self
	 */
	public static function invalid( string $code ): self {
		return new self( false, $code, '' );
	}

	/**
	 * Czy numer przeszedł walidację.
	 *
	 * @return bool
	 */
	public function isValid(): bool {
		return $this->valid;
	}

	/**
	 * Kod błędu; pusty ciąg dla wyniku poprawnego.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Numer po normalizacji; pusty ciąg dla wyniku niepoprawnego.
	 */
	public function normalized(): string {
		return $this->normalized;
	}
}
