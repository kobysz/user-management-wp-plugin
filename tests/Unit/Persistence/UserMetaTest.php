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
		$declared = array_values( ( new \ReflectionClass( UserMeta::class ) )->getConstants() );
		$declared = array_filter( $declared, 'is_string' );
		$this->assertNotEmpty( $declared );
		$this->assertSame( array_values( $declared ), UserMeta::all() );
	}
}
