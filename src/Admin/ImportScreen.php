<?php
/**
 * Ekran importu: skan kluczy user_meta starej wtyczki członkowskiej i formularz mapowania.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Import\MetaKeyStat;
use PortalUsers\Persistence\ImportMappingRepository;
use PortalUsers\Persistence\MetaKeyScanner;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;

/**
 * Pokazuje, co faktycznie leży w `usermeta` (klucz, liczba kont, próbki wartości), i pozwala zbudować
 * mapowanie: pole ACF => klucz źródłowy, klucz profesji i klucz stanu z tabelami wartości.
 *
 * Formularz wysyła i czyta mapowanie w kształcie `ImportMapping::toArray()`. Stany konta pochodzą
 * wyłącznie z `ImportMapping::ALLOWED_STATUSES`, a kody są wysyłane małymi literami, bo domena porównuje
 * je dokładnie: to ta ścisłość trzyma stan `email_unconfirmed` poza zasięgiem importu.
 */
final class ImportScreen {

	public const ACTION_SAVE = 'pusers_save_import_mapping';

	public const NOTICE_SAVED_MAPPING  = 'import_mapping_saved';
	public const NOTICE_NOTHING_MAPPED = 'import_nothing_mapped';
	public const NOTICE_CLEARED        = 'import_mapping_cleared';

	private const FIELD_PREFIX = 'pusers_';

	/**
	 * Ile pustych wierszy par "wartość => cel" formularz dokłada pod zapisanymi.
	 */
	private const BLANK_PAIR_ROWS = 3;

	/**
	 * Podpina podstronę i obsługę zapisu mapowania.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
	}

	/**
	 * Dodaje podstronę importu pod menu Użytkownicy.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Import danych', 'portal-users' ),
			__( 'Import danych', 'portal-users' ),
			Menu::CAPABILITY,
			Menu::SLUG_IMPORT,
			array( self::class, 'render' )
		);
	}

	/**
	 * Zapisuje mapowanie z formularza, scalając je z zapisanym. Pole spoza grupy ACF i profesja spoza kolekcji
	 * nie są przyjmowane z formularza, a ich zapisane wpisy zostają nietknięte. Pusty formularz
	 * przy niepustym zapisanym mapowaniu je czyści (osobny komunikat), a przy już pustym niczego nie zapisuje
	 * i mówi o tym osobnym komunikatem.
	 */
	public static function handle_save(): void {
		Request::guard( self::ACTION_SAVE );
		self::require_post();

		$known       = self::source()->fields();
		$professions = ProfessionRepository::get();

		// Celowo omijamy ImportMappingRepository::get(): przycina ono wpisy do bieżących pól i profesji,
		// a zapis przycięcia skasowałby na stałe mapowanie pola, którego grupa ACF chwilowo nie zwraca
		// (literówka w kluczu grupy daje zero pól). Wpisy, których celu teraz nie ma, zostają bez zmian;
		// wpisy z celem obecnym w całości rządzi formularz.
		$storedMapping = ImportMapping::fromArray( OptionStore::read( OptionStore::OPTION_IMPORT_MAPPING ) );
		$stored        = $storedMapping->toArray();

		$fields = array();
		foreach ( $stored['fields'] as $field => $metaKey ) {
			if ( ! array_key_exists( $field, $known ) ) {
				$fields[ $field ] = $metaKey;
			}
		}
		foreach ( self::posted_fields() as $field => $metaKey ) {
			if ( array_key_exists( $field, $known ) ) {
				$fields[ $field ] = $metaKey;
			}
		}

		$professionValues = array();
		foreach ( $stored['professionValues'] as $source => $target ) {
			if ( ! $professions->has( $target ) ) {
				$professionValues[ $source ] = $target;
			}
		}
		foreach ( self::posted_pairs( 'profession_values' ) as $pair ) {
			if ( $professions->has( $pair['target'] ) ) {
				$professionValues[ $pair['source'] ] = $pair['target'];
			}
		}

		// Kody stanów nie są tu poprawiane ani filtrowane: ImportMapping odrzuca wszystko spoza
		// ALLOWED_STATUSES, więc "Approved" czy "email_unconfirmed" nie przechodzą dalej.
		$statusValues = array();
		foreach ( self::posted_pairs( 'status_values' ) as $pair ) {
			$statusValues[ $pair['source'] ] = $pair['target'];
		}

		$mapping = ImportMapping::fromArray(
			array(
				'fields'           => $fields,
				'professionKey'    => self::posted_string( 'profession_key' ),
				'professionValues' => $professionValues,
				'statusKey'        => self::posted_string( 'status_key' ),
				'statusValues'     => $statusValues,
				'fallback'         => self::posted_string( 'fallback' ),
			)
		);

		// Pusty formularz przy pustym zapisanym mapowaniu nic nie zmienia i tak to ogłaszamy. Przy niepustym
		// zapisanym jest świadomym wyczyszczeniem: odmowa zostawiłaby administratora bez drogi do zera
		// poza bazą danych (wpisy, których celu teraz nie ma, i tak zostają, patrz wyżej).
		if ( $mapping->isEmpty() && $storedMapping->isEmpty() ) {
			Request::redirect( Menu::SLUG_IMPORT, self::NOTICE_NOTHING_MAPPED );
			return;
		}

		// Zapis bez zmian zwraca false z update_option(), więc wyniku nie interpretujemy.
		ImportMappingRepository::save( $mapping );

		Request::redirect( Menu::SLUG_IMPORT, $mapping->isEmpty() ? self::NOTICE_CLEARED : self::NOTICE_SAVED_MAPPING );
	}

	/**
	 * Wypisuje ekran: tabelę skanu, formularz mapowania i miejsce na raport.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Import danych', 'portal-users' ) . '</h1>';
		self::render_notices();

		// Porażka skanu nie może wyglądać jak pusty portal: administrator nic by nie zmapował i nie wiedział czemu.
		$stats = array();
		try {
			$stats = MetaKeyScanner::scan();
			self::render_scan( $stats );
		} catch ( \RuntimeException $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}

		self::render_form( $stats );
		echo '<div class="pusers-import-report"></div>';
		echo '</div>';
	}

	/**
	 * Powiadomienia po zapisie.
	 */
	private static function render_notices(): void {
		$notice = Request::notice();
		if ( self::NOTICE_SAVED_MAPPING === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Mapowanie importu zostało zapisane.', 'portal-users' ) . '</p></div>';
		}
		if ( self::NOTICE_CLEARED === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Mapowanie importu zostało wyczyszczone.', 'portal-users' ) . '</p></div>';
		}
		if ( self::NOTICE_NOTHING_MAPPED === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nic nie zapisano: mapowanie było puste i formularz nie wskazał żadnego klucza metadanych.', 'portal-users' ) . '</p></div>';
		}
	}

	/**
	 * Tabela skanu: klucz, liczba kont i próbki wartości. Wszystko pochodzi z bazy, więc wszystko jest eskejpowane.
	 *
	 * @param array<int, MetaKeyStat> $stats Wynik skanu.
	 */
	private static function render_scan( array $stats ): void {
		echo '<h2>' . esc_html__( 'Klucze metadanych w bazie', 'portal-users' ) . '</h2>';
		if ( array() === $stats ) {
			echo '<p>' . esc_html__( 'Nie znaleziono żadnych kluczy metadanych do zmapowania.', 'portal-users' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Klucz', 'portal-users' ) . '</th><th scope="col">' . esc_html__( 'Liczba kont', 'portal-users' ) . '</th><th scope="col">' . esc_html__( 'Przykładowe wartości', 'portal-users' ) . '</th></tr></thead><tbody>';
		foreach ( $stats as $stat ) {
			echo '<tr><td><code>' . esc_html( $stat->key ) . '</code></td><td>' . esc_html( (string) $stat->accounts ) . '</td><td>' . esc_html( implode( ', ', $stat->samples ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Formularz mapowania. Wartości początkowe pochodzą z zapisanego mapowania.
	 *
	 * @param array<int, MetaKeyStat> $stats Wynik skanu; pusty także wtedy, gdy skan się nie powiódł.
	 */
	private static function render_form( array $stats ): void {
		$mapping = ImportMappingRepository::get();
		$stored  = $mapping->toArray();

		// Zapisane klucze zostają na liście także wtedy, gdy skan ich nie widzi (porażka skanu, usunięte konta).
		$keys = array();
		foreach ( $stats as $stat ) {
			$keys[ $stat->key ] = $stat->key;
		}
		foreach ( $mapping->sourceKeys() as $key ) {
			$keys[ $key ] = $key;
		}

		$professions = array();
		foreach ( ProfessionRepository::get()->all() as $profession ) {
			$professions[ $profession->key ] = $profession->label;
		}

		echo '<h2>' . esc_html__( 'Mapowanie', 'portal-users' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_header( self::ACTION_SAVE );

		echo '<h3>' . esc_html__( 'Pola', 'portal-users' ) . '</h3>';
		$acfFields = self::source()->fields();
		if ( array() === $acfFields ) {
			echo '<p>' . esc_html__( 'Wybrana grupa pól ACF nie jest dostępna albo nie zawiera pól. Wskaż grupę w ustawieniach.', 'portal-users' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Pole', 'portal-users' ) . '</th><th scope="col">' . esc_html__( 'Klucz źródłowy', 'portal-users' ) . '</th></tr></thead><tbody>';
			foreach ( $acfFields as $fieldKey => $label ) {
				$fieldKey = (string) $fieldKey;
				echo '<tr><th scope="row">' . esc_html( $label ) . ' <code>' . esc_html( $fieldKey ) . '</code></th><td>';
				self::render_select( self::FIELD_PREFIX . 'fields[' . $fieldKey . ']', $keys, $stored['fields'][ $fieldKey ] ?? '', true );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Profesja', 'portal-users' ) . '</h3>';
		echo '<p><label>' . esc_html__( 'Klucz z profesją', 'portal-users' ) . ' ';
		self::render_select( self::FIELD_PREFIX . 'profession_key', $keys, $stored['professionKey'], true );
		echo '</label></p>';
		self::render_pairs( 'profession_values', $stored['professionValues'], $professions, __( 'Profesja', 'portal-users' ) );

		echo '<h3>' . esc_html__( 'Stan konta', 'portal-users' ) . '</h3>';
		echo '<p><label>' . esc_html__( 'Klucz ze stanem', 'portal-users' ) . ' ';
		self::render_select( self::FIELD_PREFIX . 'status_key', $keys, $stored['statusKey'], true );
		echo '</label></p>';
		self::render_pairs( 'status_values', $stored['statusValues'], self::status_options(), __( 'Stan konta', 'portal-users' ) );

		echo '<p><label>' . esc_html__( 'Konto bez rozpoznanego stanu', 'portal-users' ) . ' ';
		self::render_select(
			self::FIELD_PREFIX . 'fallback',
			array(
				ImportMapping::FALLBACK_NONE     => __( 'zostaw bez stanu', 'portal-users' ),
				ImportMapping::FALLBACK_PENDING  => __( 'skieruj do wniosków', 'portal-users' ),
				ImportMapping::FALLBACK_APPROVED => __( 'zatwierdź', 'portal-users' ),
			),
			$stored['fallback'],
			false
		);
		echo '</label></p>';

		submit_button( __( 'Zapisz mapowanie', 'portal-users' ) );
		echo '</form>';
	}

	/**
	 * Tabela par "wartość źródłowa => cel": zapisane pary, a pod nimi puste wiersze do uzupełnienia.
	 *
	 * @param string                $name    Nazwa pola bez prefiksu.
	 * @param array<string, string> $stored  Zapisane pary: wartość źródłowa => cel.
	 * @param array<string, string> $targets Dostępne cele: wartość opcji => etykieta.
	 * @param string                $heading Nagłówek kolumny celu.
	 */
	private static function render_pairs( string $name, array $stored, array $targets, string $heading ): void {
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Wartość w bazie', 'portal-users' ) . '</th><th scope="col">' . esc_html( $heading ) . '</th></tr></thead><tbody>';

		$rows = array();
		foreach ( $stored as $source => $target ) {
			$rows[] = array( (string) $source, $target );
		}
		for ( $i = 0; $i < self::BLANK_PAIR_ROWS; $i++ ) {
			$rows[] = array( '', '' );
		}

		foreach ( $rows as $index => $row ) {
			$base = self::FIELD_PREFIX . $name . '[' . $index . ']';
			printf( '<tr><td><input type="text" name="%1$s[source]" value="%2$s" class="regular-text" /></td><td>', esc_attr( $base ), esc_attr( $row[0] ) );
			self::render_select( $base . '[target]', $targets, $row[1], true );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Lista wyboru z opcjonalną pustą opcją (nie mapuj).
	 *
	 * @param string                $name     Atrybut name.
	 * @param array<string, string> $options  Wartość opcji => etykieta.
	 * @param string                $selected Zaznaczona wartość.
	 * @param bool                  $blank    Czy dodać pustą opcję na początku.
	 */
	private static function render_select( string $name, array $options, string $selected, bool $blank ): void {
		printf( '<select name="%s">', esc_attr( $name ) );
		if ( $blank ) {
			printf( '<option value=""%1$s>—</option>', selected( '', $selected, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() zwraca gotowy, bezpieczny atrybut.
		}
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $selected, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() zwraca gotowy, bezpieczny atrybut.
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Stany oferowane w formularzu: wyłącznie ImportMapping::ALLOWED_STATUSES, kody małymi literami.
	 *
	 * @return array<string, string> Kod stanu => etykieta.
	 */
	private static function status_options(): array {
		$labels = array(
			'pending'  => __( 'oczekujące (pending)', 'portal-users' ),
			'approved' => __( 'zatwierdzone (approved)', 'portal-users' ),
			'rejected' => __( 'odrzucone (rejected)', 'portal-users' ),
		);

		$options = array();
		foreach ( ImportMapping::ALLOWED_STATUSES as $code ) {
			$options[ $code ] = $labels[ $code ] ?? $code;
		}
		return $options;
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
	 * Tablica z żądania pod nazwą z prefiksem albo pusta. Nonce i uprawnienia sprawdza Request::guard().
	 *
	 * @param string $key Klucz pola bez prefiksu.
	 * @return array<array-key, mixed>
	 */
	private static function posted_array( string $key ): array {
		$name = self::FIELD_PREFIX . $key;
		if ( ! isset( $_POST[ $name ] ) || ! is_array( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w obsłudze żądania.
			return array();
		}
		$raw = wp_unslash( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() w obsłudze żądania; wartości przycinane i sprawdzane niżej oraz w ImportMapping.
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Tekst z żądania, przycięty, albo pusty ciąg. Bez usuwania znaczników: wartość źródłowa jest porównywana
	 * z bazą dokładnie, więc zmiana jej treści ukryłaby parę; wyjście zawsze przechodzi przez esc_*().
	 *
	 * @param string $key Klucz pola bez prefiksu.
	 */
	private static function posted_string( string $key ): string {
		$name = self::FIELD_PREFIX . $key;
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w obsłudze żądania.
			return '';
		}
		return trim( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() w obsłudze żądania; ImportMapping sprawdza kodowanie.
	}

	/**
	 * Mapa pól z żądania: klucz pola ACF => źródłowy klucz metadanych. Puste wartości (nie mapuj) odpadają.
	 *
	 * @return array<string, string>
	 */
	private static function posted_fields(): array {
		$out = array();
		foreach ( self::posted_array( 'fields' ) as $field => $metaKey ) {
			if ( is_string( $metaKey ) && '' !== trim( $metaKey ) ) {
				$out[ (string) $field ] = trim( $metaKey );
			}
		}
		return $out;
	}

	/**
	 * Pary "wartość źródłowa => cel" z żądania. Wiersz o złym kształcie albo z pustą wartością lub celem odpada.
	 *
	 * @param string $key Klucz pola bez prefiksu.
	 * @return list<array{source: string, target: string}>
	 */
	private static function posted_pairs( string $key ): array {
		$out = array();
		foreach ( self::posted_array( $key ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['source'], $row['target'] ) || ! is_string( $row['source'] ) || ! is_string( $row['target'] ) ) {
				continue;
			}
			$source = trim( $row['source'] );
			$target = trim( $row['target'] );
			if ( '' === $source || '' === $target ) {
				continue;
			}
			$out[] = array(
				'source' => $source,
				'target' => $target,
			);
		}
		return $out;
	}
}
