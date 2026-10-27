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
 * Z łańcucha (`a, b, c`) bierzemy OSTATNI niepusty wpis: obsługujemy JEDNO zaufane proxy, które dopisuje
 * adres połączenia do nagłówka, więc prawy wpis jest tym, który wpisało samo proxy, a lewe wpisy klient
 * podstawia dowolnie. Łańcuch wielu zaufanych proxy wymagałby konfigurowanej głębokości. Dla REMOTE_ADDR
 * nic się nie zmienia.
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
			$parts     = array_values( array_filter( array_map( 'trim', explode( ',', $server[ $trustedHeader ] ) ), static fn ( string $part ): bool => '' !== $part ) );
			$candidate = array() === $parts ? '' : $parts[ count( $parts ) - 1 ];
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
