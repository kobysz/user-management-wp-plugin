<?php
/**
 * Testy ekranu wniosków: zakładki z licznikami, filtr profesji, kolumna numeru z werdyktem i rejestracja.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Account\Decision;
use PortalUsers\Admin\ApplicationsScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\Mailer;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Plugin;
use WP_UnitTestCase;
use WPDieException;

final class ApplicationsScreenTest extends WP_UnitTestCase {

	use AdminPostingTrait;

	private string $originalMethod = 'GET';

	/**
	 * Anulujący filtr przekierowania, który zapamiętuje adres.
	 *
	 * @var callable
	 */
	private $captureRedirect;

	private string $redirectedTo = '';

	/**
	 * Globalna `$submenu` sprzed testu: WP_UnitTestCase przywraca hooki, ale nie tę zmienną, a test menu ją zeruje.
	 *
	 * @var mixed
	 */
	private $originalSubmenu;

	public function set_up(): void {
		parent::set_up();
		global $submenu;
		$this->originalSubmenu = $submenu;
		$this->originalMethod  = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->redirectedTo    = '';
		$this->captureRedirect = function ( $location ) {
			$this->redirectedTo = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->captureRedirect );
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
		global $submenu;
		$submenu = $this->originalSubmenu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		remove_filter( 'wp_redirect', $this->captureRedirect );
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
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

	public function test_kolumna_daty_rejestracji_jest_w_strefie_witryny_a_nie_surowym_utc(): void {
		update_option( 'timezone_string', 'Europe/Warsaw' );
		try {
			$id = $this->account( 'pending', 'lekarz', '', 'Data Danuta' );
			wp_update_user(
				array(
					'ID'              => $id,
					'user_registered' => '2026-10-01 08:15:00',
				)
			);

			$rows = $this->rows( $this->render() );

			$this->assertCount( 1, $rows );
			// Literał: 1 października w Warszawie obowiązuje UTC+2, więc 08:15 UTC to 10:15.
			$this->assertStringContainsString( '<td>2026-10-01 10:15</td>', $rows[0] );
			$this->assertStringNotContainsString( '2026-10-01 08:15', $rows[0] );
		} finally {
			delete_option( 'timezone_string' );
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

	/**
	 * Składa żądanie rozstrzygnięcia od administratora i woła uchwyt. Zwraca argumenty zapytania z adresu przekierowania.
	 *
	 * @param string               $decision Decyzja.
	 * @param mixed                $users    Wartość pola z identyfikatorami.
	 * @param array<string, mixed> $extra    Dodatkowe pola (trait dokłada im przedrostek `pusers_`).
	 * @return array<string, string>
	 */
	private function decide( string $decision, mixed $users, array $extra = array() ): array {
		$this->asAdminPosting(
			ApplicationsScreen::ACTION_DECIDE,
			array_merge(
				array(
					'decision' => $decision,
					'users'    => $users,
				),
				$extra
			)
		);
		return $this->run_decide();
	}

	/**
	 * Woła uchwyt na już ustawionym $_POST i rozbiera adres przekierowania.
	 *
	 * @return array<string, string>
	 */
	private function run_decide(): array {
		$this->redirectedTo = '';
		ApplicationsScreen::handle_decide();
		$query = (string) wp_parse_url( $this->redirectedTo, PHP_URL_QUERY );
		parse_str( $query, $args );
		return array_map( 'strval', $args );
	}

	private function status_of( int $id ): ?AccountStatus {
		return AccountStatusRepository::get( $id );
	}

	/**
	 * Wiersze logu o danej akcji.
	 *
	 * @return array<int, object>
	 */
	private function log_rows( string $action ): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return array_values( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id ASC", $action ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * Zapisuje listę gotowych powodów odrzucenia.
	 *
	 * @param array<int, string> $reasons Powody.
	 */
	private function set_presets( array $reasons ): void {
		SettingsRepository::save( Settings::fromArray( array_merge( SettingsRepository::get()->toArray(), array( 'rejection_reasons' => $reasons ) ) ) );
	}

	/**
	 * Ekran z argumentami przekierowania po akcji, czyli to, co zobaczy redaktor.
	 *
	 * @param array<string, string> $args Argumenty zapytania.
	 */
	private function render_notice( array $args ): string {
		return $this->render( array( 'status' => 'pending' ) + $args );
	}

	public function test_zatwierdzenie_pojedynczego_wniosku_z_listy(): void {
		$id = $this->account( 'pending' );

		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array( (string) $id ) );

		$this->assertSame( AccountStatus::Approved, $this->status_of( $id ) );
		$this->assertSame( ApplicationsScreen::NOTICE_DECIDED, $args['pusers_notice'] );
		$this->assertSame( '1', $args['pusers_done'] );
	}

	public function test_akcja_masowa_zatwierdza_wszystkie_zaznaczone(): void {
		$ids   = array( $this->account( 'pending' ), $this->account( 'pending' ), $this->account( 'pending' ) );
		$other = $this->account( 'pending' );

		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array_map( 'strval', $ids ) );

		foreach ( $ids as $id ) {
			$this->assertSame( AccountStatus::Approved, $this->status_of( $id ) );
		}
		$this->assertSame( AccountStatus::Pending, $this->status_of( $other ), 'Niezaznaczone konto nie może się ruszyć.' );
		$this->assertSame( '3', $args['pusers_done'] );
	}

	public function test_akcja_masowa_odrzuca_z_jednym_powodem_dla_calej_partii(): void {
		$ids = array( $this->account( 'pending' ), $this->account( 'pending' ) );

		$args = $this->decide( ApplicationsScreen::DECIDE_REJECT, array_map( 'strval', $ids ), array( 'reason' => 'Brak numeru PWZ' ) );

		foreach ( $ids as $id ) {
			$this->assertSame( AccountStatus::Rejected, $this->status_of( $id ) );
			$this->assertSame( 'Brak numeru PWZ', get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
		}
		$this->assertSame( '2', $args['pusers_done'] );
		$this->assertSame( ApplicationsScreen::DECIDE_REJECT, $args['pusers_decision_done'] );
	}

	public function test_odrzucenie_bez_powodu_nie_dotyka_zadnego_konta(): void {
		$ids = array( $this->account( 'pending' ), $this->account( 'pending' ), $this->account( 'pending' ) );

		$args = $this->decide( ApplicationsScreen::DECIDE_REJECT, array_map( 'strval', $ids ), array( 'reason' => "  \n " ) );

		$this->assertSame( ApplicationsScreen::NOTICE_NEEDS_REASON, $args['pusers_notice'] );
		foreach ( $ids as $id ) {
			$this->assertSame( AccountStatus::Pending, $this->status_of( $id ) );
			$this->assertSame( '', (string) get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
		}
		$this->assertCount( 0, $this->log_rows( Decision::LOG_REJECTED ) );
	}

	public function test_powod_z_listy_gotowych_jest_akceptowany(): void {
		$this->set_presets( array( 'Brak numeru PWZ', 'Dane niezgodne z rejestrem' ) );
		$id = $this->account( 'pending' );

		$args = $this->decide( ApplicationsScreen::DECIDE_REJECT, array( (string) $id ), array( 'reason_preset' => 'Dane niezgodne z rejestrem' ) );

		$this->assertSame( ApplicationsScreen::NOTICE_DECIDED, $args['pusers_notice'] );
		$this->assertSame( AccountStatus::Rejected, $this->status_of( $id ) );
		$this->assertSame( 'Dane niezgodne z rejestrem', get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
	}

	public function test_gotowy_powod_spoza_ustawien_nie_wystarcza(): void {
		$this->set_presets( array( 'Brak numeru PWZ' ) );
		$id = $this->account( 'pending' );

		$args = $this->decide( ApplicationsScreen::DECIDE_REJECT, array( (string) $id ), array( 'reason_preset' => 'Wymyślony powód' ) );

		$this->assertSame( ApplicationsScreen::NOTICE_NEEDS_REASON, $args['pusers_notice'] );
		$this->assertSame( AccountStatus::Pending, $this->status_of( $id ) );
	}

	public function test_konto_usuniete_miedzy_renderem_a_akcja_jest_pomijane(): void {
		$gone = $this->account( 'pending' );
		$kept = $this->account( 'pending' );
		wp_delete_user( $gone );

		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array( (string) $gone, (string) $kept ) );

		$this->assertSame( AccountStatus::Approved, $this->status_of( $kept ) );
		$this->assertSame( '1', $args['pusers_done'] );
		$this->assertSame( '1', $args['pusers_skipped'] );
		$this->assertStringContainsString( 'Pominięto wniosków: 1', $this->render_notice( $args ) );
	}

	public function test_konto_w_zlym_stanie_w_partii_nie_blokuje_pozostalych(): void {
		$a   = $this->account( 'pending' );
		$bad = $this->account( 'email_unconfirmed' );
		$c   = $this->account( 'pending' );

		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array( (string) $a, (string) $bad, (string) $c ) );

		$this->assertSame( AccountStatus::Approved, $this->status_of( $a ) );
		$this->assertSame( AccountStatus::Approved, $this->status_of( $c ) );
		$this->assertSame( AccountStatus::EmailUnconfirmed, $this->status_of( $bad ) );
		$this->assertSame( '2', $args['pusers_done'] );
		$this->assertSame( '1', $args['pusers_skipped'] );
	}

	public function test_akcja_bez_nonce_jest_odrzucana(): void {
		$id = $this->account( 'pending' );
		$this->asAdminPosting(
			ApplicationsScreen::ACTION_DECIDE,
			array(
				'decision' => 'approve',
				'users'    => array( (string) $id ),
			)
		);
		unset( $_POST['pusers_nonce'] );

		try {
			ApplicationsScreen::handle_decide();
			$this->fail( 'Akcja bez nonce powinna zostać odrzucona.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( AccountStatus::Pending, $this->status_of( $id ) );
			$this->assertCount( 0, $this->log_rows( Decision::LOG_APPROVED ) );
		}
	}

	public function test_akcja_bez_uprawnienia_jest_odrzucana(): void {
		$id = $this->account( 'pending' );
		$this->asRolePosting(
			'editor',
			ApplicationsScreen::ACTION_DECIDE,
			array(
				'decision' => 'approve',
				'users'    => array( (string) $id ),
			)
		);

		try {
			ApplicationsScreen::handle_decide();
			$this->fail( 'Akcja bez uprawnienia powinna zostać odrzucona.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( AccountStatus::Pending, $this->status_of( $id ) );
			$this->assertCount( 0, $this->log_rows( Decision::LOG_APPROVED ) );
		}
	}

	public function test_akcja_przez_get_jest_odrzucana(): void {
		$id = $this->account( 'pending' );
		$this->asAdminPosting(
			ApplicationsScreen::ACTION_DECIDE,
			array(
				'decision' => 'approve',
				'users'    => array( (string) $id ),
			)
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';

		try {
			ApplicationsScreen::handle_decide();
			$this->fail( 'Zmiana stanu przez GET powinna zostać odrzucona.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( AccountStatus::Pending, $this->status_of( $id ) );
		}
	}

	public function test_pusty_wybor_daje_komunikat_nie_bialy_ekran(): void {
		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array() );

		$this->assertSame( ApplicationsScreen::NOTICE_NOTHING_SELECTED, $args['pusers_notice'] );
		$this->assertStringContainsString( 'Nie zaznaczono', $this->render_notice( $args ) );
	}

	public function test_identyfikatory_z_zadania_sa_oczyszczane(): void {
		$id = $this->account( 'pending' );

		foreach ( array( array( 'abc', '0', '-5' ), 'abc', '0', '-5', array( array( '1' ) ), null ) as $junk ) {
			$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, $junk );
			$this->assertSame( ApplicationsScreen::NOTICE_NOTHING_SELECTED, $args['pusers_notice'] );
		}
		$this->assertSame( AccountStatus::Pending, $this->status_of( $id ) );
		$this->assertCount( 0, $this->log_rows( Decision::LOG_APPROVED ) );
	}

	public function test_ujemny_identyfikator_nie_trafia_w_konto_o_wartosci_bezwzglednej(): void {
		// absint('-N') daje N, więc bez filtra cyfr ten napis zatwierdziłby konto N.
		$id = $this->account( 'pending' );

		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array( '-' . $id ) );

		$this->assertSame( ApplicationsScreen::NOTICE_NOTHING_SELECTED, $args['pusers_notice'] );
		$this->assertSame( AccountStatus::Pending, $this->status_of( $id ) );
		$this->assertCount( 0, $this->log_rows( Decision::LOG_APPROVED ) );
	}

	public function test_ponowne_zatwierdzenie_nie_mowi_o_powodzie(): void {
		$id = $this->account( 'approved' );

		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array( (string) $id ) );

		$html = $this->render_notice( $args );
		$this->assertStringContainsString( 'były już zatwierdzone', $html );
		$this->assertStringNotContainsString( 'powód', $html );
	}

	public function test_rozstrzygniecie_ze_szczegolow_zachowuje_zakladke_i_filtr(): void {
		$id = $this->account( 'rejected' );

		$html = $this->render(
			array(
				'user'       => (string) $id,
				'status'     => 'rejected',
				'profession' => 'lekarz',
			)
		);
		$this->assertStringContainsString( 'name="status" value="rejected"', $html );
		$this->assertStringContainsString( 'name="profession" value="lekarz"', $html );

		$this->asAdminPosting(
			ApplicationsScreen::ACTION_DECIDE,
			array(
				'decision' => 'approve',
				'users'    => array( (string) $id ),
			)
		);
		// Wartości z ukrytych pól formularza szczegółów.
		$_POST['status']     = 'rejected';
		$_POST['profession'] = 'lekarz';
		$args                = $this->run_decide();

		$this->assertSame( 'rejected', $args['status'] );
		$this->assertSame( 'lekarz', $args['profession'] );
	}

	public function test_szczegoly_bez_zakladki_w_adresie_nie_wymyslaja_jej(): void {
		$id = $this->account( 'pending' );

		$html = $this->render( array( 'user' => (string) $id ) );

		$this->assertStringNotContainsString( 'name="status"', $html );
		$this->assertStringNotContainsString( 'name="profession"', $html );
	}

	public function test_komunikat_mowi_gdy_mail_nie_wyszedl(): void {
		$id = $this->account( 'pending' );
		// Kolejka odmawia przyjęcia: ten sam klucz odporności w tej samej minucie co mail już zakolejkowany.
		$freeze = static fn() => '200001010000';
		add_filter( 'pusers_internal_test_seam_minute_bucket', $freeze );
		$this->assertTrue( Mailer::approved( $id ), 'Fixtura: pierwszy mail musi wejść do kolejki.' );

		$args = $this->decide( ApplicationsScreen::DECIDE_APPROVE, array( (string) $id ) );

		remove_filter( 'pusers_internal_test_seam_minute_bucket', $freeze );
		$this->assertSame( AccountStatus::Approved, $this->status_of( $id ), 'Konto jest jednak zatwierdzone.' );
		$this->assertSame( '1', $args['pusers_mail_failed'] );
		$html = $this->render_notice( $args );
		$this->assertStringContainsString( 'Mail do wnioskodawcy nie wyszedł', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function test_powtorne_odrzucenie_z_tym_samym_powodem_mowi_ze_powod_jest_identyczny(): void {
		$id = $this->account( 'pending' );
		$this->decide( ApplicationsScreen::DECIDE_REJECT, array( (string) $id ), array( 'reason' => 'Pierwszy' ) );

		$args = $this->decide( ApplicationsScreen::DECIDE_REJECT, array( (string) $id ), array( 'reason' => 'Pierwszy' ) );

		$this->assertSame( 'Pierwszy', get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
		$this->assertSame( '0', $args['pusers_done'] );
		$this->assertSame( '1', $args['pusers_unchanged'] );
		$html = $this->render_notice( $args );
		$this->assertStringContainsString( 'powód jest identyczny z zapisanym', $html );
		$this->assertStringNotContainsString( 'Odrzucono wniosków', $html );
	}

	public function test_poprawa_powodu_odrzucenia_ma_wlasny_komunikat_i_jest_sukcesem(): void {
		$id = $this->account( 'pending' );
		$this->decide( ApplicationsScreen::DECIDE_REJECT, array( (string) $id ), array( 'reason' => 'Pierwszy' ) );

		$args = $this->decide( ApplicationsScreen::DECIDE_REJECT, array( (string) $id ), array( 'reason' => 'Poprawiony' ) );

		$this->assertSame( 'Poprawiony', get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
		$this->assertSame( '1', $args['pusers_reason_updated'] );
		$this->assertSame( '0', $args['pusers_unchanged'] );
		$this->assertSame( '0', $args['pusers_skipped'] );
		$html = $this->render_notice( $args );
		$this->assertStringContainsString( 'Poprawiono powód odrzucenia: 1', $html );
		$this->assertStringNotContainsString( 'Pominięto', $html );
		$this->assertStringContainsString( 'notice-success', $html );
	}

	public function test_po_akcji_nastepuje_przekierowanie_z_zachowaniem_zakladki_i_filtra(): void {
		$id = $this->account( 'rejected' );
		$this->asAdminPosting(
			ApplicationsScreen::ACTION_DECIDE,
			array(
				'decision' => 'approve',
				'users'    => array( (string) $id ),
			)
		);
		// Zakładka i filtr jadą w ukrytych polach formularza listy pod nazwami argumentów adresu.
		$_POST['status']     = 'rejected';
		$_POST['profession'] = 'lekarz';

		$args = $this->run_decide();

		$this->assertSame( 'rejected', $args['status'] );
		$this->assertSame( 'lekarz', $args['profession'] );
		$this->assertSame( Menu::SLUG_APPLICATIONS, $args['page'] );
	}

	public function test_wiersz_logu_niesie_identyfikator_zalogowanego_administratora(): void {
		$id = $this->account( 'pending' );

		$this->decide( ApplicationsScreen::DECIDE_APPROVE, array( (string) $id ) );

		$rows = $this->log_rows( Decision::LOG_APPROVED );
		$this->assertCount( 1, $rows );
		$this->assertGreaterThan( 0, get_current_user_id() );
		$this->assertSame( get_current_user_id(), (int) $rows[0]->actor_user_id );
		$this->assertSame( $id, (int) $rows[0]->subject_user_id );
	}

	public function test_uchwyt_jest_podpiety_pod_admin_post(): void {
		remove_all_actions( 'admin_post_' . ApplicationsScreen::ACTION_DECIDE );
		ApplicationsScreen::register();

		$this->assertSame( 10, has_action( 'admin_post_' . ApplicationsScreen::ACTION_DECIDE, array( ApplicationsScreen::class, 'handle_decide' ) ) );
	}

	public function test_lista_ma_formularz_z_nonce_polami_wyboru_i_gotowymi_powodami(): void {
		$this->set_presets( array( 'Brak numeru PWZ' ) );
		$id = $this->account( 'pending' );

		$html = $this->render( array( 'status' => 'pending' ) );

		$this->assertStringContainsString( 'name="pusers_nonce"', $html );
		$this->assertStringContainsString( 'value="' . ApplicationsScreen::ACTION_DECIDE . '"', $html );
		$this->assertStringContainsString( 'name="' . ApplicationsScreen::FIELD_USERS . '[]" value="' . $id . '"', $html );
		$this->assertStringContainsString( 'name="' . ApplicationsScreen::FIELD_REASON_PRESET . '"', $html );
		$this->assertStringContainsString( 'Brak numeru PWZ', $html );
	}

	public function test_szczegoly_maja_przyciski_z_tym_samym_punktem_wejscia(): void {
		$id = $this->account( 'pending' );

		$html = $this->render( array( 'user' => (string) $id ) );

		$this->assertStringContainsString( 'name="pusers_nonce"', $html );
		$this->assertStringContainsString( 'value="' . ApplicationsScreen::ACTION_DECIDE . '"', $html );
		$this->assertStringContainsString( 'name="pusers_decision" value="approve"', $html );
		$this->assertStringContainsString( 'name="pusers_decision" value="reject"', $html );
	}

	public function test_szczegoly_konta_odrzuconego_maja_pole_powodu_i_przycisk_korekty(): void {
		$id = $this->account( 'rejected' );
		update_user_meta( $id, UserMeta::REJECTION_REASON, 'Stary' );

		$html = $this->render( array( 'user' => (string) $id ) );

		$this->assertStringContainsString( 'name="' . ApplicationsScreen::FIELD_REASON . '"', $html );
		$this->assertStringContainsString( 'name="pusers_decision" value="reject"', $html );
		$this->assertStringContainsString( 'Popraw powód odrzucenia', $html );
		$this->assertStringNotContainsString( '>Odrzuć<', $html, 'Przycisk korekty mówi, co robi, a nie „Odrzuć".' );
	}

	public function test_szczegoly_konta_niepotwierdzonego_nie_maja_zadnego_przycisku(): void {
		$id = $this->account( 'email_unconfirmed' );

		$html = $this->render( array( 'user' => (string) $id ) );

		$this->assertStringNotContainsString( 'name="pusers_decision"', $html );
		$this->assertStringNotContainsString( 'name="' . ApplicationsScreen::FIELD_REASON . '"', $html );
	}
}
