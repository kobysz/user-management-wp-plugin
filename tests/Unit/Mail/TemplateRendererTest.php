<?php
/**
 * Testy renderowania szablonu maila.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Mail\Placeholders;
use PortalUsers\Domain\Mail\TemplateRenderer;

final class TemplateRendererTest extends TestCase {

	public function test_known_placeholders_are_substituted(): void {
		$out = TemplateRenderer::render( 'Witaj {nazwa_konta}, konto {email}.', new Placeholders( array( 'nazwa_konta' => 'Jan', 'email' => 'jan@szpital.pl' ) ) );
		$this->assertSame( 'Witaj Jan, konto jan@szpital.pl.', $out );
	}

	public function test_an_unknown_placeholder_stays_in_the_text_verbatim(): void {
		// Literówka admina ma być WIDOCZNA w mailu. Pusty łańcuch zamieniłby ją w cichą dziurę.
		$this->assertSame( 'Numer: {numer_uprawnen}', TemplateRenderer::render( 'Numer: {numer_uprawnen}', new Placeholders( array( 'numer_uprawnien' => '1234567' ) ) ) );
	}

	public function test_a_substituted_value_is_not_expanded_again(): void {
		// Jeden przebieg: wartość zawierająca {klucz} nie jest rozwijana, więc dane z formularza nie sterują szablonem.
		$out = TemplateRenderer::render( '{a}', new Placeholders( array( 'a' => '{b}', 'b' => 'TAJNE' ) ) );
		$this->assertSame( '{b}', $out );
	}

	public function test_an_empty_value_removes_the_placeholder(): void {
		$this->assertSame( 'Numer: ', TemplateRenderer::render( 'Numer: {numer_uprawnien}', new Placeholders( array( 'numer_uprawnien' => '' ) ) ) );
	}
}
