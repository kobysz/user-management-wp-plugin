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
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use WP_UnitTestCase;
use WPDieException;

final class SettingsScreenTest extends WP_UnitTestCase {

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
		remove_all_filters( 'pusers_acf_groups' );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_GET['pusers_notice'] );
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

	public function test_settings_submenu_is_registered_under_users(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Izolacja Menu::register(): ekrany profesji i macierzy podpinają własne podstrony pod
		// admin_menu, więc zdejmujemy wszystkie callbacki i sprawdzamy wyłącznie to, co dodaje Menu.
		remove_all_actions( 'admin_menu' );
		global $submenu;
		$submenu = array();
		Menu::register();
		do_action( 'admin_menu' );
		$slugs = wp_list_pluck( $submenu['users.php'] ?? array(), 2 );
		$this->assertContains( Menu::SLUG_SETTINGS, $slugs );
		// Ekrany profesji i macierzy rejestrują swoje podstrony same (Taski 6 i 7), nie Menu.
		$this->assertNotContains( Menu::SLUG_PROFESSIONS, $slugs );
		$this->assertNotContains( Menu::SLUG_MATRIX, $slugs );
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
		// Prawdziwy formularz przy odznaczonym checkboxie wysyła "0" z ukrytego pola, razem z resztą pól.
		$this->asAdminPosting( 'pusers_save_settings', array(
			'approval_enabled'    => '0',
			'rate_limit_per_hour' => '5',
		) );
		SettingsScreen::handle_save();
		$this->assertFalse( SettingsRepository::get()->approvalEnabled );
		$this->assertSame( 5, SettingsRepository::get()->rateLimitPerHour );
	}

	public function test_explicit_zero_for_approval_turns_approval_off(): void {
		$this->asAdminPosting( 'pusers_save_settings', array( 'approval_enabled' => '0' ) );
		SettingsScreen::handle_save();
		$this->assertFalse( SettingsRepository::get()->approvalEnabled );
	}

	public function test_unchecked_delete_gate_turns_the_gate_off(): void {
		SettingsRepository::save( Settings::fromArray( array( 'delete_data_on_uninstall' => true ) ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'delete_data_on_uninstall' => '0' ) );
		SettingsScreen::handle_save();
		$this->assertFalse( SettingsRepository::get()->deleteDataOnUninstall );
	}

	public function test_fields_missing_from_the_request_keep_their_saved_values(): void {
		// Gdy wszystkie strony są w koszu, wp_dropdown_pages nie renderuje selecta, więc POST
		// nie niesie pól stron. Zapis nie może zerować wskazań ani innych pól, których nie przysłano.
		SettingsRepository::save( Settings::fromArray( array(
			'login_page_id'       => 11,
			'register_page_id'    => 12,
			'reset_page_id'       => 13,
			'approval_enabled'    => false,
			'rate_limit_per_hour' => 42,
			'acf_group_key'       => 'group_abc',
		) ) );
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'time_trap_seconds' => '9' ) );
		SettingsScreen::handle_save();
		$saved = SettingsRepository::get();
		$this->assertSame( array( 11, 12, 13 ), array( $saved->loginPageId, $saved->registerPageId, $saved->resetPageId ) );
		$this->assertFalse( $saved->approvalEnabled );
		$this->assertSame( 42, $saved->rateLimitPerHour );
		$this->assertSame( 'group_abc', $saved->acfGroupKey );
		$this->assertSame( 9, $saved->timeTrapSeconds );
	}

	public function test_the_per_email_threshold_is_saved_without_touching_the_per_ip_one(): void {
		SettingsRepository::save( Settings::fromArray( array( 'rate_limit_per_hour' => 42 ) ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'registration_limit_per_email_per_hour' => '5' ) );
		SettingsScreen::handle_save();

		$saved = SettingsRepository::get();
		$this->assertSame( 5, $saved->registrationLimitPerEmailPerHour );
		$this->assertSame( 42, $saved->rateLimitPerHour );
	}

	public function test_a_save_that_omits_the_per_email_threshold_keeps_it(): void {
		SettingsRepository::save( Settings::fromArray( array( 'registration_limit_per_email_per_hour' => 7 ) ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'time_trap_seconds' => '9' ) );
		SettingsScreen::handle_save();

		$this->assertSame( 7, SettingsRepository::get()->registrationLimitPerEmailPerHour );
	}

	public function test_the_screen_shows_both_registration_thresholds_with_their_values(): void {
		SettingsRepository::save( Settings::fromArray( array( 'rate_limit_per_hour' => 42, 'registration_limit_per_email_per_hour' => 7 ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="pusers_rate_limit_per_hour" value="42"/', $html );
		$this->assertMatchesRegularExpression( '/name="pusers_registration_limit_per_email_per_hour" value="7"/', $html );
	}

	public function test_the_five_login_and_recovery_settings_are_saved(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->asAdminPosting( 'pusers_save_settings', array(
			'login_limit_per_hour'             => '301',
			'login_limit_per_account_per_hour' => '11',
			'reset_limit_per_hour'             => '61',
			'reset_limit_per_email_per_hour'   => '6',
			'after_login_page_id'              => (string) $page,
		) );
		SettingsScreen::handle_save();

		$saved = SettingsRepository::get();
		$this->assertSame( array( 301, 11, 61, 6, $page ), array( $saved->loginLimitPerHour, $saved->loginLimitPerAccountPerHour, $saved->resetLimitPerHour, $saved->resetLimitPerEmailPerHour, $saved->afterLoginPageId ) );
	}

	public function test_an_empty_form_does_not_zero_the_new_thresholds(): void {
		SettingsRepository::save( Settings::fromArray( array(
			'login_limit_per_hour'             => 301,
			'login_limit_per_account_per_hour' => 11,
			'reset_limit_per_hour'             => 61,
			'reset_limit_per_email_per_hour'   => 6,
			'after_login_page_id'              => 14,
		) ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'time_trap_seconds' => '9' ) );
		SettingsScreen::handle_save();

		$saved = SettingsRepository::get();
		$this->assertSame( array( 301, 11, 61, 6, 14 ), array( $saved->loginLimitPerHour, $saved->loginLimitPerAccountPerHour, $saved->resetLimitPerHour, $saved->resetLimitPerEmailPerHour, $saved->afterLoginPageId ) );
	}

	public function test_a_zero_threshold_posted_from_the_form_keeps_the_default_not_a_closed_gate(): void {
		$this->asAdminPosting( 'pusers_save_settings', array( 'login_limit_per_hour' => '0', 'reset_limit_per_email_per_hour' => '' ) );
		SettingsScreen::handle_save();

		$saved = SettingsRepository::get();
		$this->assertSame( 200, $saved->loginLimitPerHour );
		$this->assertSame( 3, $saved->resetLimitPerEmailPerHour );
	}

	public function test_the_screen_shows_the_five_new_fields_and_the_lockout_warning(): void {
		SettingsRepository::save( Settings::fromArray( array(
			'login_limit_per_hour'             => 301,
			'login_limit_per_account_per_hour' => 11,
			'reset_limit_per_hour'             => 61,
			'reset_limit_per_email_per_hour'   => 6,
		) ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Panel' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="pusers_login_limit_per_hour" value="301"/', $html );
		$this->assertMatchesRegularExpression( '/name="pusers_login_limit_per_account_per_hour" value="11"/', $html );
		$this->assertMatchesRegularExpression( '/name="pusers_reset_limit_per_hour" value="61"/', $html );
		$this->assertMatchesRegularExpression( '/name="pusers_reset_limit_per_email_per_hour" value="6"/', $html );
		$this->assertStringContainsString( "name='pusers_after_login_page_id'", $html );
		foreach ( array( 'Limit nieudanych logowań z jednego adresu na godzinę', 'Limit nieudanych logowań na jedno konto na godzinę', 'Limit żądań odzyskania hasła z jednego adresu na godzinę', 'Limit żądań odzyskania hasła na jeden adres e-mail na godzinę', 'Strona po zalogowaniu' ) as $label ) {
			$this->assertStringContainsString( $label, $html );
		}
		$this->assertStringContainsString( 'Blokada jest czasowa (60 minut) i redakcja może ją zdjąć.', $html );
	}

	public function test_the_screen_tells_the_administrator_which_proxy_header_not_to_name(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		SettingsScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'NADPISUJE', $html );
		$this->assertStringContainsString( 'prawy (ostatni) wpis', $html );
		$this->assertStringContainsString( 'wartość należy podnieść', $html );
	}

	public function test_a_get_request_is_refused_and_changes_nothing(): void {
		SettingsRepository::save( Settings::fromArray( array( 'rate_limit_per_hour' => 42 ) ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'rate_limit_per_hour' => '5' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		try {
			SettingsScreen::handle_save();
			$this->fail( 'Oczekiwano wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 42, SettingsRepository::get()->rateLimitPerHour );
		}
	}

	public function test_line_breaks_from_every_platform_split_lists(): void {
		// Przeglądarki wysyłają z <textarea> CRLF; samotny CR zdarza się w starszych źródłach.
		$this->asAdminPosting( 'pusers_save_settings', array( 'rejection_reasons' => "a\r\nb\rc\nd" ) );
		SettingsScreen::handle_save();
		$this->assertSame( array( 'a', 'b', 'c', 'd' ), SettingsRepository::get()->rejectionReasons );
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
		$this->asRolePosting( 'subscriber', 'pusers_save_settings', array( 'approval_enabled' => '0' ) );
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
		// Każda rzecz kasowana przez Uninstaller::run() musi być w ostrzeżeniu po imieniu.
		$deleted = array(
			'ustawienia ogólne',
			'listę profesji',
			'macierz pól',
			'log zdarzeń',
			'archiwum wersji regulaminu',
		);
		foreach ( $deleted as $item ) {
			$this->assertStringContainsString( $item, $html, 'Ostrzeżenie nie wymienia: ' . $item );
		}
	}

	/**
	 * Źródło pól (grupa ACF z jednym polem `pwz`) i profesje o podanych kluczach, typ numeru "brak".
	 *
	 * @param string ...$keys Klucze profesji.
	 */
	private function withConfiguredSourceAndProfessions( string ...$keys ): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'PWZ',
				),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
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

	private function renderScreenAsAdmin(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SettingsScreen::render();
		return (string) ob_get_clean();
	}

	public function test_screen_shows_the_error_count_and_a_link_to_the_matrix_when_a_profession_has_no_row(): void {
		$this->withConfiguredSourceAndProfessions( 'lekarz', 'pielegniarka' );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$html = $this->renderScreenAsAdmin();
		$this->assertStringContainsString( 'notice-error pusers-consistency-notice', $html );
		$this->assertStringContainsString( 'Liczba wykrytych błędów: 1.', $html );
		$this->assertStringContainsString( 'page=' . Menu::SLUG_MATRIX, $html );
	}

	public function test_screen_counts_every_error(): void {
		$this->withConfiguredSourceAndProfessions( 'lekarz', 'pielegniarka' );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array() ) );
		$this->assertStringContainsString( 'Liczba wykrytych błędów: 2.', $this->renderScreenAsAdmin() );
	}

	public function test_screen_shows_nothing_when_the_configuration_is_consistent(): void {
		$this->withConfiguredSourceAndProfessions( 'lekarz' );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
		$html = $this->renderScreenAsAdmin();
		$this->assertStringNotContainsString( 'pusers-consistency-notice', $html );
		$this->assertStringNotContainsString( 'page=' . Menu::SLUG_MATRIX, $html );
	}

	public function test_screen_does_not_count_warnings(): void {
		// Pole ze źródła bez wpisu w wierszu to tylko ostrzeżenie.
		$this->withConfiguredSourceAndProfessions( 'lekarz' );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'inne' => 'visible' ) ) ) );
		$this->assertStringNotContainsString( 'pusers-consistency-notice', $this->renderScreenAsAdmin() );
	}

	public function test_the_terms_text_is_saved_with_its_line_breaks_and_the_consent_field_with_it(): void {
		$this->asAdminPosting( 'pusers_save_settings', array( 'terms_text' => "Punkt 1\r\n\r\nPunkt 2", 'terms_consent_field' => 'zgoda' ) );
		SettingsScreen::handle_save();

		$saved = SettingsRepository::get();
		$this->assertSame( "Punkt 1\n\nPunkt 2", $saved->termsText );
		$this->assertSame( 'zgoda', $saved->termsConsentField );
	}

	public function test_a_save_without_the_terms_fields_keeps_the_saved_terms(): void {
		SettingsRepository::save( Settings::fromArray( array( 'terms_text' => 'Regulamin v1', 'terms_consent_field' => 'zgoda' ) ) );
		$this->asAdminPosting( 'pusers_save_settings', array( 'time_trap_seconds' => '9' ) );
		SettingsScreen::handle_save();

		$saved = SettingsRepository::get();
		$this->assertSame( 'Regulamin v1', $saved->termsText );
		$this->assertSame( 'zgoda', $saved->termsConsentField );
		$this->assertSame( 9, $saved->timeTrapSeconds );
	}

	public function test_the_screen_shows_the_saved_terms_text(): void {
		SettingsRepository::save( Settings::fromArray( array( 'terms_text' => 'Regulamin <b>v1</b>' ) ) );
		$html = $this->renderScreenAsAdmin();

		$this->assertStringContainsString( 'name="pusers_terms_text"', $html );
		$this->assertStringContainsString( 'Regulamin &lt;b&gt;v1&lt;/b&gt;', $html );
	}
}
