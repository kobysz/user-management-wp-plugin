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
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], 'klucz' );

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
		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], 'reset:' . $account['id'] );

		$this->assertTrue( CoreCapture::capture( null, $atts ), 'Pierwsza wiadomość ma zostać przechwycona.' );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );

		$this->assertNull( CoreCapture::capture( null, $atts ), 'Druga wiadomość jedzie już bez uzbrojenia.' );
		$this->assertSame( 1, MailQueueRepository::count_by_status( '' ), 'Rozbrojone przechwytywanie nie dokłada wierszy.' );
	}

	public function test_the_core_password_change_notification_is_left_alone(): void {
		// Specyfikacja każe zostawić ten mail standardowym, WordPressowym. Idzie poza uzbrojeniem,
		// więc nie jest ani przechwytywany, ani logowany — a uzbrojenie na inne konto go nie tyka,
		// bo jedzie na adres administratora, nie na adres uzbrojonego konta.
		$account = $this->account();

		wp_password_change_notification( get_userdata( $account['id'] ) );

		$this->assertCount( 1, $this->mails, 'Powiadomienie o zmianie hasła musi dojść do transportu poczty.' );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ), 'Powiadomienia o zmianie hasła nie wolno zapisać w kolejce.' );

		CoreCapture::arm( MailKind::PasswordReset, $account['id'], $account['email'], 'reset:' . $account['id'] );
		wp_password_change_notification( get_userdata( $account['id'] ) );

		$this->assertCount( 2, $this->mails, 'Uzbrojenie na inne konto nie może zabrać tego powiadomienia.' );
		$this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
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
