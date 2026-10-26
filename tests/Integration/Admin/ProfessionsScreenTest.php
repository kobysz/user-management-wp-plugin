<?php
/**
 * Testy ekranu profesji i jego zapisu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\Menu;
use PortalUsers\Admin\ProfessionsScreen;
use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ProfessionRepository;
use WP_UnitTestCase;
use WPDieException;

final class ProfessionsScreenTest extends WP_UnitTestCase {

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
	 * Stan globalnych hooków admin_menu i menu sprzed testu (testy izolują rejestrację menu).
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
		$this->redirected     = '';
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->cancelRedirect = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_all_filters( 'pusers_acf_fields' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
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

	public function test_adding_a_profession_persists_it_with_the_selected_credential_type(): void {
		$this->asAdminPosting( 'pusers_save_professions', array(
			'profession' => array( 'key' => 'lekarz', 'label' => 'Lekarz',
				'credential_type' => 'physician_pwz', 'credential_field' => 'pwz',
				'registry_url' => 'https://rejestr.nil.org.pl/', 'notify_email' => 'redakcja@example.test' ),
		) );
		ProfessionsScreen::handle_save();
		$saved = ProfessionRepository::get()->get( 'lekarz' );
		$this->assertSame( CredentialType::PhysicianPwz, $saved->credentialType );
		$this->assertSame( 'redakcja@example.test', $saved->notifyEmail );
	}

	public function test_unknown_credential_type_from_the_request_is_rejected_not_degraded(): void {
		// Degradacja do None jest poprawna przy czytaniu zepsutej konfiguracji,
		// ale przy zapisie z panelu oznaczałaby ciche wyłączenie weryfikacji numeru.
		$this->asAdminPosting( 'pusers_save_professions', array(
			'profession' => array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pw' ),
		) );
		ProfessionsScreen::handle_save();
		$this->assertNull( ProfessionRepository::get()->get( 'lekarz' ) );
	}

	public function test_editing_an_existing_profession_cannot_change_its_key(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
		) ) );
		$this->asAdminPosting( 'pusers_save_professions', array(
			'original_key' => 'lekarz',
			'profession'   => array( 'key' => 'doktor', 'label' => 'Doktor', 'credential_type' => 'physician_pwz' ),
		) );
		ProfessionsScreen::handle_save();
		$collection = ProfessionRepository::get();
		$this->assertSame( array( 'lekarz' ), $collection->keys() );
		$this->assertSame( 'Doktor', $collection->get( 'lekarz' )->label );
	}

	public function test_accounts_using_counts_users_with_that_profession(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'pusers_profession', 'lekarz' );
		$this->assertSame( 1, ProfessionsScreen::accounts_using( 'lekarz' ) );
		$this->assertSame( 0, ProfessionsScreen::accounts_using( 'student' ) );
	}

	public function test_deleting_a_profession_in_use_requires_explicit_confirmation(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
		) ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, 'pusers_profession', 'lekarz' );
		$this->asAdminPosting( 'pusers_delete_profession', array( 'delete' => 'lekarz' ) );
		ProfessionsScreen::handle_delete();
		$this->assertNotNull( ProfessionRepository::get()->get( 'lekarz' ), 'Bez potwierdzenia profesja zostaje.' );

		$this->asAdminPosting( 'pusers_delete_profession', array( 'delete' => 'lekarz', 'confirm_accounts' => '1' ) );
		ProfessionsScreen::handle_delete();
		$this->assertNull( ProfessionRepository::get()->get( 'lekarz' ) );
	}

	public function test_deleting_an_unused_profession_needs_no_confirmation(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		$this->asAdminPosting( 'pusers_delete_profession', array( 'delete' => 'student' ) );
		ProfessionsScreen::handle_delete();
		$this->assertNull( ProfessionRepository::get()->get( 'student' ) );
	}

	public function test_rendered_screen_offers_only_the_four_known_credential_types(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		ProfessionsScreen::render();
		$html = (string) ob_get_clean();
		foreach ( CredentialType::cases() as $case ) {
			$this->assertStringContainsString( 'value="' . $case->value . '"', $html );
		}
		$this->assertStringContainsString( '<select id="pusers_profession[credential_type]" name="pusers_profession[credential_type]">', $html );
	}

	// --- Testy dopisane przez implementera (patrz raport: testy z briefu, które same nie dowodzą). ---

	public function test_credential_type_is_rendered_as_a_select_with_exactly_four_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		ProfessionsScreen::render();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '#<select id="pusers_profession\[credential_type\]" name="pusers_profession\[credential_type\]">(.*?)</select>#s', $html, $match ), 'Typ numeru ma być listą wyboru.' );
		$this->assertSame( 4, substr_count( $match[1], '<option ' ) );
		$this->assertStringNotContainsString( 'type="text" class="regular-text" id="pusers_profession[credential_type]"', $html );
	}

	public function test_editing_one_profession_leaves_the_others_untouched(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz', 'registry_url' => 'https://nil.example.test/', 'notify_email' => 'a@example.test' ),
			array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka', 'credential_type' => 'nurse_pwz', 'credential_field' => 'pwz_p', 'registry_url' => 'https://nipip.example.test/', 'notify_email' => 'b@example.test' ),
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		$before = ProfessionRepository::get()->get( 'pielegniarka' )->toArray();
		$this->asAdminPosting( 'pusers_save_professions', array(
			'original_key' => 'lekarz',
			'profession'   => array( 'label' => 'Lekarz medycyny', 'credential_type' => 'physician_pwz' ),
		) );
		ProfessionsScreen::handle_save();
		$collection = ProfessionRepository::get();
		$this->assertSame( array( 'lekarz', 'pielegniarka', 'student' ), $collection->keys(), 'Kolejność i skład kolekcji bez zmian.' );
		$this->assertSame( $before, $collection->get( 'pielegniarka' )->toArray() );
		$this->assertSame( 'Lekarz medycyny', $collection->get( 'lekarz' )->label );
	}

	public function test_fields_missing_from_an_edit_request_keep_their_saved_values(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz', 'registry_url' => 'https://nil.example.test/', 'notify_email' => 'a@example.test' ),
		) ) );
		$this->asAdminPosting( 'pusers_save_professions', array(
			'original_key' => 'lekarz',
			'profession'   => array( 'label' => 'Lekarz 2' ),
		) );
		ProfessionsScreen::handle_save();
		$saved = ProfessionRepository::get()->get( 'lekarz' );
		$this->assertSame( 'Lekarz 2', $saved->label );
		$this->assertSame( CredentialType::PhysicianPwz, $saved->credentialType );
		$this->assertSame( 'pwz', $saved->credentialField );
		$this->assertSame( 'https://nil.example.test/', $saved->registryUrl );
		$this->assertSame( 'a@example.test', $saved->notifyEmail );
	}

	public function test_an_empty_field_in_an_edit_request_clears_the_value(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'notify_email' => 'a@example.test' ),
		) ) );
		$this->asAdminPosting( 'pusers_save_professions', array(
			'original_key' => 'lekarz',
			'profession'   => array( 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'notify_email' => '' ),
		) );
		ProfessionsScreen::handle_save();
		$this->assertSame( '', ProfessionRepository::get()->get( 'lekarz' )->notifyEmail );
	}

	public function test_unknown_credential_type_on_edit_is_rejected_and_keeps_the_saved_type(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
		) ) );
		$this->asAdminPosting( 'pusers_save_professions', array(
			'original_key' => 'lekarz',
			'profession'   => array( 'label' => 'Zmieniona', 'credential_type' => 'physician_pw' ),
		) );
		ProfessionsScreen::handle_save();
		$saved = ProfessionRepository::get()->get( 'lekarz' );
		$this->assertSame( CredentialType::PhysicianPwz, $saved->credentialType );
		$this->assertSame( 'Lekarz', $saved->label );
		$this->assertStringContainsString( 'pusers_notice=profession_invalid_type', $this->redirected );
	}

	public function test_a_new_profession_without_a_credential_type_is_rejected(): void {
		$this->asAdminPosting( 'pusers_save_professions', array( 'profession' => array( 'key' => 'lekarz', 'label' => 'Lekarz' ) ) );
		ProfessionsScreen::handle_save();
		$this->assertNull( ProfessionRepository::get()->get( 'lekarz' ) );
	}

	public function test_a_new_profession_cannot_overwrite_an_existing_key(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
		) ) );
		$this->asAdminPosting( 'pusers_save_professions', array(
			'profession' => array( 'key' => 'lekarz', 'label' => 'Podmieniony', 'credential_type' => 'none' ),
		) );
		ProfessionsScreen::handle_save();
		$this->assertSame( 'Lekarz', ProfessionRepository::get()->get( 'lekarz' )->label );
		$this->assertStringContainsString( 'pusers_notice=profession_invalid_key', $this->redirected );
	}

	public function test_an_invalid_notify_email_is_rejected_not_silently_dropped(): void {
		$this->asAdminPosting( 'pusers_save_professions', array(
			'profession' => array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'notify_email' => 'nie-adres' ),
		) );
		ProfessionsScreen::handle_save();
		$this->assertNull( ProfessionRepository::get()->get( 'lekarz' ) );
	}

	public function test_saving_redirects_back_with_a_saved_notice(): void {
		$this->asAdminPosting( 'pusers_save_professions', array(
			'profession' => array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) );
		ProfessionsScreen::handle_save();
		$this->assertStringContainsString( 'page=pusers-professions', $this->redirected );
		$this->assertStringContainsString( 'pusers_notice=saved', $this->redirected );
	}

	public function test_refused_delete_reports_how_many_accounts_are_affected(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
		) ) );
		foreach ( array( 1, 2, 3 ) as $ignored ) {
			update_user_meta( self::factory()->user->create(), 'pusers_profession', 'lekarz' );
		}
		$this->asAdminPosting( 'pusers_delete_profession', array( 'delete' => 'lekarz' ) );
		ProfessionsScreen::handle_delete();
		$this->assertStringContainsString( 'pusers_notice=profession_confirm_accounts', $this->redirected );

		// Ekran liczy konta od nowa: podrobiony parametr accounts=99 w adresie nie ma wpływu.
		$_GET = array( 'pusers_notice' => 'profession_confirm_accounts', 'profession' => 'lekarz', 'accounts' => '99' );
		ob_start();
		ProfessionsScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'przypisana do 3 kont', $html );
		$this->assertStringNotContainsString( 'przypisana do 99', $html );
		$this->assertStringContainsString( 'name="pusers_confirm_accounts" value="1"', $html );
	}

	public function test_deleting_a_profession_leaves_account_meta_and_other_professions_alone(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, 'pusers_profession', 'lekarz' );
		$this->asAdminPosting( 'pusers_delete_profession', array( 'delete' => 'lekarz', 'confirm_accounts' => '1' ) );
		ProfessionsScreen::handle_delete();
		$this->assertSame( array( 'student' ), ProfessionRepository::get()->keys() );
		$this->assertSame( 'lekarz', get_user_meta( $user, 'pusers_profession', true ) );
	}

	public function test_saving_is_refused_for_a_user_without_the_capability_and_changes_nothing(): void {
		$this->asRolePosting( 'subscriber', 'pusers_save_professions', array(
			'profession' => array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
		) );
		try {
			ProfessionsScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertNull( ProfessionRepository::get()->get( 'lekarz' ) );
		}
	}

	public function test_a_get_request_is_refused_and_changes_nothing(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		$this->asAdminPosting( 'pusers_delete_profession', array( 'delete' => 'student' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		try {
			ProfessionsScreen::handle_delete();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( ProfessionRepository::get()->get( 'student' ) );
		}
	}

	public function test_the_screen_registers_its_own_submenu_page_under_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_all_actions( 'admin_menu' );
		global $submenu;
		$submenu = array();
		ProfessionsScreen::register();
		do_action( 'admin_menu' );
		$slugs = wp_list_pluck( $submenu['users.php'] ?? array(), 2 );
		$this->assertContains( Menu::SLUG_PROFESSIONS, $slugs );
		$this->assertNotContains( Menu::SLUG_SETTINGS, $slugs );
	}

	public function test_rendered_screen_escapes_stored_values_and_keeps_a_field_outside_the_list(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => '<script>alert(1)</script>', 'credential_type' => 'physician_pwz', 'credential_field' => 'pole_spoza_listy' ),
		) ) );
		$_GET = array( 'edit' => 'lekarz' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		ProfessionsScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '<option value="pole_spoza_listy" selected=\'selected\'>', $html );
		$this->assertStringContainsString( 'name="pusers_original_key" value="lekarz"', $html );
		$this->assertStringContainsString( 'readonly="readonly"', $html );
	}

	public function test_editing_a_key_that_does_not_exist_creates_nothing(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
		) ) );
		$this->asAdminPosting( 'pusers_save_professions', array(
			'original_key' => 'nie-ma-takiej',
			'profession'   => array( 'key' => 'nowa', 'label' => 'Nowa', 'credential_type' => 'none' ),
		) );
		ProfessionsScreen::handle_save();
		$this->assertSame( array( 'lekarz' ), ProfessionRepository::get()->keys() );
		$this->assertStringContainsString( 'pusers_notice=profession_invalid_key', $this->redirected );
	}

	public function test_deleting_is_refused_for_a_user_without_the_capability_and_the_profession_stays(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		$this->asRolePosting( 'subscriber', 'pusers_delete_profession', array( 'delete' => 'student' ) );
		try {
			ProfessionsScreen::handle_delete();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( ProfessionRepository::get()->get( 'student' ) );
		}
	}

	public function test_deleting_is_refused_with_a_bad_nonce_and_the_profession_stays(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		$this->asAdminPosting( 'pusers_delete_profession', array( 'delete' => 'student' ) );
		$_POST['pusers_nonce'] = 'zly-nonce';
		$_REQUEST              = $_POST;
		try {
			ProfessionsScreen::handle_delete();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( ProfessionRepository::get()->get( 'student' ) );
		}
	}

	public function test_a_nonce_issued_for_saving_does_not_authorise_deleting_a_profession(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		$this->asAdminPosting( 'pusers_save_professions', array( 'delete' => 'student' ) );
		try {
			ProfessionsScreen::handle_delete();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( ProfessionRepository::get()->get( 'student' ) );
		}
	}

	public function test_each_rendered_form_carries_a_nonce_for_its_own_action(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array(
			array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
		) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		ProfessionsScreen::render();
		$html = (string) ob_get_clean();

		foreach ( array(
			'pusers_delete_profession' => 'pusers_save_professions',
			'pusers_save_professions'  => 'pusers_delete_profession',
		) as $own => $other ) {
			$pattern = '/name="action" value="' . $own . '" \/><input type="hidden" id="pusers_nonce" name="pusers_nonce" value="([^"]+)"/';
			$this->assertSame( 1, preg_match( $pattern, $html, $m ), 'Brak formularza akcji ' . $own );
			$this->assertNotFalse( wp_verify_nonce( $m[1], $own ), 'Nonce formularza ' . $own . ' nie jest dla jego akcji.' );
			$this->assertFalse( wp_verify_nonce( $m[1], $other ), 'Nonce formularza ' . $own . ' autoryzuje też ' . $other . '.' );
		}
	}
}
