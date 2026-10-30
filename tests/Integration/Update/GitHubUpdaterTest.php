<?php
/**
 * Testy aktualizatora opartego o wydania GitHuba.
 *
 * Odpowiedzi GitHuba podstawia filtr `pre_http_request`, który liczy też wywołania —
 * bufor (pozytywny i negatywny) jest ochroną przed limitem zapytań GitHuba, więc liczba
 * wywołań jest zachowaniem, nie szczegółem.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Update;

use PortalUsers\Plugin;
use PortalUsers\Update\GitHubUpdater;
use WP_Error;
use WP_UnitTestCase;

final class GitHubUpdaterTest extends WP_UnitTestCase {

	private const CACHE_KEY = 'pusers_update_latest';

	private int $calls = 0;

	/**
	 * Ścieżka pliku-atrapy wtyczki, gdy test udaje podmianę plików w locie.
	 */
	private string $fixture = '';

	public function set_up(): void {
		parent::set_up();
		$this->calls = 0;
		delete_transient( self::CACHE_KEY );
	}

	public function tear_down(): void {
		try {
			remove_all_filters( 'pre_http_request' );
			delete_transient( self::CACHE_KEY );
			if ( '' !== $this->fixture ) {
				@unlink( $this->fixture );
				$this->fixture = '';
				Plugin::boot( WP_PLUGIN_DIR . '/portal-users/portal-users.php' );
			}
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * Treść odpowiedzi API dla wydania.
	 *
	 * @return array<string, mixed>
	 */
	private function release( string $tag, bool $with_asset = true ): array {
		$assets = $with_asset
			? array( array( 'name' => 'portal-users.zip', 'browser_download_url' => 'https://example.test/portal-users.zip' ) )
			: array( array( 'name' => 'inny-plik.zip', 'browser_download_url' => 'https://example.test/inny-plik.zip' ) );

		return array(
			'tag_name' => $tag,
			'body'     => "### Naprawione\n- coś",
			'html_url' => 'https://example.test/releases/' . $tag,
			'assets'   => $assets,
		);
	}

	/**
	 * Podstawia odpowiedź HTTP i liczy wywołania.
	 *
	 * @param array<string, mixed>|string $body   Treść odpowiedzi; string trafia do ciała bez kodowania.
	 * @param int                         $status Kod odpowiedzi HTTP.
	 */
	private function respond( $body, int $status = 200 ): void {
		add_filter(
			'pre_http_request',
			function () use ( $body, $status ) {
				++$this->calls;

				return array(
					'headers'  => array(),
					'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => 200 === $status ? 'OK' : 'Error',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	private function respond_with_error(): void {
		add_filter(
			'pre_http_request',
			function () {
				++$this->calls;

				return new WP_Error( 'http_request_failed', 'Brak połączenia' );
			}
		);
	}

	private function basename(): string {
		return plugin_basename( Plugin::plugin_file() );
	}

	private function installed(): string {
		return Plugin::version( true );
	}

	public function test_injects_update_when_release_is_newer(): void {
		$this->respond( $this->release( 'v9.9.9' ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$entry = $result->response[ $this->basename() ] ?? null;
		$this->assertIsObject( $entry, 'Nowsze wydanie musi trafić do `response`.' );
		$this->assertSame( '9.9.9', $entry->new_version, 'Wiodące „v" z tagu jest zdejmowane.' );
		$this->assertSame( 'https://example.test/portal-users.zip', $entry->package );
		$this->assertSame( $this->basename(), $entry->plugin );
		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->no_update ?? array() ) );
	}

	public function test_registers_no_update_entry_when_up_to_date(): void {
		// Wpis w `no_update` jest obowiązkowy dla wtyczek spoza wordpress.org: bez obecności
		// w `response` LUB `no_update` rdzeń uznaje wtyczkę za nieobsługującą aktualizacji
		// i ukrywa przełącznik „Włącz automatyczne aktualizacje".
		$this->respond( $this->release( 'v' . $this->installed() ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$entry = $result->no_update[ $this->basename() ] ?? null;
		$this->assertIsObject( $entry, 'Wersja aktualna musi trafić do `no_update`.' );
		$this->assertSame( $this->installed(), $entry->new_version );
		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
	}

	public function test_older_release_lands_in_no_update(): void {
		$this->respond( $this->release( 'v0.0.1' ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayHasKey( $this->basename(), (array) ( $result->no_update ?? array() ) );
		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
	}

	public function test_clears_stale_response_entry_when_up_to_date(): void {
		// Nieaktualne „dostępna aktualizacja" potrafi przeżyć w transiencie udaną aktualizację.
		$this->respond( $this->release( 'v' . $this->installed() ) );
		$transient           = new \stdClass();
		$transient->response = array( $this->basename() => (object) array( 'new_version' => '9.9.9' ) );

		$result = ( new GitHubUpdater() )->inject_update( $transient );

		$this->assertArrayNotHasKey( $this->basename(), $result->response );
		$this->assertArrayHasKey( $this->basename(), $result->no_update );
	}

	public function test_clears_stale_no_update_entry_when_newer_exists(): void {
		$this->respond( $this->release( 'v9.9.9' ) );
		$transient            = new \stdClass();
		$transient->no_update = array( $this->basename() => (object) array( 'new_version' => $this->installed() ) );

		$result = ( new GitHubUpdater() )->inject_update( $transient );

		$this->assertArrayNotHasKey( $this->basename(), $result->no_update );
		$this->assertArrayHasKey( $this->basename(), $result->response );
	}

	public function test_no_phantom_update_when_files_were_swapped_mid_request(): void {
		// WordPress przelicza aktualizacje w tym samym żądaniu, w którym podmienił pliki, mając
		// w pamięci wersję sprzed podmiany. Odczyt musi iść świeżo z dysku.
		$this->fixture = get_temp_dir() . 'pusers-swap-' . wp_generate_password( 8, false ) . '.php';
		file_put_contents( $this->fixture, "<?php\n/**\n * Plugin Name: Atrapa\n * Version: 0.0.1\n */\n" );
		Plugin::boot( $this->fixture );
		$this->assertSame( '0.0.1', Plugin::version(), 'Wersja sprzed podmiany ma wejść do pamięci procesu.' );

		// Podmiana plików w locie: na dysku jest już nowa wersja, w pamięci stara.
		file_put_contents( $this->fixture, "<?php\n/**\n * Plugin Name: Atrapa\n * Version: 9.9.9\n */\n" );
		$this->respond( $this->release( 'v9.9.9' ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayNotHasKey(
			plugin_basename( $this->fixture ),
			(array) ( $result->response ?? array() ),
			'Po podmianie plików wtyczka jest aktualna — zgłoszenie aktualizacji byłoby widmem.'
		);
		$this->assertArrayHasKey( plugin_basename( $this->fixture ), (array) ( $result->no_update ?? array() ) );
	}

	public function test_non_semver_tag_is_ignored(): void {
		// `version_compare( 'release-2', '0.1.0', '>' )` jest prawdą, więc tag pomyłkowy
		// skierowałby wszystkie portale na przypadkowy asset.
		$this->respond( $this->release( 'release-2' ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->no_update ?? array() ) );
	}

	public function test_two_segment_tag_is_ignored(): void {
		$this->respond( $this->release( 'v0.2' ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
	}

	public function test_cached_payload_of_unknown_shape_is_ignored(): void {
		// Transient zapisany starszą wersją wtyczki ma kształt sprzed zmiany; czytanie
		// brakującego klucza kończyłoby się ostrzeżeniem w żądaniu panelu.
		set_transient( self::CACHE_KEY, array( 'version' => '9.9.9' ), HOUR_IN_SECONDS );
		$this->respond( $this->release( 'v' . $this->installed() ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertSame( 1, $this->calls, 'Wpis buforowy o nieznanym kształcie ma być traktowany jak brak buforu.' );
		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
		$this->assertArrayHasKey( $this->basename(), (array) ( $result->no_update ?? array() ) );
	}

	public function test_release_without_matching_asset_is_ignored(): void {
		$this->respond( $this->release( 'v9.9.9', false ) );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->no_update ?? array() ) );
	}

	public function test_http_error_status_leaves_the_transient_untouched(): void {
		$this->respond( 'rate limit exceeded', 403 );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->no_update ?? array() ) );
	}

	public function test_wp_error_leaves_the_transient_untouched(): void {
		$this->respond_with_error();

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
	}

	public function test_negative_cache_prevents_second_http_call(): void {
		$this->respond( 'rate limit exceeded', 403 );
		$updater = new GitHubUpdater();

		$updater->inject_update( new \stdClass() );
		$updater->inject_update( new \stdClass() );

		$this->assertSame( 1, $this->calls, 'Porażka ma być zapamiętana — limit zapytań GitHuba to 60 na godzinę na adres.' );
	}

	public function test_successful_payload_prevents_second_http_call(): void {
		$this->respond( $this->release( 'v9.9.9' ) );
		$updater = new GitHubUpdater();

		$updater->inject_update( new \stdClass() );
		$updater->inject_update( new \stdClass() );

		$this->assertSame( 1, $this->calls );
	}

	public function test_body_that_is_not_json_is_ignored(): void {
		$this->respond( '<html>GitHub</html>' );

		$result = ( new GitHubUpdater() )->inject_update( new \stdClass() );

		$this->assertArrayNotHasKey( $this->basename(), (array) ( $result->response ?? array() ) );
	}

	public function test_non_object_transient_is_returned_unchanged(): void {
		$this->respond( $this->release( 'v9.9.9' ) );

		$this->assertFalse( ( new GitHubUpdater() )->inject_update( false ) );
	}

	public function test_foreign_object_transient_is_returned_unchanged(): void {
		// Rdzeń podaje tu `stdClass`; obiekt obcej klasy przepuszczamy nietknięty, zamiast
		// dopisywać właściwości dynamiczne do cudzego obiektu o nieznanym kontrakcie.
		$this->respond( $this->release( 'v9.9.9' ) );
		$foreign = new \ArrayObject();

		$result = ( new GitHubUpdater() )->inject_update( $foreign );

		$this->assertSame( $foreign, $result );
		$this->assertObjectNotHasProperty( 'response', $result );
	}

	public function test_plugins_api_returns_details_for_our_slug(): void {
		$this->respond( $this->release( 'v9.9.9' ) );
		$slug = dirname( $this->basename() );

		$result = ( new GitHubUpdater() )->plugins_api( false, 'plugin_information', (object) array( 'slug' => $slug ) );

		$this->assertIsObject( $result );
		$this->assertSame( $slug, $result->slug );
		$this->assertSame( '9.9.9', $result->version );
		$this->assertSame( 'https://example.test/portal-users.zip', $result->download_link );
		$this->assertStringContainsString( 'Naprawione', $result->sections['changelog'] );
	}

	public function test_plugins_api_passes_through_for_other_action(): void {
		$this->respond( $this->release( 'v9.9.9' ) );

		$result = ( new GitHubUpdater() )->plugins_api( false, 'query_plugins', (object) array( 'slug' => dirname( $this->basename() ) ) );

		$this->assertFalse( $result );
		$this->assertSame( 0, $this->calls, 'Obca akcja nie może nawet pytać GitHuba.' );
	}

	public function test_plugins_api_passes_through_for_other_slug(): void {
		$this->respond( $this->release( 'v9.9.9' ) );

		$result = ( new GitHubUpdater() )->plugins_api( false, 'plugin_information', (object) array( 'slug' => 'inna-wtyczka' ) );

		$this->assertFalse( $result );
		$this->assertSame( 0, $this->calls );
	}
}
