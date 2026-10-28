<?php
/**
 * Trasy użytkowników REST zamknięte dla niezalogowanych.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

/**
 * Rdzeń odpowiada na `/wp/v2/users/<id>` kodem `rest_user_invalid_id` (404) dla identyfikatora
 * nieistniejącego i `rest_user_cannot_view` (403) dla istniejącego, więc „czy pojawił się nowy
 * wiersz użytkownika” czyta się bez uwierzytelnienia. Portal, który chowa rejestrację za
 * ogólnym komunikatem, a tę samą wiedzę oddaje przez REST, niczego nie ukrył. Trasy
 * `application-passwords` mają to samo zachowanie (`get_user()` w kontrolerze zwraca
 * `rest_user_invalid_id` PRZED sprawdzeniem uprawnień), więc idą razem z nimi.
 *
 * Mechanizm to filtr `rest_endpoints`, nie `permission_callback`: odmowa z uprawnień trasy
 * zostawiałaby trasę zarejestrowaną, a kod odpowiedzi dla istniejącego i nieistniejącego
 * identyfikatora rdzeń ustala już w ciele kontrolera. Bez trasy obcy dostaje jedno i to samo
 * `rest_no_route` dla każdego identyfikatora. Filtr odpala się przy składaniu tablicy tras,
 * czyli PO rozpoznaniu użytkownika, więc `is_user_logged_in()` jest tam wiarygodne.
 *
 * To zupełnie inne pytanie niż to z `LoginGateway::gate_rest()` („czy to KONTO może jeszcze
 * używać REST”): tu pytamy, czy obcy może czytać trasę. Obie decyzje stoją osobno.
 *
 * Zalogowany (panel i blokowy edytor czytają tę trasę) oraz portal z włączonym
 * `FILTER_PUBLIC` dostają tablicę bez zmian. `/wp/v2/users/me` zostaje: dotyczy wyłącznie
 * zalogowanego i nic nie ujawnia.
 *
 * Rejestracja jedzie z `Plugin::boot()`, poza hookiem zależnym od ACF Pro: to kontrola
 * ujawniania, a taka znikająca razem z zależnością byłaby fail-open.
 */
final class RestUsers {

	/**
	 * Filtr portalu: `true` zostawia trasy użytkowników otwarte dla niezalogowanych (np. motyw
	 * wypisujący autorów wpisów anonimowo).
	 */
	public const FILTER_PUBLIC = 'pusers_rest_users_public';

	/**
	 * Przestrzeń nazw i trasa kontrolera użytkowników w rdzeniu.
	 */
	private const BASE = '/wp/v2/users';

	/**
	 * Trasa zalogowanego o sobie; jedyna z rodziny, która zostaje.
	 */
	private const ME = '/wp/v2/users/me';

	/**
	 * Podpina zawężenie pod składanie tablicy tras.
	 */
	public static function register(): void {
		add_filter( 'rest_endpoints', array( self::class, 'restrict' ) );
	}

	/**
	 * Zdejmuje trasy użytkowników z tablicy tras żądania niezalogowanego.
	 *
	 * @param array<string, mixed> $endpoints Trasy złożone przez serwer REST.
	 * @return array<string, mixed>
	 */
	public static function restrict( array $endpoints ): array {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		if ( (bool) apply_filters( self::FILTER_PUBLIC, false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej FILTER_PUBLIC, która ma prefiks pusers_.
			return $endpoints;
		}

		foreach ( array_keys( $endpoints ) as $route ) {
			if ( self::ME === $route ) {
				continue;
			}
			// Dokładnie `/wp/v2/users` i jego podtrasy: `/wp/v2/users-extra` ani cudza
			// przestrzeń nazw nie są nasze.
			if ( self::BASE === $route || str_starts_with( (string) $route, self::BASE . '/' ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}
}
