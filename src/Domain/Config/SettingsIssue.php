<?php
/**
 * Problem wykryty w konfiguracji: kod, waga i kontekst.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

/**
 * Domena zwraca kody problemów, nie teksty. Tłumaczenie kodu na komunikat
 * dla administratora należy do warstwy panelu.
 */
final class SettingsIssue {

	public const ERROR   = 'error';
	public const WARNING = 'warning';

	/**
	 * Konstruktor.
	 *
	 * @param string                $code     Kod problemu.
	 * @param string                $severity Waga: ERROR albo WARNING.
	 * @param array<string, string> $context  Dane potrzebne do opisania problemu.
	 */
	private function __construct(
		public readonly string $code,
		public readonly string $severity,
		public readonly array $context
	) {}

	/**
	 * Błąd: konfiguracja działa inaczej, niż zakładał administrator.
	 *
	 * @param string                $code    Kod problemu.
	 * @param array<string, string> $context Dane potrzebne do opisania problemu.
	 * @return self
	 */
	public static function error( string $code, array $context = array() ): self {
		return new self( $code, self::ERROR, $context );
	}

	/**
	 * Ostrzeżenie: niespójność, która nie psuje zgłoszeń, ale warto ją znać.
	 *
	 * @param string                $code    Kod problemu.
	 * @param array<string, string> $context Dane potrzebne do opisania problemu.
	 * @return self
	 */
	public static function warning( string $code, array $context = array() ): self {
		return new self( $code, self::WARNING, $context );
	}
}
