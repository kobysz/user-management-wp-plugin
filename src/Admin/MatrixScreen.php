<?php
/**
 * Ekran macierzy profesja x pole: stan każdego pola dla każdej profesji i raport spójności.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Domain\Config\ConsistencyChecker;
use PortalUsers\Domain\Config\SettingsIssue;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldState;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;

/**
 * Wiersze to pola ze źródła (grupy ACF), kolumny to profesje. Zapis scala przysłane pary
 * z zapisaną macierzą, bo formularz zna tylko pola bieżącej grupy; wpisy pól spoza źródła
 * ("martwe wiersze") usuwa osobna, jawna akcja.
 */
final class MatrixScreen {

	public const ACTION_SAVE         = 'pusers_save_matrix';
	public const ACTION_DELETE_FIELD = 'pusers_delete_matrix_field';

	public const NOTICE_FIELD_REMOVED = 'matrix_field_removed';
	public const NOTICE_FIELD_REFUSED = 'matrix_field_refused';
	public const NOTICE_SAVE_REFUSED  = 'matrix_save_refused';

	private const FIELD_PREFIX = 'pusers_';

	/**
	 * Podpina podstronę oraz obsługę zapisu i usuwania martwego pola.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_FIELD, array( self::class, 'handle_delete_field' ) );
	}

	/**
	 * Dodaje podstronę macierzy pod menu Użytkownicy.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Portal: macierz pól', 'portal-users' ),
			__( 'Portal: macierz pól', 'portal-users' ),
			Menu::CAPABILITY,
			Menu::SLUG_MATRIX,
			array( self::class, 'render' )
		);
	}

	/**
	 * Składa walidator spójności z zapisanej konfiguracji i źródła pól wybranej grupy ACF.
	 */
	public static function checker(): ConsistencyChecker {
		return new ConsistencyChecker(
			ProfessionRepository::get(),
			FieldMatrixRepository::get(),
			self::source()
		);
	}

	/**
	 * Zapisuje macierz. Przysłane pary profesja-pole nadpisują zapisane, reszta zostaje;
	 * pusty stan oznacza "nie zmieniaj", a nierozpoznany degraduje FieldMatrix do "ukryte".
	 */
	public static function handle_save(): void {
		Request::guard( self::ACTION_SAVE );
		self::require_post();

		// Bez czytelnej grupy nie ma czego zapisywać (ekran nie renderuje wierszy), a wpis dla pola,
		// którego nikt nie umie zweryfikować, mógłby zablokować rejestrację. Odrzucamy całość.
		$source = self::source();
		if ( ! $source->groupExists() ) {
			Request::redirect( Menu::SLUG_MATRIX, self::NOTICE_SAVE_REFUSED );
			return;
		}

		// Przyjmujemy tylko pola grupy: wpis pola, którego formularz nie wyrenderuje, blokowałby
		// rejestrację. Klucz porównujemy surowo - przynależność do źródła jest sanityzacją.
		$known = $source->fields();

		// Tak samo profesje: klucz spoza kolekcji jest odrzucany, nie sanityzowany. Sanityzacja
		// (np. zmiana wielkości liter) zapisałaby wpis pod innym kluczem, niż czyta macierz,
		// i pole po cichu przestałoby być wymagane.
		$professions = ProfessionRepository::get();

		$merged = FieldMatrixRepository::get()->toArray();
		foreach ( self::posted_matrix() as $profession => $fields ) {
			if ( ! $professions->has( (string) $profession ) ) {
				continue;
			}
			foreach ( $fields as $field => $state ) {
				if ( '' === $state || ! array_key_exists( $field, $known ) ) {
					continue;
				}
				$merged[ $profession ][ $field ] = $state;
			}
		}

		// Zapis bez zmian zwraca false z update_option(), więc wyniku nie interpretujemy.
		FieldMatrixRepository::save( FieldMatrix::fromArray( $merged ) );

		Request::redirect( Menu::SLUG_MATRIX, Request::NOTICE_SAVED );
	}

	/**
	 * Zdejmuje wpisy jednego pola u wszystkich profesji. Operacja destrukcyjna, więc ma własną
	 * akcję nonce'a: nonce zapisu macierzy jej nie autoryzuje.
	 */
	public static function handle_delete_field(): void {
		Request::guard( self::ACTION_DELETE_FIELD );
		self::require_post();

		// Usuwamy tylko pole martwe: grupa istnieje, a pola w niej nie ma. Gdy źródła nie da się
		// przeczytać, nie wiadomo, co jest martwe, więc macierz zostaje nietknięta.
		$field  = self::posted_string( 'field' );
		$source = self::source();
		$rows   = FieldMatrixRepository::get()->toArray();
		if ( '' === $field || ! $source->groupExists() || array_key_exists( $field, $source->fields() ) || ! self::in_matrix( $rows, $field ) ) {
			Request::redirect( Menu::SLUG_MATRIX, self::NOTICE_FIELD_REFUSED );
			return;
		}

		foreach ( $rows as $profession => $fields ) {
			unset( $rows[ $profession ][ $field ] );
		}
		FieldMatrixRepository::save( FieldMatrix::fromArray( $rows ) );

		Request::redirect( Menu::SLUG_MATRIX, self::NOTICE_FIELD_REMOVED );
	}

	/**
	 * Wypisuje ekran macierzy.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Portal: macierz pól', 'portal-users' ) . '</h1>';
		self::render_notices();
		self::render_report();

		$professions = ProfessionRepository::get()->all();
		$matrix      = FieldMatrixRepository::get();
		$acf         = self::source();
		$readable    = $acf->groupExists();
		$source      = $acf->fields();

		if ( array() === $professions ) {
			echo '<p>' . esc_html__( 'Najpierw zdefiniuj profesje, a potem ustaw dla nich pola.', 'portal-users' ) . '</p>';
		} elseif ( ! $readable ) {
			echo '<p>' . esc_html__( 'Wybranej grupy pól nie wskazano albo nie istnieje już w ACF. Wskaż grupę w ustawieniach.', 'portal-users' ) . '</p>';
		} elseif ( array() === $source ) {
			echo '<p>' . esc_html__( 'Wybrana grupa pól nie zawiera żadnych pól.', 'portal-users' ) . '</p>';
		} else {
			self::render_matrix_form( $professions, $matrix, $source );
		}

		self::render_dead_rows( $matrix, $source, $readable );
		echo '</div>';
	}

	/**
	 * Powiadomienia po zapisie lub usunięciu pola.
	 */
	private static function render_notices(): void {
		$notice = Request::notice();
		$texts  = array(
			Request::NOTICE_SAVED      => __( 'Macierz została zapisana.', 'portal-users' ),
			self::NOTICE_FIELD_REMOVED => __( 'Wpisy pola zostały usunięte z macierzy.', 'portal-users' ),
		);
		if ( isset( $texts[ $notice ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $texts[ $notice ] ) . '</p></div>';
		}
		$errors = array(
			self::NOTICE_FIELD_REFUSED => __( 'Nie usunięto: wskazane pole nie jest martwym polem macierzy albo wybranej grupy nie da się odczytać.', 'portal-users' ),
			self::NOTICE_SAVE_REFUSED  => __( 'Nie zapisano macierzy: wybranej grupy pól nie wskazano albo nie istnieje już w ACF.', 'portal-users' ),
		);
		if ( isset( $errors[ $notice ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $notice ] ) . '</p></div>';
		}
	}

	/**
	 * Raport spójności nad tabelą; Checker zwraca błędy przed ostrzeżeniami.
	 */
	private static function render_report(): void {
		$issues = self::checker()->issues();
		if ( array() === $issues ) {
			return;
		}
		echo '<div class="pusers-report"><h2>' . esc_html__( 'Raport spójności konfiguracji', 'portal-users' ) . '</h2><ul>';
		foreach ( $issues as $issue ) {
			$class = SettingsIssue::ERROR === $issue->severity ? 'notice-error' : 'notice-warning';
			echo '<li class="notice inline ' . esc_attr( $class ) . '"><p>' . esc_html( IssueMessages::text( $issue ) ) . '</p></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Formularz macierzy: wiersze to pola źródła, kolumny to profesje.
	 *
	 * @param array<string, \PortalUsers\Domain\Profession\Profession> $professions Profesje.
	 * @param FieldMatrix                                              $matrix      Zapisana macierz.
	 * @param array<string, string>                                    $source      Pola źródła: klucz => etykieta.
	 */
	private static function render_matrix_form( array $professions, FieldMatrix $matrix, array $source ): void {
		$stored = $matrix->toArray();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_header( self::ACTION_SAVE );
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Pole', 'portal-users' ) . '</th>';
		foreach ( $professions as $profession ) {
			echo '<th scope="col">' . esc_html( $profession->label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $source as $fieldKey => $label ) {
			$fieldKey = (string) $fieldKey;
			echo '<tr data-field="' . esc_attr( $fieldKey ) . '"><th scope="row">' . esc_html( $label ) . ' <code>' . esc_html( $fieldKey ) . '</code></th>';
			foreach ( $professions as $profession ) {
				$configured = isset( $stored[ $profession->key ] ) && array_key_exists( $fieldKey, $stored[ $profession->key ] );
				echo '<td>';
				self::render_cell( $profession->key, $fieldKey, $configured ? $matrix->stateFor( $profession->key, $fieldKey ) : null );
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Zapisz macierz', 'portal-users' ) );
		echo '</form>';
	}

	/**
	 * Lista wyboru stanu; komórka bez wpisu ma znacznik i pustą opcję, która niczego nie zapisuje.
	 *
	 * @param string          $profession Klucz profesji.
	 * @param string          $field      Klucz pola.
	 * @param FieldState|null $state      Zapisany stan albo null, gdy brak wpisu.
	 */
	private static function render_cell( string $profession, string $field, ?FieldState $state ): void {
		$name = self::FIELD_PREFIX . 'matrix[' . $profession . '][' . $field . ']';
		printf( '<select name="%s">', esc_attr( $name ) );
		if ( null === $state ) {
			echo '<option value="" selected="selected">—</option>';
		}
		$labels = array(
			FieldState::Hidden->value   => __( 'Ukryte', 'portal-users' ),
			FieldState::Visible->value  => __( 'Widoczne', 'portal-users' ),
			FieldState::Required->value => __( 'Wymagane', 'portal-users' ),
		);
		foreach ( $labels as $value => $text ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( null !== $state && $state->value === $value, true, false ),
				esc_html( $text )
			);
		}
		echo '</select>';
		if ( null === $state ) {
			echo ' <span class="pusers-unconfigured">' . esc_html__( 'nieskonfigurowane', 'portal-users' ) . '</span>';
		}
	}

	/**
	 * Pola obecne w macierzy, a nieobecne w źródle, z osobnym formularzem usunięcia każdego.
	 *
	 * @param FieldMatrix           $matrix Zapisana macierz.
	 * @param array<string, string> $source   Pola źródła.
	 * @param bool                  $readable Czy wybraną grupę da się odczytać; bez tego nie oferujemy usuwania.
	 */
	private static function render_dead_rows( FieldMatrix $matrix, array $source, bool $readable ): void {
		$dead = array();
		foreach ( $matrix->toArray() as $profession => $fields ) {
			foreach ( array_keys( $fields ) as $fieldKey ) {
				$fieldKey = (string) $fieldKey;
				if ( ! array_key_exists( $fieldKey, $source ) ) {
					$dead[ $fieldKey ][] = (string) $profession;
				}
			}
		}
		if ( array() === $dead ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Pola spoza wybranej grupy', 'portal-users' ) . '</h2>';
		if ( $readable ) {
			echo '<p>' . esc_html__( 'Macierz zawiera wpisy pól, których nie ma w wybranej grupie. Usunięcie zdejmuje wpisy tego pola u wszystkich profesji. Pole może też istnieć w ACF, ale mieć typ nieobsługiwany przez wtyczkę (kontener albo typ układowy).', 'portal-users' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Wybranej grupy pól nie da się odczytać, więc nie wiadomo, które pola są martwe. Usuwanie jest wyłączone.', 'portal-users' ) . '</p>';
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Pole', 'portal-users' ) . '</th><th scope="col">' . esc_html__( 'Profesje', 'portal-users' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $dead as $fieldKey => $professionKeys ) {
			$fieldKey = (string) $fieldKey;
			echo '<tr data-dead-field="' . esc_attr( $fieldKey ) . '"><td><code>' . esc_html( $fieldKey ) . '</code></td><td>' . esc_html( implode( ', ', $professionKeys ) ) . '</td><td>';
			if ( ! $readable ) {
				echo '</td></tr>';
				continue;
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::form_header( self::ACTION_DELETE_FIELD );
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( self::FIELD_PREFIX . 'field' ), esc_attr( $fieldKey ) );
			submit_button( __( 'Usuń', 'portal-users' ), 'link-delete', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Pola ukryte formularza: akcja admin-post i nonce tej akcji.
	 *
	 * @param string $action Akcja admin-post, a zarazem akcja nonce'a.
	 */
	private static function form_header( string $action ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action, Request::NONCE_FIELD );
	}

	/**
	 * Źródło pól wybranej grupy ACF.
	 */
	private static function source(): AcfFieldSource {
		return new AcfFieldSource( SettingsRepository::get()->acfGroupKey );
	}

	/**
	 * Odrzuca żądanie inne niż POST. Wywoływane po guardzie, żeby nieuprawniony GET dostał 403.
	 */
	private static function require_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}
	}

	/**
	 * Tekstowa wartość pola z żądania albo pusty ciąg. Nonce i uprawnienia sprawdza Request::guard().
	 *
	 * @param string $key Klucz pola bez prefiksu.
	 */
	private static function posted_string( string $key ): string {
		$name = self::FIELD_PREFIX . $key;
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w obsłudze żądania.
			return '';
		}
		return sanitize_text_field( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w obsłudze żądania.
	}

	/**
	 * Czy pole występuje w którymkolwiek wierszu macierzy.
	 *
	 * @param array<string, array<string, string>> $rows  Macierz w postaci tablicy.
	 * @param string                               $field Klucz pola.
	 */
	private static function in_matrix( array $rows, string $field ): bool {
		foreach ( $rows as $fields ) {
			if ( array_key_exists( $field, $fields ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Macierz z żądania: profesja => pole => stan (tekst). Klucze profesji i pól zostają surowe (patrz handle_save()).
	 * Elementy o złym kształcie są pomijane.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function posted_matrix(): array {
		$name = self::FIELD_PREFIX . 'matrix';
		if ( ! isset( $_POST[ $name ] ) || ! is_array( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w obsłudze żądania.
			return array();
		}
		$raw = wp_unslash( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() w obsłudze żądania; wartości czyszczone niżej.
		$out = array();
		foreach ( $raw as $profession => $fields ) {
			$profession = (string) $profession;
			if ( ! is_array( $fields ) || '' === $profession ) {
				continue;
			}
			foreach ( $fields as $field => $state ) {
				if ( is_string( $state ) && '' !== (string) $field ) {
					$out[ $profession ][ (string) $field ] = sanitize_text_field( $state );
				}
			}
		}
		return $out;
	}
}
