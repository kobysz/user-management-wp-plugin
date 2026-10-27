<?php
/**
 * Ustalanie adresu IP klienta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Spam;

/**
 * Adres klienta z tablicy serwera. Nagłówek proxy jest czytany WYŁĄCZNIE wtedy, gdy administrator
 * go nazwał w ustawieniach: inaczej każdy klient podstawiałby sobie dowolny adres i omijał limity.
 * Kierunek błędu jest zawsze ten sam: wszystko, co nie wygląda na adres IP, zastępuje REMOTE_ADDR.
 */
final class IpAddress {

	/**
	 * Adres klienta.
	 *
	 * @param array<string, mixed> $server        Tablica serwera ($_SERVER).
	 * @param string               $trustedHeader Nazwa zaufanego nagłówka w postaci z tablicy serwera
	 *                                            (np. HTTP_X_FORWARDED_FOR); pusta = tylko REMOTE_ADDR.
	 * @return string Adres IP albo pusty tekst, gdy serwer nie podał poprawnego adresu połączenia.
	 */
	public static function from( array $server, string $trustedHeader ): string {
		if ( '' !== $trustedHeader && isset( $server[ $trustedHeader ] ) && is_string( $server[ $trustedHeader ] ) ) {
			$parts     = explode( ',', $server[ $trustedHeader ] );
			$candidate = trim( $parts[0] );
			if ( false !== filter_var( $candidate, \FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		$remote = $server['REMOTE_ADDR'] ?? '';
		if ( is_string( $remote ) && false !== filter_var( $remote, \FILTER_VALIDATE_IP ) ) {
			return $remote;
		}
		return '';
	}
}
