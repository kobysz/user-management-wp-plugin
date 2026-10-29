<?php
/**
 * Testy treści i odbiorców wiadomości rejestracji.
 *
 * Od Planu 5 zdarzenie nie woła `wp_mail()`: renderuje szablon i wstawia wiersz do kolejki. Asercje
 * o treści zostają te same, zmienia się tylko ich źródło — wiersz kolejki zamiast przechwyconej wysyłki.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\Mailer;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Mail\MailQueue;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\MailTemplateRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Tests\Integration\Support\QueuedMailsTrait;
use WP_UnitTestCase;

final class MailerTest extends WP_UnitTestCase {

	use QueuedMailsTrait;

	/**
	 * Wiadomości przechwycone zamiast wysłania; od Planu 5 ma zostać pusta.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->sent[] = (array) $atts;
				return true;
			},
			10,
			2
		);
		SettingsRepository::save( Settings::fromArray( array( 'notify_emails' => array( 'redakcja@example.test' ) ) ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'notatka' => 'visible', 'sekret' => 'hidden' ) ) ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
					array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka' ),
				)
			)
		);
	}

	private function account( string $profession, string $pwz = '', string $email = 'osoba@example.test' ): int {
		$id = (int) self::factory()->user->create( array( 'user_email' => $email ) );
		update_user_meta( $id, UserMeta::PROFESSION, $profession );
		if ( '' !== $pwz ) {
			update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'pwz', $pwz );
		}
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'sekret', 'TAJNE-DANE' );
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'notatka', "Pierwsza linia\nDruga linia" );
		AccountStatusRepository::set( $id, AccountStatus::Pending );
		return $id;
	}

	/**
	 * Czyści adres konta wprost w bazie: rdzeń nie pozwala zapisać pustego adresu, a guard
	 * „konto bez adresu e-mail" trzeba sprawdzić na koncie, które istnieje.
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	private function clear_email( int $userId ): void {
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $userId ) );
		clean_user_cache( $userId );
	}

	/**
	 * Treść pierwszego zakolejkowanego maila.
	 */
	private function first_body(): string {
		return (string) $this->mails()[0]['message'];
	}

	/**
	 * @return list<string> Akcje zapisane w logu zdarzeń.
	 */
	private function log_actions(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		$rows  = (array) $wpdb->get_results( "SELECT action FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
		return array_map( static fn ( object $row ): string => (string) $row->action, $rows );
	}

	public function test_no_mail_leaves_at_the_moment_of_the_event(): void {
		// Cała racja bytu kolejki: odpowiedź dla człowieka nie czeka na serwer poczty, a wysyłka ma ślad.
		$id = $this->account( 'lekarz', '5425740' );

		Mailer::editorial( $id );

		$this->assertSame( array(), $this->sent, 'Zdarzenie nie woła wp_mail().' );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_every_mail_kind_has_a_public_constant_on_the_mailer(): void {
		// Rodzaj w filtrach pusers_mail_body / pusers_mail_subject pochodzi z enuma, a obcy kod
		// rozgałęzia się po stałych Mailera. Rodzaj bez stałej to gałąź, której nikt nie napisze:
		// tak było z `editorial_auto`, który cicho omijał filtr pisany pod `KIND_EDITORIAL`.
		// Dlatego iterujemy po WSZYSTKICH rodzajach, zamiast wymieniać trzy z nich.
		$kinds = array();
		foreach ( ( new \ReflectionClass( Mailer::class ) )->getConstants() as $name => $value ) {
			if ( str_starts_with( (string) $name, 'KIND_' ) ) {
				$kinds[ (string) $name ] = $value;
			}
		}

		foreach ( MailKind::cases() as $case ) {
			$this->assertContains( $case->value, $kinds, 'Brak publicznej stałej KIND_* dla rodzaju ' . $case->value );
		}
		$this->assertCount( count( MailKind::cases() ), $kinds, 'Stała KIND_* bez odpowiadającego rodzaju maila.' );

		// Trzy nazwy, które Plan 3 wystawił publicznie, zostają dosłownie: są w cudzym kodzie.
		$this->assertSame( MailKind::Confirmation->value, Mailer::KIND_CONFIRMATION );
		$this->assertSame( MailKind::Editorial->value, Mailer::KIND_EDITORIAL );
		$this->assertSame( MailKind::ExistingAccount->value, Mailer::KIND_EXISTING );
	}

	public function test_the_editorial_mail_summarises_the_fields_and_carries_the_credential(): void {
		$id = $this->account( 'lekarz', '5425740' );

		$this->assertTrue( Mailer::editorial( $id ) );

		$body = $this->first_body();
		$this->assertStringContainsString( 'Profesja: Lekarz', $body );
		$this->assertStringContainsString( 'osoba@example.test', $body );
		$this->assertStringContainsString( '- pwz: 5425740', $body );
		$this->assertStringContainsString( '- notatka: Pierwsza linia Druga linia', $body, 'Wartość w jednej linii.' );
		$this->assertStringContainsString( 'Numer uprawnień: 5425740', $body );
		$this->assertStringContainsString( 'Wynik walidacji: poprawny', $body );
		$this->assertStringContainsString( 'page=pusers-applications&user=' . $id, $body );
		$this->assertSame( MailKind::Editorial->value, $this->mails()[0]['kind'] );
	}

	public function test_a_field_hidden_for_the_profession_is_not_sent_to_the_editors(): void {
		$id = $this->account( 'lekarz', '5425740' );

		Mailer::editorial( $id );

		$this->assertStringNotContainsString( 'TAJNE-DANE', $this->first_body() );
		$this->assertStringNotContainsString( 'sekret', $this->first_body() );
		$this->assertStringContainsString( '- notatka:', $this->first_body() );
	}

	public function test_the_credential_number_reaches_the_editors_even_when_the_matrix_hides_its_field(): void {
		// Numer jest podstawą decyzji redakcji: nie zależy od widoczności pola w macierzy.
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden', 'notatka' => 'visible' ) ) ) );
		$id = $this->account( 'lekarz', '5425740' );

		Mailer::editorial( $id );

		$body = $this->first_body();
		$this->assertStringContainsString( 'Numer uprawnień: 5425740', $body );
		$this->assertStringContainsString( 'Wynik walidacji: poprawny', $body );
		$this->assertStringNotContainsString( '- pwz:', $body, 'Pole ukryte nie należy do zgłoszenia.' );
	}

	public function test_the_editorial_mail_marks_an_invalid_credential(): void {
		// Treść DOMYŚLNA, bez nadpisania: tak wygląda mail, który dostaje redakcja portalu. Sam numer
		// bez werdyktu zostawiałby człowieka bez odpowiedzi na pytanie, po które ten mail do niego idzie.
		// Surowego kodu walidatora tu nie ma celowo — patrz komentarz w PlaceholderFactory.
		$id = $this->account( 'lekarz', '1234567' );

		Mailer::editorial( $id );

		$body = $this->first_body();
		$this->assertStringContainsString( 'Numer uprawnień: 1234567', $body );
		$this->assertStringContainsString( 'Wynik walidacji: niepoprawny', $body );
	}

	public function test_the_editorial_mail_substitutes_the_verdict_into_a_custom_template_too(): void {
		// Redakcja, która przepisze szablon po swojemu, nadal dostaje te same dwie zmienne.
		MailTemplateRepository::save( MailKind::Editorial, new MailTemplate( '', '{numer_uprawnien} (wynik walidacji: {wynik_walidacji})' ) );
		$id = $this->account( 'lekarz', '5425740' );

		Mailer::editorial( $id );

		$this->assertStringContainsString( '5425740 (wynik walidacji: poprawny)', $this->first_body() );
	}

	public function test_the_editorial_mail_distinguishes_a_profession_without_a_credential_from_a_missing_number(): void {
		// Dwa różne wnioski: pielęgniarka numeru nie ma, a lekarz bez numeru ma wniosek do odrzucenia.
		// Pusty werdykt zwijał oba przypadki w jedno i redakcja nie widziała między nimi różnicy.
		$nurse = $this->account( 'pielegniarka' );
		Mailer::editorial( $nurse );
		$this->assertStringContainsString( 'Wynik walidacji: nie dotyczy', $this->first_body() );

		$this->forget_mails();
		$doctor = $this->account( 'lekarz', '', 'druga-osoba@example.test' );
		Mailer::editorial( $doctor );
		$this->assertStringContainsString( 'Wynik walidacji: brak numeru', $this->first_body() );
		$this->assertStringContainsString( "Numer uprawnień: \n", $this->first_body(), 'Sam numer zostaje pusty.' );
	}

	public function test_an_approved_account_gets_the_informational_editorial_mail(): void {
		// O rodzaju decyduje STAN konta: zatwierdzone nie ma czego rozstrzygać, więc bez wezwania do decyzji.
		$id = $this->account( 'lekarz', '5425740' );
		AccountStatusRepository::set( $id, AccountStatus::Approved );

		Mailer::editorial( $id );

		$this->assertSame( MailKind::EditorialAuto->value, $this->mails()[0]['kind'] );
		$this->assertStringContainsString( 'informacyjny', $this->first_body() );
		$this->assertStringNotContainsString( 'user-edit.php', $this->first_body(), 'Bez wezwania do działania.' );
	}

	public function test_editorial_queues_a_separate_row_for_every_recipient(): void {
		// W jednym `To:` każdy redaktor widziałby adresy pozostałych (reguła Planu 3), a bez odbiorcy
		// w kluczu odporności drugi adres nigdy nie dostałby wiersza.
		SettingsRepository::save( Settings::fromArray( array( 'notify_emails' => array( 'pierwsza@example.test', 'druga@example.test' ) ) ) );
		$id = $this->account( 'lekarz', '5425740' );

		$this->assertTrue( Mailer::editorial( $id ) );

		$this->assertSame( array( 'pierwsza@example.test' ), $this->mails()[0]['to'] );
		$this->assertSame( array( 'druga@example.test' ), $this->mails()[1]['to'] );
		$this->assertSame( 2, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_editorial_without_recipients_writes_the_log_row_and_queues_nothing(): void {
		// Zachowanie Planu 3: brak odbiorców to zdarzenie w logu, nie cicha porażka.
		SettingsRepository::save( Settings::fromArray( array( 'notify_emails' => array() ) ) );
		$id = $this->account( 'lekarz', '5425740' );

		$this->assertFalse( Mailer::editorial( $id ) );

		$this->assertContains( Mailer::NO_RECIPIENTS_LOG_ACTION, $this->log_actions() );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_editorial_queued_twice_for_one_account_makes_one_row(): void {
		// Adres potwierdza się raz, więc drugie powiadomienie o tym samym wniosku byłoby duplikatem.
		$id = $this->account( 'lekarz', '5425740' );

		$this->assertTrue( Mailer::editorial( $id ) );
		$this->assertFalse( Mailer::editorial( $id ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_confirmation_queues_a_row_for_the_account_address_with_the_link(): void {
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::confirmation( $id, 'https://example.test/rejestracja/?pusers_uid=1&pusers_token=abc' ) );

		$this->assertSame( array( 'osoba@example.test' ), $this->mails()[0]['to'] );
		$this->assertSame( MailKind::Confirmation->value, $this->mails()[0]['kind'] );
		$this->assertStringContainsString( 'https://example.test/rejestracja/?pusers_uid=1&pusers_token=abc', $this->first_body() );
	}

	public function test_the_confirmation_mail_says_the_password_is_set_from_the_link_and_keeps_the_warning(): void {
		// Zgłoszenie o hasło nie pyta, więc link jest JEDYNĄ drogą do danych logowania; człowiek
		// musi to wiedzieć. Ostrzeżenie zostaje: zgłoszenie cudzego adresu jest nadal możliwe.
		$id = $this->account( 'lekarz' );

		Mailer::confirmation( $id, 'https://example.test/rejestracja/?pusers_uid=1&pusers_token=abc' );

		$body = $this->first_body();
		$this->assertStringContainsString( 'ustaw hasło', $body );
		$this->assertStringContainsString( 'Jeżeli to nie Ty zakładałeś konto, zignoruj tę wiadomość.', $body );
	}

	public function test_a_resend_with_a_new_token_queues_a_second_row(): void {
		// Ponowna wysyłka (funkcja Planu 3) generuje NOWY token: klucz odporności bez tokenu
		// zablokowałby ją na zawsze, a stary odnośnik już nie działa.
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::confirmation( $id, 'https://example.test/?pusers_token=pierwszy' ) );
		$this->assertFalse( Mailer::confirmation( $id, 'https://example.test/?pusers_token=pierwszy' ), 'Ten sam odnośnik to ten sam mail.' );
		$this->assertTrue( Mailer::confirmation( $id, 'https://example.test/?pusers_token=drugi' ) );

		$this->assertSame( 2, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_confirmation_mail_is_queued_for_immediate_dispatch(): void {
		// Człowiek czeka przy ekranie i bez tego odnośnika nie wejdzie na konto: nie czekamy na crona.
		$id = $this->account( 'lekarz' );

		Mailer::confirmation( $id, 'https://example.test/?pusers_token=abc' );

		$this->assertNotFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}

	public function test_an_account_without_an_address_queues_nothing(): void {
		// Dwa osobne guardy: konta nie ma wcale, i konto jest, ale bez adresu. Drugi broni przed
		// wierszem z pustym odbiorcą, a identyfikator 0 nigdy do niego nie dochodzi.
		$this->assertFalse( Mailer::confirmation( 0, 'https://example.test/?pusers_token=abc' ) );
		$this->assertFalse( Mailer::existing_account( 0 ) );
		$this->assertFalse( Mailer::editorial( 0 ) );

		$id = $this->account( 'lekarz' );
		$this->clear_email( $id );

		$this->assertFalse( Mailer::confirmation( $id, 'https://example.test/?pusers_token=abc' ) );
		$this->assertFalse( Mailer::existing_account( $id ) );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_existing_account_mail_goes_only_to_the_owner_and_carries_no_link_to_act_on(): void {
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::existing_account( $id ) );

		$this->assertCount( 1, $this->mails() );
		$this->assertSame( array( 'osoba@example.test' ), $this->mails()[0]['to'] );
		$body = $this->first_body();
		$this->assertStringContainsString( 'już istnieje', $body );
		$this->assertStringContainsString( 'Jeżeli to nie Ty', $body );
		$this->assertStringNotContainsString( 'pusers_token', $body );
		$this->assertStringContainsString( wp_lostpassword_url(), $body );
	}

	public function test_the_existing_account_mail_is_queued_at_most_once_an_hour(): void {
		// Osoba trzecia zgłaszająca cudzy adres nie zasypie właściciela, zgodnie z progiem may_send_to().
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::existing_account( $id ) );
		$this->assertFalse( Mailer::existing_account( $id ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_subject_can_be_replaced_through_its_filter(): void {
		add_filter( Mailer::FILTER_SUBJECT, static fn (): string => 'Inny temat' );
		$id = $this->account( 'lekarz' );

		Mailer::existing_account( $id );

		$this->assertSame( 'Inny temat', $this->mails()[0]['subject'] );
	}

	public function test_a_filter_returning_a_non_string_keeps_the_default_body(): void {
		add_filter( Mailer::FILTER_BODY, static fn (): array => array( 'nie tekst' ) );
		$id = $this->account( 'lekarz' );

		Mailer::existing_account( $id );

		$this->assertStringContainsString( 'już istnieje', $this->first_body() );
	}

	public function test_the_rejection_mail_carries_the_reason(): void {
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::rejected( $id, 'numer nieprawidłowy' ) );

		$this->assertSame( MailKind::Rejected->value, $this->mails()[0]['kind'] );
		$this->assertStringContainsString( 'numer nieprawidłowy', $this->first_body() );
		$this->assertStringNotContainsString( '{', $this->first_body(), 'Żadna zmienna nie zostaje w treści dosłownie.' );
	}

	public function test_an_empty_rejection_reason_is_not_judged_by_the_mailer(): void {
		// Kontrakt: niepusty powód jest obowiązkiem wołającego (ekran decyzji). Mailer kolejkuje to, co dostał.
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::rejected( $id, '' ) );
		$this->assertStringEndsWith( 'Powód:', trim( $this->first_body() ) );
	}

	public function test_a_re_rejection_with_another_reason_queues_again_but_the_same_reason_does_not(): void {
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::rejected( $id, 'numer nieprawidłowy' ) );
		$this->assertFalse( Mailer::rejected( $id, 'numer nieprawidłowy' ) );
		$this->assertTrue( Mailer::rejected( $id, 'brak dokumentów' ) );
	}

	public function test_application_received_and_approved_mails_resolve_every_variable(): void {
		$pageId = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Logowanie' ) );
		SettingsRepository::save( Settings::fromArray( array( 'login_page_id' => $pageId ) ) );
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::application_received( $id ) );
		$this->assertTrue( Mailer::approved( $id ) );

		foreach ( $this->mails() as $mail ) {
			$this->assertStringNotContainsString( '{', $mail['message'] );
			$this->assertStringNotContainsString( '{', $mail['subject'] );
			$this->assertSame( array( 'osoba@example.test' ), $mail['to'] );
		}
		$kinds = array_column( $this->mails(), 'kind' );
		$this->assertSame( array( MailKind::ApplicationReceived->value, MailKind::Approved->value ), $kinds );
		$this->assertStringContainsString( (string) get_permalink( $pageId ), $this->mails()[1]['message'] );
	}

	public function test_a_double_click_on_approve_queues_one_mail_but_a_later_decision_queues_another(): void {
		// Idempotencja chroni od podwójnego kliknięcia, nie od ponownej decyzji miesiąc później.
		$id     = $this->account( 'lekarz' );
		$bucket = '202610281200';
		$seam   = static function () use ( &$bucket ): string {
			return $bucket;
		};
		add_filter( 'pusers_internal_test_seam_minute_bucket', $seam );

		$this->assertTrue( Mailer::approved( $id ) );
		$this->assertFalse( Mailer::approved( $id ) );
		$bucket = '202611281200';
		$this->assertTrue( Mailer::approved( $id ) );

		$this->assertCount( 2, $this->mails() );
		remove_filter( 'pusers_internal_test_seam_minute_bucket', $seam );
	}

	public function test_the_same_rejection_in_a_later_bucket_queues_a_second_row(): void {
		$id     = $this->account( 'lekarz' );
		$bucket = '202610281200';
		$seam   = static function () use ( &$bucket ): string {
			return $bucket;
		};
		add_filter( 'pusers_internal_test_seam_minute_bucket', $seam );

		$this->assertTrue( Mailer::rejected( $id, 'powód' ) );
		$this->assertFalse( Mailer::rejected( $id, 'powód' ) );
		$bucket = '202610281201';
		$this->assertTrue( Mailer::rejected( $id, 'powód' ) );
		remove_filter( 'pusers_internal_test_seam_minute_bucket', $seam );
	}

	public function test_application_received_is_queued_once_ever(): void {
		$id = $this->account( 'lekarz' );

		$this->assertTrue( Mailer::application_received( $id ) );
		$this->assertFalse( Mailer::application_received( $id ) );
	}

	public function test_the_cycle_mails_are_not_immediate(): void {
		$id = $this->account( 'lekarz' );
		Mailer::application_received( $id );
		Mailer::approved( $id );
		Mailer::rejected( $id, 'x' );

		$this->assertFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ), 'Nikt nie czeka przy ekranie: idzie z najbliższym przebiegiem crona.' );
		$this->assertCount( 3, $this->mails() );
	}

	public function test_cycle_mails_for_an_account_without_an_address_queue_nothing(): void {
		$this->assertFalse( Mailer::application_received( 0 ) );
		$this->assertFalse( Mailer::approved( 0 ) );
		$this->assertFalse( Mailer::rejected( 0, 'x' ) );

		$id = $this->account( 'lekarz' );
		$this->clear_email( $id );

		$this->assertFalse( Mailer::application_received( $id ) );
		$this->assertFalse( Mailer::approved( $id ) );
		$this->assertFalse( Mailer::rejected( $id, 'x' ) );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_per_address_mail_limit_allows_the_configured_number_and_then_refuses(): void {
		SettingsRepository::save( Settings::fromArray( array( 'registration_limit_per_email_per_hour' => 2 ) ) );

		$this->assertTrue( Mailer::may_send_to( 'ktos@example.test', Mailer::PURPOSE_RESEND ) );
		$this->assertTrue( Mailer::may_send_to( 'KTOS@example.test ', Mailer::PURPOSE_RESEND ), 'Adres jest normalizowany.' );
		$this->assertFalse( Mailer::may_send_to( 'ktos@example.test', Mailer::PURPOSE_RESEND ) );
		$this->assertTrue( Mailer::may_send_to( 'ktos-inny@example.test', Mailer::PURPOSE_RESEND ), 'Kubełek jest per adres.' );
		$this->assertFalse( Mailer::may_send_to( '', Mailer::PURPOSE_RESEND ) );
	}
}
