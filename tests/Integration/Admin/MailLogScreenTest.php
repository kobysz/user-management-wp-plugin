<?php
/**
 * Testy ekranu logu wysyłek: lista, zakładki, szczegóły tylko do czytania i „Wznów".
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\MailLogScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\Migrations;
use WP_UnitTestCase;
use WPDieException;

final class MailLogScreenTest extends WP_UnitTestCase {

	use AdminPostingTrait;

	private string $originalMethod = 'GET';

	/**
	 * Anulujący filtr przekierowania, który zapamiętuje adres.
	 *
	 * @var callable
	 */
	private $captureRedirect;

	private string $redirectedTo = '';

	public function set_up(): void {
		parent::set_up();
		$this->originalMethod  = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$this->redirectedTo    = '';
		$this->captureRedirect = function ( $location ) {
			$this->redirectedTo = (string) $location;
			return false;
		};
		add_filter( 'wp_redirect', $this->captureRedirect );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->captureRedirect );
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		parent::tear_down();
	}

	/**
	 * Wstawia wiersz i ustawia mu stan oraz powód błędu.
	 *
	 * @param QueueStatus $status Stan docelowy.
	 * @param string      $error  Powód ostatniej porażki.
	 * @param string      $key    Przedrostek klucza odporności.
	 * @param string      $body   Treść maila.
	 */
	private function seed( QueueStatus $status, string $error = '', string $key = 'k', string $body = 'Treść maila', string $subject = 'Potwierdź konto', string $recipient = 'lekarz@example.com' ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		MailQueueRepository::insert(
			array(
				'created_at'   => $now,
				'scheduled_at' => $now,
				'kind'         => 'confirmation',
				'dedupe_key'   => $key . wp_generate_password( 6, false ),
				'user_id'      => 7,
				'recipient'    => $recipient,
				'subject'      => $subject,
				'body'         => $body,
			)
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->update(
			Migrations::table( 'mail_queue' ),
			array(
				'status'     => $status->value,
				'last_error' => $error,
				'attempts'   => '' === $error ? 1 : 3,
			),
			array( 'id' => $id )
		);
		return $id;
	}

	private function render_screen(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MailLogScreen::render();
		return (string) ob_get_clean();
	}

	public function test_the_list_shows_a_failed_row_with_its_reason(): void {
		$this->seed( QueueStatus::Failed, 'SMTP connect() failed' );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'lekarz@example.com', $html );
		$this->assertStringContainsString( 'Potwierdź konto', $html );
		$this->assertStringContainsString( 'SMTP connect() failed', $html );
		$this->assertStringContainsString( 'confirmation', $html );
	}

	public function test_a_long_reason_is_shortened_in_the_list(): void {
		$this->seed( QueueStatus::Failed, str_repeat( 'x', 500 ) );

		$html = $this->render_screen();

		$this->assertStringContainsString( '>' . str_repeat( 'x', 80 ) . '…<', $html );
		$this->assertStringNotContainsString( str_repeat( 'x', 81 ), $html );
	}

	public function test_page_two_shows_the_remaining_row(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->seed( QueueStatus::Sent, '', 'p' . $i );
		}
		$_GET['paged'] = '2';

		$this->assertSame( 1, substr_count( $this->render_screen(), 'class="pusers-mail-row"' ) );
	}

	public function test_a_page_beyond_the_last_clamps_to_the_last_page(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->seed( QueueStatus::Sent, '', 'p' . $i );
		}
		$_GET['paged'] = '99';

		$this->assertSame( 1, substr_count( $this->render_screen(), 'class="pusers-mail-row"' ) );
	}

	public function test_a_non_numeric_page_falls_back_to_the_first(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->seed( QueueStatus::Sent, '', 'p' . $i );
		}
		$_GET['paged'] = 'abc';

		$this->assertSame( 20, substr_count( $this->render_screen(), 'class="pusers-mail-row"' ) );
	}

	public function test_subject_recipient_and_error_are_escaped_in_the_list_and_the_detail(): void {
		$id = $this->seed( QueueStatus::Failed, '<em>boom</em>', 'a', 'Treść', '<img src=x onerror=alert(1)>', '<u>rcpt</u>@example.com' );

		$list           = $this->render_screen();
		$_GET['action'] = 'view';
		$_GET['id']     = (string) $id;
		$detail         = $this->render_screen();

		foreach ( array( $list, $detail ) as $html ) {
			$this->assertStringNotContainsString( '<img', $html );
			$this->assertStringNotContainsString( '<u>', $html );
			$this->assertStringNotContainsString( '<em>', $html );
			$this->assertStringContainsString( '&lt;img src=x', $html );
			$this->assertStringContainsString( '&lt;u&gt;rcpt', $html );
			$this->assertStringContainsString( '&lt;em&gt;boom', $html );
		}
	}

	public function test_a_queued_row_with_an_old_error_is_not_presented_as_failing_now(): void {
		// Wznowienie zostawia last_error jako ślad. Administrator ma odróżnić „psuje się teraz" od „psuło się, czeka".
		$this->seed( QueueStatus::Queued, 'Stary błąd SMTP', 'a' );
		$this->seed( QueueStatus::Failed, 'Aktualny błąd SMTP', 'b' );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'Wcześniejsza próba: Stary błąd SMTP', $html );
		$this->assertStringNotContainsString( 'Wcześniejsza próba: Aktualny błąd SMTP', $html );
		$this->assertStringContainsString( 'Aktualny błąd SMTP', $html );
	}

	public function test_the_status_tabs_count_rows(): void {
		$this->seed( QueueStatus::Failed, 'e', 'a' );
		$this->seed( QueueStatus::Failed, 'e', 'b' );
		$this->seed( QueueStatus::Sent, '', 'c' );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'Wszystkie: 3', $html );
		$this->assertStringContainsString( 'Nieudane: 2', $html );
		$this->assertStringContainsString( 'Wysłane: 1', $html );
		$this->assertStringContainsString( 'Oczekujące: 0', $html );
	}

	public function test_the_status_filter_narrows_the_list(): void {
		$this->seed( QueueStatus::Failed, 'e', 'a' );
		$this->seed( QueueStatus::Sent, '', 'b' );
		$_GET['status'] = 'sent';

		$html = $this->render_screen();

		$this->assertSame( 1, substr_count( $html, 'class="pusers-mail-row"' ) );
		$this->assertStringNotContainsString( 'name="pusers_mail_id"', $html, 'Wiersz sent nie ma przycisku Wznów.' );
	}

	public function test_the_list_is_paginated_twenty_rows_per_page(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->seed( QueueStatus::Sent, '', 'p' . $i );
		}

		$html = $this->render_screen();

		$this->assertSame( 20, substr_count( $html, 'class="pusers-mail-row"' ) );
		$this->assertSame( 20, MailLogScreen::PER_PAGE );
	}

	public function test_only_a_failed_row_offers_the_requeue_button(): void {
		$this->seed( QueueStatus::Sent, '', 'a' );
		$this->seed( QueueStatus::Queued, '', 'b' );
		$this->assertStringNotContainsString( MailLogScreen::ACTION_REQUEUE, $this->render_screen() );

		$this->seed( QueueStatus::Failed, 'e', 'c' );
		$this->assertStringContainsString( MailLogScreen::ACTION_REQUEUE, $this->render_screen() );
	}

	public function test_a_failed_row_anonymized_by_erasure_has_no_requeue_button(): void {
		global $wpdb;
		$id = $this->seed( QueueStatus::Failed, 'e', 'z' );
		$wpdb->update( Migrations::table( 'mail_queue' ), array( 'anonymized' => 1 ), array( 'id' => $id ) );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'pusers-mail-row', $html, 'Wiersz dalej jest na liście, jako nieudany.' );
		$this->assertStringNotContainsString( MailLogScreen::ACTION_REQUEUE, $html );

		// Kontrola negatywna: ten sam stan bez anonimizacji dostaje przycisk.
		$this->seed( QueueStatus::Failed, 'e', 'y' );
		$this->assertStringContainsString( MailLogScreen::ACTION_REQUEUE, $this->render_screen() );
	}

	public function test_requeue_returns_a_failed_row_to_the_queue(): void {
		$id = $this->seed( QueueStatus::Failed, 'SMTP connect() failed' );
		$this->asAdminPosting( MailLogScreen::ACTION_REQUEUE, array( 'mail_id' => (string) $id ) );

		MailLogScreen::handle_requeue();

		$row = MailQueueRepository::find( $id );
		$this->assertSame( QueueStatus::Queued->value, $row['status'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertSame( 'SMTP connect() failed', $row['last_error'], 'Powód zostaje jako ślad.' );
		$this->assertStringContainsString( 'pusers_notice=' . MailLogScreen::NOTICE_REQUEUED, $this->redirectedTo );
	}

	public function test_requeue_does_not_run_the_dispatcher(): void {
		// Ekran resetuje wiersz; wysyła cron w następnym przebiegu.
		$id = $this->seed( QueueStatus::Failed, 'e' );
		$this->asAdminPosting( MailLogScreen::ACTION_REQUEUE, array( 'mail_id' => (string) $id ) );
		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		MailLogScreen::handle_requeue();

		$this->assertSame( 0, $mails );
		$this->assertSame( QueueStatus::Queued->value, MailQueueRepository::find( $id )['status'] );
	}

	/**
	 * @return array<string, array{0:QueueStatus}>
	 */
	public static function not_failed_statuses(): array {
		return array(
			'sent'    => array( QueueStatus::Sent ),
			'queued'  => array( QueueStatus::Queued ),
			'sending' => array( QueueStatus::Sending ),
		);
	}

	/**
	 * @dataProvider not_failed_statuses
	 */
	public function test_requeue_refuses_a_row_that_is_not_failed( QueueStatus $status ): void {
		// Wznowienie wysłanego maila wysłałoby go drugi raz. Odpowiedź to powiadomienie, nie zmiana.
		$id = $this->seed( $status );
		$this->asAdminPosting( MailLogScreen::ACTION_REQUEUE, array( 'mail_id' => (string) $id ) );

		MailLogScreen::handle_requeue();

		$this->assertSame( $status->value, MailQueueRepository::find( $id )['status'] );
		$this->assertSame( 1, (int) MailQueueRepository::find( $id )['attempts'] );
		$this->assertStringContainsString( 'pusers_notice=' . MailLogScreen::NOTICE_NOT_FAILED, $this->redirectedTo );
	}

	public function test_requeue_of_a_missing_row_ends_in_a_notice(): void {
		$this->asAdminPosting( MailLogScreen::ACTION_REQUEUE, array( 'mail_id' => '999999' ) );

		MailLogScreen::handle_requeue();

		$this->assertStringContainsString( 'pusers_notice=' . MailLogScreen::NOTICE_NOT_FAILED, $this->redirectedTo );
	}

	public function test_requeue_without_a_nonce_or_capability_is_refused(): void {
		$id = $this->seed( QueueStatus::Failed, 'e' );

		$this->asAdminPosting( MailLogScreen::ACTION_REQUEUE, array( 'mail_id' => (string) $id ) );
		unset( $_POST['pusers_nonce'] );
		try {
			MailLogScreen::handle_requeue();
			$this->fail( 'Wznowienie bez nonce\'a powinno zostać odrzucone.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( QueueStatus::Failed->value, MailQueueRepository::find( $id )['status'] );
		}

		$this->asRolePosting( 'subscriber', MailLogScreen::ACTION_REQUEUE, array( 'mail_id' => (string) $id ) );
		try {
			MailLogScreen::handle_requeue();
			$this->fail( 'Wznowienie bez uprawnień powinno zostać odrzucone.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( QueueStatus::Failed->value, MailQueueRepository::find( $id )['status'] );
		}
	}

	public function test_requeue_by_get_is_refused(): void {
		$id = $this->seed( QueueStatus::Failed, 'e' );
		$this->asAdminPosting( MailLogScreen::ACTION_REQUEUE, array( 'mail_id' => (string) $id ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		try {
			MailLogScreen::handle_requeue();
			$this->fail( 'Zmiana stanu przez GET powinna zostać odrzucona.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( QueueStatus::Failed->value, MailQueueRepository::find( $id )['status'] );
		}
	}

	public function test_the_screen_shows_the_requeue_notices(): void {
		$_GET['pusers_notice'] = MailLogScreen::NOTICE_NOT_FAILED;
		$this->assertStringContainsString( 'notice-warning', $this->render_screen() );

		$_GET['pusers_notice'] = MailLogScreen::NOTICE_REQUEUED;
		$this->assertStringContainsString( 'notice-success', $this->render_screen() );
	}

	public function test_the_detail_view_escapes_the_stored_body(): void {
		// Treść pochodzi z szablonu, który edytuje człowiek; w panelu nie wolno jej wykonać.
		$id             = $this->seed( QueueStatus::Sent, '<script>alert(2)</script>', 'a', '<script>alert(1)</script></pre><b>x</b>' );
		$_GET['action'] = 'view';
		$_GET['id']     = (string) $id;

		$html = $this->render_screen();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $html );
		$this->assertStringContainsString( '<pre', $html );
	}

	public function test_the_detail_view_has_no_editing_controls(): void {
		$id             = $this->seed( QueueStatus::Failed, 'e' );
		$_GET['action'] = 'view';
		$_GET['id']     = (string) $id;

		$html = $this->render_screen();

		$this->assertStringContainsString( 'lekarz@example.com', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_the_detail_view_of_a_missing_row_says_so(): void {
		$_GET['action'] = 'view';
		$_GET['id']     = '999999';

		$html = $this->render_screen();

		$this->assertStringContainsString( 'Nie znaleziono', $html );
	}

	public function test_the_screen_refuses_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		MailLogScreen::render();
	}

	public function test_the_screen_and_its_handler_are_registered(): void {
		$post_hook  = 'admin_post_' . MailLogScreen::ACTION_REQUEUE;
		$saved_menu = isset( $GLOBALS['wp_filter']['admin_menu'] ) ? clone $GLOBALS['wp_filter']['admin_menu'] : null;
		$saved_post = isset( $GLOBALS['wp_filter'][ $post_hook ] ) ? clone $GLOBALS['wp_filter'][ $post_hook ] : null;
		$saved_sub  = $GLOBALS['submenu'] ?? null;

		try {
			// Rozruch wtyczki mógł już podpiąć oba haki: bez czyszczenia test przeszedłby nawet przy pustym register().
			remove_all_actions( 'admin_menu' );
			remove_all_actions( $post_hook );
			$GLOBALS['submenu'] = array();
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

			MailLogScreen::register();
			do_action( 'admin_menu' );

			$slugs = array_column( $GLOBALS['submenu'][ Menu::PARENT ] ?? array(), 2 );
			$this->assertContains( Menu::SLUG_MAIL_LOG, $slugs );
			$this->assertNotFalse( has_action( $post_hook, array( MailLogScreen::class, 'handle_requeue' ) ) );
		} finally {
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
}
