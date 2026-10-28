<?php
/**
 * Testy zamknięcia tras użytkowników REST dla niezalogowanych.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Front\RestUsers;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class RestUsersTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		RestUsers::register();
		$this->fresh_server();
	}

	public function tear_down(): void {
		remove_all_filters( RestUsers::FILTER_PUBLIC );
		$this->fresh_server();
		parent::tear_down();
	}

	/**
	 * Wymusza złożenie tablicy tras od nowa, bo filtr `rest_endpoints` odpala się tylko wtedy.
	 */
	private function fresh_server(): void {
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Odpowiedź serwera na żądanie GET, rozbita na to, co dostaje obcy: kod HTTP i kod błędu.
	 *
	 * @param string $route Ścieżka trasy.
	 * @return array{status: int, code: string}
	 */
	private function answer( string $route ): array {
		$this->fresh_server();
		$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		return array(
			'status' => $response->get_status(),
			'code'   => is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '',
		);
	}

	/**
	 * Identyfikator, którego żadne konto nie ma.
	 *
	 * @param int $existing Identyfikator istniejącego konta.
	 */
	private function missing_id( int $existing ): int {
		$id = $existing + 1000;
		$this->assertFalse( get_userdata( $id ), 'Identyfikator „nieistniejący” musi nim być.' );
		return $id;
	}

	public function test_an_anonymous_request_gets_the_same_answer_for_an_existing_and_a_missing_account(): void {
		$existing = self::factory()->user->create();
		$missing  = $this->missing_id( $existing );
		wp_set_current_user( 0 );

		$yes = $this->answer( '/wp/v2/users/' . $existing );
		$no  = $this->answer( '/wp/v2/users/' . $missing );

		// Strażnik porównania: oba wyniki muszą być prawdziwymi odpowiedziami, inaczej równość
		// dwóch pustych albo przypadkowo identycznych wartości przechodziłaby bez niczego.
		$this->assertGreaterThanOrEqual( 400, $yes['status'], 'Obcy nie może dostać sukcesu.' );
		$this->assertNotSame( '', $yes['code'] );
		$this->assertNotSame( '', $no['code'] );

		$this->assertSame( $yes, $no, 'Obcy odróżnia konto istniejące od nieistniejącego.' );
	}

	public function test_an_anonymous_request_cannot_tell_accounts_apart_through_the_application_password_routes(): void {
		$existing = self::factory()->user->create();
		$missing  = $this->missing_id( $existing );
		wp_set_current_user( 0 );

		$yes = $this->answer( '/wp/v2/users/' . $existing . '/application-passwords' );
		$no  = $this->answer( '/wp/v2/users/' . $missing . '/application-passwords' );

		$this->assertGreaterThanOrEqual( 400, $yes['status'] );
		$this->assertNotSame( '', $yes['code'] );
		$this->assertNotSame( '', $no['code'] );
		$this->assertSame( $yes, $no );
	}

	public function test_a_logged_in_editor_still_reaches_the_users_route(): void {
		$editor   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$existing = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $existing ) );
		wp_set_current_user( $editor );

		// Edytor czyta pojedyncze konto autora (panel, blokowy edytor), a nieistniejące konto jest
		// dla niego zwykłym „nie ma”. Lista bez uprawnienia `list_users` dostaje od rdzenia
		// odmowę uprawnień, więc o trasie mówi tu brak `rest_no_route`, nie sam kod HTTP.
		$one  = $this->answer( '/wp/v2/users/' . $existing );
		$none = $this->answer( '/wp/v2/users/' . $this->missing_id( $existing ) );
		$list = $this->answer( '/wp/v2/users' );

		$this->assertSame( 200, $one['status'] );
		$this->assertGreaterThanOrEqual( 400, $none['status'] );
		$this->assertNotSame( $one, $none );
		$this->assertNotSame( 'rest_no_route', $none['code'], 'Trasa musi istnieć dla zalogowanego.' );
		$this->assertNotSame( 'rest_no_route', $list['code'], 'Trasa listy musi istnieć dla zalogowanego.' );
	}

	public function test_a_logged_in_request_gets_the_endpoint_array_back_unchanged(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$endpoints = array(
			'/wp/v2/users'    => array( 'callback' => 'a' ),
			'/wp/v2/users/me' => array( 'callback' => 'b' ),
			'/wp/v2/posts'    => array( 'callback' => 'c' ),
		);

		$this->assertSame( $endpoints, RestUsers::restrict( $endpoints ) );
	}

	public function test_the_me_route_is_untouched(): void {
		$endpoints = array(
			'/wp/v2/users'    => array( 'callback' => 'a' ),
			'/wp/v2/users/me' => array( 'callback' => 'b' ),
			'/wp/v2/posts'    => array( 'callback' => 'c' ),
		);
		wp_set_current_user( 0 );

		$restricted = RestUsers::restrict( $endpoints );

		$this->assertArrayNotHasKey( '/wp/v2/users', $restricted, 'Strażnik: bez tego test nie dowodziłby, że coś zostało zdjęte.' );
		$this->assertArrayHasKey( '/wp/v2/users/me', $restricted );
		$this->assertSame( $endpoints['/wp/v2/users/me'], $restricted['/wp/v2/users/me'] );

		// Prawdziwa trasa: zalogowany czyta siebie.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$me = $this->answer( '/wp/v2/users/me' );
		$this->assertSame( 200, $me['status'] );
	}

	public function test_only_user_routes_are_removed_for_an_anonymous_request(): void {
		$endpoints = array(
			'/wp/v2/users'               => array( 'callback' => 'a' ),
			'/wp/v2/users/(?P<id>[\d]+)' => array( 'callback' => 'b' ),
			'/wp/v2/users/me'            => array( 'callback' => 'c' ),
			'/wp/v2/posts'               => array( 'callback' => 'd' ),
			'/wp/v2/users-extra'         => array( 'callback' => 'e' ),
			'/other/v1/users'            => array( 'callback' => 'f' ),
		);
		wp_set_current_user( 0 );

		$this->assertSame(
			array( '/wp/v2/users/me', '/wp/v2/posts', '/wp/v2/users-extra', '/other/v1/users' ),
			array_keys( RestUsers::restrict( $endpoints ) )
		);
	}

	public function test_a_portal_can_reopen_the_route_with_the_filter(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( 0 );

		$closed = $this->answer( '/wp/v2/users/' . $author );
		$this->assertGreaterThanOrEqual( 400, $closed['status'], 'Bez filtra trasa ma być zamknięta.' );

		$calls = 0;
		add_filter(
			RestUsers::FILTER_PUBLIC,
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		$open = $this->answer( '/wp/v2/users/' . $author );
		$this->assertGreaterThan( 0, $calls, 'Filtr musi być zapytany.' );
		$this->assertSame( 200, $open['status'], 'Trasa musi naprawdę wrócić, nie tylko zapytać o filtr.' );

		$endpoints = array( '/wp/v2/users' => array( 'callback' => 'a' ) );
		$this->assertSame( $endpoints, RestUsers::restrict( $endpoints ) );
	}
}
