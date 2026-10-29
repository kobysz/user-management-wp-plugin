<?php
/**
 * Testy ekranu importu: skan kluczy, formularz mapowania i jego zapis.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\ImportScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Import\AccountChange;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ImportMappingRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
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

	public function test_zapisana_wartosc_zrodlowa_i_etykiety_sa_eskejpowane(): void {
		$hostile = '"><script>alert(1)</script>';
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'             => 'lekarz',
						'label'           => '<b>profesja</b>',
						'credential_type' => 'none',
					),
				)
			)
		);
		$this->withSource( array( 'pwz' => '<i>pole</i>' ) );
		ImportMappingRepository::save(
			ImportMapping::fromArray(
				array(
					'fields'           => array( 'pwz' => 'wpmem_pwz' ),
					'professionKey'    => 'wpmem_zawod',
					'professionValues' => array( $hostile => 'lekarz' ),
				)
			)
		);

		$html = $this->renderAsAdmin();

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'Wartość źródłowa w atrybucie.' );
		$this->assertStringNotContainsString( '<b>profesja</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;profesja&lt;/b&gt;', $html, 'Etykieta profesji.' );
		$this->assertStringNotContainsString( '<i>pole</i>', $html );
		$this->assertStringContainsString( '&lt;i&gt;pole&lt;/i&gt;', $html, 'Etykieta pola ACF.' );
	}

	public function test_spreparowany_post_nie_nadpisuje_zapisanego_wpisu_pola_bez_celu(): void {
		OptionStore::write( OptionStore::OPTION_IMPORT_MAPPING, array( 'fields' => array( 'zniknelo' => 'prawdziwy_klucz' ) ) );
		$post = $this->validPost();
		$post['fields']['zniknelo'] = 'evil';
		$this->asAdminPosting( ImportScreen::ACTION_SAVE, $post );

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( 'prawdziwy_klucz', $raw['fields']['zniknelo'] ?? null, 'Zapisany wpis przeżywa próbę nadpisania.' );
		$this->assertSame( 'wpmem_pwz', $raw['fields']['pwz'] ?? null );
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

	public function test_pusty_zapis_przy_niepustym_mapowaniu_je_czysci_ale_zachowuje_wpisy_bez_celu(): void {
		OptionStore::write(
			OptionStore::OPTION_IMPORT_MAPPING,
			array(
				'fields'           => array(
					'pwz'      => 'wpmem_pwz',
					'zniknelo' => 'stary_klucz',
				),
				'professionKey'    => 'wpmem_zawod',
				'professionValues' => array( 'lekarz' => 'lekarz' ),
			)
		);
		$this->asAdminPosting(
			ImportScreen::ACTION_SAVE,
			array(
				'fields'   => array( 'pwz' => '' ),
				'fallback' => ImportMapping::FALLBACK_NONE,
			)
		);

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array( 'zniknelo' => 'stary_klucz' ), $raw['fields'], 'Widoczne wpisy znikają, wpis pola nieobecnego w grupie zostaje.' );
		$this->assertSame( '', $raw['professionKey'] );
		$this->assertSame( array(), $raw['professionValues'] );
		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_SAVED_MAPPING, $this->redirected, 'Zostały wpisy bez celu, więc to zwykły zapis.' );
	}

	public function test_pusty_zapis_calkowicie_czyszczacy_mapowanie_mowi_ze_je_wyczyszczono(): void {
		$this->withStoredMapping();
		$this->asAdminPosting(
			ImportScreen::ACTION_SAVE,
			array(
				'fields'   => array( 'pwz' => '' ),
				'fallback' => ImportMapping::FALLBACK_NONE,
			)
		);

		ImportScreen::handle_save();

		$raw = OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING );
		$this->assertSame( array(), $raw['fields'] );
		$this->assertSame( '', $raw['professionKey'] );
		$this->assertTrue( ImportMappingRepository::get()->isEmpty() );
		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_CLEARED, $this->redirected );
		$this->assertStringNotContainsString( ImportScreen::NOTICE_NOTHING_MAPPED, $this->redirected );
	}

	public function test_pusty_zapis_przy_pustym_mapowaniu_daje_wlasny_komunikat_i_nic_nie_zapisuje(): void {
		$this->assertFalse( get_option( OptionStore::OPTION_IMPORT_MAPPING ), 'Punkt wyjścia: brak opcji.' );
		$this->asAdminPosting(
			ImportScreen::ACTION_SAVE,
			array(
				'fields'   => array( 'pwz' => '' ),
				'fallback' => ImportMapping::FALLBACK_APPROVED,
			)
		);

		ImportScreen::handle_save();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_NOTHING_MAPPED, $this->redirected );
		$this->assertFalse( get_option( OptionStore::OPTION_IMPORT_MAPPING ), 'Nic nie zostało zapisane.' );
	}
	public function test_komunikaty_po_zapisie_sa_widoczne(): void {
		$_GET['pusers_notice'] = ImportScreen::NOTICE_SAVED_MAPPING;
		$this->assertMatchesRegularExpression( '#notice-success.*Mapowanie importu zostało zapisane#s', $this->renderAsAdmin() );

		$_GET['pusers_notice'] = ImportScreen::NOTICE_NOTHING_MAPPED;
		$this->assertMatchesRegularExpression( '#notice-error.*Nic nie zapisano#s', $this->renderAsAdmin() );

		$_GET['pusers_notice'] = ImportScreen::NOTICE_CLEARED;
		$this->assertMatchesRegularExpression( '#notice-success.*Mapowanie importu zostało wyczyszczone#s', $this->renderAsAdmin() );
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

	/**
	 * Mapowanie importu użyte w testach raportu: numer i profesja z kluczy `wpmem_*`.
	 */
	private function importMapping(): ImportMapping {
		return ImportMapping::fromArray(
			array(
				'fields'           => array( 'pwz' => 'wpmem_pwz' ),
				'professionKey'    => 'wpmem_role',
				'professionValues' => array( 'doctor' => 'lekarz' ),
			)
		);
	}

	/**
	 * Zapisuje mapowanie importu i macierz pól, a potem tworzy podaną liczbę kont ze źródłowymi danymi.
	 *
	 * @param int $accounts Liczba kont do zaimportowania.
	 * @return list<int> Identyfikatory kont.
	 */
	private function seedImportable( int $accounts = 1 ): array {
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		ImportMappingRepository::save( $this->importMapping() );
		$ids = array();
		for ( $i = 0; $i < $accounts; $i++ ) {
			$id = (int) self::factory()->user->create();
			update_user_meta( $id, 'wpmem_pwz', '123456' . $i );
			update_user_meta( $id, 'wpmem_role', 'doctor' );
			$ids[] = $id;
		}
		return $ids;
	}

	private function preview(): void {
		$this->redirected = '';
		$this->asAdminPosting( ImportScreen::ACTION_PREVIEW, array() );
		ImportScreen::handle_preview();
	}

	private function apply(): void {
		$this->redirected = '';
		$this->asAdminPosting( ImportScreen::ACTION_APPLY, array() );
		ImportScreen::handle_apply();
	}

	/**
	 * Metadane importu konta w jednym ciągu: puste "|" oznacza, że nic nie zapisano.
	 *
	 * @param int $id Konto.
	 */
	private function importedMeta( int $id ): string {
		return get_user_meta( $id, UserMeta::PROFESSION, true ) . '|' . get_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', true );
	}

	/**
	 * Przenosi argumenty z ostatniego przekierowania do $_GET, jak zrobiłaby to przeglądarka.
	 */
	private function followRedirect(): void {
		$query = (string) wp_parse_url( $this->redirected, PHP_URL_QUERY );
		parse_str( $query, $args );
		$_GET = $args;
	}

	/**
	 * Przestawia czas zapisanego podglądu.
	 *
	 * @param int $seconds Ile sekund temu powstał.
	 */
	private function ageThePreview( int $seconds ): void {
		$stored       = OptionStore::read( OptionStore::OPTION_IMPORT_PREVIEW );
		$stored['at'] = time() - $seconds;
		OptionStore::write( OptionStore::OPTION_IMPORT_PREVIEW, $stored );
	}

	/**
	 * Uruchamia zapis z zepsutym zapytaniem; filtr i tryb błędów wracają w finally.
	 *
	 * @param callable $broken Filtr `query`.
	 */
	private function applyWithBrokenQuery( callable $broken ): void {
		global $wpdb;
		$previous = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $broken );
		try {
			$this->apply();
		} finally {
			remove_filter( 'query', $broken );
			$wpdb->suppress_errors( $previous );
		}
	}

	public function test_raport_po_trybie_probnym_pokazuje_liczby_i_przyciski(): void {
		$ids = $this->seedImportable( 2 );
		$this->preview();
		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_PREVIEW_READY, $this->redirected );
		$this->followRedirect();

		$html = $this->renderAsAdmin();

		$this->assertMatchesRegularExpression( '#<th scope="row">Konta do zmiany</th>\s*<td>2</td>#', $html );
		$this->assertMatchesRegularExpression( '#<th scope="row">Przeskanowane konta</th>\s*<td>\d+</td>#', $html );
		$this->assertStringContainsString( 'name="action" value="' . ImportScreen::ACTION_APPLY . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . ImportScreen::ACTION_PREVIEW . '"', $html );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ), 'Tryb próbny niczego nie zapisuje.' );
	}

	public function test_przed_podgladem_jest_przycisk_podgladu_ale_nie_zapisu(): void {
		$this->seedImportable();

		$html = $this->renderAsAdmin();

		$this->assertStringNotContainsString( 'value="' . ImportScreen::ACTION_APPLY . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . ImportScreen::ACTION_PREVIEW . '"', $html );
	}

	public function test_podglad_zapisuje_odcisk_mapowania_przycietego_przez_get(): void {
		$this->seedImportable();
		$raw                   = $this->importMapping()->toArray();
		$raw['fields']['duch'] = 'wpmem_duch';
		OptionStore::write( OptionStore::OPTION_IMPORT_MAPPING, $raw );
		$this->assertNotSame( ImportMapping::fromArray( $raw )->hash(), ImportMappingRepository::get()->hash(), 'Warunek testu: przycięcie zmienia odcisk.' );

		$this->preview();

		$preview = ImportMappingRepository::preview();
		$this->assertNotNull( $preview );
		$this->assertSame( ImportMappingRepository::get()->hash(), $preview['hash'] );
	}

	public function test_zapis_przy_mapowaniu_z_wpisem_do_przyciecia_przechodzi_bramke(): void {
		$ids                   = $this->seedImportable();
		$raw                   = $this->importMapping()->toArray();
		$raw['fields']['duch'] = 'wpmem_duch';
		OptionStore::write( OptionStore::OPTION_IMPORT_MAPPING, $raw );
		$this->preview();

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_APPLIED, $this->redirected );
		$this->assertSame( 'lekarz|1234560', $this->importedMeta( $ids[0] ) );
	}

	public function test_zapis_bez_raportu_jest_odrzucony(): void {
		$ids = $this->seedImportable();

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_STALE_PREVIEW, $this->redirected );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ), 'Odmowa bramki nie zapisuje niczego.' );
	}

	public function test_zmiana_mapowania_po_raporcie_uniewaznia_zapis(): void {
		$ids = $this->seedImportable();
		$this->preview();
		ImportMappingRepository::save(
			ImportMapping::fromArray(
				array_merge( $this->importMapping()->toArray(), array( 'fallback' => ImportMapping::FALLBACK_APPROVED ) )
			)
		);

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_STALE_PREVIEW, $this->redirected );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ) );
	}

	public function test_przedawniony_raport_uniewaznia_zapis(): void {
		$ids = $this->seedImportable();
		$this->preview();
		$this->ageThePreview( ImportScreen::PREVIEW_MAX_AGE + 1 );

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_STALE_PREVIEW, $this->redirected );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ) );
	}

	public function test_raport_tuz_przed_granica_wieku_jest_jeszcze_wazny(): void {
		$ids = $this->seedImportable();
		$this->preview();
		$this->ageThePreview( ImportScreen::PREVIEW_MAX_AGE - 30 );

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_APPLIED, $this->redirected );
		$this->assertSame( 'lekarz|1234560', $this->importedMeta( $ids[0] ) );
	}

	public function test_raport_dokladnie_w_wieku_granicznym_jest_odrzucony(): void {
		$ids = $this->seedImportable();
		$this->preview();
		$this->ageThePreview( ImportScreen::PREVIEW_MAX_AGE );

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_STALE_PREVIEW, $this->redirected );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ) );
	}

	public function test_puste_mapowanie_nie_ma_przycisku_zapisu_a_zapis_jest_odrzucony_jawnie(): void {
		global $wpdb;
		$ids = $this->seedImportable();
		ImportMappingRepository::save( ImportMapping::fromArray( array() ) );
		$this->preview();
		$this->assertNotNull( ImportMappingRepository::preview(), 'Warunek testu: podgląd pustego mapowania istnieje, więc bramka sama by się otworzyła.' );
		$table  = Migrations::table( 'event_log' );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().

		$html = $this->renderAsAdmin();
		$this->apply();

		$this->assertStringNotContainsString( 'value="' . ImportScreen::ACTION_APPLY . '"', $html );
		$this->assertStringContainsString( 'nie ma czego importować', $html );
		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_NOTHING_TO_RUN, $this->redirected );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ) );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'Odmowa nie zostawia wiersza w dzienniku.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
		$this->followRedirect();
		$this->assertStringContainsString( 'notice-error', $this->renderAsAdmin() );
	}

	public function test_raport_z_przyszlosci_nie_otwiera_bramki(): void {
		$ids = $this->seedImportable();
		$this->preview();
		$this->ageThePreview( -600 );

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_STALE_PREVIEW, $this->redirected );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ) );
	}

	public function test_udany_zapis_czysci_podglad_i_drugi_klik_juz_nie_przechodzi(): void {
		$ids = $this->seedImportable();
		$this->preview();

		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_APPLIED, $this->redirected );
		$this->assertSame( 'lekarz|1234560', $this->importedMeta( $ids[0] ) );

		delete_user_meta( $ids[0], UserMeta::PROFESSION );
		delete_user_meta( $ids[0], UserMeta::ACF_PREFIX . 'pwz' );
		$this->apply();

		$this->assertStringContainsString( 'pusers_notice=' . ImportScreen::NOTICE_STALE_PREVIEW, $this->redirected );
		$this->assertSame( '|', $this->importedMeta( $ids[0] ), 'Drugi przebieg nie ma prawa niczego zapisać.' );
	}

	public function test_podglad_jest_zuzyty_zanim_zacznie_sie_przebieg(): void {
		$this->seedImportable();
		$this->preview();
		$this->assertNotNull( ImportMappingRepository::preview(), 'Warunek testu: podgląd istnieje przed zapisem.' );
		$seen = array();
		$spy  = static function ( $meta_id, $user_id, $meta_key ) use ( &$seen ): void {
			if ( UserMeta::PROFESSION === $meta_key ) {
				$seen[] = null === ImportMappingRepository::preview();
			}
		};
		add_action( 'added_user_meta', $spy, 10, 4 );
		add_action( 'updated_user_meta', $spy, 10, 4 );
		try {
			$this->apply();
		} finally {
			remove_action( 'added_user_meta', $spy );
			remove_action( 'updated_user_meta', $spy );
		}

		$this->assertNotSame( array(), $seen, 'Przebieg musiał coś zapisać.' );
		$this->assertNotContains( false, $seen, 'Podgląd istniał jeszcze w trakcie przebiegu.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function actions(): array {
		return array(
			'podglad' => array( ImportScreen::ACTION_PREVIEW ),
			'zapis'   => array( ImportScreen::ACTION_APPLY ),
		);
	}

	/**
	 * Wywołuje uchwyt akcji.
	 *
	 * @param string $action Akcja admin-post.
	 */
	private function call( string $action ): void {
		if ( ImportScreen::ACTION_PREVIEW === $action ) {
			ImportScreen::handle_preview();
			return;
		}
		ImportScreen::handle_apply();
	}

	/**
	 * Stan, w którym ominięcie bramki żądania zapisałoby dane: ważny podgląd istnieje.
	 *
	 * @return int Konto, które nie może zostać zmienione.
	 */
	private function armedForApply(): int {
		$ids = $this->seedImportable();
		$this->preview();
		$this->redirected = '';
		return $ids[0];
	}

	/**
	 * @dataProvider actions
	 */
	public function test_podglad_i_zapis_odrzucaja_brak_uprawnien( string $action ): void {
		$id = $this->armedForApply();
		$this->asRolePosting( 'subscriber', $action, array() );

		try {
			$this->call( $action );
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '', $this->redirected );
			$this->assertSame( '|', $this->importedMeta( $id ) );
			$this->assertNotNull( ImportMappingRepository::preview(), 'Odmowa nie rusza podglądu.' );
		}
	}

	/**
	 * @dataProvider actions
	 */
	public function test_podglad_i_zapis_odrzucaja_brak_nonce( string $action ): void {
		$id = $this->armedForApply();
		$this->asAdminPosting( $action, array() );
		unset( $_POST['pusers_nonce'] );

		try {
			$this->call( $action );
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '', $this->redirected );
			$this->assertSame( '|', $this->importedMeta( $id ) );
			$this->assertNotNull( ImportMappingRepository::preview() );
		}
	}

	/**
	 * @dataProvider actions
	 */
	public function test_podglad_i_zapis_odrzucaja_metode_get( string $action ): void {
		$id = $this->armedForApply();
		$this->asAdminPosting( $action, array() );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		try {
			$this->call( $action );
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '', $this->redirected );
			$this->assertSame( '|', $this->importedMeta( $id ) );
			$this->assertNotNull( ImportMappingRepository::preview() );
		}
	}

	public function test_nieaktualny_raport_jest_opisany_i_nie_ma_przycisku_zapisu(): void {
		$this->seedImportable();
		$this->preview();
		ImportMappingRepository::save(
			ImportMapping::fromArray( array_merge( $this->importMapping()->toArray(), array( 'fallback' => ImportMapping::FALLBACK_PENDING ) ) )
		);

		$html = $this->renderAsAdmin();

		$this->assertStringNotContainsString( 'value="' . ImportScreen::ACTION_APPLY . '"', $html );
		$this->assertMatchesRegularExpression( '#notice-warning[^>]*>\s*<p>[^<]*nieaktualny#u', $html );
	}

	public function test_raport_ostrzega_o_kontach_bez_profesji_i_pokazuje_probke_jako_linki(): void {
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		ImportMappingRepository::save( $this->importMapping() );
		$id = (int) self::factory()->user->create();
		update_user_meta( $id, 'wpmem_pwz', '1234567' );
		update_user_meta( $id, 'wpmem_role', 'nieznana' );
		$this->preview();

		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $id ) ) . '"', $html );
	}

	public function test_raport_nie_wypisuje_niezaufanych_wartosci_z_zapisanego_podgladu(): void {
		$this->seedImportable();
		OptionStore::write(
			OptionStore::OPTION_IMPORT_PREVIEW,
			array(
				'hash'   => ImportMappingRepository::get()->hash(),
				'at'     => time(),
				'report' => array(
					'scanned'        => 3,
					'changed'        => 2,
					'write_failures' => 0,
					'issues'         => array( AccountChange::ISSUE_PROFESSION_UNMAPPED => 2 ),
					'samples'        => array( AccountChange::ISSUE_PROFESSION_UNMAPPED => array( 7, '"><script>alert(1)</script>', -5 ) ),
				),
			)
		);

		$html = $this->renderAsAdmin();

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( 'user_id=7"', $html );
		$this->assertStringNotContainsString( 'user_id=5"', $html, 'Ujemna liczba nie staje się dodatnim identyfikatorem.' );
	}

	public function test_porazka_zapisu_logu_daje_ostrzezenie_a_dane_zostaja(): void {
		$ids    = $this->seedImportable();
		$table  = Migrations::table( 'event_log' );
		$filter = static fn ( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, "`{$table}`" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;
		$this->preview();

		$this->applyWithBrokenQuery( $filter );
		$this->followRedirect();
		$html = $this->renderAsAdmin();

		$this->assertSame( 'lekarz|1234560', $this->importedMeta( $ids[0] ) );
		$this->assertMatchesRegularExpression( '#notice-warning[^>]*>\s*<p>[^<]*dziennik#u', $html );
		$this->assertStringContainsString( 'notice-success', $html );
	}

	public function test_udany_zapis_bez_porazek_nie_ostrzega(): void {
		$this->seedImportable();
		$this->preview();
		$this->apply();
		$this->followRedirect();

		$html = $this->renderAsAdmin();

		$this->assertStringNotContainsString( 'notice-warning', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
		$this->assertMatchesRegularExpression( '#notice-success[^>]*>\s*<p>[^<]*1#u', $html );
	}

	public function test_porazka_zapisu_konta_mowi_o_czesciowym_zapisie_i_bezpiecznym_ponowieniu(): void {
		global $wpdb;
		$ids    = $this->seedImportable( 2 );
		$victim = $ids[0];
		$filter = static fn ( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' )
			&& str_contains( $query, "`{$wpdb->usermeta}`" )
			&& str_contains( $query, "({$victim}, '" . UserMeta::ACF_PREFIX . "pwz'" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;
		$this->preview();

		$this->applyWithBrokenQuery( $filter );
		$this->assertStringContainsString( 'write_failures=1', $this->redirected );
		$this->followRedirect();
		$html = $this->renderAsAdmin();

		$this->assertMatchesRegularExpression( '#notice-warning[^>]*>\s*<p>[^<]*częściowo[^<]*bezpieczne#u', $html );
	}
}
