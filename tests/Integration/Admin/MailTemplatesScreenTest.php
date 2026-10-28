<?php
/**
 * Testy ekranu szablonów maili i jego zapisu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\MailTemplatesScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Mail\DefaultTemplates;
use PortalUsers\Persistence\MailTemplateRepository;
use WP_UnitTestCase;
use WPDieException;

final class MailTemplatesScreenTest extends WP_UnitTestCase {

	use AdminPostingTrait;

	private string $originalMethod = 'GET';

	/**
	 * Anulujący filtr przekierowania (żeby test przeżył przekierowanie po zapisie).
	 *
	 * @var callable
	 */
	private $cancelRedirect;

	public function set_up(): void {
		parent::set_up();
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->cancelRedirect = static fn() => false;
		add_filter( 'wp_redirect', $this->cancelRedirect );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->cancelRedirect );
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		parent::tear_down();
	}

	private function render_screen(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MailTemplatesScreen::render();
		return (string) ob_get_clean();
	}

	public function test_saving_a_subject_and_body_stores_the_override(): void {
		$this->asAdminPosting(
			MailTemplatesScreen::ACTION,
			array(
				'tpl_approved_subject' => 'Witaj {nazwa_konta}',
				'tpl_approved_body'    => 'Konto zatwierdzone.',
			)
		);

		MailTemplatesScreen::handle_save();

		$stored = MailTemplateRepository::override( MailKind::Approved );
		$this->assertSame( 'Witaj {nazwa_konta}', $stored->subject );
		$this->assertSame( 'Konto zatwierdzone.', $stored->body );
	}

	public function test_clearing_both_fields_restores_the_default(): void {
		MailTemplateRepository::save( MailKind::Approved, new \PortalUsers\Domain\Mail\MailTemplate( 'Własny', 'Własna treść' ) );
		$this->asAdminPosting(
			MailTemplatesScreen::ACTION,
			array(
				'tpl_approved_subject' => '',
				'tpl_approved_body'    => '',
			)
		);

		MailTemplatesScreen::handle_save();

		$this->assertTrue( MailTemplateRepository::override( MailKind::Approved )->isEmpty() );
		$this->assertSame( DefaultTemplates::for( MailKind::Approved )->toArray(), MailTemplateRepository::get( MailKind::Approved )->toArray() );
	}

	public function test_a_save_without_a_nonce_is_refused(): void {
		$this->asAdminPosting( MailTemplatesScreen::ACTION, array( 'tpl_approved_body' => 'x' ) );
		unset( $_POST['pusers_nonce'] );

		$this->expectException( WPDieException::class );
		MailTemplatesScreen::handle_save();
	}

	public function test_a_save_without_the_capability_is_refused(): void {
		$this->asRolePosting( 'subscriber', MailTemplatesScreen::ACTION, array( 'tpl_approved_body' => 'x' ) );

		try {
			MailTemplatesScreen::handle_save();
			$this->fail( 'Zapis bez uprawnień powinien zostać odrzucony.' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( MailTemplateRepository::override( MailKind::Approved )->isEmpty() );
		}
	}

	public function test_a_typo_in_a_variable_name_is_saved_as_typed(): void {
		// Panel nie „naprawia" {numer_uprawnen} na {numer_uprawnien}: literówka ma zostać widoczna.
		$this->asAdminPosting( MailTemplatesScreen::ACTION, array( 'tpl_editorial_body' => 'Numer: {numer_uprawnen}' ) );

		MailTemplatesScreen::handle_save();

		$this->assertSame( 'Numer: {numer_uprawnen}', MailTemplateRepository::override( MailKind::Editorial )->body );
	}

	public function test_tags_are_stripped_from_the_body(): void {
		$this->asAdminPosting( MailTemplatesScreen::ACTION, array( 'tpl_approved_body' => 'Witaj <b>lekarzu</b>' ) );

		MailTemplatesScreen::handle_save();

		$body = MailTemplateRepository::override( MailKind::Approved )->body;
		$this->assertStringNotContainsString( '<b>', $body );
		$this->assertStringContainsString( 'lekarzu', $body );
	}

	public function test_the_screen_lists_every_kind_and_its_variables(): void {
		$html = $this->render_screen();
		foreach ( MailKind::cases() as $kind ) {
			$this->assertStringContainsString( $kind->value, $html );
			foreach ( $kind->variables() as $variable ) {
				$this->assertStringContainsString( '{' . $variable . '}', $html );
			}
		}
		$this->assertStringContainsString( '{pole_', $html );
	}

	public function test_multiline_bodies_survive_the_round_trip(): void {
		// Treść maila to linie. Zapis, który je zlepia, psuje każdy mail wtyczki naraz.
		$this->asAdminPosting( MailTemplatesScreen::ACTION, array( 'tpl_approved_body' => "pierwsza\r\ndruga" ) );

		MailTemplatesScreen::handle_save();

		$this->assertSame( "pierwsza\ndruga", MailTemplateRepository::override( MailKind::Approved )->body );
	}

	public function test_the_defaults_are_shown_as_placeholders_and_stored_values_as_values(): void {
		MailTemplateRepository::save( MailKind::Approved, new \PortalUsers\Domain\Mail\MailTemplate( 'Mój "temat"', '' ) );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'value="Mój &quot;temat&quot;"', $html );
		$this->assertStringContainsString( 'placeholder="' . esc_attr( DefaultTemplates::for( MailKind::Approved )->subject ) . '"', $html );
	}

	public function test_the_screen_warns_that_the_reset_link_works_only_in_the_body(): void {
		$html = $this->render_screen();

		$this->assertStringContainsString( 'tylko w treści', $html );
	}

	public function test_the_page_is_registered_under_its_slug(): void {
		$this->assertSame( 'pusers-mail-templates', Menu::SLUG_MAIL_TEMPLATES );
		$this->assertSame( 'pusers_save_mail_templates', MailTemplatesScreen::ACTION );
	}
}
