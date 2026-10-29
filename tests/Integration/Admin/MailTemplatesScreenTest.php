<?php
/**
 * Testy ekranu szablonów maili i jego zapisu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\MailTemplatesScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;
use PortalUsers\Mail\DefaultTemplates;
use PortalUsers\Mail\MailQueue;
use PortalUsers\Mail\PlaceholderFactory;
use PortalUsers\Persistence\MailQueueRepository;
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

	public function test_a_request_without_a_kinds_fields_leaves_that_kind_untouched(): void {
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Temat A', 'Treść A' ) );
		MailTemplateRepository::save( MailKind::Rejected, new MailTemplate( 'Temat R', 'Treść R' ) );
		$this->asAdminPosting( MailTemplatesScreen::ACTION, array( 'tpl_approved_body' => 'Nowa treść' ) );

		MailTemplatesScreen::handle_save();

		$approved = MailTemplateRepository::override( MailKind::Approved );
		$this->assertSame( 'Temat A', $approved->subject );
		$this->assertSame( 'Nowa treść', $approved->body );
		$this->assertSame( array( 'Temat R', 'Treść R' ), array_values( MailTemplateRepository::override( MailKind::Rejected )->toArray() ) );
	}

	public function test_a_stored_body_cannot_break_out_of_the_textarea(): void {
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( '', '</textarea><script>alert(1)</script>' ) );

		$html = $this->render_screen();

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;&lt;script&gt;', $html );
	}

	public function test_the_screen_and_its_save_handler_are_registered(): void {
		$post_hook  = 'admin_post_' . MailTemplatesScreen::ACTION;
		$saved_menu = isset( $GLOBALS['wp_filter']['admin_menu'] ) ? clone $GLOBALS['wp_filter']['admin_menu'] : null;
		$saved_post = isset( $GLOBALS['wp_filter'][ $post_hook ] ) ? clone $GLOBALS['wp_filter'][ $post_hook ] : null;
		$saved_sub  = $GLOBALS['submenu'] ?? null;

		try {
			// Rozruch wtyczki mógł już podpiąć oba haki: bez czyszczenia test przeszedłby nawet
			// przy pustym register().
			remove_all_actions( 'admin_menu' );
			remove_all_actions( $post_hook );
			$GLOBALS['submenu'] = array();
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

			MailTemplatesScreen::register();
			do_action( 'admin_menu' );

			$slugs = array_column( $GLOBALS['submenu'][ Menu::PARENT ] ?? array(), 2 );
			$this->assertContains( Menu::SLUG_MAIL_TEMPLATES, $slugs );
			$this->assertNotFalse( has_action( $post_hook, array( MailTemplatesScreen::class, 'handle_save' ) ) );
		} finally {
			// $submenu jest globalem, którego teardown testu nie przywraca.
			remove_all_actions( 'admin_menu' );
			remove_all_actions( $post_hook );
			if ( null !== $saved_menu ) {
				$GLOBALS['wp_filter']['admin_menu'] = $saved_menu;
			}
			if ( null !== $saved_post ) {
				$GLOBALS['wp_filter'][ $post_hook ] = $saved_post;
			}
			if ( null === $saved_sub ) {
				unset( $GLOBALS['submenu'] );
			} else {
				$GLOBALS['submenu'] = $saved_sub;
			}
		}
	}

	public function test_a_bare_less_than_typed_into_the_body_reaches_the_mail(): void {
		// `sanitize_textarea_field()` przepuszcza treść najpierw przez `wp_pre_kses_less_than()`,
		// więc samotny `<` wraca jako `&lt;` (sprawdzone w tym środowisku). Ekran ostrzega, że
		// znaczniki HTML są usuwane; nie ostrzega — i nie powinien musieć — że operator porównania
		// zamienia się w encję, którą `HtmlBody::wrap()` eskejpuje jeszcze raz i lekarz widzi
		// dosłowne `&lt;` w mailu.
		$this->asAdminPosting(
			MailTemplatesScreen::ACTION,
			array(
				'tpl_approved_subject' => 'Temat',
				'tpl_approved_body'    => 'Wiek < 30 lat',
			)
		);

		MailTemplatesScreen::handle_save();

		$this->assertSame( 'Wiek < 30 lat', MailTemplateRepository::override( MailKind::Approved )->body );

		$userId = (int) self::factory()->user->create( array( 'user_email' => 'jan@szpital.pl' ) );
		$this->assertTrue(
			MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'approved:' . $userId )
		);

		$body = (string) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 1 )[0]['body'];
		$this->assertSame( 'Wiek < 30 lat', $body );
		$this->assertStringNotContainsString( '&lt;', $body );
	}

	public function test_an_html_tag_in_the_body_is_still_stripped(): void {
		// Druga strona tej samej poprawki: przywracanie `<` nie może przywrócić HTML-a, bo ekran
		// obiecuje administratorowi zwykły tekst, a `HtmlBody::wrap()` i tak eskejpuje wszystko.
		$this->asAdminPosting( MailTemplatesScreen::ACTION, array( 'tpl_approved_body' => 'To jest <b>ważne</b> zdanie.' ) );

		MailTemplatesScreen::handle_save();

		$this->assertSame( 'To jest ważne zdanie.', MailTemplateRepository::override( MailKind::Approved )->body );
	}
}
