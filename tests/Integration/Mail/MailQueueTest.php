<?php
/**
 * Testy kolejkowania maila: snapshot treści, klucz odporności, filtry i zdarzenie natychmiastowe.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Mail;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;
use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Front\Mailer;
use PortalUsers\Mail\MailQueue;
use PortalUsers\Mail\PlaceholderFactory;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\MailTemplateRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

final class MailQueueTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		SettingsRepository::save( Settings::fromArray( array() ) );
	}

	private function account(): int {
		return (int) self::factory()->user->create(
			array(
				'user_login' => 'jan.kowalski',
				'user_email' => 'jan@szpital.pl',
			)
		);
	}

	/**
	 * Wiersze gotowe do wysyłki, najstarszy naprzód.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function queued(): array {
		return MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 20 );
	}

	public function test_the_queued_row_holds_the_rendered_snapshot(): void {
		$userId = $this->account();
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto {email}', 'Wejdź: {link_logowania}' ) );

		$this->assertTrue(
			MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'approved:' . $userId )
		);

		$row = $this->queued()[0];
		$this->assertSame( 'Konto jan@szpital.pl', $row['subject'] );
		$this->assertStringContainsString( 'Wejdź: http', (string) $row['body'] );
		$this->assertSame( MailKind::Approved->value, $row['kind'] );
		$this->assertSame( $userId, (int) $row['user_id'] );
		$this->assertSame( 'jan@szpital.pl', $row['recipient'] );
		$this->assertSame( QueueStatus::Queued->value, $row['status'] );
	}

	public function test_editing_the_template_later_does_not_change_a_row_already_queued(): void {
		// Snapshot przy zapisie: dispatcher nie potrzebuje ustawień ani konta, a redakcja nie przepisuje
		// historii maili, które już poszły.
		$userId = $this->account();
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto {email}', 'Stara treść' ) );
		MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'approved:' . $userId );

		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Zupełnie inny temat', 'Nowa treść' ) );

		$row = $this->queued()[0];
		$this->assertSame( 'Konto jan@szpital.pl', $row['subject'] );
		$this->assertSame( 'Stara treść', $row['body'] );
	}

	public function test_the_same_recipient_is_not_queued_twice_for_one_dedupe_key(): void {
		$userId = $this->account();
		$values = PlaceholderFactory::for_user( $userId );

		$this->assertTrue( MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', $values, 'approved:' . $userId ) );
		$this->assertFalse( MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', $values, 'approved:' . $userId ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_same_dedupe_key_with_a_different_recipient_is_queued_again(): void {
		// Bez odbiorcy w kluczu drugi adres redakcji nigdy nie dostałby wniosku.
		$userId = $this->account();
		$values = PlaceholderFactory::for_user( $userId );

		$this->assertTrue( MailQueue::enqueue( MailKind::Editorial, $userId, 'pierwsza@redakcja.test', $values, 'editorial:' . $userId ) );
		$this->assertTrue( MailQueue::enqueue( MailKind::Editorial, $userId, 'druga@redakcja.test', $values, 'editorial:' . $userId ) );
		$this->assertSame( 2, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_recipient_in_the_key_ignores_letter_case(): void {
		// Ten sam adres pisany inaczej to nadal jeden człowiek i jeden mail.
		$userId = $this->account();
		$values = PlaceholderFactory::for_user( $userId );

		$this->assertTrue( MailQueue::enqueue( MailKind::Approved, $userId, 'Jan@Szpital.pl', $values, 'approved:' . $userId ) );
		$this->assertFalse( MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', $values, 'approved:' . $userId ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_body_and_subject_filters_keep_the_contract_from_plan_three(): void {
		$userId = $this->account();
		$seen   = array();
		add_filter(
			Mailer::FILTER_BODY,
			static function ( $body, $kind, $context ) use ( &$seen ): string {
				$seen = array( $kind, $context );
				return 'podmieniona treść';
			},
			10,
			3
		);
		add_filter( Mailer::FILTER_SUBJECT, static fn (): string => 'podmieniony temat', 10, 3 );

		MailQueue::enqueue( MailKind::Confirmation, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'k' );

		$this->assertSame( MailKind::Confirmation->value, $seen[0] );
		$this->assertSame( $userId, $seen[1]['user_id'] );
		$this->assertSame( 'jan@szpital.pl', $seen[1]['values']['email'], 'Kontekst niesie zmienne szablonu.' );
		$row = $this->queued()[0];
		$this->assertSame( 'podmieniona treść', $row['body'] );
		$this->assertSame( 'podmieniony temat', $row['subject'] );
	}

	public function test_a_filter_returning_a_non_string_leaves_the_rendered_content(): void {
		// Obca wtyczka oddająca null nie może zostawić maila bez treści.
		$userId = $this->account();
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Temat szablonu', 'Treść szablonu' ) );
		add_filter( Mailer::FILTER_BODY, static fn (): ?string => null, 10, 3 );
		add_filter( Mailer::FILTER_SUBJECT, static fn (): array => array( 'nie tekst' ), 10, 3 );

		MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'k' );

		$row = $this->queued()[0];
		$this->assertSame( 'Temat szablonu', $row['subject'] );
		$this->assertSame( 'Treść szablonu', $row['body'] );
	}

	public function test_a_newline_in_a_value_cannot_reach_the_subject(): void {
		// Powód odrzucenia wpisuje ręcznie człowiek, więc może nieść nową linię. Nowa linia w temacie
		// to wstrzyknięcie nagłówka do wiadomości, nie usterka kosmetyczna.
		$userId = $this->account();
		MailTemplateRepository::save( MailKind::Rejected, new MailTemplate( 'Powód: {powod_odrzucenia}', 'Treść' ) );
		$values = PlaceholderFactory::for_user( $userId, array( 'powod_odrzucenia' => "numer\r\nBcc: obcy@example.test\t  zły" ) );

		MailQueue::enqueue( MailKind::Rejected, $userId, 'jan@szpital.pl', $values, 'rejected:' . $userId );

		$this->assertSame( 'Powód: numer Bcc: obcy@example.test zły', $this->queued()[0]['subject'] );
	}

	public function test_a_newline_in_the_subject_filter_result_cannot_reach_the_subject_either(): void {
		$userId = $this->account();
		add_filter( Mailer::FILTER_SUBJECT, static fn (): string => "Temat\nz nową linią", 10, 3 );

		MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'k' );

		$this->assertSame( 'Temat z nową linią', $this->queued()[0]['subject'] );
	}

	public function test_the_body_keeps_its_own_newlines(): void {
		$userId = $this->account();
		MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Temat', "Pierwsza\n\nTrzecia" ) );

		MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'k' );

		$this->assertSame( "Pierwsza\n\nTrzecia", $this->queued()[0]['body'] );
	}

	public function test_an_invalid_recipient_is_refused_without_a_row(): void {
		$userId = $this->account();
		$values = PlaceholderFactory::for_user( $userId );

		$this->assertFalse( MailQueue::enqueue( MailKind::Approved, $userId, 'to-nie-adres', $values, 'k' ) );
		$this->assertFalse( MailQueue::enqueue( MailKind::Approved, $userId, '', $values, 'k' ) );
		$this->assertFalse( MailQueue::enqueue( MailKind::Approved, $userId, '   ', $values, 'k' ) );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_a_template_rendering_to_nothing_is_refused_without_a_row(): void {
		// Szablon złożony z jednej zmiennej, która wyszła pusta: wiersz bez tematu i bez treści
		// byłby pustym mailem do człowieka i hałasem w logu wysyłek.
		$userId = $this->account();
		MailTemplateRepository::save( MailKind::Rejected, new MailTemplate( '{powod_odrzucenia}', '{powod_odrzucenia}' ) );

		$this->assertFalse(
			MailQueue::enqueue( MailKind::Rejected, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId, array( 'powod_odrzucenia' => '' ) ), 'k' )
		);
		$this->assertFalse( MailQueue::enqueue_rendered( MailKind::Rejected, $userId, 'jan@szpital.pl', ' ', "\n ", 'k' ) );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_a_dedupe_key_longer_than_the_index_is_refused_without_a_row(): void {
		// Kolumna `dedupe_key` ma 191 znaków i UNIQUE, a wpdb pracuje bez trybów ścisłych: dłuższy klucz
		// MySQL obciąłby po cichu, dwa różne klucze o wspólnym przedrostku zderzyłyby się na indeksie,
		// insert() zwrócił false, a wołający odczytał to jako „już w kolejce" — mail by nie wyszedł.
		$userId = $this->account();

		$this->assertFalse(
			MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), str_repeat( 'x', 200 ) )
		);
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_an_immediate_mail_schedules_a_dispatch_event(): void {
		$userId = $this->account();

		MailQueue::enqueue( MailKind::Confirmation, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'k', true );

		$this->assertNotFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}

	public function test_a_mail_that_can_wait_does_not_schedule_a_dispatch_event(): void {
		$userId = $this->account();

		MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', PlaceholderFactory::for_user( $userId ), 'k' );

		$this->assertFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}

	public function test_a_refused_mail_does_not_schedule_a_dispatch_event(): void {
		// Zdarzenie bez wiersza budziłoby dispatchera po nic.
		$userId = $this->account();

		MailQueue::enqueue( MailKind::Confirmation, $userId, 'to-nie-adres', PlaceholderFactory::for_user( $userId ), 'k', true );

		$this->assertFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}

	public function test_a_rendered_mail_can_be_queued_without_a_template(): void {
		// Droga dla maila rdzeniowego (Task 7): treść jest już gotowa, szablonu nie ma po co czytać.
		$userId = $this->account();

		$this->assertTrue(
			MailQueue::enqueue_rendered( MailKind::PasswordReset, $userId, 'jan@szpital.pl', "Temat\nz linii", 'Treść gotowa', 'password_reset:' . $userId )
		);

		$row = $this->queued()[0];
		$this->assertSame( 'Temat z linii', $row['subject'] );
		$this->assertSame( 'Treść gotowa', $row['body'] );
		$this->assertSame( MailKind::PasswordReset->value, $row['kind'] );
	}
}
