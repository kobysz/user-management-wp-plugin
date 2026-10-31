<?php
/**
 * Testy mapy klas formularzy frontu.
 *
 * Mapa jest jedynym źródłem klas Bootstrapa dla sześciu widoków i dla testu pokrycia arkusza
 * zapasowego. Filtr `pusers_form_classes` otwiera ją dla skórki, która Bootstrapa nie używa —
 * i dlatego jej wartości trzeba traktować jak dane z zewnątrz, a nie jak gotowy HTML.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Front\FormClasses;
use WP_UnitTestCase;

final class FormClassesTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( FormClasses::FILTER );
		parent::tear_down();
	}

	public function test_zwraca_klasy_domyslne(): void {
		$this->assertSame( 'form-control', FormClasses::for( 'control' ) );
		$this->assertSame( 'btn btn-primary', FormClasses::for( 'button' ) );
	}

	public function test_filtr_podmienia_role(): void {
		add_filter(
			FormClasses::FILTER,
			static function ( array $roles ): array {
				$roles['control'] = 'input-xl';
				return $roles;
			}
		);

		$this->assertSame( 'input-xl', FormClasses::for( 'control' ) );
	}

	public function test_filtr_nie_tablica_jest_ignorowany(): void {
		add_filter( FormClasses::FILTER, static fn(): string => 'nope' );

		$this->assertSame( 'form-control', FormClasses::for( 'control' ) );
	}

	public function test_filtr_wartosc_nie_napis_jest_ignorowana(): void {
		add_filter(
			FormClasses::FILTER,
			static function ( array $roles ): array {
				$roles['control'] = 42;
				return $roles;
			}
		);

		$this->assertSame( 'form-control', FormClasses::for( 'control' ) );
	}

	public function test_filtr_nie_moze_dodac_nowej_roli(): void {
		add_filter(
			FormClasses::FILTER,
			static function ( array $roles ): array {
				$roles['cokolwiek'] = 'x';
				return $roles;
			}
		);

		$this->assertSame( '', FormClasses::for( 'cokolwiek' ) );
	}

	public function test_atrybut_jest_zescapowany(): void {
		add_filter(
			FormClasses::FILTER,
			static function ( array $roles ): array {
				$roles['control'] = 'a" onclick="zlo()';
				return $roles;
			}
		);

		// Escapowanie, nie wycinanie: cudzysłów nie może wyjść z atrybutu i otworzyć drugiego.
		$this->assertStringNotContainsString( '"', FormClasses::attr( 'control' ) );
		$this->assertStringContainsString( '&quot;', FormClasses::attr( 'control' ) );
	}

	public function test_atrybut_skleja_klasy_wlasne(): void {
		$this->assertSame( 'pusers-field mb-3', FormClasses::attr( 'field', 'pusers-field' ) );
	}

	public function test_pusta_rola_nie_zostawia_spacji(): void {
		// Rola `wrapper` jest pusta w trybie gołym; atrybut nie może mieć wiszącej spacji.
		$this->assertSame( 'pusers-front', FormClasses::attr( 'wrapper', 'pusers-front' ) );
	}

	public function test_kazda_rola_z_domyslnych_ma_napis(): void {
		foreach ( FormClasses::defaults() as $role => $classes ) {
			$this->assertIsString( $classes, 'Rola ' . $role . ' musi mieć napis z klasami.' );
		}
	}
}
