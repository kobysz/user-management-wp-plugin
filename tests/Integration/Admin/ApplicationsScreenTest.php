<?php
/**
 * Testy ekranu wniosków: zakładki z licznikami, filtr profesji, kolumna numeru z werdyktem i rejestracja.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\ApplicationsScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Plugin;
use WP_UnitTestCase;
use WPDieException;

final class ApplicationsScreenTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'              => 'lekarz',
						'label'            => 'Lekarz',
						'credential_type'  => 'physician_pwz',
						'credential_field' => 'pwz',
						'registry_url'     => 'https://rejestr.nil.org.pl/?pwz={numer}',
					),
					array(
						'key'             => 'student',
						'label'           => 'Student',
						'credential_type' => 'none',
					),
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Konto w danym stanie i profesji, z numerem w polu ACF.
	 *
	 * @param string $status     Surowy stan.
	 * @param string $profession Klucz profesji.
	 * @param string $number     Numer uprawnień.
	 * @param string $name       Nazwa wyświetlana.
	 */
	private function account( string $status, string $profession = 'lekarz', string $number = '', string $name = '' ): int {
		$args = '' === $name ? array() : array( 'display_name' => $name );
		$id   = (int) self::factory()->user->create( $args );
		update_user_meta( $id, UserMeta::STATUS, $status );
		update_user_meta( $id, UserMeta::PROFESSION, $profession );
		if ( '' !== $number ) {
			update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', $number );
		}
		return $id;
	}

	/**
	 * Render ekranu z podanymi parametrami adresu.
	 *
	 * @param array<string, string> $get Parametry GET.
	 */
	private function render( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		try {
			ApplicationsScreen::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Wiersze tabeli z HTML-a.
	 *
	 * @return array<int, string>
	 */
	private function rows( string $html ): array {
		preg_match_all( '/<tr class="pusers-application-row">.*?<\/tr>/s', $html, $m );
		return $m[0];
	}

	public function test_zakladki_pokazuja_liczniki_wszystkich_stanow(): void {
		$this->account( 'pending' );
		$this->account( 'pending' );
		$this->account( 'pending' );
		$this->account( 'rejected' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/status=pending[^>]*>Oczekujące <span class="count">\(3\)/', $html );
		$this->assertMatchesRegularExpression( '/status=rejected[^>]*>Odrzucone <span class="count">\(1\)/', $html );
		$this->assertMatchesRegularExpression( '/status=approved[^>]*>Zatwierdzone <span class="count">\(0\)/', $html );
		$this->assertStringNotContainsString( 'email_unconfirmed', $html, 'Niepotwierdzone nie są wnioskiem i nie mają zakładki.' );
	}

	public function test_domyslna_zakladka_to_oczekujace(): void {
		$this->account( 'pending', 'lekarz', '', 'Oczekujący Olek' );
		$this->account( 'approved', 'lekarz', '', 'Zatwierdzona Zofia' );

		$html = $this->render();

		$this->assertStringContainsString( 'Oczekujący Olek', $html );
		$this->assertStringNotContainsString( 'Zatwierdzona Zofia', $html );
	}

	public function test_filtr_profesji_zawezaj_tabele_i_zostaje_w_adresach_zakladek(): void {
		$this->account( 'pending', 'lekarz', '', 'Lekarz Lech' );
		$this->account( 'pending', 'student', '', 'Student Stefan' );

		$html = $this->render( array( 'profession' => 'lekarz' ) );

		$this->assertStringContainsString( 'Lekarz Lech', $html );
		$this->assertStringNotContainsString( 'Student Stefan', $html );
		foreach ( array( 'pending', 'approved', 'rejected' ) as $status ) {
			$this->assertMatchesRegularExpression( '/href="[^"]*status=' . $status . '[^"]*profession=lekarz[^"]*"/', $html, 'Zakładka ' . $status . ' zgubiła filtr profesji.' );
		}
	}

	public function test_kolumna_numeru_rozroznia_format_od_sumy_kontrolnej(): void {
		$this->account( 'pending', 'lekarz', '5425741', 'Zla Suma' );
		$this->account( 'pending', 'lekarz', 'abc', 'Zly Format' );

		$rows = $this->rows( $this->render() );
		$this->assertCount( 2, $rows );
		$checksum = array_values( array_filter( $rows, static fn ( string $r ): bool => str_contains( $r, 'Zla Suma' ) ) )[0];
		$format   = array_values( array_filter( $rows, static fn ( string $r ): bool => str_contains( $r, 'Zly Format' ) ) )[0];

		$this->assertStringContainsString( 'nieprawidłowa suma kontrolna', $checksum );
		$this->assertStringNotContainsString( 'nieprawidłowy format numeru', $checksum );
		$this->assertStringContainsString( 'nieprawidłowy format numeru', $format );
		$this->assertStringNotContainsString( 'nieprawidłowa suma kontrolna', $format );
		$this->assertStringContainsString( 'pusers-verdict-warning', $checksum );
	}

	public function test_profesja_bez_numeru_ma_pusta_kolumne_bez_odnosnika(): void {
		$this->account( 'pending', 'student', '', 'Student Stefan' );

		$rows = $this->rows( $this->render() );
		$this->assertCount( 1, $rows );
		preg_match_all( '/<td>(.*?)<\/td>/s', $rows[0], $cells );
		$this->assertSame( '', $cells[1][2], 'Kolumna numeru profesji bez numeru ma być pusta.' );
		$this->assertStringNotContainsString( 'target="_blank"', $rows[0] );
		$this->assertStringNotContainsString( 'nie dotyczy', $rows[0] );
	}

	public function test_odnosnik_do_rejestru_niesie_numer(): void {
		$this->account( 'pending', 'lekarz', '5425740' );

		$html = $this->render();

		$this->assertStringContainsString( 'href="' . esc_url( 'https://rejestr.nil.org.pl/?pwz=5425740' ) . '"', $html );
		$this->assertStringContainsString( 'poprawny', $html );
	}

	public function test_profesja_usunieta_z_ustawien_renderuje_wiersz(): void {
		$this->account( 'pending', 'zniknela', '', 'Dawna Dorota' );

		$rows = $this->rows( $this->render() );

		$this->assertCount( 1, $rows, 'Wiersz konta z usuniętą profesją ma się wyrenderować.' );
		$this->assertStringContainsString( '<td>zniknela</td>', $rows[0] );
		$this->assertStringContainsString( 'nie dotyczy', $rows[0] );
		$this->assertStringNotContainsString( 'target="_blank"', $rows[0] );
	}

	public function test_ekran_wymaga_uprawnienia(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );
		$this->render();
	}

	public function test_licznik_oczekujacych_jest_w_tytule_menu(): void {
		global $submenu;
		$ids = array( $this->account( 'pending' ), $this->account( 'pending' ) );

		$submenu[ Menu::PARENT ] = array();
		ApplicationsScreen::add_page();
		$this->assertStringContainsString( '<span class="awaiting-mod">2</span>', $this->menu_title() );

		foreach ( $ids as $id ) {
			update_user_meta( $id, UserMeta::STATUS, 'approved' );
		}
		$submenu[ Menu::PARENT ] = array();
		ApplicationsScreen::add_page();
		$this->assertStringNotContainsString( 'awaiting-mod', $this->menu_title() );
	}

	/**
	 * Tytuł podstrony wniosków z globalnego submenu.
	 */
	private function menu_title(): string {
		global $submenu;
		foreach ( $submenu[ Menu::PARENT ] as $item ) {
			if ( Menu::SLUG_APPLICATIONS === $item[2] ) {
				return (string) $item[0];
			}
		}
		$this->fail( 'Podstrona wniosków nie powstała.' );
	}

	public function test_ekran_rejestruje_sie_bez_acf(): void {
		remove_all_actions( 'admin_menu' );
		remove_all_actions( 'pusers_register_features' );
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertSame( 10, has_action( 'admin_menu', array( ApplicationsScreen::class, 'add_page' ) ) );
	}

	public function test_numer_strony_poza_zakresem_nie_daje_pustej_tabeli(): void {
		$this->account( 'pending', 'lekarz', '', 'Jedyny Jan' );

		$html = $this->render( array( 'paged' => '999' ) );

		$this->assertStringContainsString( 'Jedyny Jan', $html );
	}
}
