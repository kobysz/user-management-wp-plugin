<?php
/**
 * Testy przechwytywania maila rdzeniowego na `pre_wp_mail`: trafienie do kolejki, zapora odbiorcy,
 * rozbrojenie po jednej wiadomości i nietykalność powiadomienia o zmianie hasła.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Mail;

use PortalUsers\Dependencies;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;
use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Front\PasswordReset;
use PortalUsers\Mail\CoreCapture;
use PortalUsers\Mail\MailQueue;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\MailTemplateRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;
use WP_UnitTestCase;

final class CoreCaptureTest extends WP_UnitTestCase {

	/**
	 * Wiadomości, które doszłyby do transportu poczty (szew testowy ZA naszym przechwytywaniem).
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * @var callable
	 */
	private $captureMail;

	/**
	 * Numer kolejnego konta; wspólny dla klasy, żeby loginy nie powtarzały się między testami.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();

		// Szew testowy stoi na tym samym priorytecie, co nasze przechwytywanie, ale rejestruje się
		// PÓŹNIEJ (nasze jedzie z `Plugin::boot()`). `pre_wp_mail` woła WSZYSTKIE callbacki, więc
		// o tym, czy wiadomość została przejęta, rozstrzyga wartość na wejściu, nie samo wywołanie.
		$this->captureMail = function ( $short, $atts ) {
			if ( null !== $short ) {
				return $short;
			}
			$this->mails[] = (array) $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->captureMail, 10, 2 );

		// Treść maila odzyskiwania podmieniają filtry rdzenia podpięte w `register()`; bez nich
		// nic się nie uzbroi i do kolejki nie trafi żaden wiersz.
		PasswordReset::register();
		$this->configure();
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', $this->captureMail, 10 );
		// Uzbrojenie żyje jedno żądanie, a cała suita jest JEDNYM żądaniem: uzbrojenie zostawione
		// przez test przechwyciłoby mail następnego.
		CoreCapture::disarm();
		parent::tear_down();
	}

	/**
	 * Strona odzyskiwania hasła w ustawieniach; bez niej filtry treści oddają treść rdzenia.
	 */
	private function configure(): void {
		$page = (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Odzyskiwanie hasła',
				'post_content' => '[' . PasswordReset::SHORTCODE . ']',
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'reset_page_id' => $page ) ) );
	}

	/**
	 * Konto lekarza.
	 *
	 * @return array{id: int, login: string, email: string}
	 */
	private function account(): array {
		++self::$sequence;
		$login = 'przechwyt' . self::$sequence;
		$email = $login . '@przyklad.test';
		$id    = (int) self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $email,
			)
		);

		return array(
			'id'    => $id,
			'login' => $login,
			'email' => $email,
		);
	}

	/**
	 * Wiersze kolejki gotowe do wysyłki, najstarszy naprzód.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function queued(): array {
		return MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 20 );
	}

	/**
	 * @return list<object> Wiersze logu zdarzeń, od najstarszego.
	 */
	private function logRows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
	}

	/**
	 * Zawartość kontekstu wiersza logu.
	 *
	 * @param object $row Wiersz logu.
	 * @return array<string, mixed>
	 */
	private function contextOf( object $row ): array {
		$context = json_decode( (string) ( $row->context ?? '' ), true );

		return is_array( $context ) ? $context : array();
	}

	public function test_the_recovery_mail_lands_in_the_queue_with_the_template_content(): void {
		$account = $this->account();
		MailTemplateRepository::save( MailKind::PasswordReset, new MailTemplate( 'Hasło dla {login}', 'Otwórz: {link_hasla}' ) );

		$this->assertTrue( retrieve_password( $account['email'] ) );

		// Rdzeń swojego maila nie wysłał: wyszedł z `wp_mail()` na naszym filtrze.
		$this->assertSame( array(), $this->mails, 'Mail rdzenia nie może dojść do transportu poczty.' );

		$rows = $this->queued();
		$this->assertCount( 1, $rows, 'Mail odzyskiwania ma zostawić dokładnie jeden wiersz w kolejce.' );
		$row = $rows[0];
		$this->assertSame( MailKind::PasswordReset->value, $row['kind'] );
		$this->assertSame( $account['id'], (int) $row['user_id'], 'Wiersz nie wskazuje konta, którego mail dotyczy.' );
		$this->assertSame( $account['email'], $row['recipient'] );
		$this->assertSame( QueueStatus::Queued->value, $row['status'] );
		$this->assertSame( 'Hasło dla ' . $account['login'], $row['subject'], 'Temat nie pochodzi z szablonu.' );
		$this->assertStringContainsString( PasswordReset::KEY_ARG, (string) $row['body'], 'Treść z szablonu nie niesie odnośnika z kluczem.' );
		// Wysyłka od razu, nie za minutę: na odnośnik do ustawienia hasła człowiek czeka przy ekranie.
		$this->assertNotFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ), 'Wiersz nie obudził dispatchera.' );
	}

	public function test_a_second_request_for_the_same_account_gets_its_own_row(): void {
		// Klucz odporności niesie skrót KLUCZA rdzenia, a rdzeń generuje go za każdym razem nowy:
		// klucz bez niego zablokowałby drugie żądanie na zawsze, a każde żądanie ma własny odnośnik.
		$account = $this->account();

		$this->assertTrue( retrieve_password( $account['email'] ) );
		$this->assertTrue( retrieve_password( $account['email'] ) );

		$this->assertSame( 2, MailQueueRepository::count_by_status( '' ), 'Drugie żądanie nie dostało własnego wiersza.' );
	}

	public function test_a_foreign_plugin_mail_sent_while_armed_is_not_captured(): void {
		// Przechwycenie zabrałoby cudzej wtyczce wysyłkę i zapisało jej treść w naszym logu wysyłek.
		$account = $this->account();
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], 'klucz', 'Treść maila.' );

		$this->assertNull(
			CoreCapture::capture(
				null,
				array(
					'to'      => 'ktos@inny.test',
					'subject' => 'Faktura',
					'message' => 'Treść obcej wtyczki.',
				)
			),
			'Wiadomość do innego adresu musi wyjść z filtra nietknięta.'
		);
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ), 'Cudza wiadomość nie może trafić do naszej kolejki.' );
	}

	public function test_a_mail_to_the_armed_address_with_another_message_is_not_captured(): void {
		// Zakres ADRESEM nie wystarcza: uzbrojenie, które przeżyło (ścieżka `wp-login.php` nie ma
		// naszego `finally`), zabrałoby następną wiadomość WordPressa na ten sam adres — a przy
		// kluczu odporności już obecnym w bazie PORZUCIŁOby ją, oddając rdzeniowi „wysłano".
		$account = $this->account();
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], 'klucz', 'Treść maila.' );

		$this->assertNull(
			CoreCapture::capture(
				null,
				array(
					'to'      => $account['email'],
					'subject' => 'Zupełnie inny mail',
					'message' => 'Zupełnie inna treść.',
				)
			),
			'Przechwytywanie musi być zakresowane wiadomością, nie samym adresem.'
		);
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
		$this->assertSame( array(), $this->logRows(), 'Wiadomości, której nie dotykamy, nie wolno logować.' );
	}

	public function test_a_mail_the_queue_refuses_leaves_a_row_in_the_log(): void {
		// Odmowa kolejki znaczy mail PORZUCONY, a odpowiedź dla człowieka musi zostać ta sama
		// (inaczej formularz stałby się czytnikiem „czy ten adres ma konto"). Wiersz w logu jest
		// więc jedynym śladem: rdzeń zgłaszał awarię wysyłki `retrieve_password_email_failure`,
		// a przejęta wysyłka zwraca `true` i tej drogi już nie ma.
		$account = $this->account();
		$atts    = array(
			'to'      => $account['email'],
			'subject' => 'Ustawienie nowego hasła',
			'message' => 'Treść maila.',
		);
		$dedupe  = 'reset:' . $account['id'];

		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], $dedupe, 'Treść maila.' );
		$this->assertTrue( CoreCapture::capture( null, $atts ) );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
		$this->assertSame( array(), $this->logRows(), 'Udane zakolejkowanie nie dopisuje wiersza do logu zdarzeń.' );

		// TEN SAM klucz odporności: kolejka wiersza nie przyjmie.
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], $dedupe, 'Treść maila.' );
		$this->assertTrue( CoreCapture::capture( null, $atts ), 'Odmowa kolejki nie może zmienić wyniku dla rdzenia.' );

		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ), 'Odmowa nie może dołożyć wiersza do kolejki.' );
		$rows = $this->logRows();
		$this->assertCount( 1, $rows, 'Porzucony mail musi zostawić dokładnie jeden wiersz w logu.' );
		$this->assertSame( CoreCapture::LOG_ACTION_NOT_QUEUED, $rows[0]->action );
		$this->assertSame( $account['id'], (int) $rows[0]->subject_user_id, 'Wiersz nie wskazuje konta, którego mail przepadł.' );
		$this->assertSame( MailKind::PasswordReset->value, $this->contextOf( $rows[0] )['kind'] ?? '' );
		// Adresu jawnego w logu NIE MA NIGDZIE (ta sama higiena, co w logu żądań o link).
		$this->assertStringNotContainsString( $account['email'], (string) $rows[0]->context );
	}

	public function test_nothing_is_captured_when_not_armed(): void {
		$atts = array(
			'to'      => 'ktos@inny.test',
			'subject' => 'Faktura',
			'message' => 'Treść obcej wtyczki.',
		);

		$this->assertNull( CoreCapture::capture( null, $atts ) );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
	}

	public function test_the_capture_disarms_itself_after_one_mail(): void {
		// Uzbrojenie, które zostaje, przechwyciłoby następny mail WordPressa w tym żądaniu.
		$account = $this->account();
		$atts    = array(
			'to'      => $account['email'],
			'subject' => 'Ustawienie nowego hasła',
			'message' => 'Treść maila.',
		);
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], 'reset:' . $account['id'], 'Treść maila.' );

		$this->assertTrue( CoreCapture::capture( null, $atts ), 'Pierwsza wiadomość ma zostać przechwycona.' );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );

		$this->assertNull( CoreCapture::capture( null, $atts ), 'Druga wiadomość jedzie już bez uzbrojenia.' );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ), 'Rozbrojone przechwytywanie nie dokłada wierszy.' );
	}

	public function test_the_core_password_change_notification_is_left_alone(): void {
		// Specyfikacja każe zostawić ten mail standardowym, WordPressowym — to jedyna wiadomość,
		// którą ten mechanizm mógłby połknąć przez przypadek, bo powstaje na tej samej ścieżce.
		$account = $this->account();
		$admin   = (string) get_option( 'admin_email' );
		$this->assertNotSame( strtolower( $admin ), strtolower( $account['email'] ), 'Test wymaga konta INNEGO niż administrator.' );

		// 1. Poza uzbrojeniem: ani przechwycenia, ani wiersza w logu wysyłek.
		wp_password_change_notification( get_userdata( $account['id'] ) );

		$this->assertCount( 1, $this->mails, 'Powiadomienie o zmianie hasła musi dojść do transportu poczty.' );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ), 'Powiadomienia o zmianie hasła nie wolno zapisać w kolejce.' );

		// 2. Uzbrojenie na adres KONTA: powiadomienie jedzie na adres administratora, więc odcina
		// je zapora odbiorcy.
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], 'reset:' . $account['id'], 'Treść maila odzyskiwania.' );
		wp_password_change_notification( get_userdata( $account['id'] ) );

		$this->assertCount( 2, $this->mails, 'Uzbrojenie na adres konta nie może zabrać tego powiadomienia.' );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );

		// 3. Uzbrojenie na adres ADMINISTRATORA, czyli DOKŁADNIE na adres, na który to powiadomienie
		// idzie. Tu zapora odbiorcy już nie pomaga i broni sama zapora treści: powiadomienie nie ma
		// treści, którą uzbrojenie wyrenderowało. To jest właściwy dowód, że ten mail zostaje
		// rdzeniowy — zakres adresem by go połknął.
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $admin, 'reset:' . $account['id'] . ':admin', 'Treść maila odzyskiwania.' );
		wp_password_change_notification( get_userdata( $account['id'] ) );

		$this->assertCount( 3, $this->mails, 'Powiadomienie o zmianie hasła zostało połknięte przez przechwytywanie.' );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
		$this->assertSame( array(), $this->logRows(), 'Rdzeniowego powiadomienia nie wolno nigdzie logować.' );
	}

	public function test_the_capture_is_registered_without_acf(): void {
		// Odzyskiwanie hasła musi działać także wtedy, gdy ACF Pro jest wyłączone: `register()`
		// tej klasy jedzie z `Plugin::boot()`, nie przez hook zależny od zależności.
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );
		remove_filter( 'pre_wp_mail', array( CoreCapture::class, 'capture' ), 10 );
		$this->assertFalse( Dependencies::satisfied(), 'Test ma działać przy NIEspełnionych zależnościach.' );
		$this->assertFalse( has_filter( 'pre_wp_mail', array( CoreCapture::class, 'capture' ) ), 'Przechwytywanie miało być zdjęte przed próbą.' );

		Plugin::boot( Plugin::plugin_file() );
		Plugin::load_features();

		$this->assertFalse(
			has_action( 'pusers_register_features', array( CoreCapture::class, 'register' ) ),
			'Przechwytywanie nie może jechać przez hook zależny od ACF Pro.'
		);
		// Priorytet DOKŁADNIE 10: wyżej wyprzedzałby filtr obcej wtyczki chcącej ZABLOKOWAĆ wysyłkę,
		// a wtedy zakolejkowalibyśmy mail, którego ta wtyczka nie przepuściła.
		$this->assertSame( 10, has_filter( 'pre_wp_mail', array( CoreCapture::class, 'capture' ) ) );
	}
}
