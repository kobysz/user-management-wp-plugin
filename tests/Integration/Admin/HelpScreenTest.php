<?php
/**
 * Testy ekranu pomocy i panelu listy startowej.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Account\Backfill;
use PortalUsers\Admin\Help\Applications;
use PortalUsers\Admin\Help\FormChanges;
use PortalUsers\Admin\Help\Messages;
use PortalUsers\Admin\Help\SetupGuide;
use PortalUsers\Admin\HelpScreen;
use PortalUsers\Admin\IssueMessages;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Config\SettingsIssue;
use PortalUsers\Domain\Config\SetupStepKey;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Tests\Support\ConfigurationDoc;
use WP_UnitTestCase;
use WPDieException;

final class HelpScreenTest extends WP_UnitTestCase {

	/**
	 * Stan globalnych hooków admin_menu sprzed testu.
	 *
	 * @var mixed
	 */
	private $savedAdminMenuHooks;

	/**
	 * Stan globalnego $submenu sprzed testu.
	 *
	 * @var mixed
	 */
	private $savedSubmenu;

	/**
	 * Rola domyślna sprzed testu.
	 *
	 * @var string
	 */
	private string $originalRole = 'subscriber';

	public function set_up(): void {
		parent::set_up();
		$this->savedAdminMenuHooks = isset( $GLOBALS['wp_filter']['admin_menu'] ) ? clone $GLOBALS['wp_filter']['admin_menu'] : null;
		$this->savedSubmenu        = $GLOBALS['submenu'] ?? null;
		$this->originalRole        = (string) get_option( 'default_role' );
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_pro_loaded' );
		delete_option( OptionStore::OPTION_DEPLOYED_AT );
		Backfill::forget_count();
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_pro_loaded' );
		update_option( 'default_role', $this->originalRole );
		Backfill::forget_count();
		if ( null === $this->savedAdminMenuHooks ) {
			unset( $GLOBALS['wp_filter']['admin_menu'] );
		} else {
			$GLOBALS['wp_filter']['admin_menu'] = $this->savedAdminMenuHooks;
		}
		if ( null === $this->savedSubmenu ) {
			unset( $GLOBALS['submenu'] );
		} else {
			$GLOBALS['submenu'] = $this->savedSubmenu;
		}
		parent::tear_down();
	}

	/**
	 * Zapisuje ustawienia, zachowując to, co już zapisane.
	 *
	 * @param array<string, mixed> $values Wartości do nadpisania.
	 */
	private function withSettings( array $values ): void {
		SettingsRepository::save( Settings::fromArray( array_merge( SettingsRepository::get()->toArray(), $values ) ) );
	}

	/**
	 * Konfiguruje grupę ACF z jednym polem tekstowym i wskazuje ją w ustawieniach.
	 */
	private function withSource(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'Numer PWZ',
					'type'  => 'text',
				),
			)
		);
		$this->withSettings( array( 'acf_group_key' => 'group_abc' ) );
	}

	/**
	 * Zapisuje jedną profesję bez numeru uprawnień.
	 */
	private function withProfession(): void {
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'             => 'lekarz',
						'label'           => 'Lekarz',
						'credential_type' => 'none',
					),
				)
			)
		);
	}

	/**
	 * Tworzy opublikowaną stronę.
	 */
	private function page(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Doprowadza konfigurację do stanu skończonego: kroki od pierwszego do szóstego zrobione.
	 */
	private function fullyConfigured(): void {
		// ACF Pro jest licencjonowany i nie ma go w środowisku testowym; filtr `pusers_acf_pro_loaded`
		// jest szwem, przez który Dependencies da się opowiedzieć. Bez niego „pełna konfiguracja”
		// nie jest pełna, bo zależność wtyczki nie jest spełniona.
		add_filter( 'pusers_acf_pro_loaded', '__return_true' );
		$this->withSource();
		$this->withProfession();
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'visible' ) ) ) );
		$this->withSettings(
			array(
				'login_page_id'       => $this->page(),
				'register_page_id'    => $this->page(),
				'reset_page_id'       => $this->page(),
				'after_login_page_id' => $this->page(),
				'notify_emails'       => array( 'redakcja@example.test' ),
				'rejection_reasons'   => array( 'Numer nie zgadza się z rejestrem' ),
			)
		);
	}

	/**
	 * Tworzy konta bez stanu sprzed wdrożenia.
	 */
	private function statelessAccounts( int $count ): void {
		$deployed = time() - 5 * DAY_IN_SECONDS;
		OptionStore::write( OptionStore::OPTION_DEPLOYED_AT, array( Backfill::KEY_AT => $deployed ) );
		for ( $i = 0; $i < $count; $i++ ) {
			self::factory()->user->create( array( 'user_registered' => gmdate( 'Y-m-d H:i:s', $deployed - DAY_IN_SECONDS ) ) );
		}
		Backfill::forget_count();
	}

	private function renderAsAdmin(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		HelpScreen::render();

		return (string) ob_get_clean();
	}

	public function test_ekran_wymaga_uprawnienia(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		ob_start();
		try {
			HelpScreen::render();
		} finally {
			ob_get_clean();
		}
	}

	public function test_bez_acf_pro_ekran_sie_renderuje_i_mowi_ze_nie_moze_sprawdzic(): void {
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'Portal: pomoc', $html, 'Bez ACF Pro to jedyny ekran wtyczki, jaki administrator widzi — musi się wyrenderować.' );
		$this->assertStringContainsString( 'Nie można sprawdzić', $html, 'Kroki zależne od ACF nie są ani zrobione, ani niezrobione, gdy nie ma czym ich zmierzyć.' );
		$this->assertStringContainsString( 'ACF Pro', $html, 'Ekran ma powiedzieć, czego brakuje.' );
	}

	public function test_lista_startowa_wypisuje_kroki_w_kolejnosci_dokumentu(): void {
		$this->statelessAccounts( 2 );
		$html = $this->renderAsAdmin();

		$previous = -1;
		foreach ( ConfigurationDoc::STEPS as $number => $key ) {
			$position = strpos( $html, HelpScreen::title( $key ) );
			$this->assertNotFalse( $position, 'Krok ' . $number . ' nie pokazał się na ekranie.' );
			$this->assertGreaterThan( $previous, $position, 'Krok ' . $number . ' stoi na ekranie przed krokiem poprzednim.' );
			$previous = $position;
		}
	}

	public function test_tytul_kazdego_kroku_jest_naglowkiem_z_dokumentu(): void {
		$headings = ConfigurationDoc::headings();

		foreach ( ConfigurationDoc::STEPS as $number => $key ) {
			$this->assertSame(
				$headings[ $number ],
				HelpScreen::title( $key ),
				'Tytuł kroku na ekranie musi być dokładnie nagłówkiem z docs/konfiguracja.md (bez pliku .mo __() zwraca string źródłowy).'
			);
		}
	}

	public function test_skonczona_konfiguracja_zwija_liste_do_jednej_linii(): void {
		$this->fullyConfigured();
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'pusers-setup-complete', $html );
		$this->assertStringNotContainsString( 'pusers-setup-checklist', $html, 'Skończona konfiguracja nie ma po co zajmować ekranu tabelą ośmiu kroków.' );
	}

	public function test_osmy_krok_pokazuje_sie_tylko_przy_kontach_bez_stanu(): void {
		// Asercja idzie na znacznik WIERSZA listy, nie na tytuł kroku: tytuł pada na ekranie
		// jeszcze raz, jako nagłówek w części o uruchomieniu od zera, i tam należy się zawsze.
		$marker = 'pusers-setup-step--' . SetupStepKey::LegacyAccounts->value;

		$this->assertStringNotContainsString(
			$marker,
			$this->renderAsAdmin(),
			'Portal bez kont sprzed wdrożenia nie ma po co widzieć tego kroku na liście startowej.'
		);

		$this->statelessAccounts( 3 );
		$this->assertStringContainsString( $marker, $this->renderAsAdmin() );
	}

	public function test_panel_odsyla_do_raportu_spojnosci_zamiast_powtarzac_jego_tresci(): void {
		// Zależność spełniona, bo tylko wtedy ekrany wtyczki istnieją i krok ma gdzie prowadzić.
		add_filter( 'pusers_acf_pro_loaded', '__return_true' );
		$this->withSource();
		$this->withProfession();
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'page=' . Menu::SLUG_MATRIX, $html, 'Krok macierzy prowadzi na ekran, na którym stoi raport spójności.' );
		$this->assertStringNotContainsString(
			'Profesja „lekarz”',
			$html,
			'Lista startowa mówi, gdzie jesteś; co jest nie tak, mówi raport spójności. Powtarzanie jego tekstów robiłoby z niej drugi raport.'
		);
	}

	public function test_setup_incomplete_jest_prawda_przy_swiezej_instalacji_i_falsz_przy_pelnej(): void {
		$this->assertTrue( HelpScreen::setup_incomplete() );

		$this->fullyConfigured();
		$this->assertFalse( HelpScreen::setup_incomplete() );
	}

	public function test_darmowy_acf_nie_pozwala_uznac_konfiguracji_za_skonczona(): void {
		// Pełna konfiguracja BEZ filtra ACF Pro: wszystko da się zmierzyć (filtr grup działa jak
		// darmowy ACF, który definiuje te same funkcje), ale bramka zależności nie jest spełniona.
		$this->withSource();
		$this->withProfession();
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'visible' ) ) ) );
		$this->withSettings(
			array(
				'login_page_id'       => $this->page(),
				'register_page_id'    => $this->page(),
				'reset_page_id'       => $this->page(),
				'after_login_page_id' => $this->page(),
				'notify_emails'       => array( 'redakcja@example.test' ),
			)
		);

		$html = $this->renderAsAdmin();
		$this->assertStringNotContainsString( 'pusers-setup-complete', $html, 'Zielona lista nad bezczynną wtyczką jest kłamstwem, którego ten ekran ma nie mówić.' );
		$this->assertStringContainsString( 'Darmowy ACF nie wystarcza', $html );
		$this->assertStringNotContainsString( 'href="' . esc_url( Menu::url( Menu::SLUG_MATRIX ) ) . '"', $html, 'Bez spełnionej zależności ekrany wtyczki nie są zarejestrowane, więc odnośnik prowadziłby na stronę, której rdzeń nie zna.' );
		$this->assertTrue( HelpScreen::setup_incomplete() );
	}

	public function test_osmy_krok_zostaje_widoczny_po_zwinieciu_listy(): void {
		$this->fullyConfigured();
		$this->statelessAccounts( 3 );

		$html = $this->renderAsAdmin();
		$this->assertStringContainsString( 'pusers-setup-complete', $html );
		$this->assertStringContainsString(
			'pusers-setup-step--' . SetupStepKey::LegacyAccounts->value,
			$html,
			'Konta sprzed wdrożenia wchodzą na portal niezależnie od decyzji redakcji — schowanie tego razem ze zwiniętą listą zostawiłoby otwarte drzwi bez śladu na żadnym ekranie.'
		);
	}

	public function test_osmy_krok_nie_wplywa_na_setup_incomplete(): void {
		$this->fullyConfigured();
		$this->statelessAccounts( 4 );

		$this->assertFalse( HelpScreen::setup_incomplete(), 'Konta sprzed wdrożenia nie wchodzą do warunku ukończenia, a linia na ekranie ustawień nie liczy ich w ogóle.' );
	}

	public function test_spis_odnosnikow_prowadzi_do_kotwic_ktore_istnieja(): void {
		$html = $this->renderAsAdmin();

		foreach ( array( SetupGuide::ANCHOR, FormChanges::ANCHOR, Applications::ANCHOR, Messages::ANCHOR ) as $anchor ) {
			$this->assertStringContainsString( 'href="#' . $anchor . '"', $html, 'Spis nie prowadzi do części ' . $anchor . '.' );
			$this->assertStringContainsString( 'id="' . $anchor . '"', $html, 'Część ' . $anchor . ' nie ma kotwicy, do której spis prowadzi.' );
		}
	}

	public function test_czesc_o_zmianie_formularza_mowi_prawde_o_polu_wymaganym_poza_grupa(): void {
		$html = $this->renderAsAdmin();

		// Plan rejestracji przycina macierz do pól źródła (RegistrationPlan::matrix()), więc wymóg
		// pola spoza grupy PRZESTAJE OBOWIĄZYWAĆ — nie blokuje rejestracji. Blokuje tylko pole
		// numeru uprawnień, i to przez zdjęcie profesji z listy, nie przez odmowę zgłoszenia.
		$this->assertStringContainsString( 'nie blokuje rejestracji', $html );
		$this->assertStringNotContainsString(
			'blokuje rejestrację tej profesji',
			$html,
			'Fałszywa wersja tego zdania kazałaby naprawiać coś, co nie jest zepsute, i obiecywała fail-closed tam, gdzie wtyczka świadomie fail-open.'
		);
		$this->assertStringContainsString( 'nie pojawia się na liście profesji', $html, 'Prawdziwy skutek dla pola numeru musi być powiedziany.' );
	}

	public function test_czesc_o_zmianie_formularza_mowi_o_ostrzezeniu_w_raporcie(): void {
		$this->assertStringContainsString(
			'ostrzeżeniem przy każdej profesji',
			$this->renderAsAdmin(),
			'ConsistencyChecker wystawia field_not_configured dla pola bez stanu — mówienie, że nie ma po tym żadnego błędu, kasuje istniejącą siatkę bezpieczeństwa.'
		);
	}

	public function test_czesc_o_zmianie_formularza_mowi_ze_przemianowanie_pola_jest_nowym_polem(): void {
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'Zmiana NAZWY jest dla wtyczki usunięciem jednego pola i dodaniem drugiego', $html );
		$this->assertStringContainsString(
			'Pola spoza wybranej grupy',
			$html,
			'Instrukcja musi nazwać sekcję ekranu macierzy jej nagłówkiem, bo tam idzie się po wpis zostawiony przez starą nazwę.'
		);
	}

	public function test_czesc_o_wnioskach_nazywa_ekran_wnioskow_i_odnosnik_do_rejestru(): void {
		$html = $this->renderAsAdmin();

		$this->assertStringContainsString( 'Portal: wnioski', $html );
		$this->assertStringContainsString( 'rejestru zawodowego', $html, 'Weryfikacja numeru dzieje się w rejestrze, nie w portalu — instrukcja musi to powiedzieć.' );
		$this->assertStringContainsString( 'Odrzucenie wymaga powodu', $html );
	}

	public function test_czesc_o_wnioskach_nie_obiecuje_ze_panel_pokazuje_wszystkie_pola(): void {
		$html = $this->renderAsAdmin();

		// ApplicationDetails pokazuje WYŁĄCZNIE pola widoczne w dzisiejszej macierzy, a pozostałe
		// tylko liczy. Obietnica kompletności kazałaby rozstrzygać wniosek w przekonaniu, że widać
		// wszystko, co lekarz podał.
		$this->assertStringContainsString( 'nie są pokazywane', $html );
		$this->assertStringNotContainsString( 'wszystkie pola, które zgłaszający wypełnił', $html );
	}

	public function test_tabela_komunikatow_wymienia_komunikat_o_braku_wiersza_w_macierzy(): void {
		$this->assertStringContainsString(
			'Profesja … nie ma wiersza w macierzy pól',
			$this->renderAsAdmin(),
			'To komunikat, na którym stanęła pierwsza instalacja — ściąga musi go mieć.'
		);
	}

	/**
	 * Źródło prawdy każdego cytatu ze ściągi komunikatów: `code:<kod problemu>` dla tekstów
	 * z raportu spójności, `file:<ścieżka>` dla komunikatów wypisywanych wprost w kodzie.
	 *
	 * Mapowanie mieszka w kodzie testowym, nie produkcyjnym — jak `ConfigurationDoc::STEPS`
	 * i `SCHEMA_SURFACE` w `MigrationsTest`: dopisanie wiersza do ściągi bez dopisania go
	 * tutaj zapala test, a to jest moment, w którym autor ma pomyśleć, skąd ten komunikat
	 * naprawdę pochodzi.
	 *
	 * @var array<string, string>
	 */
	private const MESSAGE_SOURCES = array(
		'Profesja … nie ma wiersza w macierzy pól'                                                   => 'code:profession_without_matrix_row',
		'Wybranej grupy pól nie wskazano albo nie istnieje już w ACF'                                => 'file:src/Admin/MatrixScreen.php',
		'Wybrana grupa pól nie zawiera żadnych pól'                                                  => 'file:src/Admin/MatrixScreen.php',
		'Profesja … wymaga numeru uprawnień, ale …'                                                  => 'code:credential_field_not_required',
		'… wskazane pole … jest w ACF typu …, którego wtyczka nie przyjmuje jako pola numeru'         => 'code:credential_field_wrong_type',
		'Macierz profesji … oznacza jako wymagane pole …, którego nie ma w wybranej grupie pól'       => 'code:matrix_required_field_not_in_source',
		'Macierz profesji … zawiera pole …, którego nie ma w wybranej grupie pól'                                => 'code:matrix_field_not_in_source',
		'Rejestracja jest wyłączona: domyślna rola …'                                                => 'code:default_role_dangerous_capability',
		'Rejestracja jest chwilowo niedostępna (na stronie portalu)'                                  => 'file:src/Front/RegistrationForm.php',
		'Wtyczka Portal Users wymaga ACF Pro'                                                         => 'file:src/Dependencies.php',
		'Wtyczka Portal Users nie może wystartować: brakuje katalogu vendor'                          => 'file:portal-users.php',
	);

	/**
	 * Kontekst na tyle pełny, żeby każdy komunikat raportu dał się złożyć z prawdziwymi danymi.
	 *
	 * @return array<string, string>
	 */
	private function sampleContext(): array {
		return array(
			'profession' => 'lekarz',
			'field'      => 'pwz',
			'type'       => 'repeater',
			'role'       => 'editor',
			'capability' => 'unfiltered_html',
		);
	}

	/**
	 * Prawdziwy tekst, z którego skrócony jest cytat ze ściągi.
	 */
	private function sourceTextOf( string $source ): string {
		if ( 0 === strpos( $source, 'code:' ) ) {
			return IssueMessages::text( SettingsIssue::error( substr( $source, 5 ), $this->sampleContext() ) );
		}

		$path = dirname( __DIR__, 3 ) . '/' . substr( $source, 5 );
		$this->assertFileExists( $path, 'Ściąga powołuje się na plik, którego nie ma: ' . $source );

		return (string) file_get_contents( $path );
	}

	public function test_sciaga_komunikatow_ma_dokladnie_wiersze_z_mapy_zrodel(): void {
		$this->assertSame(
			array_keys( self::MESSAGE_SOURCES ),
			array_keys( Messages::rows() ),
			'Każdy wiersz ściągi musi mieć w mapie wskazane źródło prawdy — i w tej samej kolejności.'
		);
	}

	/**
	 * Cytat w ściądze musi dać się odnaleźć w prawdziwym tekście.
	 *
	 * Zmiana treści komunikatu w `IssueMessages` zostawiała dotąd ściągę z cytatem, którego
	 * nikt już na ekranie nie zobaczy — zdarzyło się przy poprawianiu komunikatu o polu
	 * wymaganym spoza grupy. Cytaty są skrócone wielokropkiem w miejscach, w których wtyczka
	 * wstawia klucz profesji albo nazwę pola, więc sprawdzamy fragmenty po kolei, a nie całość.
	 */
	public function test_kazdy_cytat_ze_sciagi_jest_fragmentem_prawdziwego_komunikatu(): void {
		foreach ( Messages::rows() as $quote => $cause ) {
			$source = self::MESSAGE_SOURCES[ $quote ] ?? '';
			$this->assertNotSame( '', $source, 'Cytat bez źródła w mapie: ' . $quote );
			$text = $this->sourceTextOf( $source );

			// Dopisek w nawiasie na końcu cytatu jest wskazówką, gdzie ten komunikat widać,
			// a nie częścią jego treści — jedyny taki wiersz to „(na stronie portalu)".
			$offset = 0;
			foreach ( explode( '…', (string) preg_replace( '/\s*\([^)]*\)$/u', '', $quote ) ) as $fragment ) {
				if ( '' === $fragment ) {
					continue;
				}
				$at = strpos( $text, $fragment, $offset );
				$this->assertNotFalse(
					$at,
					'Fragment „' . $fragment . '” ze ściągi nie występuje (w tej kolejności) w prawdziwym tekście ' . $source . '. Komunikat zmienił treść, a ściąga została ze starym cytatem.'
				);
				$offset = $at + strlen( $fragment );
			}

			$this->assertNotSame( '', $cause, 'Wiersz ściągi bez przyczyny: ' . $quote );
		}
	}

	/**
	 * Ta sama ściąga stoi w `docs/konfiguracja.md` i nie ma prawa cytować inaczej niż ekran.
	 *
	 * Dokument jest podzbiorem: nie ma wierszy o komunikatach, których operator portalu nie
	 * zobaczy w panelu. Każdy jego cytat musi jednak być jednym z cytatów ekranu — inaczej
	 * poprawianie komunikatu wymaga pamiętania o dwóch tabelach, a to już raz nie wyszło.
	 */
	public function test_sciaga_w_dokumencie_cytuje_to_samo_co_ekran(): void {
		$screen = array();
		foreach ( array_keys( Messages::rows() ) as $quote ) {
			$screen[] = (string) preg_replace( '/\s*\([^)]*\)$/u', '', $quote );
		}

		$quotes = ConfigurationDoc::messageQuotes();
		$this->assertNotSame( array(), $quotes, 'Tabela „komunikat → przyczyna” w docs/konfiguracja.md nie dała się odczytać.' );

		foreach ( $quotes as $quote ) {
			$this->assertContains( $quote, $screen, 'docs/konfiguracja.md cytuje „' . $quote . '”, czego nie ma w ściądze na ekranie pomocy.' );
		}
	}

	public function test_render_nie_wypuszcza_surowego_skryptu(): void {
		$this->assertStringNotContainsString( '<script', $this->renderAsAdmin() );
	}

	public function test_podstrona_rejestruje_sie_pod_users_php(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		remove_all_actions( 'admin_menu' );
		global $submenu;
		$submenu = array();
		HelpScreen::register();
		do_action( 'admin_menu' );

		$slugs = wp_list_pluck( $submenu['users.php'] ?? array(), 2 );
		$this->assertContains( Menu::SLUG_HELP, $slugs );
	}
}
