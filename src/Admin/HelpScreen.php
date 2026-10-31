<?php
/**
 * Ekran pomocy: lista startowa konfiguracji i instrukcja.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

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
		echo '</div>';
	}

	/**
	 * Lista startowa albo jedna linia, gdy konfiguracja jest skończona.
	 */
	private static function render_checklist(): void {
		$facts     = SetupFactsReader::read();
		$checklist = new SetupChecklist( $facts );

		if ( $checklist->isComplete() ) {
			echo '<p class="pusers-setup-complete">' . esc_html__( 'Konfiguracja jest skończona: portal może przyjmować zgłoszenia. Instrukcja niżej zostaje na później.', 'portal-users' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html__( 'Od czego zacząć', 'portal-users' ) . '</h2>';
		echo '<p>' . esc_html__( 'Kroki idą w narzuconej kolejności: każdy karmi listę wyborów w następnym. Przeskoczenie dwóch pierwszych kończy się błędem o braku wiersza w macierzy pól, którego nie da się usunąć na ekranie macierzy.', 'portal-users' ) . '</p>';

		if ( ! $facts->acfReadable ) {
			self::render_missing_acf_notice();
		}

		echo '<table class="widefat striped pusers-setup-checklist"><tbody>';
		foreach ( $checklist->steps() as $step ) {
			self::render_row( $step, $facts->acfReadable );
		}
		echo '</tbody></table>';
	}

	/**
	 * Komunikat o nieobecnym ACF Pro.
	 *
	 * Bez ACF Pro `Menu::register()` nie odpala, więc poza tym ekranem i ekranem wniosków nie ma
	 * pod Użytkownikami żadnego ekranu wtyczki — odnośniki kroków prowadziłyby w nicość, dlatego
	 * w tym stanie kroki nie są odnośnikami.
	 */
	private static function render_missing_acf_notice(): void {
		echo '<div class="notice notice-warning inline"><p>'
			. esc_html__( 'Wtyczka nie widzi ACF Pro, więc nie może sprawdzić kroków zależnych od grupy pól, a formularz rejestracji nie ma z czego powstać. Do włączenia ACF Pro widoczne są tylko dwa ekrany wtyczki: ten i ekran wniosków.', 'portal-users' )
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

		echo '<tr><td style="width:12em">' . esc_html( self::status_label( $step->status ) ) . '</td><td>';
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
