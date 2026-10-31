<?php
/**
 * Ekran pomocy: lista startowa konfiguracji i instrukcja.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Admin\Help\Applications;
use PortalUsers\Admin\Help\FormChanges;
use PortalUsers\Admin\Help\Messages;
use PortalUsers\Admin\Help\SetupGuide;
use PortalUsers\Domain\Config\SetupChecklist;
use PortalUsers\Domain\Config\SetupStep;
use PortalUsers\Domain\Config\SetupStepKey;

/**
 * Ekran odpowiada na dwa różne pytania dwóch różnych osób. Lista startowa na górze mówi
 * administratorowi stawiającemu portal, GDZIE JEST w konfiguracji; instrukcja pod nią mówi
 * osobie, która wtyczki nie stawiała, jak zmienić formularz i jak obsłużyć wnioski.
 *
 * Lista startowa nie jest drugim raportem spójności: niesie stan kroku i co najwyżej
 * podpowiedź, a nigdy tekstu problemu. Co jest nie tak, mówi raport na ekranie macierzy,
 * do którego krok prowadzi odnośnikiem.
 *
 * Ekran rejestruje się poza hookiem `pusers_register_features` — patrz `Plugin::boot()`.
 */
final class HelpScreen {

	/**
	 * Podpina podstronę pod `admin_menu`.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
	}

	/**
	 * Dodaje podstronę pomocy pod menu Użytkownicy.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Portal: pomoc', 'portal-users' ),
			__( 'Portal: pomoc', 'portal-users' ),
			Menu::CAPABILITY,
			Menu::SLUG_HELP,
			array( self::class, 'render' )
		);
	}

	/**
	 * Tytuł kroku.
	 *
	 * Teksty są dokładnie nagłówkami kroków z `docs/konfiguracja.md`; zgodność trzyma
	 * `HelpScreenTest::test_tytul_kazdego_kroku_jest_naglowkiem_z_dokumentu`.
	 *
	 * @param SetupStepKey $key Krok.
	 */
	public static function title( SetupStepKey $key ): string {
		return match ( $key ) {
			SetupStepKey::AcfGroup       => __( 'Grupa pól w ACF Pro', 'portal-users' ),
			SetupStepKey::GroupSelected  => __( 'Wskazanie grupy w ustawieniach', 'portal-users' ),
			SetupStepKey::Professions    => __( 'Profesje', 'portal-users' ),
			SetupStepKey::Matrix         => __( 'Macierz pól', 'portal-users' ),
			SetupStepKey::Pages          => __( 'Trzy strony portalu', 'portal-users' ),
			SetupStepKey::Settings       => __( 'Pozostałe ustawienia', 'portal-users' ),
			SetupStepKey::MailTemplates  => __( 'Szablony maili', 'portal-users' ),
			SetupStepKey::LegacyAccounts => __( 'Portal, który już działał', 'portal-users' ),
		};
	}

	/**
	 * Czy konfiguracja nie jest skończona — dla linii odsyłającej tu z ekranu ustawień.
	 *
	 * Czyta fakty BEZ liczenia kont sprzed wdrożenia: ósmy krok nie wchodzi do warunku
	 * ukończenia, a zapytanie po użytkownikach robi na tym samym ekranie `BackfillNotice`.
	 */
	public static function setup_incomplete(): bool {
		return ! ( new SetupChecklist( SetupFactsReader::read( false ) ) )->isComplete();
	}

	/**
	 * Wypisuje ekran pomocy.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Portal: pomoc', 'portal-users' ) . '</h1>';
		self::render_checklist();
		self::render_nav();
		SetupGuide::render();
		FormChanges::render();
		Applications::render();
		Messages::render();
		echo '</div>';
	}

	/**
	 * Spis odnośników do części instrukcji. Ekran jest długi, a przychodzi się na niego
	 * z jednym z trzech pytań, nie po to, żeby czytać go od początku.
	 */
	private static function render_nav(): void {
		$parts = array(
			array( SetupGuide::ANCHOR, SetupGuide::heading() ),
			array( FormChanges::ANCHOR, FormChanges::heading() ),
			array( Applications::ANCHOR, Applications::heading() ),
			array( Messages::ANCHOR, Messages::heading() ),
		);

		echo '<h2>' . esc_html__( 'Instrukcja', 'portal-users' ) . '</h2>';
		echo '<ul class="pusers-help-nav">';
		foreach ( $parts as $part ) {
			printf( '<li><a href="#%1$s">%2$s</a></li>', esc_attr( $part[0] ), esc_html( $part[1] ) );
		}
		echo '</ul>';
	}

	/**
	 * Lista startowa albo jedna linia, gdy konfiguracja jest skończona.
	 */
	private static function render_checklist(): void {
		$facts     = SetupFactsReader::read();
		$checklist = new SetupChecklist( $facts );

		if ( $checklist->isComplete() ) {
			echo '<p class="pusers-setup-complete">' . esc_html__( 'Konfiguracja jest skończona: portal może przyjmować zgłoszenia. Instrukcja niżej zostaje na później.', 'portal-users' ) . '</p>';
			// Jedna rzecz przebija zwinięcie listy: konta sprzed wdrożenia bez stanu wchodzą na
			// portal niezależnie od decyzji redakcji. Schowanie tego razem z resztą listy kazałoby
			// portalowi po migracji trzymać otwarte drzwi bez śladu o tym na żadnym ekranie.
			self::render_legacy_accounts_step( $checklist->steps() );
			return;
		}

		echo '<h2>' . esc_html__( 'Od czego zacząć', 'portal-users' ) . '</h2>';
		echo '<p>' . esc_html__( 'Kroki idą w narzuconej kolejności: każdy karmi listę wyborów w następnym. Przeskoczenie dwóch pierwszych kończy się błędem o braku wiersza w macierzy pól, którego nie da się usunąć na ekranie macierzy.', 'portal-users' ) . '</p>';

		if ( ! $facts->dependenciesSatisfied ) {
			self::render_missing_acf_notice();
		}

		echo '<table class="widefat striped pusers-setup-checklist"><tbody>';
		foreach ( $checklist->steps() as $step ) {
			self::render_row( $step, $facts->dependenciesSatisfied );
		}
		echo '</tbody></table>';
	}

	/**
	 * Wiersz kroku o kontach sprzed wdrożenia, jeżeli taki krok w ogóle istnieje.
	 *
	 * @param array<int, SetupStep> $steps Kroki listy startowej.
	 */
	private static function render_legacy_accounts_step( array $steps ): void {
		foreach ( $steps as $step ) {
			if ( SetupStepKey::LegacyAccounts === $step->key ) {
				echo '<table class="widefat striped pusers-setup-checklist"><tbody>';
				self::render_row( $step, true );
				echo '</tbody></table>';
				return;
			}
		}
	}

	/**
	 * Komunikat o niespełnionej zależności od ACF Pro.
	 *
	 * Kluczem jest `dependenciesSatisfied`, nie `acfReadable`, i to jest różnica, która ma skutki:
	 * darmowy ACF definiuje te same funkcje, więc konfiguracja daje się ZMIERZYĆ do końca, ale
	 * bramka zależności wymaga Pro i wtyczka jest bezczynna. Przy mierze zamiast zależności ten
	 * komunikat by nie padł, a lista pokazałaby zieloną, skończoną konfigurację nad martwą wtyczką.
	 *
	 * W tym stanie `Menu::register()` nie odpala, więc poza tym ekranem i ekranem wniosków nie ma
	 * pod Użytkownikami żadnego ekranu wtyczki — odnośniki kroków prowadziłyby na stronę, której
	 * rdzeń nie zna, dlatego kroki nie są wtedy odnośnikami.
	 */
	private static function render_missing_acf_notice(): void {
		echo '<div class="notice notice-warning inline"><p>'
			. esc_html__( 'Wtyczka nie widzi ACF Pro, więc jest bezczynna: formularz rejestracji nie ma z czego powstać, a część kroków może być nie do sprawdzenia. Darmowy ACF nie wystarcza. Do włączenia ACF Pro widoczne są tylko dwa ekrany wtyczki: ten i ekran wniosków, dlatego kroki niżej nie są odnośnikami.', 'portal-users' )
			. '</p></div>';
	}

	/**
	 * Wiersz kroku: stan, tytuł (odnośnikiem, gdy ekran docelowy istnieje) i podpowiedzi.
	 *
	 * @param SetupStep $step   Krok z wyliczonym stanem.
	 * @param bool      $linked Czy ekrany wtyczki są zarejestrowane, więc czy warto linkować.
	 */
	private static function render_row( SetupStep $step, bool $linked ): void {
		$url   = self::url( $step->key );
		$title = self::title( $step->key );

		// Klasa wiersza niesie klucz kroku: testy pytają o OBECNOŚĆ kroku na liście, a tytuł
		// kroku pada na ekranie jeszcze raz, jako nagłówek w części o uruchomieniu od zera.
		printf( '<tr class="pusers-setup-step pusers-setup-step--%s">', esc_attr( $step->key->value ) );
		echo '<td style="width:12em">' . esc_html( self::status_label( $step->status ) ) . '</td><td>';
		if ( $linked && '' !== $url ) {
			printf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $title ) );
		} else {
			echo esc_html( $title );
		}
		foreach ( $step->hints as $hint ) {
			echo '<p class="description">' . esc_html( self::hint_text( $hint ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Słowo opisujące stan kroku.
	 *
	 * @param string $status Stan z `SetupStep`.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			SetupStep::DONE    => __( 'Zrobione', 'portal-users' ),
			SetupStep::TODO    => __( 'Do zrobienia', 'portal-users' ),
			SetupStep::UNKNOWN => __( 'Nie można sprawdzić', 'portal-users' ),
			SetupStep::INFO    => __( 'Do przejrzenia', 'portal-users' ),
			default            => '',
		};
	}

	/**
	 * Tekst podpowiedzi dla jej kodu.
	 *
	 * @param string $hint Kod podpowiedzi z `SetupStep`.
	 */
	private static function hint_text( string $hint ): string {
		return match ( $hint ) {
			SetupStep::HINT_AFTER_LOGIN_PAGE_UNSET  => __( 'Strona po zalogowaniu nie jest wskazana: użytkownik po zalogowaniu trafi na stronę główną witryny.', 'portal-users' ),
			SetupStep::HINT_REJECTION_REASONS_EMPTY => __( 'Lista powodów odrzucenia jest pusta, a akceptacja kont jest włączona: redakcja będzie wpisywać powód ręcznie przy każdym odrzuceniu.', 'portal-users' ),
			default                                 => '',
		};
	}

	/**
	 * Adres ekranu, na którym wykonuje się krok; pusty, gdy krok nie ma swojego ekranu.
	 *
	 * @param SetupStepKey $key Krok.
	 */
	private static function url( SetupStepKey $key ): string {
		return match ( $key ) {
			SetupStepKey::AcfGroup       => admin_url( 'edit.php?post_type=acf-field-group' ),
			SetupStepKey::GroupSelected  => Menu::url( Menu::SLUG_SETTINGS ),
			SetupStepKey::Professions    => Menu::url( Menu::SLUG_PROFESSIONS ),
			SetupStepKey::Matrix         => Menu::url( Menu::SLUG_MATRIX ),
			SetupStepKey::Pages          => Menu::url( Menu::SLUG_SETTINGS ),
			SetupStepKey::Settings       => Menu::url( Menu::SLUG_SETTINGS ),
			SetupStepKey::MailTemplates  => Menu::url( Menu::SLUG_MAIL_TEMPLATES ),
			SetupStepKey::LegacyAccounts => Menu::url( Menu::SLUG_IMPORT ),
		};
	}
}
