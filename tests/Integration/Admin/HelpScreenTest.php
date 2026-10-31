<?php
/**
 * Testy ekranu pomocy i panelu listy startowej.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Account\Backfill;
use PortalUsers\Admin\HelpScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Config\SetupStepKey;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Tests\Support\ConfigurationDoc;
use WP_UnitTestCase;
use WPDieException;

final class HelpScreenTest extends WP_UnitTestCase {

	/**
	 * Stan globalnych hooków admin_menu sprzed testu.
	 *
	 * @var mixed
	 */
	private $savedAdminMenuHooks;

	/**
	 * Stan globalnego $submenu sprzed testu.
	 *
	 * @var mixed
	 */
	private $savedSubmenu;

	/**
	 * Rola domyślna sprzed testu.
	 *
	 * @var string
	 */
	private string $originalRole = 'subscriber';

	public function set_up(): void {
		parent::set_up();
		$this->savedAdminMenuHooks = isset( $GLOBALS['wp_filter']['admin_menu'] ) ? clone $GLOBALS['wp_filter']['admin_menu'] : null;
		$this->savedSubmenu        = $GLOBALS['submenu'] ?? null;
		$this->originalRole        = (string) get_option( 'default_role' );
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		delete_option( OptionStore::OPTION_DEPLOYED_AT );
		Backfill::forget_count();
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		update_option( 'default_role', $this->originalRole );
		Backfill::forget_count();
		if ( null === $this->savedAdminMenuHooks ) {
			unset( $GLOBALS['wp_filter']['admin_menu'] );
		} else {
			$GLOBALS['wp_filter']['admin_menu'] = $this->savedAdminMenuHooks;
		}
		if ( null === $this->savedSubmenu ) {
			unset( $GLOBALS['submenu'] );
		} else {
			$GLOBALS['submenu'] = $this->savedSubmenu;
		}
		parent::tear_down();
	}

	/**
	 * Zapisuje ustawienia, zachowując to, co już zapisane.
	 *
	 * @param array<string, mixed> $values Wartości do nadpisania.
	 */
	private function withSettings( array $values ): void {
		SettingsRepository::save( Settings::fromArray( array_merge( SettingsRepository::get()->toArray(), $values ) ) );
	}

	/**
	 * Konfiguruje grupę ACF z jednym polem tekstowym i wskazuje ją w ustawieniach.
	 */
	private function withSource(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'Numer PWZ',
					'type'  => 'text',
				),
			)
		);
		$this->withSettings( array( 'acf_group_key' => 'group_abc' ) );
	}

	/**
	 * Zapisuje jedną profesję bez numeru uprawnień.
	 */
	private function withProfession(): void {
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'             => 'lekarz',
						'label'           => 'Lekarz',
						'credential_type' => 'none',
					),
				)
			)
		);
	}

	/**
	 * Tworzy opublikowaną stronę.
	 */
	private function page(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Doprowadza konfigurację do stanu skończonego: kroki od pierwszego do szóstego zrobione.
	 */
	private function fullyConfigured(): void {
		$this->withSource();
		$this->withProfession();
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'visible' ) ) ) );
		$this->withSettings(
			array(
				'login_page_id'       => $this->page(),
				'register_page_id'    => $this->page(),
				'reset_page_id'       => $this->page(),
				'after_login_page_id' => $this->page(),
				'notify_emails'       => array( 'redakcja@example.test' ),
				'rejection_reasons'   => array( 'Numer nie zgadza się z rejestrem' ),
			)
		);
	}

	/**
	 * Tworzy konta bez stanu sprzed wdrożenia.
	 */
	private function statelessAccounts( int $count ): void {
		$deployed = time() - 5 * DAY_IN_SECONDS;
		OptionStore::write( OptionStore::OPTION_DEPLOYED_AT, array( Backfill::KEY_AT => $deployed ) );
		for ( $i = 0; $i < $count; $i++ ) {
			self::factory()->user->create( array( 'user_registered' => gmdate( 'Y-m-d H:i:s', $deployed - DAY_IN_SECONDS ) ) );
		}
		Backfill::forget_count();
	}

	private function renderAsAdmin(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		HelpScreen::render();

		return (string) ob_get_clean();
	}

	public function test_ekran_wymaga_uprawnienia(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		ob_start();
		try {
			HelpScreen::render();
		} finally {
			ob_get_clean();
		}
	}

	public function test_bez_acf_pro_ekran_sie_renderuje_i_mowi_ze_nie_moze_sprawdzic(): void {
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'Portal: pomoc', $html, 'Bez ACF Pro to jedyny ekran wtyczki, jaki administrator widzi — musi się wyrenderować.' );
		$this->assertStringContainsString( 'Nie można sprawdzić', $html, 'Kroki zależne od ACF nie są ani zrobione, ani niezrobione, gdy nie ma czym ich zmierzyć.' );
		$this->assertStringContainsString( 'ACF Pro', $html, 'Ekran ma powiedzieć, czego brakuje.' );
	}

	public function test_lista_startowa_wypisuje_kroki_w_kolejnosci_dokumentu(): void {
		$this->statelessAccounts( 2 );
		$html = $this->renderAsAdmin();

		$previous = -1;
		foreach ( ConfigurationDoc::STEPS as $number => $key ) {
			$position = strpos( $html, HelpScreen::title( $key ) );
			$this->assertNotFalse( $position, 'Krok ' . $number . ' nie pokazał się na ekranie.' );
			$this->assertGreaterThan( $previous, $position, 'Krok ' . $number . ' stoi na ekranie przed krokiem poprzednim.' );
			$previous = $position;
		}
	}

	public function test_tytul_kazdego_kroku_jest_naglowkiem_z_dokumentu(): void {
		$headings = ConfigurationDoc::headings();

		foreach ( ConfigurationDoc::STEPS as $number => $key ) {
			$this->assertSame(
				$headings[ $number ],
				HelpScreen::title( $key ),
				'Tytuł kroku na ekranie musi być dokładnie nagłówkiem z docs/konfiguracja.md (bez pliku .mo __() zwraca string źródłowy).'
			);
		}
	}

	public function test_skonczona_konfiguracja_zwija_liste_do_jednej_linii(): void {
		$this->fullyConfigured();
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'pusers-setup-complete', $html );
		$this->assertStringNotContainsString( 'pusers-setup-checklist', $html, 'Skończona konfiguracja nie ma po co zajmować ekranu tabelą ośmiu kroków.' );
	}

	public function test_osmy_krok_pokazuje_sie_tylko_przy_kontach_bez_stanu(): void {
		$this->assertStringNotContainsString(
			HelpScreen::title( SetupStepKey::LegacyAccounts ),
			$this->renderAsAdmin(),
			'Portal bez kont sprzed wdrożenia nie ma po co widzieć tego kroku.'
		);

		$this->statelessAccounts( 3 );
		$this->assertStringContainsString( HelpScreen::title( SetupStepKey::LegacyAccounts ), $this->renderAsAdmin() );
	}

	public function test_panel_odsyla_do_raportu_spojnosci_zamiast_powtarzac_jego_tresci(): void {
		$this->withSource();
		$this->withProfession();
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'page=' . Menu::SLUG_MATRIX, $html, 'Krok macierzy prowadzi na ekran, na którym stoi raport spójności.' );
		$this->assertStringNotContainsString(
			'Profesja „lekarz”',
			$html,
			'Lista startowa mówi, gdzie jesteś; co jest nie tak, mówi raport spójności. Powtarzanie jego tekstów robiłoby z niej drugi raport.'
		);
	}

	public function test_setup_incomplete_jest_prawda_przy_swiezej_instalacji_i_falsz_przy_pelnej(): void {
		$this->assertTrue( HelpScreen::setup_incomplete() );

		$this->fullyConfigured();
		$this->assertFalse( HelpScreen::setup_incomplete() );
	}

	public function test_osmy_krok_nie_wplywa_na_setup_incomplete(): void {
		$this->fullyConfigured();
		$this->statelessAccounts( 4 );

		$this->assertFalse( HelpScreen::setup_incomplete(), 'Konta sprzed wdrożenia nie wchodzą do warunku ukończenia, a linia na ekranie ustawień nie liczy ich w ogóle.' );
	}

	public function test_podstrona_rejestruje_sie_pod_users_php(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_all_actions( 'admin_menu' );
		global $submenu;
		$submenu = array();
		HelpScreen::register();
		do_action( 'admin_menu' );

		$slugs = wp_list_pluck( $submenu['users.php'] ?? array(), 2 );
		$this->assertContains( Menu::SLUG_HELP, $slugs );
	}
}
