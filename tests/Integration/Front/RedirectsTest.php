<?php
/**
 * Testy przekierowań, odcięcia panelu i paska administracyjnego.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Front\LoginHandler;
use PortalUsers\Front\Redirects;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;
use RuntimeException;
use WP_UnitTestCase;
use WP_User;

final class RedirectsTest extends WP_UnitTestCase {

	/**
	 * Początek komunikatu wyjątku, którym szew testowy odtwarza koniec żądania.
	 */
	private const TERMINATED = 'Przekierowanie zakończyło żądanie: ';

	private string $redirected = '';

	private string $originalPagenow = '';

	private string $originalMethod = 'GET';

	/**
	 * @var callable
	 */
	private $catchRedirect;

	public function set_up(): void {
		parent::set_up();
		$this->redirected      = '';
		$this->originalPagenow = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		$this->originalMethod  = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

		// Szew testowy RZUCA WYJĄTEK, a nie tylko anuluje przekierowanie: w produkcji
		// `wp_safe_redirect()` jest zaraz potem kończone `exit`, więc szew, który pozwalałby
		// wykonywać się dalszym liniom, nie odróżniłby odcięcia panelu od jego braku — a to
		// jedyna rzecz, o którą pyta test handlera pod admin-post.php.
		$this->catchRedirect = function ( $location ) {
			$this->redirected = (string) $location;
			throw new RuntimeException( self::TERMINATED . $this->redirected );
		};
		add_filter( 'wp_redirect', $this->catchRedirect );

		Redirects::register();
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', $this->catchRedirect );
		$GLOBALS['pagenow']        = $this->originalPagenow;
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Tworzy strony wtyczki, zapisuje ustawienia i oddaje identyfikatory.
	 *
	 * @param array<string, int> $override Nadpisania identyfikatorów, np. `after_login_page_id => 0`.
	 * @return array<string, int>
	 */
	private function configure( array $override = array() ): array {
		$ids = array(
			'login_page_id'       => (int) self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => 'Logowanie',
				)
			),
			'register_page_id'    => (int) self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => 'Rejestracja',
				)
			),
			'reset_page_id'       => (int) self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => 'Odzyskiwanie hasla',
				)
			),
			'after_login_page_id' => (int) self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => 'Po zalogowaniu',
				)
			),
		);

		$ids = array_merge( $ids, $override );
		SettingsRepository::save( Settings::fromArray( $ids ) );

		return $ids;
	}

	/**
	 * Konto o wskazanej roli, ustawione jako zalogowane.
	 *
	 * @param string $role Rola konta.
	 */
	private function logIn( string $role ): WP_User {
		$id = (int) self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );

		return new WP_User( $id );
	}

	/**
	 * Otwiera ekran panelu i oddaje adres przekierowania (`''` = żądanie przeszło).
	 *
	 * Kolejność z `wp-admin/admin.php` odtwarzamy wywołaniem `block_admin()`, bo to właśnie jego
	 * woła `admin_init`; samo `do_action( 'admin_init' )` ściągnęłoby do testu sprawdzanie
	 * aktualizacji rdzenia i planowanie cronów, czyli hałas nie na temat.
	 *
	 * @param string $pagenow Plik panelu, który obsługuje żądanie (`$GLOBALS['pagenow']`).
	 */
	private function openAdmin( string $pagenow = 'index.php' ): string {
		$GLOBALS['pagenow'] = $pagenow;
		$this->redirected   = '';

		try {
			Redirects::block_admin();
		} catch ( RuntimeException $e ) {
			return $this->redirected;
		}

		return '';
	}

	public function test_a_plugin_handler_under_admin_post_still_runs_for_a_logged_in_non_editor(): void {
		// Review Focus 1. `wp-admin/admin-post.php` woła `do_action( 'admin_init' )` PRZED
		// rozdzieleniem akcji (komentarz rdzenia w `wp-admin/admin.php`: „It runs on admin-ajax.php
		// and admin-post.php as well"), a na admin-post.php stoi KAŻDY formularz tej wtyczki:
		// ustawienie hasła, rejestracja, logowanie, odzyskiwanie hasła. Odcięcie panelu bez wyjątku
		// kończyłoby te żądania przekierowaniem, czyli zabijałoby wtyczkę dla każdego lekarza —
		// po rejestracji to jest każde konto portalu.
		$this->configure();
		$this->logIn( 'subscriber' );

		$ran = 0;
		add_action(
			'admin_post_' . LoginHandler::ACTION,
			static function () use ( &$ran ): void {
				++$ran;
			}
		);
		$GLOBALS['pagenow']        = 'admin-post.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Przekierowanie z `block_admin()` rzuca tu wyjątkiem (szew odtwarza `exit`), więc
		// dalsze linie wykonują się WYŁĄCZNIE wtedy, gdy żądanie nie zostało odcięte.
		Redirects::block_admin();
		do_action( 'admin_post_' . LoginHandler::ACTION );

		$this->assertSame( 1, $ran, 'Handler wtyczki pod admin-post.php musi się wykonać dla zalogowanego nie-redaktora.' );
		$this->assertSame( '', $this->redirected, 'Żądania admin-post.php odcięcie panelu nie może przekierowywać.' );
	}

	public function test_an_ajax_request_from_a_non_editor_is_not_redirected(): void {
		// Druga droga, na której `admin_init` odpala się bez ekranu panelu: admin-ajax.php.
		$this->configure();
		$this->logIn( 'subscriber' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( '', $this->openAdmin( 'admin-ajax.php' ), 'Żądanie AJAX nie jest wejściem w panel.' );
	}

	/**
	 * @dataProvider editorialRoles
	 *
	 * @param string $role Rola z uprawnieniem do edycji treści.
	 */
	public function test_an_editor_reaches_wp_admin_and_keeps_the_admin_bar( string $role ): void {
		// Review Focus 5. Specyfikacja trzyma `wp-login.php` i `wp-admin` jako wejście ZAPASOWE
		// redakcji, więc przekierowanie albo ukryty pasek, który złapie redaktora, odcina redakcję
		// od pracy.
		$this->configure();
		$this->logIn( $role );

		$this->assertSame( '', $this->openAdmin(), 'Redakcja musi wejść w panel.' );
		$this->assertTrue( apply_filters( 'show_admin_bar', true ), 'Redakcja musi zachować pasek administracyjny.' );
	}

	/**
	 * Role, które mają `edit_posts`, czyli uprawnienie do edycji treści.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function editorialRoles(): array {
		return array(
			'administrator'  => array( 'administrator' ),
			'redaktor'       => array( 'editor' ),
			'autor'          => array( 'author' ),
			// Współpracownik ma `edit_posts`, więc jest redakcją. Reguła pisana po ROLI
			// („podzbiór subscribera") zamknęłaby mu panel — na tym pękł Plan 3.
			'wspolpracownik' => array( 'contributor' ),
		);
	}

	public function test_a_non_editor_opening_an_admin_screen_lands_on_the_after_login_page(): void {
		$ids = $this->configure();
		$this->logIn( 'subscriber' );

		$target = $this->openAdmin();

		$this->assertSame( get_permalink( $ids['after_login_page_id'] ), $target );
		$this->assertStringNotContainsString( 'wp-admin', $target, 'Odesłanie z panelu prowadzi na front.' );
	}

	public function test_a_non_editor_loses_the_admin_bar(): void {
		// Specyfikacja: „pasek administracyjny ukryty". Bez tego testu `hide_admin_bar()` mogłoby
		// oddawać wejście bez zmian, a suita i tak byłaby zielona.
		$this->configure();
		$this->logIn( 'subscriber' );

		$this->assertFalse( apply_filters( 'show_admin_bar', true ), 'Konto bez uprawnień redakcyjnych nie widzi paska.' );
	}

	public function test_the_after_login_page_falls_back_to_the_home_page_when_unset(): void {
		// Dwa przypadki braku: brak wskazania w ustawieniach i strona usunięta z panelu.
		$ids = $this->configure( array( 'after_login_page_id' => 0 ) );
		$this->assertFallsBackHome( 'bez wskazania w ustawieniach', $ids );

		// I to samo na realnym przekierowaniu: odwrót nie może wyjść pusty, bo puste
		// przekierowanie zostawia człowieka tam, skąd przyszedł, bez żadnej wskazówki.
		$this->logIn( 'subscriber' );
		$this->assertSame( home_url( '/' ), $this->openAdmin(), 'Odesłanie z panelu bez wskazanej strony idzie na stronę główną.' );

		$ids = $this->configure();
		wp_delete_post( $ids['after_login_page_id'], true );
		$this->assertFallsBackHome( 'strona usunięta z panelu', $ids );
	}

	/**
	 * Odwrót na stronę główną: niepusty, nie na stronę logowania (to pętla), na stronę główną.
	 *
	 * @param string             $case Opis przypadku w komunikacie asercji.
	 * @param array<string, int> $ids  Identyfikatory stron z `configure()`.
	 */
	private function assertFallsBackHome( string $case, array $ids ): void {
		$url = Redirects::after_login_url();

		$this->assertNotSame( '', $url, $case . ': puste przekierowanie nie prowadzi nigdzie.' );
		$this->assertNotSame( (string) get_permalink( $ids['login_page_id'] ), $url, $case . ': strona logowania to pętla.' );
		$this->assertSame( home_url( '/' ), $url, $case . ': odwrót idzie na stronę główną.' );
	}

	public function test_an_editor_logging_in_keeps_the_core_destination(): void {
		// Redaktor po zalogowaniu idzie do kokpitu, jak dotąd: wyrok rdzenia zostaje nietknięty.
		$this->configure();
		$editor    = $this->logIn( 'editor' );
		$dashboard = admin_url();
		$posts     = admin_url( 'edit.php' );

		$this->assertSame( $dashboard, Redirects::after_login( $dashboard, '', $editor ) );
		$this->assertSame( $posts, Redirects::after_login( $posts, $posts, $editor ) );
	}

	public function test_a_non_editor_logging_in_goes_to_the_indicated_page_not_the_dashboard(): void {
		// Rdzeń sam podstawia kontu bez `edit_posts` ekran `profile.php` — i to jest adres,
		// który ta reguła zastępuje stroną z ustawień.
		$ids  = $this->configure();
		$user = $this->logIn( 'subscriber' );

		$target = Redirects::after_login( admin_url( 'profile.php' ), '', $user );

		$this->assertSame( get_permalink( $ids['after_login_page_id'] ), $target );
		$this->assertStringNotContainsString( 'wp-admin', $target, 'Konto bez uprawnień redakcyjnych nie idzie do panelu.' );
	}

	public function test_a_requested_destination_pointing_outside_the_site_is_dropped(): void {
		$ids  = $this->configure();
		$user = $this->logIn( 'subscriber' );
		$away = 'https://zupelnie-inny-serwis.test/dalej';

		$outside = Redirects::after_login( $away, $away, $user );

		$this->assertStringNotContainsString( 'zupelnie-inny-serwis.test', $outside, 'Adres z żądania nie wyprowadza z witryny.' );
		$this->assertSame( get_permalink( $ids['after_login_page_id'] ), $outside );

		// Kontrast: adres z żądania wskazujący TĘ witrynę zostaje. Bez tego pole adresu
		// docelowego formularza logowania przestałoby działać dla każdego lekarza.
		$inside = (string) get_permalink( $ids['register_page_id'] );
		$this->assertSame( $inside, Redirects::after_login( $inside, $inside, $user ) );
	}

	public function test_the_core_login_and_lostpassword_urls_point_at_the_plugin_pages(): void {
		$ids = $this->configure();

		$this->assertSame( get_permalink( $ids['login_page_id'] ), wp_login_url() );
		$this->assertSame( get_permalink( $ids['reset_page_id'] ), wp_lostpassword_url() );
		$this->assertSame( get_permalink( $ids['register_page_id'] ), wp_registration_url() );

		// Bez wskazania strony zostaje adres RDZENIA, nigdy pusty łańcuch: pusty odnośnik
		// logowania w mailu odzyskiwania hasła jest odnośnikiem martwym.
		SettingsRepository::save( Settings::defaults() );
		$core = array(
			'logowanie'          => wp_login_url(),
			'odzyskiwanie hasła' => wp_lostpassword_url(),
			'rejestracja'        => wp_registration_url(),
		);
		foreach ( $core as $case => $url ) {
			$this->assertNotSame( '', $url, $case . ': pusty adres to martwy odnośnik.' );
			$this->assertStringContainsString( 'wp-login.php', $url, $case . ': adres rdzenia zostaje nietknięty.' );
		}
	}

	public function test_register_hooks_the_destination_the_panel_the_bar_and_the_core_urls(): void {
		// `set_up()` wywołało już `register()`; testy wołają metody wprost, więc bez tej asercji
		// brakująca linia `add_filter()` zostawiłaby całą suitę zieloną.
		$this->assertNotFalse( has_filter( 'login_redirect', array( Redirects::class, 'after_login' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( Redirects::class, 'block_admin' ) ) );
		$this->assertNotFalse( has_filter( 'show_admin_bar', array( Redirects::class, 'hide_admin_bar' ) ) );
		$this->assertNotFalse( has_filter( 'login_url', array( Redirects::class, 'login_url' ) ) );
		$this->assertNotFalse( has_filter( 'lostpassword_url', array( Redirects::class, 'lostpassword_url' ) ) );
		$this->assertNotFalse( has_filter( 'register_url', array( Redirects::class, 'register_url' ) ) );
	}

	public function test_the_redirects_register_under_the_features_hook_not_outside_the_dependency_check(): void {
		// Przekierowania NIE są zamkiem, więc nie powtarzają wyjątku bramy logowania i strażnika
		// sesji: gdy wtyczka bezczynnie czeka na ACF Pro, panel ma zachowywać się jak w czystym
		// WordPressie, a nie odsyłać ludzi regułami, których bezczynna wtyczka nie obsłuży.
		$this->assertNotFalse( has_action( 'pusers_register_features', array( Redirects::class, 'register' ) ) );

		remove_all_actions( 'admin_init' );
		remove_all_filters( 'show_admin_bar' );
		remove_all_filters( 'login_url' );
		add_filter( 'pusers_acf_pro_loaded', '__return_false' );

		Plugin::load_features();

		$this->assertFalse( has_action( 'admin_init', array( Redirects::class, 'block_admin' ) ), 'Bezczynna wtyczka nie odcina panelu.' );
		$this->assertFalse( has_filter( 'show_admin_bar', array( Redirects::class, 'hide_admin_bar' ) ) );
		$this->assertFalse( has_filter( 'login_url', array( Redirects::class, 'login_url' ) ) );
	}
}
