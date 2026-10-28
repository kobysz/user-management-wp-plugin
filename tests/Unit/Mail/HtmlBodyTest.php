<?php
/**
 * Testy opakowania treści maila w HTML.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Mail\HtmlBody;

final class HtmlBodyTest extends TestCase {

	public function test_special_characters_are_escaped(): void {
		$this->assertStringContainsString( 'Jan &amp; Anna &lt;b&gt;', HtmlBody::wrap( 'Jan & Anna <b>' ) );
		$this->assertStringNotContainsString( '<b>', HtmlBody::wrap( 'Jan & Anna <b>' ) );
	}

	public function test_line_breaks_survive_as_markup(): void {
		// Mail złożony z linii musi zostać mailem złożonym z linii, nie jednym akapitem.
		$this->assertStringContainsString( "pierwsza<br />\ndruga", HtmlBody::wrap( "pierwsza\ndruga" ) );
	}

	public function test_the_wrapper_is_a_complete_document(): void {
		$html = HtmlBody::wrap( 'tekst' );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertStringEndsWith( '</html>', trim( $html ) );
	}
}
