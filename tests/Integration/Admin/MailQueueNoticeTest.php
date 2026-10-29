<?php
/**
 * Testy komunikatu o niedrenowanej kolejce maili.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\MailQueueNotice;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\Migrations;
use WP_UnitTestCase;

final class MailQueueNoticeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_all_actions( 'admin_notices' );
	}

	/**
	 * Wstawia wiersz kolejki o danym wieku i stanie; oddaje jego identyfikator.
	 *
	 * @param int         $ageSeconds Wiek względem `created_at`.
	 * @param string      $key        Klucz odporności.
	 * @param QueueStatus $status     Stan wiersza.
	 */
	private function seed( int $ageSeconds, string $key, QueueStatus $status = QueueStatus::Queued ): int {
		global $wpdb;
		$created = gmdate( 'Y-m-d H:i:s', time() - $ageSeconds );
		MailQueueRepository::insert(
			array(
				'created_at'   => $created,
				'scheduled_at' => $created,
				'kind'         => 'confirmation',
				'dedupe_key'   => $key,
				'user_id'      => 7,
				'recipient'    => 'lekarz@example.com',
				'subject'      => 'Potwierdź konto',
				'body'         => 'Treść',
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( QueueStatus::Queued !== $status ) {
			$wpdb->update( Migrations::table( 'mail_queue' ), array( 'status' => $status->value ), array( 'id' => $id ) );
		}

		return $id;
	}

	private function renderAsAdmin(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MailQueueNotice::render();

		return (string) ob_get_clean();
	}

	public function test_an_empty_queue_shows_nothing(): void {
		$this->assertSame( '', $this->renderAsAdmin() );
	}

	public function test_a_fresh_queue_shows_nothing(): void {
		// Kolejka opróżniana co minutę zawsze ma w sobie świeże wiersze; ostrzeżenie przy każdym
		// z nich byłoby komunikatem, który administrator przestaje czytać.
		$this->seed( MailQueueNotice::WARN_AFTER - 60, 'swiezy' );

		$this->assertSame( '', $this->renderAsAdmin() );
	}

	public function test_a_stalled_queue_warns_the_administrator_and_links_to_the_log(): void {
		// Ta gałąź jest jedynym miejscem, które mówi człowiekowi, że wysyłka stoi. Bez niej na hoście
		// bez działającego WP-Crona nie wychodzi NIC i nikt się o tym nie dowiaduje, dopóki nie
		// zajrzy do logu wysyłek — czyli dopóki już nie podejrzewa awarii.
		$this->seed( MailQueueNotice::WARN_AFTER + 120, 'zalegly' );

		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'notice-warning pusers-mail-queue-notice', $html );
		$this->assertStringContainsString( 'WP-Cron', $html, 'Komunikat ma wskazywać najczęstszą przyczynę.' );
		$this->assertStringContainsString( Menu::SLUG_MAIL_LOG, $html, 'Komunikat ma prowadzić do logu wysyłek.' );
	}

	public function test_a_row_that_already_went_out_does_not_warn(): void {
		// Log wysyłek trzyma wiersze 90 dni, więc stary wiersz `sent` jest normą, nie awarią.
		$this->seed( 30 * DAY_IN_SECONDS, 'wyslany', QueueStatus::Sent );
		$this->seed( 30 * DAY_IN_SECONDS, 'nieudany', QueueStatus::Failed );

		$this->assertSame( '', $this->renderAsAdmin() );
	}

	public function test_a_user_without_the_capability_sees_nothing(): void {
		// Komunikat jest dla administratora i prowadzi na ekran za `manage_options`.
		$this->seed( MailQueueNotice::WARN_AFTER + 120, 'zalegly' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		MailQueueNotice::render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_register_hooks_the_notice_on_admin_notices(): void {
		MailQueueNotice::register();

		$this->assertNotFalse( has_action( 'admin_notices', array( MailQueueNotice::class, 'render' ) ) );
	}
}
