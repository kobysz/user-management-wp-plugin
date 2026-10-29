<?php
/**
 * Testy ekranu importu: skan kluczy, formularz mapowania i jego zapis.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\ImportScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ImportMappingRepository;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;
use WPDieException;

final class ImportScreenTest extends WP_UnitTestCase {

	use AdminPostingTrait;

	/**
	 * Adres ostatniego przekierowania (przekierowanie jest anulowane, żeby test przeżył).
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

	/**
	 * Metoda żądania sprzed testu.
	 *
	 * @var string
	 */
	private string $originalMethod = 'GET';

	public function set_up(): void {
		parent::set_up();
		$this->redirected     = '';
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->cancelRedirect = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		$this->withProfessions( 'lekarz', 'pielegniarka' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_groups' );
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		parent::tear_down();
	}

	/**
	 * Konfiguruje grupę ACF z podanymi polami (name => label) i zapisuje ją w ustawieniach.
	 *
	 * @param array<string, string> $fields Pola źródła.
	 */
	private function withSource( array $fields ): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static function () use ( $fields ): array {
				$out = array();
				foreach ( $fields as $name => $label ) {
					$out[] = array(
						'name'  => $name,
						'label' => $label,
					);
				}
				return $out;
			}
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
	}

	/**
	 * Zapisuje profesje o podanych kluczach.
	 *
	 * @param string ...$keys Klucze profesji.
	 */
	private function withProfessions( string ...$keys ): void {
		$items = array();
		foreach ( $keys as $key ) {
			$items[] = array(
				'key'             => $key,
				'label'           => ucfirst( $key ),
				'credential_type' => 'none',
			);
		}
		ProfessionRepository::save( ProfessionCollection::fromArray( $items ) );
	}

	/**
	 * Zapisuje mapowanie wyjściowe, względem którego testy sprawdzają "bez zmian".
	 */
	private function withStoredMapping(): ImportMapping {
		$mapping = ImportMapping::fromArray(
			array(
				'fields'        => array( 'pwz' => 'stary_klucz' ),
				'professionKey' => 'stara_profesja',
				'fallback'      => ImportMapping::FALLBACK_PENDING,
			)
		);
		ImportMappingRepository::save( $mapping );
		return $mapping;
	}

	private function renderAsAdmin(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		ImportScreen::render();
		return (string) ob_get_clean();
	}

	/**
	 * Konto z jedną wartością metadanych.
	 *
	 * @param string $key   Klucz.
	 * @param string $value Wartość.
	 */
	private function accountWith( string $key, string $value ): void {
		update_user_meta( self::factory()->user->create(), $key, $value );
	}

	/**
	 * Pola poprawnego żądania zapisu.
	 *
	 * @return array<string, mixed>
	 */
	private function validPost(): array {
		return array(
			'fields'            => array( 'pwz' => 'wpmem_pwz' ),
			'profession_key'    => 'wpmem_zawod',
			'profession_values' => array(
				array(
					'source' => 'Lekarz',
					'target' => 'lekarz',
				),
			),
			'status_key'        => 'wpmem_stan',
			'status_values'     => array(
				array(
					'source' => 'Aktywny',
					'target' => 'approved',
				),
			),
			'fallback'          => ImportMapping::FALLBACK_PENDING,
		);
	}

	public function test_tabela_skanu_pokazuje_klucz_liczbe_kont_i_probki(): void {
		$this->accountWith( 'wpmem_pwz', '1234567' );
		$this->accountWith( 'wpmem_pwz', '7654321' );

		$html = $this->renderAsAdmin();

		$this->assertMatchesRegularExpression(
			'#<tr[^>]*>\s*<td><code>wpmem_pwz</code></td>\s*<td>2</td>\s*<td>[^<]*1234567[^<]*7654321[^<]*</td>\s*</tr>#',
			$html
		);
	}

	public function test_probki_sa_eskejpowane(): void {
		$this->accountWith( 'wpmem_pwz', '<script>alert(1)</script>' );

		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_klucz_ze_skanu_jest_eskejpowany_takze_w_polu_wyboru(): void {
		$this->accountWith( 'zly"><img src=x onerror=alert(1)>', 'x' );

		$html = $this->renderAsAdmin();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( 'value="zly">', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html );
	}

	public function test_formularz_oferuje_tylko_dozwolone_stany(): void {
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'approved', $html );
		$this->assertStringContainsString( 'pending', $html );
		$this->assertStringContainsString( 'rejected', $html );
		$this->assertStringNotContainsString( 'email_unconfirmed', $html );
	}

	public function test_lista_stanow_ma_dokladnie_dozwolone_kody_malymi_literami(): void {
		$html = $this->renderAsAdmin();

		$this->assertSame( 1, preg_match( '#<select name="pusers_status_values\[0\]\[target\]">(.*?)</select>#s', $html, $select ), 'Brak listy stanów w formularzu.' );
		preg_match_all( '#<option value="([^"]*)"#', $select[1], $values );
		$offered = array_values( array_filter( $values[1], static fn ( string $v ): bool => '' !== $v ) );
		$this->assertSame( ImportMapping::ALLOWED_STATUSES, $offered );
	}

	public function test_formularz_zaznacza_zapisane_mapowanie(): void {
		ImportMappingRepository::save(
			ImportMapping::fromArray(
				array(
					'fields'           => array( 'pwz' => 'wpmem_pwz' ),
					'professionKey'    => 'wpmem_zawod',
					'professionValues' => array( 'lekarz' => 'lekarz' ),
					'statusKey'        => 'wpmem_stan',
					'statusValues'     => array( 'aktywny' => 'rejected' ),
					'fallback'         => ImportMapping::FALLBACK_APPROVED,
				)
			)
		);

		$html = $this->renderAsAdmin();

		$this->assertMatchesRegularExpression( '#<select name="pusers_fields\[pwz\]">(?:(?!</select>).)*<option value="wpmem_pwz" selected=\'selected\'>#s', $html );
		$this->assertMatchesRegularExpression( '#<select name="pusers_profession_key">(?:(?!</select>).)*<option value="wpmem_zawod" selected=\'selected\'>#s', $html );
		$this->assertMatchesRegularExpression( '#<select name="pusers_status_key">(?:(?!</select>).)*<option value="wpmem_stan" selected=\'selected\'>#s', $html );
		$this->assertMatchesRegularExpression( '#name="pusers_profession_values\[0\]\[source\]"[^>]*value="lekarz"#', $html );
		$this->assertMatchesRegularExpression( '#name="pusers_status_values\[0\]\[source\]"[^>]*value="aktywny"#', $html );
		$this->assertMatchesRegularExpression( '#<select name="pusers_status_values\[0\]\[target\]">(?:(?!</select>).)*<option value="rejected" selected=\'selected\'>#s', $html );
		$this->assertMatchesRegularExpression( '#<select name="pusers_fallback">(?:(?!</select>).)*<option value="approved" selected=\'selected\'>#s', $html );
	}

	public function test_zapis_mapowania_zapisuje_i_przekierowuje(): void {
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $this->validPost() );

		ImportScreen::handle_save();

		$stored = ImportMappingRepository::get();
		$this->assertSame( array( 'pwz' => 'wpmem_pwz' ), $stored->fields() );
		$this->assertSame( 'wpmem_zawod', $stored->professionKey() );
		$this->assertSame( 'lekarz', $stored->professionFor( 'lekarz' ) );
		$this->assertSame( 'wpmem_stan', $stored->statusKey() );
		$this->assertSame( 'approved', $stored->statusFor( 'aktywny' )?->value );
		$this->assertSame( ImportMapping::FALLBACK_PENDING, $stored->fallback() );
		$this->assertStringContainsString( 'page=' . Menu::SLUG_IMPORT, $this->redirected );
		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_SAVED_MAPPING, $this->redirected );
	}

	public function test_zapis_odrzuca_pole_i_profesje_spoza_konfiguracji(): void {
		$post                          = $this->validPost();
		$post['fields']['nie_ma_tego'] = 'wpmem_inne';
		$post['profession_values'][]   = array(
			'source' => 'Duch',
			'target' => 'nie_ma_takiej',
		);
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $post );

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array( 'pwz' => 'wpmem_pwz' ), $raw['fields'] );
		$this->assertSame( array( 'lekarz' => 'lekarz' ), $raw['professionValues'] );
	}

	public function test_zapis_nie_przyjmuje_stanu_email_unconfirmed_ani_wielkich_liter(): void {
		$post                 = $this->validPost();
		$post['status_values'] = array(
			array(
				'source' => 'zly',
				'target' => 'email_unconfirmed',
			),
			array(
				'source' => 'wielki',
				'target' => 'Approved',
			),
			array(
				'source' => 'dobry',
				'target' => 'rejected',
			),
		);
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $post );

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array( 'dobry' => 'rejected' ), $raw['statusValues'] );
	}

	public function test_zapis_zachowuje_wpis_pola_ktorego_nie_ma_teraz_w_grupie(): void {
		OptionStore::write(
			OptionStore::OPTION_IMPORT_MAPPING,
			array(
				'fields' => array(
					'zniknelo' => 'stary_klucz',
					'pwz'      => 'wpmem_pwz',
				),
			)
		);
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $this->validPost() );

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( 'stary_klucz', $raw['fields']['zniknelo'] ?? null, 'Wpis pola nieobecnego w grupie przeżywa zapis.' );
		$this->assertSame( 'wpmem_pwz', $raw['fields']['pwz'] ?? null );
	}

	public function test_zapis_przy_blednym_kluczu_grupy_nie_kasuje_mapowania_pol(): void {
		OptionStore::write( OptionStore::OPTION_IMPORT_MAPPING, array( 'fields' => array( 'pwz' => 'wpmem_pwz' ) ) );
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_literowka' ) ) );
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, array( 'profession_key' => 'wpmem_zawod' ) );

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array( 'pwz' => 'wpmem_pwz' ), $raw['fields'] );
		$this->assertSame( 'wpmem_zawod', $raw['professionKey'] );
	}

	public function test_zapis_zachowuje_wartosc_profesji_wskazujaca_na_usunieta_profesje(): void {
		OptionStore::write(
			OptionStore::OPTION_IMPORT_MAPPING,
			array(
				'fields'           => array( 'pwz' => 'wpmem_pwz' ),
				'professionValues' => array(
					'duch'   => 'usunieta',
					'lekarz' => 'lekarz',
				),
			)
		);
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $this->validPost() );

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( 'usunieta', $raw['professionValues']['duch'] ?? null );
		$this->assertSame( 'lekarz', $raw['professionValues']['lekarz'] ?? null );
	}

	public function test_widoczne_mapowanie_pola_mozna_wyczyscic_pustym_wpisem(): void {
		OptionStore::write(
			OptionStore::OPTION_IMPORT_MAPPING,
			array(
				'fields'           => array(
					'pwz'      => 'wpmem_pwz',
					'zniknelo' => 'stary_klucz',
				),
				'professionValues' => array( 'lekarz' => 'lekarz' ),
			)
		);
		$this->asAdminPosting(
			ImportScreen::ACTION_SAVE,
			array(
				'fields'            => array( 'pwz' => '' ),
				'profession_key'    => 'wpmem_zawod',
				'profession_values' => array(
					array(
						'source' => 'lekarz',
						'target' => '',
					),
				),
			)
		);

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array( 'zniknelo' => 'stary_klucz' ), $raw['fields'], 'Widoczny wpis wyczyszczony, nieobecny zachowany.' );
		$this->assertSame( array(), $raw['professionValues'] );
	}

	public function test_zapis_zachowuje_ukosnik_i_cudzyslow_w_wartosci_zrodlowej(): void {
		$post                        = $this->validPost();
		$post['profession_values'][] = array(
			'source' => 'lek\\arz "x"',
			'target' => 'pielegniarka',
		);
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $post );

		ImportScreen::handle_save();

		$this->assertSame( 'pielegniarka', ImportMappingRepository::get()->professionFor( 'lek\\arz "x"' ) );
	}

	public function test_zapis_pustego_mapowania_daje_wlasny_komunikat(): void {
		$before = $this->withStoredMapping();
		$this->asAdminPosting(
			ImportScreen::ACTION_SAVE,
			array(
				'fields'   => array( 'pwz' => '' ),
				'fallback' => ImportMapping::FALLBACK_APPROVED,
			)
		);

		ImportScreen::handle_save();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_NOTHING_MAPPED, $this->redirected );
		$this->assertStringNotContainsString( ImportScreen::NOTICE_SAVED_MAPPING, $this->redirected );
		$this->assertSame( $before->toArray(), ImportMappingRepository::get()->toArray(), 'Puste mapowanie nie nadpisuje zapisanego.' );
	}

	public function test_komunikaty_po_zapisie_sa_widoczne(): void {
		$_GET['pusers_notice'] = ImportScreen::NOTICE_SAVED_MAPPING;
		$this->assertMatchesRegularExpression( '#notice-success.*Mapowanie importu zostało zapisane#s', $this->renderAsAdmin() );

		$_GET['pusers_notice'] = ImportScreen::NOTICE_NOTHING_MAPPED;
		$this->assertMatchesRegularExpression( '#notice-error.*Nie zapisano#s', $this->renderAsAdmin() );
	}

	public function test_zapis_bez_uprawnien_jest_odrzucony(): void {
		$before = $this->withStoredMapping();
		$this->asRolePosting( 'subscriber', ImportScreen::ACTION_SAVE, $this->validPost() );

		try {
			ImportScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before->toArray(), ImportMappingRepository::get()->toArray() );
			$this->assertSame( '', $this->redirected );
		}
	}

	public function test_zapis_bez_nonce_jest_odrzucony(): void {
		$before = $this->withStoredMapping();
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $this->validPost() );
		unset( $_POST['pusers_nonce'] );

		try {
			ImportScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before->toArray(), ImportMappingRepository::get()->toArray() );
		}
	}

	public function test_zapis_metoda_get_jest_odrzucony(): void {
		$before = $this->withStoredMapping();
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $this->validPost() );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		try {
			ImportScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before->toArray(), ImportMappingRepository::get()->toArray() );
		}
	}

	public function test_render_bez_uprawnien_jest_odrzucony(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		ImportScreen::render();
	}

	public function test_porazka_skanu_pokazuje_blad_a_nie_pusta_tabele_i_zostawia_formularz(): void {
		$this->accountWith( 'wpmem_pwz', '1' );
		global $wpdb;
		$filter   = static fn ( string $query ): string => str_contains( $query, 'COUNT(DISTINCT' ) ? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje' : $query;
		$previous = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$html = $this->renderAsAdmin();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}

		$this->assertMatchesRegularExpression( '#notice-error[^>]*>\s*<p>[^<]*user_meta#', $html );
		$this->assertStringNotContainsString( 'wpmem_pwz', $html, 'Nie wolno udawać wyniku skanu.' );
		$this->assertStringNotContainsString( 'Nie znaleziono żadnych kluczy', $html, 'Błąd skanu to nie "portal bez metadanych".' );
		$this->assertStringContainsString( 'name="action" value="' . ImportScreen::ACTION_SAVE . '"', $html );
		$this->assertStringContainsString( 'name="pusers_fields[pwz]"', $html );
	}

	public function test_pusty_skan_mowi_ze_nie_ma_kluczy(): void {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wpdb->query( "DELETE FROM {$wpdb->usermeta}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- czyszczenie tabeli testowej; transakcja testu wycofa zmianę.
		ob_start();
		ImportScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Nie znaleziono żadnych kluczy', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	public function test_formularz_ma_nonce_i_akcje_zapisu(): void {
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'name="action" value="' . ImportScreen::ACTION_SAVE . '"', $html );
		$this->assertStringContainsString( 'name="pusers_nonce"', $html );
	}

	public function test_strona_wisi_pod_uzytkownikami(): void {
		$saved_sub          = $GLOBALS['submenu'] ?? null;
		$GLOBALS['submenu'] = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		try {
			ImportScreen::add_page();

			$slugs = array_column( $GLOBALS['submenu'][ Menu::PARENT ] ?? array(), 2 );
			$this->assertContains( Menu::SLUG_IMPORT, $slugs );
			$this->assertSame( 'pusers-import', Menu::SLUG_IMPORT );
		} finally {
			if ( null === $saved_sub ) {
				unset( $GLOBALS['submenu'] );
			} else {
				$GLOBALS['submenu'] = $saved_sub;
			}
		}
	}

	public function test_register_podpina_strone_i_zapis(): void {
		$post_hook  = 'admin_post_' . ImportScreen::ACTION_SAVE;
		$saved_menu = isset( $GLOBALS['wp_filter']['admin_menu'] ) ? clone $GLOBALS['wp_filter']['admin_menu'] : null;
		remove_all_actions( 'admin_menu' );
		remove_all_actions( $post_hook );

		try {
			ImportScreen::register();

			$this->assertNotFalse( has_action( 'admin_menu', array( ImportScreen::class, 'add_page' ) ) );
			$this->assertNotFalse( has_action( $post_hook, array( ImportScreen::class, 'handle_save' ) ) );
		} finally {
			remove_all_actions( $post_hook );
			if ( null === $saved_menu ) {
				unset( $GLOBALS['wp_filter']['admin_menu'] );
			} else {
				$GLOBALS['wp_filter']['admin_menu'] = $saved_menu;
			}
		}
	}
}
