<?php
/**
 * Testy ekranu macierzy profesja x pole.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\MatrixScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldState;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;
use WPDieException;

final class MatrixScreenTest extends WP_UnitTestCase {

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

	public function set_up(): void {
		parent::set_up();
		$this->savedAdminMenuHooks = isset( $GLOBALS['wp_filter']['admin_menu'] ) ? clone $GLOBALS['wp_filter']['admin_menu'] : null;
		$this->savedSubmenu        = $GLOBALS['submenu'] ?? null;
		$this->redirected          = '';
		$this->originalMethod      = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->cancelRedirect      = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_groups' );
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
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
	 * Zapisuje profesje o podanych kluczach (zapis macierzy przyjmuje tylko klucze z kolekcji).
	 *
	 * @param string ...$keys Klucze profesji.
	 */
	private function withProfessions( string ...$keys ): void {
		$items = array();
		foreach ( $keys as $key ) {
			$items[] = array(
				'key'             => $key,
				'label'           => $key,
				'credential_type' => 'none',
			);
		}
		ProfessionRepository::save( ProfessionCollection::fromArray( $items ) );
	}

	private function renderAsAdmin(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MatrixScreen::render();
		return (string) ob_get_clean();
	}

	public function test_saving_the_matrix_persists_the_three_states(): void {
		$this->withProfessions( 'lekarz' );
		$this->withSource(
			array(
				'pwz'    => 'PWZ',
				'miasto' => 'Miasto',
				'pwzp'   => 'PWZ pielęgniarki',
			)
		);
		$this->asAdminPosting(
			'pusers_save_matrix',
			array(
				'matrix' => array(
					'lekarz' => array(
						'pwz'    => 'required',
						'miasto' => 'visible',
						'pwzp'   => 'hidden',
					),
				),
			)
		);
		MatrixScreen::handle_save();
		$matrix = FieldMatrixRepository::get();
		$this->assertSame( FieldState::Required, $matrix->stateFor( 'lekarz', 'pwz' ) );
		$this->assertSame( FieldState::Visible, $matrix->stateFor( 'lekarz', 'miasto' ) );
		$this->assertSame( FieldState::Hidden, $matrix->stateFor( 'lekarz', 'pwzp' ) );
		$this->assertArrayHasKey( 'pwzp', FieldMatrixRepository::get()->toArray()['lekarz'], 'Jawne "hidden" jest zapisanym wpisem.' );
	}

	public function test_unknown_state_from_the_request_is_stored_as_hidden(): void {
		$this->withProfessions( 'lekarz' );
		$this->withSource( array( 'pwz' => 'PWZ' ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$this->asAdminPosting(
			'pusers_save_matrix',
			array(
				'matrix' => array( 'lekarz' => array( 'pwz' => 'bzdura' ) ),
			)
		);
		MatrixScreen::handle_save();
		$this->assertSame( FieldState::Hidden, FieldMatrixRepository::get()->stateFor( 'lekarz', 'pwz' ) );
		$this->assertSame( 'hidden', FieldMatrixRepository::get()->toArray()['lekarz']['pwz'], 'Wpis przepisany na "hidden", nie usunięty.' );
	}

	public function test_screen_lists_every_field_from_the_source_including_unconfigured_ones(): void {
		$this->withSource(
			array(
				'pwz'  => 'Numer PWZ',
				'nowe' => 'Nowe pole',
			)
		);
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
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$html = $this->renderAsAdmin();
		$this->assertStringContainsString( 'Nowe pole', $html );
		// Znacznik musi leżeć w wierszu pola "nowe": raport nad tabelą też mówi o braku ustawienia.
		$this->assertMatchesRegularExpression(
			'#<tr data-field="nowe">(?:(?!</tr>).)*<span class="pusers-unconfigured">nieskonfigurowane</span>#s',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'#<tr data-field="pwz">(?:(?!</tr>).)*pusers-unconfigured#s',
			$html,
			'Pole z wpisem w macierzy nie jest nieskonfigurowane.'
		);
	}

	public function test_screen_reports_consistency_issues(): void {
		$this->withSource(
			array(
				'pwz'  => 'Numer PWZ',
				'nowe' => 'Nowe pole',
			)
		);
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'              => 'lekarz',
						'label'            => 'Lekarz',
						'credential_type'  => 'physician_pwz',
						'credential_field' => 'pwz',
					),
				)
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden' ) ) ) );
		$codes = array_map( static fn ( $i ): string => $i->code, MatrixScreen::checker()->issues() );
		$this->assertContains( 'credential_field_not_required', $codes );
		$this->assertContains( 'field_not_configured', $codes, 'Dowodzi, że checker() dostaje źródło pól wybranej grupy.' );
	}

	public function test_rendered_screen_shows_the_consistency_report_above_the_table(): void {
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'              => 'lekarz',
						'label'            => 'Lekarz',
						'credential_type'  => 'physician_pwz',
						'credential_field' => 'pwz',
					),
				)
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden' ) ) ) );
		$html   = $this->renderAsAdmin();
		$report = strpos( $html, 'nie jest w macierzy oznaczone jako wymagane' );
		$table  = strpos( $html, '<table' );
		$this->assertNotFalse( $report );
		$this->assertNotFalse( $table );
		$this->assertLessThan( $table, $report );
	}

	public function test_rendered_screen_carries_a_nonce_and_escapes_field_labels(): void {
		$this->withSource( array( 'x' => '<script>alert(1)</script>' ) );
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
		$html = $this->renderAsAdmin();
		$this->assertStringContainsString( 'pusers_nonce', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	// --- Dopisane na polecenie: scalanie, puste wartości, usuwanie martwych wierszy, uprawnienia. ---

	public function test_saving_merges_into_the_stored_matrix_instead_of_replacing_it(): void {
		$this->withProfessions( 'lekarz', 'pielegniarka' );
		$this->withSource( array( 'pwz' => 'PWZ' ) );
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz'       => array(
						'pwz'   => 'required',
						'stare' => 'visible',
					),
					'pielegniarka' => array( 'pwzp' => 'required' ),
				)
			)
		);
		$this->asAdminPosting(
			'pusers_save_matrix',
			array(
				'matrix' => array( 'lekarz' => array( 'pwz' => 'visible' ) ),
			)
		);
		MatrixScreen::handle_save();
		$matrix = FieldMatrixRepository::get();
		$this->assertSame( FieldState::Visible, $matrix->stateFor( 'lekarz', 'pwz' ), 'Przysłana para jest nadpisana.' );
		$this->assertSame( FieldState::Visible, $matrix->stateFor( 'lekarz', 'stare' ), 'Pole spoza żądania zostaje.' );
		$this->assertSame( FieldState::Required, $matrix->stateFor( 'pielegniarka', 'pwzp' ), 'Profesja spoza żądania zostaje.' );
	}

	public function test_an_empty_state_leaves_the_cell_unconfigured(): void {
		$this->withProfessions( 'lekarz' );
		$this->withSource(
			array(
				'pwz'  => 'PWZ',
				'nowe' => 'Nowe',
			)
		);
		$this->asAdminPosting(
			'pusers_save_matrix',
			array(
				'matrix' => array(
					'lekarz' => array(
						'pwz'  => 'required',
						'nowe' => '',
					),
				),
			)
		);
		MatrixScreen::handle_save();
		$saved = FieldMatrixRepository::get()->toArray();
		$this->assertSame( 'required', $saved['lekarz']['pwz'] );
		$this->assertArrayNotHasKey( 'nowe', $saved['lekarz'], 'Pusty wybór nie zapisuje stanu "hidden".' );
	}

	public function test_saving_redirects_back_with_a_saved_notice(): void {
		$this->withSource( array( 'pwz' => 'PWZ' ) );
		$this->asAdminPosting( 'pusers_save_matrix', array( 'matrix' => array( 'lekarz' => array( 'pwz' => 'visible' ) ) ) );
		MatrixScreen::handle_save();
		$this->assertStringContainsString( 'page=pusers-matrix', $this->redirected );
		$this->assertStringContainsString( 'pusers_notice=saved', $this->redirected );
	}

	public function test_saving_is_refused_for_a_user_without_the_capability_and_changes_nothing(): void {
		$this->withSource( array( 'pwz' => 'PWZ' ) );
		$this->asRolePosting( 'subscriber', 'pusers_save_matrix', array( 'matrix' => array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		try {
			MatrixScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), FieldMatrixRepository::get()->toArray() );
		}
	}

	public function test_removing_a_dead_field_clears_only_that_field_for_every_profession(): void {
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz'       => array(
						'pwz'    => 'required',
						'martwe' => 'visible',
					),
					'pielegniarka' => array(
						'martwe' => 'required',
						'pwzp'   => 'required',
					),
					'student'      => array( 'miasto' => 'visible' ),
				)
			)
		);
		$this->withSource(
			array(
				'pwz'    => 'PWZ',
				'pwzp'   => 'PWZ pielęgniarki',
				'miasto' => 'Miasto',
			)
		);
		$this->asAdminPosting( 'pusers_delete_matrix_field', array( 'field' => 'martwe' ) );
		MatrixScreen::handle_delete_field();
		$this->assertSame(
			array(
				'lekarz'       => array( 'pwz' => 'required' ),
				'pielegniarka' => array( 'pwzp' => 'required' ),
				'student'      => array( 'miasto' => 'visible' ),
			),
			FieldMatrixRepository::get()->toArray()
		);
		$this->assertStringContainsString( 'page=pusers-matrix', $this->redirected );
	}

	public function test_removing_a_dead_field_is_refused_without_the_capability(): void {
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'martwe' => 'visible' ) ) ) );
		$this->asRolePosting( 'subscriber', 'pusers_delete_matrix_field', array( 'field' => 'martwe' ) );
		try {
			MatrixScreen::handle_delete_field();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( FieldState::Visible, FieldMatrixRepository::get()->stateFor( 'lekarz', 'martwe' ) );
			$this->assertArrayHasKey( 'martwe', FieldMatrixRepository::get()->toArray()['lekarz'] );
		}
	}

	public function test_removing_a_dead_field_is_refused_with_a_bad_nonce(): void {
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'martwe' => 'visible' ) ) ) );
		$this->asAdminPosting( 'pusers_delete_matrix_field', array( 'field' => 'martwe' ) );
		$_POST['pusers_nonce'] = 'zly-nonce';
		$_REQUEST              = $_POST;
		try {
			MatrixScreen::handle_delete_field();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertArrayHasKey( 'martwe', FieldMatrixRepository::get()->toArray()['lekarz'] );
		}
	}

	public function test_a_nonce_issued_for_saving_does_not_authorise_removal(): void {
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'martwe' => 'visible' ) ) ) );
		$this->asAdminPosting( 'pusers_save_matrix', array( 'field' => 'martwe' ) );
		try {
			MatrixScreen::handle_delete_field();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertArrayHasKey( 'martwe', FieldMatrixRepository::get()->toArray()['lekarz'] );
		}
	}

	public function test_dead_rows_are_listed_separately_with_a_removal_form(): void {
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
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
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'pwz'    => 'visible',
						'martwe' => 'visible',
					),
				)
			)
		);
		$html = $this->renderAsAdmin();
		$this->assertStringNotContainsString( '<tr data-field="martwe">', $html, 'Martwe pole nie jest wierszem edytowalnej tabeli.' );
		$this->assertMatchesRegularExpression( '#<tr data-dead-field="martwe">(?:(?!</tr>).)*name="pusers_field" value="martwe"#s', $html );
		$this->assertStringContainsString( 'value="pusers_delete_matrix_field"', $html );
	}

	public function test_the_screen_registers_its_own_submenu_page_under_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_all_actions( 'admin_menu' );
		global $submenu;
		$submenu = array();
		MatrixScreen::register();
		do_action( 'admin_menu' );
		$slugs = wp_list_pluck( $submenu['users.php'] ?? array(), 2 );
		$this->assertContains( Menu::SLUG_MATRIX, $slugs );
		$this->assertNotContains( Menu::SLUG_PROFESSIONS, $slugs );
	}

	// --- Runda poprawek 1. ---

	public function test_a_live_field_cannot_be_removed_even_with_a_valid_request(): void {
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$this->asAdminPosting( 'pusers_delete_matrix_field', array( 'field' => 'pwz' ) );
		MatrixScreen::handle_delete_field();
		$this->assertSame( FieldState::Required, FieldMatrixRepository::get()->stateFor( 'lekarz', 'pwz' ) );
		$this->assertStringContainsString( 'pusers_notice=matrix_field_refused', $this->redirected );
	}

	public function test_nothing_is_removed_when_the_group_cannot_be_read(): void {
		// Brak filtrów ACF i grupy w ustawieniach: źródła nie da się przeczytać.
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$this->asAdminPosting( 'pusers_delete_matrix_field', array( 'field' => 'pwz' ) );
		MatrixScreen::handle_delete_field();
		$this->assertSame( FieldState::Required, FieldMatrixRepository::get()->stateFor( 'lekarz', 'pwz' ) );
		$this->assertStringContainsString( 'pusers_notice=matrix_field_refused', $this->redirected );
	}

	public function test_an_empty_field_removes_nothing_and_reports_no_success(): void {
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'pwz'    => 'required',
						'martwe' => 'visible',
					),
				)
			)
		);
		$this->asAdminPosting( 'pusers_delete_matrix_field', array( 'field' => '' ) );
		MatrixScreen::handle_delete_field();
		$this->assertSame(
			array(
				'pwz'    => 'required',
				'martwe' => 'visible',
			),
			FieldMatrixRepository::get()->toArray()['lekarz']
		);
		$this->assertStringNotContainsString( 'matrix_field_removed', $this->redirected );
		$this->assertStringContainsString( 'pusers_notice=matrix_field_refused', $this->redirected );
	}

	public function test_a_source_field_with_capital_letters_can_be_saved(): void {
		$this->withProfessions( 'lekarz' );
		$this->withSource( array( 'PWZ' => 'Numer PWZ' ) );
		$this->asAdminPosting( 'pusers_save_matrix', array( 'matrix' => array( 'lekarz' => array( 'PWZ' => 'required' ) ) ) );
		MatrixScreen::handle_save();
		$this->assertSame( FieldState::Required, FieldMatrixRepository::get()->stateFor( 'lekarz', 'PWZ' ) );
	}

	public function test_a_field_outside_a_readable_source_is_not_saved_and_does_not_become_required(): void {
		$this->withProfessions( 'lekarz' );
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		$this->asAdminPosting(
			'pusers_save_matrix',
			array(
				'matrix' => array(
					'lekarz' => array(
						'smiec' => 'required',
						'pwz'   => 'required',
					),
				),
			)
		);
		MatrixScreen::handle_save();
		$matrix = FieldMatrixRepository::get();
		$this->assertSame( array( 'pwz' ), $matrix->requiredFields( 'lekarz' ) );
		$this->assertArrayNotHasKey( 'smiec', $matrix->toArray()['lekarz'] );
	}

	public function test_a_profession_key_with_capital_letters_is_saved_and_read_under_the_same_form(): void {
		$this->withSource( array( 'pwz' => 'PWZ' ) );
		$this->withProfessions( 'Lekarz' );
		$this->asAdminPosting( 'pusers_save_matrix', array( 'matrix' => array( 'Lekarz' => array( 'pwz' => 'required' ) ) ) );
		MatrixScreen::handle_save();
		$matrix = FieldMatrixRepository::get();
		$this->assertSame( FieldState::Required, $matrix->stateFor( 'Lekarz', 'pwz' ), 'Klucz profesji ma zostać taki, jaki jest w kolekcji.' );
		$this->assertArrayNotHasKey( 'lekarz', $matrix->toArray(), 'Klucz nie może zostać zmieniony na małe litery.' );
		$this->assertSame( array( 'pwz' ), $matrix->requiredFields( 'Lekarz' ) );
	}

	public function test_a_profession_key_missing_from_the_collection_is_not_saved(): void {
		$this->withSource( array( 'pwz' => 'PWZ' ) );
		$this->withProfessions( 'lekarz' );
		$this->asAdminPosting(
			'pusers_save_matrix',
			array(
				'matrix' => array(
					'lekarz'  => array( 'pwz' => 'required' ),
					'LEKARZ'  => array( 'pwz' => 'hidden' ),
					'duch'    => array( 'pwz' => 'required' ),
				),
			)
		);
		MatrixScreen::handle_save();
		$saved = FieldMatrixRepository::get()->toArray();
		$this->assertSame( array( 'lekarz' => array( 'pwz' => 'required' ) ), $saved, 'Obca profesja nie trafia do macierzy, a wielkość liter nie jest skłaniana do istniejącego klucza.' );
	}

	public function test_a_get_request_to_save_is_refused_and_changes_nothing(): void {
		$this->withSource( array( 'pwz' => 'PWZ' ) );
		$this->asAdminPosting( 'pusers_save_matrix', array( 'matrix' => array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		try {
			MatrixScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), FieldMatrixRepository::get()->toArray() );
		}
	}

	public function test_a_get_request_to_remove_a_field_is_refused_and_changes_nothing(): void {
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'martwe' => 'visible' ) ) ) );
		$this->asAdminPosting( 'pusers_delete_matrix_field', array( 'field' => 'martwe' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		try {
			MatrixScreen::handle_delete_field();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertArrayHasKey( 'martwe', FieldMatrixRepository::get()->toArray()['lekarz'] );
		}
	}

	public function test_dead_rows_have_no_removal_form_when_the_group_cannot_be_read(): void {
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
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$html = $this->renderAsAdmin();
		$this->assertStringContainsString( '<tr data-dead-field="pwz">', $html, 'Wiersze nadal widać.' );
		$this->assertStringNotContainsString( 'value="pusers_delete_matrix_field"', $html );
		$this->assertStringContainsString( 'nie istnieje już w ACF', $html );
		$this->assertStringContainsString( 'Usuwanie jest wyłączone', $html, 'Akapit w sekcji martwych wierszy.' );
	}

	public function test_an_existing_but_empty_group_gets_its_own_message(): void {
		$this->withSource( array() );
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
		$html = $this->renderAsAdmin();
		$this->assertStringContainsString( 'nie zawiera żadnych pól', $html );
		$this->assertStringNotContainsString( 'nie istnieje już w ACF', $html );
	}

	// --- Runda poprawek 3. ---

	public function test_saving_is_rejected_as_a_whole_when_the_group_is_not_selected(): void {
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'visible' ) ) ) );
		$before = FieldMatrixRepository::get()->toArray();
		$this->asAdminPosting( 'pusers_save_matrix', array( 'matrix' => array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		MatrixScreen::handle_save();
		$this->assertSame( $before, FieldMatrixRepository::get()->toArray() );
		$this->assertStringContainsString( 'pusers_notice=matrix_save_refused', $this->redirected );
		$this->assertStringNotContainsString( 'pusers_notice=saved', $this->redirected );
	}

	public function test_saving_is_rejected_when_the_selected_group_is_gone_from_acf(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_inna' => 'Inna' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'PWZ',
				),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_usunieta' ) ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'visible' ) ) ) );
		$before = FieldMatrixRepository::get()->toArray();
		$this->asAdminPosting( 'pusers_save_matrix', array( 'matrix' => array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		MatrixScreen::handle_save();
		$this->assertSame( $before, FieldMatrixRepository::get()->toArray() );
		$this->assertStringContainsString( 'pusers_notice=matrix_save_refused', $this->redirected );
	}

	public function test_an_unknown_key_is_skipped_but_the_rest_of_the_request_is_saved(): void {
		$this->withProfessions( 'lekarz', 'pielegniarka' );
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		$this->asAdminPosting(
			'pusers_save_matrix',
			array(
				'matrix' => array(
					'lekarz'       => array(
						'smiec' => 'required',
						'pwz'   => 'required',
					),
					'pielegniarka' => array( 'pwz' => 'visible' ),
				),
			)
		);
		MatrixScreen::handle_save();
		$saved = FieldMatrixRepository::get()->toArray();
		$this->assertSame( array( 'pwz' => 'required' ), $saved['lekarz'] );
		$this->assertSame( array( 'pwz' => 'visible' ), $saved['pielegniarka'] );
	}

	public function test_removing_a_field_that_is_nowhere_in_the_matrix_reports_no_success(): void {
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$this->asAdminPosting( 'pusers_delete_matrix_field', array( 'field' => 'nie-ma-takiego' ) );
		MatrixScreen::handle_delete_field();
		$this->assertStringNotContainsString( 'matrix_field_removed', $this->redirected );
		$this->assertStringContainsString( 'pusers_notice=matrix_field_refused', $this->redirected );
		$this->assertSame( array( 'lekarz' => array( 'pwz' => 'required' ) ), FieldMatrixRepository::get()->toArray() );
	}

	public function test_dead_rows_explain_that_removal_is_off_when_the_group_is_gone_from_acf(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_inna' => 'Inna' ) );
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_usunieta' ) ) );
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
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$html = $this->renderAsAdmin();
		$this->assertStringContainsString( '<tr data-dead-field="pwz">', $html );
		$this->assertStringNotContainsString( 'value="pusers_delete_matrix_field"', $html );
	}

	public function test_refusal_notices_are_rendered_as_errors(): void {
		$_GET = array( 'pusers_notice' => 'matrix_field_refused' );
		$this->assertMatchesRegularExpression( '#<div class="notice notice-error"><p>Nie usunięto#u', $this->renderAsAdmin() );
		$_GET = array( 'pusers_notice' => 'matrix_save_refused' );
		$this->assertMatchesRegularExpression( '#<div class="notice notice-error"><p>Nie zapisano macierzy#u', $this->renderAsAdmin() );
	}
}
