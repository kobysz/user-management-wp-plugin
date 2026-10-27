<?php
/**
 * Testy spięcia: że wtyczka podpina ekrany pod hook funkcji i że każdy formularz ma
 * handler pod swoją akcją admin-post. Suity ekranów wołają handlery bezpośrednio,
 * więc bez tych testów brakująca linia add_action() zostawiłaby wszystko zielone.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration;

use PortalUsers\Admin\MatrixScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Admin\ProfessionsScreen;
use PortalUsers\Admin\SettingsScreen;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;
use WP_UnitTestCase;

final class WiringTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_action( 'plugins_loaded', array( Plugin::class, 'load_features' ) );
		remove_all_actions( 'pusers_register_features' );
		remove_all_actions( 'admin_menu' );
		foreach ( $this->adminPostActions() as $action ) {
			remove_all_actions( 'admin_post_' . $action );
			remove_all_actions( 'admin_post_nopriv_' . $action );
		}
	}

	/**
	 * @return list<string> Wszystkie akcje admin-post wtyczki.
	 */
	private function adminPostActions(): array {
		return array(
			SettingsScreen::ACTION,
			ProfessionsScreen::ACTION,
			ProfessionsScreen::ACTION_DELETE,
			MatrixScreen::ACTION_SAVE,
			MatrixScreen::ACTION_DELETE_FIELD,
			RegistrationHandler::ACTION,
		);
	}

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public static function features(): array {
		return array(
			'menu'        => array( Menu::class ),
			'settings'    => array( SettingsScreen::class ),
			'professions' => array( ProfessionsScreen::class ),
			'matrix'      => array( MatrixScreen::class ),
			'registration' => array( RegistrationForm::class ),
			'registration handler' => array( RegistrationHandler::class ),
		);
	}

	/**
	 * @dataProvider features
	 *
	 * @param class-string $screen Klasa ekranu.
	 */
	public function test_boot_hooks_each_screen_into_the_features_hook( string $screen ): void {
		Plugin::boot( Plugin::plugin_file() );
		$this->assertNotFalse(
			has_action( 'pusers_register_features', array( $screen, 'register' ) ),
			$screen . '::register() nie jest podpięte pod pusers_register_features.'
		);
	}

	public function test_settings_register_hooks_its_save_handler(): void {
		SettingsScreen::register();
		$this->assertNotFalse( has_action( 'admin_post_' . SettingsScreen::ACTION, array( SettingsScreen::class, 'handle_save' ) ) );
	}

	public function test_professions_register_hooks_page_and_both_handlers(): void {
		ProfessionsScreen::register();
		$this->assertNotFalse( has_action( 'admin_menu', array( ProfessionsScreen::class, 'add_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . ProfessionsScreen::ACTION, array( ProfessionsScreen::class, 'handle_save' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . ProfessionsScreen::ACTION_DELETE, array( ProfessionsScreen::class, 'handle_delete' ) ) );
	}

	public function test_matrix_register_hooks_page_and_both_handlers(): void {
		MatrixScreen::register();
		$this->assertNotFalse( has_action( 'admin_menu', array( MatrixScreen::class, 'add_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . MatrixScreen::ACTION_SAVE, array( MatrixScreen::class, 'handle_save' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . MatrixScreen::ACTION_DELETE_FIELD, array( MatrixScreen::class, 'handle_delete_field' ) ) );
	}

	public function test_every_rendered_form_action_has_a_handler_behind_it(): void {
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
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'             => 'lekarz',
						'label'           => 'Lekarz',
						'credential_type' => 'physician_pwz',
					),
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		SettingsScreen::register();
		ProfessionsScreen::register();
		MatrixScreen::register();

		$seen = array();
		foreach ( array( SettingsScreen::class, ProfessionsScreen::class, MatrixScreen::class ) as $screen ) {
			ob_start();
			$screen::render();
			$html = (string) ob_get_clean();
			preg_match_all( '/<input type="hidden" name="action" value="([^"]+)"/', $html, $m );
			$this->assertNotEmpty( $m[1], $screen . ' nie wyrenderował żadnego formularza z akcją.' );
			foreach ( $m[1] as $action ) {
				$seen[] = $action;
				$this->assertNotFalse( has_action( 'admin_post_' . $action ), 'Brak handlera dla akcji formularza: ' . $action );
			}
		}
		// Formularze zapisu i usuwania profesji oraz zapisu macierzy i ustawień faktycznie się pojawiły.
		foreach ( array( SettingsScreen::ACTION, ProfessionsScreen::ACTION, ProfessionsScreen::ACTION_DELETE, MatrixScreen::ACTION_SAVE ) as $expected ) {
			$this->assertContains( $expected, $seen );
		}
	}
}
