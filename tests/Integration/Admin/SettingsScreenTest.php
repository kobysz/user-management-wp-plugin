<?php
/**
 * Testy ekranu ustawień ogólnych i jego zapisu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\Menu;
use PortalUsers\Admin\SettingsScreen;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;
use WPDieException;

final class SettingsScreenTest extends WP_UnitTestCase {

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

	public function set_up(): void {
		parent::set_up();
		$this->redirected     = '';
		$this->cancelRedirect = function ( $location ) {
			$this->redirected = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->cancelRedirect );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		remove_all_filters( 'pusers_acf_groups' );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_GET['pusers_notice'] );
		parent::tear_down();
	}

	public function test_settings_submenu_is_registered_under_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'pusers_register_features' );
		do_action( 'admin_menu' );
		global $submenu;
		$slugs = wp_list_pluck( $submenu['users.php'] ?? array(), 2 );
		$this->assertContains( Menu::SLUG_SETTINGS, $slugs );
	}

	public function test_menu_url_points_at_the_page_under_users(): void {
		$url = Menu::url( Menu::SLUG_SETTINGS, array( 'pusers_notice' => 'saved' ) );
		$this->assertStringContainsString( 'users.php', $url );
		$this->assertStringContainsString( 'page=pusers-settings', $url );
		$this->assertStringContainsString( 'pusers_notice=saved', $url );
	}

	public function test_saving_settings_persists_normalised_values(): void {
		$this->asAdminPosting( 'pusers_save_settings', array(
			'approval_enabled'    => '1',
			'notify_emails'       => "a@example.test\nnie-adres\nb@example.test",
			'rate_limit_per_hour' => '0',
		) );
		SettingsScreen::handle_save();
		$saved = SettingsRepository::get();
		$this->assertTrue( $saved->approvalEnabled );
		$this->assertSame( array( 'a@example.test', 'b@example.test' ), $saved->notifyEmails );
		$this->assertSame( 1, $saved->rateLimitPerHour );
	}

	public function test_unchecked_approval_checkbox_turns_approval_off(): void {
		// Domyślnie akceptacja jest włączona, a brak klucza daje wartość domyślną. Bez
		// jawnego "0" odznaczenie pola nigdy nie wyłączyłoby akceptacji.
		$this->asAdminPosting( 'pusers_save_settings', array( 'rate_limit_per_hour' => '5' ) );
		SettingsScreen::handle_save();
		$this->assertFalse( SettingsRepository::get()->approvalEnabled );
	}

	public function test_explicit_zero_for_approval_turns_approval_off(): void {
		$this->asAdminPosting( 'pusers_save_settings', array( 'approval_enabled' => '0' ) );
		SettingsScreen::handle_save();
		$this->assertFalse( SettingsRepository::get()->approvalEnabled );
	}

	public function test_unchecked_delete_gate_turns_the_gate_off(): void {
		SettingsRepository::save( Settings::fromArray( array( 'delete_data_on_uninstall' => true ) ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'approval_enabled' => '1' ) );
		SettingsScreen::handle_save();
		$this->assertFalse( SettingsRepository::get()->deleteDataOnUninstall );
	}

	public function test_rejection_reasons_are_split_into_a_list(): void {
		$this->asAdminPosting( 'pusers_save_settings', array(
			'rejection_reasons' => "Brak numeru PWZ\n\n  Dane nie zgadzają się  \n",
		) );
		SettingsScreen::handle_save();
		$this->assertSame( array( 'Brak numeru PWZ', 'Dane nie zgadzają się' ), SettingsRepository::get()->rejectionReasons );
	}

	public function test_page_ids_and_numbers_are_saved(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->asAdminPosting( 'pusers_save_settings', array(
			'login_page_id'            => (string) $page,
			'confirmation_token_hours' => '24',
			'trusted_proxy_header'     => 'x-forwarded-for',
			'acf_group_key'            => 'group_abc',
		) );
		SettingsScreen::handle_save();
		$saved = SettingsRepository::get();
		$this->assertSame( $page, $saved->loginPageId );
		$this->assertSame( 24, $saved->confirmationTokenHours );
		$this->assertSame( 'HTTP_X_FORWARDED_FOR', $saved->trustedProxyHeader );
		$this->assertSame( 'group_abc', $saved->acfGroupKey );
	}

	public function test_group_key_outside_the_group_list_is_rejected_and_nothing_is_saved(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc', 'rate_limit_per_hour' => 7 ) ) );
		$this->asAdminPosting( 'pusers_save_settings', array(
			'acf_group_key'       => 'group_obcy',
			'rate_limit_per_hour' => '99',
		) );
		SettingsScreen::handle_save();
		$saved = SettingsRepository::get();
		$this->assertSame( 'group_abc', $saved->acfGroupKey );
		$this->assertSame( 7, $saved->rateLimitPerHour );
		$this->assertStringContainsString( 'pusers_notice=acf_group_invalid', $this->redirected );
	}

	public function test_invalid_group_notice_is_rendered_as_an_error(): void {
		$_GET['pusers_notice'] = 'acf_group_invalid';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_known_and_empty_group_keys_are_accepted(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'acf_group_key' => 'group_abc' ) );
		SettingsScreen::handle_save();
		$this->assertSame( 'group_abc', SettingsRepository::get()->acfGroupKey );
		$this->asAdminPosting( 'pusers_save_settings', array( 'acf_group_key' => '' ) );
		SettingsScreen::handle_save();
		$this->assertSame( '', SettingsRepository::get()->acfGroupKey );
	}

	public function test_saving_redirects_back_with_a_saved_notice(): void {
		$this->asAdminPosting( 'pusers_save_settings', array( 'approval_enabled' => '1' ) );
		SettingsScreen::handle_save();
		$this->assertStringContainsString( 'page=pusers-settings', $this->redirected );
		$this->assertStringContainsString( 'pusers_notice=saved', $this->redirected );
	}

	public function test_saving_is_refused_for_a_user_without_the_capability_and_changes_nothing(): void {
		$this->asAdminPosting( 'pusers_save_settings', array( 'approval_enabled' => '0' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		try {
			SettingsScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( SettingsRepository::get()->approvalEnabled );
		}
	}

	public function test_page_that_does_not_exist_is_reported_as_an_issue(): void {
		SettingsRepository::save( Settings::fromArray( array( 'login_page_id' => 999999 ) ) );
		$this->assertContains( 'page_missing', SettingsScreen::page_issues() );
	}

	public function test_page_in_the_trash_is_reported_as_an_issue(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'trash' ) );
		SettingsRepository::save( Settings::fromArray( array( 'register_page_id' => $page ) ) );
		$this->assertContains( 'page_not_published', SettingsScreen::page_issues() );
	}

	public function test_published_page_reports_no_issue(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		SettingsRepository::save( Settings::fromArray( array( 'reset_page_id' => $page ) ) );
		$this->assertSame( array(), SettingsScreen::page_issues() );
	}

	public function test_a_post_that_is_not_a_page_is_reported_as_missing(): void {
		$post = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		SettingsRepository::save( Settings::fromArray( array( 'login_page_id' => $post ) ) );
		$this->assertSame( array( 'login_page_id' => 'page_missing' ), SettingsScreen::page_issues() );
	}

	public function test_rendered_screen_escapes_stored_values(): void {
		SettingsRepository::save( Settings::fromArray( array( 'notify_emails' => array( 'a@example.test' ) ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'pusers_nonce', $html );
	}

	public function test_rendered_screen_neutralises_markup_in_stored_text(): void {
		SettingsRepository::save( Settings::fromArray( array(
			'rejection_reasons'    => array( '</textarea><script>alert(1)</script>' ),
			'trusted_proxy_header' => 'x" onfocus="alert(1)',
			'acf_group_key'        => 'g"><script>alert(2)</script>',
		) ) );
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'g"><script>alert(2)</script>' => '<script>alert(3)</script>' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onfocus="alert', $html );
	}

	public function test_unchecked_checkboxes_send_an_explicit_zero(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();
		foreach ( array( 'pusers_approval_enabled', 'pusers_delete_data_on_uninstall' ) as $name ) {
			$hidden   = strpos( $html, '<input type="hidden" name="' . $name . '" value="0"' );
			$checkbox = strpos( $html, 'type="checkbox" name="' . $name . '"' );
			$this->assertNotFalse( $hidden, $name . ': brak ukrytego pola z zerem.' );
			$this->assertNotFalse( $checkbox, $name . ': brak checkboxa.' );
			$this->assertLessThan( $checkbox, $hidden, $name . ': ukryte pole musi poprzedzać checkbox.' );
		}
	}

	public function test_rendered_screen_warns_what_the_uninstall_gate_deletes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'nieodwracalnie', $html );
	}

	/**
	 * Przygotowuje żądanie zapisu od administratora: pola z prefiksem formularza i nonce.
	 *
	 * @param string                $action Akcja nonce'a.
	 * @param array<string, string> $fields Pola bez prefiksu `pusers_`.
	 */
	private function asAdminPosting( string $action, array $fields ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = array();
		foreach ( $fields as $key => $value ) {
			$post[ 'pusers_' . $key ] = $value;
		}
		$post['pusers_nonce'] = wp_create_nonce( $action );
		// WordPress dodaje ukośniki do danych żądania; ekran musi je zdjąć.
		$_POST    = wp_slash( $post );
		$_REQUEST = $_POST;
	}
}
