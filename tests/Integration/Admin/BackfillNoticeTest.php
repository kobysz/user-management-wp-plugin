<?php
/**
 * Testy ostrzeżenia o kontach bez stanu i przycisku backfillu na ekranie importu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Account\Backfill;
use PortalUsers\Admin\BackfillNotice;
use PortalUsers\Admin\ImportScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Plugin;
use WP_UnitTestCase;
use WPDieException;

final class BackfillNoticeTest extends WP_UnitTestCase {

	use AdminPostingTrait;

	/**
	 * Moment wdrożenia: pięć dni temu.
	 *
	 * @var int
	 */
	private int $deployed = 0;

	/**
	 * Metoda żądania sprzed testu.
	 *
	 * @var string
	 */
	private string $originalMethod = 'GET';

	/**
	 * Adres ostatniego przekierowania.
	 *
	 * @var string
	 */
	private string $redirected = '';

	/**
	 * Anulujący filtr przekierowania.
	 *
	 * @var callable
	 */
	private $cancelRedirect;

	public function set_up(): void {
		parent::set_up();
		$this->deployed       = time() - 5 * DAY_IN_SECONDS;
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->redirected     = '';
		$this->cancelRedirect = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
		delete_option( OptionStore::OPTION_DEPLOYED_AT );
		remove_all_actions( 'admin_notices' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		parent::tear_down();
	}

	/**
	 * Wdraża "pięć dni temu" i tworzy podaną liczbę kont bez stanu sprzed wdrożenia.
	 *
	 * @param int $count Liczba kont.
	 * @return list<int>
	 */
	private function statelessAccounts( int $count ): array {
		OptionStore::write( OptionStore::OPTION_DEPLOYED_AT, array( Backfill::KEY_AT => $this->deployed ) );
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = (int) self::factory()->user->create( array( 'user_registered' => gmdate( 'Y-m-d H:i:s', $this->deployed - DAY_IN_SECONDS ) ) );
		}
		return $ids;
	}

	/**
	 * Wypisuje ostrzeżenie jako administrator na wskazanej stronie panelu.
	 *
	 * @param string $page Wartość `?page=`; pusta oznacza ekran spoza wtyczki.
	 */
	private function renderNotice( string $page ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = '' === $page ? array() : array( 'page' => $page );
		ob_start();
		BackfillNotice::render();
		return (string) ob_get_clean();
	}

	private function renderImport(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		ImportScreen::render();
		return (string) ob_get_clean();
	}

	/**
	 * Przenosi argumenty ostatniego przekierowania do $_GET, jak zrobiłaby przeglądarka.
	 */
	private function followRedirect(): void {
		parse_str( (string) wp_parse_url( $this->redirected, PHP_URL_QUERY ), $args );
		$_GET = $args;
	}

	private function assertRefused(): void {
		try {
			ImportScreen::handle_backfill();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_ostrzezenie_pokazuje_sie_gdy_sa_konta_bez_stanu(): void {
		$this->statelessAccounts( 3 );

		$html = $this->renderNotice( Menu::SLUG_SETTINGS );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertMatchesRegularExpression( '#\b3\b#', $html );
		$this->assertStringContainsString( 'page=' . Menu::SLUG_IMPORT, $html );
		$this->assertStringContainsString( 'bramy', $html, 'Komunikat mówi, że reguły bramy ich nie obejmują.' );
	}

	public function test_ostrzezenia_nie_ma_gdy_wszystkie_konta_maja_stan(): void {
		$ids = $this->statelessAccounts( 2 );
		foreach ( $ids as $id ) {
			AccountStatusRepository::set( $id, AccountStatus::Approved );
		}

		$this->assertSame( '', $this->renderNotice( Menu::SLUG_SETTINGS ) );
	}

	public function test_ostrzezenia_nie_ma_poza_ekranami_wtyczki(): void {
		$this->statelessAccounts( 2 );

		$this->assertSame( '', $this->renderNotice( '' ), 'Pulpit i ekrany rdzenia go nie dostają.' );
		$this->assertSame( '', $this->renderNotice( 'cudza-strona' ) );
	}

	public function test_ostrzezenia_nie_widzi_konto_bez_uprawnien(): void {
		$this->statelessAccounts( 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET = array( 'page' => Menu::SLUG_SETTINGS );

		ob_start();
		BackfillNotice::render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_przycisk_backfillu_jest_na_ekranie_importu_z_liczba_kont(): void {
		$this->statelessAccounts( 4 );

		$html = $this->renderImport();

		$this->assertStringContainsString( 'name="action" value="' . ImportScreen::ACTION_BACKFILL . '"', $html );
		// Liczba w akapicie sekcji, nie gdziekolwiek: tabela skanu też zawiera liczby kont.
		$this->assertMatchesRegularExpression( '#<div class="pusers-backfill">.*?<p>4 kont sprzed wdrożenia#us', $html );
	}

	public function test_ekran_importu_bez_kont_bez_stanu_nie_ma_przycisku(): void {
		$this->assertStringNotContainsString( ImportScreen::ACTION_BACKFILL, $this->renderImport() );
	}

	public function test_backfill_z_ekranu_nadaje_stan_i_pokazuje_wynik(): void {
		$ids = $this->statelessAccounts( 2 );
		$this->asAdminPosting( ImportScreen::ACTION_BACKFILL, array() );

		ImportScreen::handle_backfill();

		foreach ( $ids as $id ) {
			$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ) );
		}
		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_BACKFILL_DONE, $this->redirected );
		$this->followRedirect();
		$html = $this->renderImport();
		$this->assertMatchesRegularExpression( '#notice-success.*\b2\b#s', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );
	}

	public function test_porazka_logu_backfillu_daje_ostrzezenie_a_stany_zostaja(): void {
		global $wpdb;
		$ids      = $this->statelessAccounts( 1 );
		$table    = Migrations::table( 'event_log' );
		$break    = static fn ( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, "`{$table}`" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;
		$this->asAdminPosting( ImportScreen::ACTION_BACKFILL, array() );
		$previous = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			ImportScreen::handle_backfill();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $previous );
		}
		$this->followRedirect();

		$html = $this->renderImport();

		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $ids[0] ) );
		$this->assertMatchesRegularExpression( '#notice-warning[^>]*>\s*<p>[^<]*dziennik#u', $html );
		$this->assertStringContainsString( 'notice-success', $html );
	}

	public function test_backfill_z_ekranu_ma_te_same_bramki(): void {
		$ids = $this->statelessAccounts( 2 );

		// Brak uprawnień.
		$this->asRolePosting( 'subscriber', ImportScreen::ACTION_BACKFILL, array() );
		$this->assertRefused();

		// Brak nonce'a.
		$this->asAdminPosting( ImportScreen::ACTION_BACKFILL, array() );
		unset( $_POST['pusers_nonce'] );
		$this->assertRefused();

		// Nonce innej akcji.
		$this->asAdminPosting( ImportScreen::ACTION_APPLY, array() );
		$this->assertRefused();

		// Metoda GET.
		$this->asAdminPosting( ImportScreen::ACTION_BACKFILL, array() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertRefused();

		foreach ( $ids as $id ) {
			$this->assertNull( AccountStatusRepository::get( $id ) );
		}
		$this->assertSame( '', $this->redirected );
	}

	public function test_uchwyt_backfillu_i_ostrzezenie_sa_podpiete(): void {
		remove_all_actions( 'pusers_register_features' );
		remove_all_actions( 'admin_post_' . ImportScreen::ACTION_BACKFILL );
		remove_all_actions( 'admin_notices' );
		add_filter( 'pusers_acf_pro_loaded', '__return_true' );
		Plugin::boot( Plugin::plugin_file() );

		Plugin::load_features();

		$this->assertNotFalse( has_action( 'admin_post_' . ImportScreen::ACTION_BACKFILL ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( BackfillNotice::class, 'render' ) ) );
	}
}
