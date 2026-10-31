<?php
/**
 * Testy: arkusz zapasowy pokrywa mapę klas i nie wychodzi poza formularz.
 *
 * Arkusz zapasowy jest własnym, małym arkuszem wtyczki — nie Bootstrapem. Jego jedyne
 * zobowiązanie brzmi: każda klasa, którą wtyczka wypisuje, ma tu regułę. Rozjazd ma być
 * czerwony w bramce, a nie na cudzym portalu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use PortalUsers\Front\FormClasses;

final class StylesheetCoverageTest extends TestCase {

	private const SCOPE = '.pusers-front';

	/**
	 * Treść arkusza.
	 *
	 * @param string $name Nazwa pliku w `assets/css/`.
	 */
	private function css( string $name ): string {
		$path = dirname( __DIR__, 3 ) . '/assets/css/' . $name;
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	public function test_kazda_rola_ma_regule_w_ktoryms_arkuszu(): void {
		// Zapasowy niesie klasy Bootstrapa, stały — to, czego w Bootstrapie nie ma. Razem mają
		// pokryć mapę co do roli.
		$css = $this->css( 'pusers-front-zapasowy.css' ) . $this->css( 'pusers-front.css' );

		foreach ( FormClasses::defaults() as $role => $classes ) {
			if ( '' === $classes ) {
				continue;
			}
			foreach ( explode( ' ', $classes ) as $class ) {
				$this->assertStringContainsString(
					'.' . $class,
					$css,
					'Rola „' . $role . '” wypisuje klasę „' . $class . '”, a arkusz zapasowy nie ma dla niej reguły.'
				);
			}
		}
	}

	public function test_arkusz_zapasowy_nie_wychodzi_poza_formularz(): void {
		// Operator włącza arkusz, żeby naprawić formularz. Nie może w zamian dostać przestawionego
		// nagłówka, menu i stopki portalu.
		foreach ( $this->selectors( $this->css( 'pusers-front-zapasowy.css' ) ) as $selector ) {
			$this->assertStringContainsString( self::SCOPE, $selector, 'Selektor „' . $selector . '” wychodzi poza opakowanie formularza.' );
		}
	}

	public function test_arkusz_staly_tez_nie_wychodzi_poza_formularz(): void {
		foreach ( $this->selectors( $this->css( 'pusers-front.css' ) ) as $selector ) {
			$this->assertStringContainsString( self::SCOPE, $selector );
		}
	}

	public function test_arkusz_staly_jest_maly(): void {
		// Ten arkusz jedzie także na skórkę, która ma Bootstrapa. Ma nieść to, czego w Bootstrapie
		// nie ma, i nic więcej.
		$this->assertLessThan( 1500, strlen( $this->css( 'pusers-front.css' ) ), 'Arkusz stały urósł — czy aby nie trafiło do niego coś, co należy do zapasowego?' );
		$this->assertStringContainsString( 'pusers-warning-feedback', $this->css( 'pusers-front.css' ) );
	}

	public function test_zaden_arkusz_nie_resetuje_strony(): void {
		foreach ( array( 'pusers-front.css', 'pusers-front-zapasowy.css' ) as $name ) {
			$css = $this->css( $name );
			$this->assertDoesNotMatchRegularExpression( '/(^|[,{}\s])body[\s,{]/', $css, $name . ': reguła na body wychodzi poza formularz.' );
			$this->assertDoesNotMatchRegularExpression( '/(^|[,{}\s])\*\s*[,{]/', $css, $name . ': selektor uniwersalny wychodzi poza formularz.' );
		}
	}

	/**
	 * Selektory arkusza (bez komentarzy, bez reguł @media).
	 *
	 * @param string $css Treść arkusza.
	 * @return list<string>
	 */
	private function selectors( string $css ): array {
		$css        = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$selectors  = array();
		foreach ( explode( '}', $css ) as $block ) {
			$head = trim( (string) strstr( $block . '{', '{', true ) );
			if ( '' === $head || str_starts_with( $head, '@' ) ) {
				continue;
			}
			foreach ( explode( ',', $head ) as $selector ) {
				$selector = trim( $selector );
				if ( '' !== $selector ) {
					$selectors[] = $selector;
				}
			}
		}

		return $selectors;
	}
}
