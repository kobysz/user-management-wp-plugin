<?php
/**
 * Testy dispatchera: wysyłka wiersza z kolejki, ponowienia, opakowanie HTML i wyścig dwóch przebiegów.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Mail;

use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Mail\Dispatcher;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\Migrations;
use WP_UnitTestCase;

final class DispatcherTest extends WP_UnitTestCase {

	/**
	 * Limit z zapytania `due()` przechwycony z ostatniego przebiegu.
	 */
	private int $dueLimit = 0;

	public function set_up(): void {
		parent::set_up();
		// Środowisko testowe ma nadawcę `wordpress@localhost`, a PHPMailer odrzuca adres bez domeny
		// najwyższego poziomu. Te testy sprawdzają dispatchera, nie konfigurację poczty.
		add_filter( 'wp_mail_from', static fn (): string => 'portal@example.com' );
	}

	/**
	 * Wstawia wiersz gotowy do wysyłki i oddaje jego identyfikator.
	 *
	 * @param string $dedupeKey Klucz odporności.
	 * @param string $body      Treść (tekst zwykły).
	 */
	private function queue( string $dedupeKey, string $body = "Linia 1\nLinia 2" ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->assertTrue(
			MailQueueRepository::insert(
				array(
					'created_at'   => $now,
					'scheduled_at' => $now,
					'kind'         => 'confirmation',
					'dedupe_key'   => $dedupeKey,
					'user_id'      => 7,
					'recipient'    => 'lekarz@example.com',
					'subject'      => 'Potwierdź konto',
					'body'         => $body,
				)
			)
		);

		// paginate() oddaje najnowsze naprzód, więc pierwszy wiersz to ten właśnie wstawiony.
		return (int) MailQueueRepository::paginate( '', 1, 0 )[0]['id'];
	}

	/**
	 * Przesuwa termin wiersza na teraz, żeby kolejna próba była gotowa bez czekania.
	 *
	 * @param int $id Identyfikator wiersza.
	 */
	private function make_due( int $id ): void {
		global $wpdb;
		$wpdb->update( Migrations::table( 'mail_queue' ), array( 'scheduled_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
	}

	/**
	 * Wiersz z bazy.
	 *
	 * @param int $id Identyfikator wiersza.
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		return (array) MailQueueRepository::find( $id );
	}

	/**
	 * Sprawdza, że kolejna próba stoi dokładnie o tyle sekund za momentem startu przebiegu.
	 *
	 * Punktem odniesienia jest `$startedAt` zdjęty tuż przed `run()`, nie `time()` z chwili asercji:
	 * luźny margines przepuściłby politykę, która zjechała z 60 s na 55 s.
	 *
	 * @param int                 $delay     Oczekiwane opóźnienie w sekundach.
	 * @param int                 $startedAt Znacznik czasu zdjęty tuż przed przebiegiem.
	 * @param array<string,mixed> $row       Wiersz kolejki.
	 */
	private function assert_retry_delay( int $delay, int $startedAt, array $row ): void {
		$actual = (int) strtotime( (string) $row['scheduled_at'] . ' UTC' );
		$this->assertGreaterThanOrEqual( $startedAt + $delay, $actual, 'Termin ponowienia: oczekiwano ' . $delay . ' s.' );
		// Zapas dwóch sekund tylko na upływ czasu MIĘDZY zdjęciem znacznika a zapisem wiersza.
		$this->assertLessThanOrEqual( $startedAt + $delay + 2, $actual, 'Termin ponowienia: oczekiwano ' . $delay . ' s.' );
	}

	public function test_a_due_row_is_sent_and_marked(): void {
		$id = $this->queue( 'd-sent' );

		Dispatcher::run();

		$row = $this->row( $id );
		$this->assertSame( QueueStatus::Sent->value, $row['status'], 'Mail nie wyszedł; powód z logu: ' . (string) $row['last_error'] );
		$this->assertNotNull( $row['sent_at'] );
		$this->assertSame( 1, (int) $row['attempts'] );
	}

	public function test_a_row_abandoned_in_sending_is_recovered_and_sent(): void {
		// Przebieg ubity timeoutem PHP zostawia wiersz w `sending`, a sam z niego nie wyjdzie: do
		// kolejki wraca go dopiero odzysk na początku przebiegu. Bez tego kroku mail nie wyszedłby
		// nigdy, a lekarz czekałby na link, który leży w bazie.
		$id = $this->queue( 'd-stale' );
		$this->assertSame( 1, MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );

		Dispatcher::run();

		$row = $this->row( $id );
		$this->assertSame( QueueStatus::Sent->value, $row['status'], 'Mail nie wyszedł; powód z logu: ' . (string) $row['last_error'] );
		$this->assertSame( 2, (int) $row['attempts'], 'Odzyskany wiersz jest zajmowany ponownie, więc licznik prób rośnie.' );
	}

	public function test_a_row_another_run_has_just_claimed_survives_the_pass(): void {
		// Odzysk celuje WYŁĄCZNIE w wiersze zajęte dawniej niż STALE_AFTER. Gdyby próg szedł
		// w drugą stronę, przebieg oddawałby do kolejki wiersze, które żywy przebieg trzyma właśnie
		// teraz — i ten sam link potwierdzający poszedłby do lekarza dwa razy.
		$sends = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$sends ) {
				++$sends;
				return true;
			}
		);
		$id = $this->queue( 'd-fresh-claim' );
		$this->assertSame( 1, MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) ) );

		Dispatcher::run();

		$row = $this->row( $id );
		$this->assertSame( 0, $sends, 'Wiersz trzymany przez inny przebieg nie może zostać wysłany.' );
		$this->assertSame( QueueStatus::Sending->value, $row['status'], 'Świeże zajęcie musi przeżyć przebieg.' );
		$this->assertSame( 1, (int) $row['attempts'] );
	}

	public function test_a_failing_send_is_rescheduled_then_fails_with_a_readable_reason(): void {
		// Lekarz nie dostał maila, bo SMTP nie odpowiada. Odpowiedź na „czy wyszedł" musi być w logu.
		add_filter( 'pre_wp_mail', static fn () => false );
		$id = $this->queue( 'd-retry' );

		$startedAt = time();
		Dispatcher::run();
		$row = $this->row( $id );
		$this->assertSame( QueueStatus::Queued->value, $row['status'], 'Próba 1 => ponowienie.' );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertNotSame( '', (string) $row['last_error'], 'Powód musi być w logu także wtedy, gdy transport go nie podał.' );
		$this->assert_retry_delay( 60, $startedAt, $row );

		$this->make_due( $id );
		$startedAt = time();
		Dispatcher::run();
		$row = $this->row( $id );
		$this->assertSame( QueueStatus::Queued->value, $row['status'], 'Próba 2 => ponowienie.' );
		$this->assertSame( 2, (int) $row['attempts'] );
		$this->assert_retry_delay( 300, $startedAt, $row );

		$this->make_due( $id );
		Dispatcher::run();
		$row = $this->row( $id );
		$this->assertSame( QueueStatus::Failed->value, $row['status'], 'Trzecia próba jest ostatnia.' );
		$this->assertSame( 3, (int) $row['attempts'] );
		$this->assertNotSame( '', (string) $row['last_error'] );

		// Wiersz failed nie jest już gotowy do wysyłki: czwartej próby nie ma.
		$this->make_due( $id );
		Dispatcher::run();
		$this->assertSame( 3, (int) $this->row( $id )['attempts'] );
		$this->assertSame( QueueStatus::Failed->value, $this->row( $id )['status'] );
	}

	public function test_the_reason_reported_by_the_transport_lands_in_the_log(): void {
		// Wtyczka SMTP podnosi WP_Error na `wp_mail_failed`. Bez tego powodu redakcja widzi w logu
		// tylko „nie poszło" i nie ma jak odpowiedzieć lekarzowi, dlaczego nie dostał linku.
		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'SMTP nie odpowiada' ) );
				return false;
			}
		);
		$id = $this->queue( 'd-reason' );

		Dispatcher::run();

		$this->assertStringContainsString( 'SMTP nie odpowiada', (string) $this->row( $id )['last_error'] );
	}

	public function test_a_throwing_send_does_not_abort_the_run(): void {
		// Wyjątek z PHPMailera nie może zostawić wiersza w stanie sending i zabić całej paczki.
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new \RuntimeException( 'nie ma gniazda' );
			}
		);
		$first  = $this->queue( 'd-throw-1' );
		$second = $this->queue( 'd-throw-2' );

		Dispatcher::run();

		foreach ( array( $first, $second ) as $id ) {
			$row = $this->row( $id );
			$this->assertNotSame( QueueStatus::Sending->value, $row['status'] );
			$this->assertSame( 1, (int) $row['attempts'], 'Oba wiersze przeszły przez przebieg.' );
			$this->assertStringContainsString( 'nie ma gniazda', (string) $row['last_error'] );
		}
	}

	public function test_the_mail_leaves_as_html_and_the_content_type_is_restored(): void {
		$captured    = null;
		$contentType = null;
		add_filter(
			'pre_wp_mail',
			static function ( $null, $atts ) use ( &$captured, &$contentType ) {
				$captured    = $atts;
				$contentType = apply_filters( 'wp_mail_content_type', 'text/plain' );
				return true;
			},
			10,
			2
		);
		$this->queue( 'd-html' );

		Dispatcher::run();

		$this->assertIsArray( $captured );
		$this->assertStringContainsString( '<!DOCTYPE html>', (string) $captured['message'] );
		$this->assertSame( 'text/html', $contentType, 'W chwili wysyłki typ treści musi być HTML.' );
		$this->assertSame( 'text/plain', apply_filters( 'wp_mail_content_type', 'text/plain' ) );
	}

	public function test_the_content_type_filter_is_removed_even_when_the_send_throws(): void {
		// Filtr zostawiony po wyjątku zmieniłby typ treści mailom innych wtyczek w tym samym żądaniu.
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new \RuntimeException( 'nie ma gniazda' );
			}
		);
		$this->queue( 'd-html-throw' );

		Dispatcher::run();

		$this->assertSame( 'text/plain', apply_filters( 'wp_mail_content_type', 'text/plain' ) );
	}

	public function test_the_failure_listener_is_removed_even_when_the_send_throws(): void {
		// Druga połowa pary ze `finally`. Nasłuch zostawiony po wyjątku zapisywałby powód cudzej
		// porażki do domknięcia zakończonej już próby, którego nikt nie czyta.
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new \RuntimeException( 'nie ma gniazda' );
			}
		);
		$this->queue( 'd-listener-throw' );
		$this->assertFalse( has_action( 'wp_mail_failed' ), 'Stan wyjściowy: nikt nie nasłuchuje.' );

		Dispatcher::run();

		$this->assertFalse( has_action( 'wp_mail_failed' ) );
	}

	public function test_a_row_already_claimed_by_another_run_is_skipped(): void {
		// Między due() a claim() wiersz zajmuje drugi przebieg, więc nasz warunkowy UPDATE nie trafia
		// w nic. Lekarz nie może dostać dwóch linków potwierdzających: bez wysyłki, bez zapisu.
		$sends = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$sends ) {
				++$sends;
				return true;
			}
		);
		$id = $this->queue( 'd-lost' );
		add_filter( 'query', array( $this, 'lose_the_claim' ) );

		Dispatcher::run();

		remove_filter( 'query', array( $this, 'lose_the_claim' ) );
		$row = $this->row( $id );
		$this->assertSame( 0, $sends, 'Przegrane zajęcie nie wysyła maila.' );
		$this->assertSame( QueueStatus::Queued->value, $row['status'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertNull( $row['sent_at'] );
	}

	/**
	 * Filtr zapytań: zamienia warunkowy UPDATE zajęcia w no-op, czyli symuluje przegrany wyścig.
	 *
	 * @param string $query Zapytanie SQL.
	 */
	public function lose_the_claim( $query ): string {
		return false !== strpos( (string) $query, 'attempts = attempts + 1' ) ? 'SELECT 1' : (string) $query;
	}

	public function test_the_body_in_the_queue_stays_plain_text(): void {
		// Opakowanie HTML zakłada dispatcher przy wysyłce; log wysyłek ma pokazywać czytelny tekst.
		// Przedmiotem testu jest zapisana treść, nie transport: zaślepka zamiast prawdziwej wysyłki.
		add_filter( 'pre_wp_mail', static fn () => true );
		$id = $this->queue( 'd-plain', "Linia 1\nLinia 2 & reszta" );

		Dispatcher::run();

		$this->assertSame( "Linia 1\nLinia 2 & reszta", (string) $this->row( $id )['body'] );
		$this->assertStringNotContainsString( '<', (string) $this->row( $id )['body'] );
	}

	public function test_the_batch_size_filter_caps_the_run_and_ignores_a_useless_value(): void {
		// Przedmiotem testu jest rozmiar paczki, nie transport: zaślepka zamiast prawdziwej wysyłki.
		add_filter( 'pre_wp_mail', static fn () => true );
		$first  = $this->queue( 'd-batch-1' );
		$second = $this->queue( 'd-batch-2' );
		add_filter( 'pusers_mail_batch_size', static fn (): int => 1 );

		Dispatcher::run();

		$this->assertSame( QueueStatus::Sent->value, $this->row( $first )['status'] );
		$this->assertSame( QueueStatus::Queued->value, $this->row( $second )['status'], 'Paczka jednego wiersza kończy się po pierwszym.' );

		// Wartość ≤ 0 nie może zatrzymać kolejki: wtedy obowiązuje rozmiar domyślny.
		remove_all_filters( 'pusers_mail_batch_size' );
		add_filter( 'pusers_mail_batch_size', static fn (): int => 0 );

		Dispatcher::run();

		$this->assertSame( QueueStatus::Sent->value, $this->row( $second )['status'] );
	}

	public function test_the_batch_size_filter_cannot_raise_the_limit_above_the_ceiling(): void {
		// Paczka na całą tabelę skończyłaby się timeoutem PHP w jej środku, a to zostawia wiersz
		// w stanie `sending` — jedynym, który wymaga odzysku i grozi powtórną wysyłką.
		add_filter( 'pusers_mail_batch_size', static fn (): int => 100000 );
		add_filter( 'query', array( $this, 'capture_due_limit' ) );

		Dispatcher::run();

		remove_filter( 'query', array( $this, 'capture_due_limit' ) );
		$this->assertSame( Dispatcher::MAX_BATCH, $this->dueLimit );
	}

	/**
	 * Filtr zapytań: zapamiętuje LIMIT z zapytania o wiersze gotowe do wysyłki.
	 *
	 * @param string $query Zapytanie SQL.
	 */
	public function capture_due_limit( $query ): string {
		if ( 1 === preg_match( '/ORDER BY id ASC LIMIT (\d+)/', (string) $query, $m ) ) {
			$this->dueLimit = (int) $m[1];
		}

		return (string) $query;
	}

	public function test_the_stale_threshold_clears_the_transport_timeout(): void {
		// Próg odzysku MUSI być większy od własnych timeoutów PHPMailera. Przy równych liczbach wolna,
		// ale UDANA wysyłka jest uznawana za porzuconą w jej trakcie: przebieg A siedzi w gniazdku SMTP,
		// odzysk oddaje wiersz do kolejki, przebieg B wysyła go po raz drugi, a przekaźnik przyjmuje
		// w końcu obie wiadomości — lekarz dostaje dwa linki. Czas w odczycie gniazdka nie liczy się
		// na Uniksie do `max_execution_time`, więc PHP przebiegu A też nie ubije.
		//
		// Liczby czytamy z klas rdzenia, nie z literału: gdy PHPMailer podniesie swoje timeouty,
		// ten test ma zrobić się czerwony, zamiast cicho przestać pilnować czegokolwiek.
		if ( ! class_exists( \PHPMailer\PHPMailer\SMTP::class, false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		}
		$smtp   = new \PHPMailer\PHPMailer\SMTP();
		$mailer = new \PHPMailer\PHPMailer\PHPMailer();

		$this->assertGreaterThan( (int) $smtp->Timeout, Dispatcher::STALE_AFTER, 'Próg odzysku nie przebija timeoutu gniazdka SMTP.' );
		$this->assertGreaterThan( (int) $smtp->Timelimit, Dispatcher::STALE_AFTER, 'Próg odzysku nie przebija limitu na komendę SMTP.' );
		$this->assertGreaterThan( (int) $mailer->Timeout, Dispatcher::STALE_AFTER, 'Próg odzysku nie przebija timeoutu PHPMailera.' );
	}

	public function test_the_mail_carries_a_plain_text_alternative(): void {
		// Specyfikacja: maile trafiają w znacznej części na firmowe serwery z agresywnym filtrowaniem,
		// a tekst rzadziej wpada w filtry. Sam `text/html` bez części alternatywnej podnosi ocenę
		// spamową na dokładnie tych filtrach, a tekst dispatcher ma już w wierszu kolejki.
		$this->assertFalse( has_action( 'phpmailer_init' ), 'Stan wyjściowy: nikt nie dokłada wersji tekstowej.' );
		reset_phpmailer_instance();
		$id = $this->queue( 'd-alt' );

		Dispatcher::run();

		$row = $this->row( $id );
		$this->assertSame( QueueStatus::Sent->value, $row['status'], 'Mail nie wyszedł; powód z logu: ' . (string) $row['last_error'] );

		$phpmailer = tests_retrieve_phpmailer_instance();
		$this->assertNotFalse( $phpmailer );
		$sent = $phpmailer->get_sent();
		$this->assertIsObject( $sent );
		$this->assertStringContainsString( 'multipart/alternative', (string) $sent->header, 'Wiadomość musi mieć obie części.' );
		$this->assertStringContainsString( '<!DOCTYPE html>', (string) $sent->body, 'Część HTML zostaje bez zmian.' );
		$this->assertStringContainsString( 'Content-Type: text/plain', (string) $sent->body, 'Bez części tekstowej mail jest samym HTML-em.' );
		// Złamania linii obu części na spacje: część HTML ma między liniami `<br />`, więc tylko część
		// tekstowa daje po normalizacji dwie linie wiersza obok siebie.
		$this->assertStringContainsString(
			'Linia 1 Linia 2',
			str_replace( chr( 10 ), ' ', str_replace( chr( 13 ), '', (string) $sent->body ) ),
			'Część tekstowa musi nieść treść wiersza, nie HTML.'
		);
		$this->assertFalse( has_action( 'phpmailer_init' ), 'Szew musi zejść po tej jednej wysyłce.' );
	}

	public function test_the_plain_text_seam_is_removed_even_when_the_send_throws(): void {
		// Trzecia część tej samej pary ze `finally`. Szew zostawiony po wyjątku dopisywałby naszą
		// treść tekstową do maila CUDZEJ wtyczki wysłanego w tym samym żądaniu.
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new \RuntimeException( 'nie ma gniazda' );
			}
		);
		$this->queue( 'd-alt-throw' );
		$this->assertFalse( has_action( 'phpmailer_init' ), 'Stan wyjściowy: nikt nie dokłada wersji tekstowej.' );

		Dispatcher::run();

		$this->assertFalse( has_action( 'phpmailer_init' ) );
	}
}
