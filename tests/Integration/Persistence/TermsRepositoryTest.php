<?php
/**
 * Testy archiwum wersji regulaminu w prawdziwej bazie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\TermsRepository;
use WP_UnitTestCase;

final class TermsRepositoryTest extends WP_UnitTestCase {

	/**
	 * @return list<object> Wszystkie wersje, od najstarszej.
	 */
	private function versions(): array {
		global $wpdb;
		$table = Migrations::table( 'terms_versions' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_saving_the_same_content_twice_creates_one_version(): void {
		$first  = TermsRepository::ensureVersion( 'Regulamin v1' );
		$second = TermsRepository::ensureVersion( 'Regulamin v1' );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->versions() );
	}

	public function test_changing_the_content_creates_a_new_version_and_keeps_the_old_one(): void {
		// Konto zarejestrowane wcześniej musi dalej wskazywać na tekst, który podpisał użytkownik.
		$old = TermsRepository::ensureVersion( 'Regulamin v1' );
		$new = TermsRepository::ensureVersion( 'Regulamin v2' );

		$this->assertNotSame( $old, $new );
		$this->assertCount( 2, $this->versions() );
		$this->assertSame( 'Regulamin v1', TermsRepository::contentFor( $old ), 'Stara wersja zostaje z treścią sprzed zmiany.' );
		$this->assertSame( 'Regulamin v2', TermsRepository::contentFor( $new ) );
	}

	public function test_content_can_be_read_back_for_an_old_hash(): void {
		$old = TermsRepository::ensureVersion( "Regulamin v1\n\nPunkt 1: zażółć gęślą jaźń" );
		TermsRepository::ensureVersion( 'Regulamin v2' );

		$this->assertSame( "Regulamin v1\n\nPunkt 1: zażółć gęślą jaźń", TermsRepository::contentFor( $old ) );
		$this->assertNull( TermsRepository::contentFor( str_repeat( 'a', 40 ) ), 'Nieznany skrót to brak, nie pusty tekst.' );
	}

	public function test_empty_content_creates_no_version(): void {
		$this->assertSame( '', TermsRepository::ensureVersion( '' ) );
		$this->assertSame( '', TermsRepository::ensureVersion( " \n\t " ) );
		$this->assertCount( 0, $this->versions() );
	}

	public function test_the_current_hash_follows_the_configured_text_and_is_empty_without_one(): void {
		$this->assertSame( '', TermsRepository::currentHash(), 'Regulamin nieskonfigurowany.' );

		SettingsRepository::save( Settings::fromArray( array( 'terms_text' => 'Regulamin v1' ) ) );
		$v1 = TermsRepository::currentHash();
		$this->assertSame( $v1, TermsRepository::ensureVersion( 'Regulamin v1' ), 'Skrót bieżącej treści to skrót jej wersji.' );

		SettingsRepository::save( Settings::fromArray( array( 'terms_text' => 'Regulamin v2' ) ) );
		$this->assertNotSame( $v1, TermsRepository::currentHash() );
	}

	public function test_a_failed_write_is_reported_as_no_version_instead_of_a_hash_that_is_not_in_the_archive(): void {
		$break = static fn ( $query ) => str_contains( (string) $query, 'INSERT IGNORE INTO' ) ? 'NOT SQL' : $query;
		add_filter( 'query', $break );
		$hash = TermsRepository::ensureVersion( 'Regulamin v1' );
		remove_filter( 'query', $break );

		$this->assertSame( '', $hash );
		$this->assertCount( 0, $this->versions() );
	}
}
