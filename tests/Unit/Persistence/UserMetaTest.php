<?php
/**
 * Testy: nazwy metadanych użytkownika.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Persistence;

use PortalUsers\Persistence\UserMeta;
use PHPUnit\Framework\TestCase;

final class UserMetaTest extends TestCase {

	public function test_profession_key_keeps_the_value_already_present_in_client_databases(): void {
		$this->assertSame( 'pusers_profession', UserMeta::PROFESSION );
	}

	public function test_all_lists_every_declared_key_and_nothing_else(): void {
		$declared = array();
		foreach ( ( new \ReflectionClass( UserMeta::class ) )->getConstants() as $name => $value ) {
			// ACF_PREFIX jest przedrostkiem nazw, nie nazwą metadanej: nie ma go w spisie.
			if ( is_string( $value ) && 'ACF_PREFIX' !== $name ) {
				$declared[] = $value;
			}
		}
		$this->assertNotEmpty( $declared );
		$this->assertSame( $declared, UserMeta::all() );
	}

	public function test_the_acf_value_prefix_is_declared_but_is_not_a_meta_key(): void {
		// Nazwy metadanych z wartościami pól ACF zależą od konfiguracji administratora, więc
		// wymienić da się wyłącznie przedrostek. Jest jedno miejsce, w którym się o nim czyta.
		$this->assertSame( 'pusers_acf_', UserMeta::ACF_PREFIX );
		$this->assertNotContains( UserMeta::ACF_PREFIX, UserMeta::all() );
	}
}
