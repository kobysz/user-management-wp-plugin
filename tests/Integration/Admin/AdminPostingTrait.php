<?php
/**
 * Wspólne pomocniki testów ekranów panelu: składanie żądania zapisu z nonce'em.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

trait AdminPostingTrait {

	/**
	 * Ustawia administratora i żądanie POST z nonce'em; pola dostają prefiks `pusers_`.
	 *
	 * @param string               $action Akcja nonce'a.
	 * @param array<string, mixed> $fields Pola bez prefiksu.
	 */
	private function asAdminPosting( string $action, array $fields ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = array();
		foreach ( $fields as $key => $value ) {
			$post[ 'pusers_' . $key ] = $value;
		}
		$post['pusers_nonce'] = wp_create_nonce( $action );
		// WordPress dodaje ukośniki do danych żądania; ekran musi je zdjąć.
		$_POST    = wp_slash( $post );
		$_REQUEST = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	/**
	 * Jak asAdminPosting(), ale dla konta o wskazanej roli, a nonce powstaje PO ustawieniu
	 * użytkownika: jest ważny dla niego, więc jedyną przeszkodą pozostają uprawnienia.
	 *
	 * @param string               $role   Rola konta wysyłającego żądanie.
	 * @param string               $action Akcja nonce'a.
	 * @param array<string, mixed> $fields Pola bez prefiksu.
	 */
	private function asRolePosting( string $role, string $action, array $fields ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$post = array();
		foreach ( $fields as $key => $value ) {
			$post[ 'pusers_' . $key ] = $value;
		}
		$post['pusers_nonce'] = wp_create_nonce( $action );
		$_POST    = wp_slash( $post );
		$_REQUEST = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}
}
