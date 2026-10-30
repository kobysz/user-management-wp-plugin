<?php
/**
 * Ekran importu: skan kluczy user_meta starej wtyczki członkowskiej i formularz mapowania.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Account\Backfill;
use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Domain\Import\AccountChange;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Import\ImportReport;
use PortalUsers\Domain\Import\MetaKeyStat;
use PortalUsers\Import\ImportRunner;
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
 *
 * Zapis danych przechodzi przez bramkę: `handle_apply()` działa tylko wtedy, gdy istnieje podgląd, jego odcisk
 * równa się odciskowi zapisanego mapowania (czytanego tą samą drogą, `ImportMappingRepository::get()`, po obu stronach)
 * i jest młodszy niż `PREVIEW_MAX_AGE`. Zapis zużywa podgląd (przed przebiegiem), więc ten sam raport nie autoryzuje drugiego przebiegu.
 */
final class ImportScreen {

	public const ACTION_SAVE    = 'pusers_save_import_mapping';
	public const ACTION_PREVIEW = 'pusers_preview_import';
	public const ACTION_APPLY   = 'pusers_apply_import';

	public const ACTION_BACKFILL = 'pusers_backfill_approved';

	public const NOTICE_SAVED_MAPPING  = 'import_mapping_saved';
	public const NOTICE_NOTHING_MAPPED = 'import_nothing_mapped';
	public const NOTICE_CLEARED        = 'import_mapping_cleared';
	public const NOTICE_PREVIEW_READY  = 'import_preview_ready';
	public const NOTICE_STALE_PREVIEW  = 'import_stale_preview';
	public const NOTICE_APPLIED        = 'import_applied';
	public const NOTICE_NOTHING_TO_RUN = 'import_nothing_to_run';
	public const NOTICE_BACKFILL_DONE  = 'backfill_done';

	/**
	 * Jak długo (w sekundach) raport z trybu próbnego może autoryzować zapis.
	 */
	public const PREVIEW_MAX_AGE = 3600;

	private const FIELD_PREFIX = 'pusers_';

	/**
	 * Ile pustych wierszy par "wartość => cel" formularz dokłada pod zapisanymi.
	 */
	private const BLANK_PAIR_ROWS = 3;

	/**
	 * Podpina podstronę oraz obsługę zapisu mapowania, trybu próbnego i zapisu danych.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( self::class, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_APPLY, array( self::class, 'handle_apply' ) );
		add_action( 'admin_post_' . self::ACTION_BACKFILL, array( self::class, 'handle_backfill' ) );
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
	 * Tryb próbny: liczy raport dla zapisanego mapowania, niczego nie zapisując w kontach, i zapamiętuje go
	 * razem z odciskiem tego mapowania. Mapowanie czytane jest przez `get()`, czyli przycięte; ten sam obiekt
	 * idzie do `dry_run()` i do odcisku, a bramka zapisu czyta mapowanie tą samą drogą.
	 */
	public static function handle_preview(): void {
		Request::guard( self::ACTION_PREVIEW );
		self::require_post();

		$mapping = ImportMappingRepository::get();
		$report  = ( new ImportRunner() )->dry_run( $mapping );

		// Zapis bez zmian zwraca false z update_option(), więc wyniku nie interpretujemy.
		ImportMappingRepository::save_preview( $mapping, $report );

		Request::redirect( Menu::SLUG_IMPORT, self::NOTICE_PREVIEW_READY );
	}

	/**
	 * Zapisuje import, ale tylko przez bramkę: podgląd istnieje, jego odcisk równa się odciskowi zapisanego
	 * mapowania i jest młodszy niż `PREVIEW_MAX_AGE`. Niespełniony warunek to komunikat o nieaktualnym raporcie
	 * i żadnego zapisu. Podgląd jest czyszczony po bramce, a przed przebiegiem, więc ten sam raport nie zadziała drugi raz;
	 * wynik wraca do ekranu w adresie przekierowania (liczby, nie treść).
	 */
	public static function handle_apply(): void {
		Request::guard( self::ACTION_APPLY );
		self::require_post();

		$mapping = ImportMappingRepository::get();
		// Puste mapowanie nie ma czego importować: odmowa jest jawna, a podgląd zostaje nietknięty.
		if ( $mapping->isEmpty() ) {
			Request::redirect( Menu::SLUG_IMPORT, self::NOTICE_NOTHING_TO_RUN );
			return;
		}
		if ( null === self::valid_preview( $mapping ) ) {
			Request::redirect( Menu::SLUG_IMPORT, self::NOTICE_STALE_PREVIEW );
			return;
		}

		// Raport zużywamy przed przebiegiem: drugie równoległe kliknięcie nie przejdzie bramki, więc dziennik
		// nie dostanie dwóch wierszy o jednej decyzji. Przebieg przerwany w środku wymaga nowego trybu próbnego,
		// bo stan portalu się zmienił; ponowienie jest bezpieczne, bo runner porównuje przed zapisem.
		ImportMappingRepository::clear_preview();
		$report = ( new ImportRunner() )->apply( $mapping, get_current_user_id() );

		Request::redirect(
			Menu::SLUG_IMPORT,
			self::NOTICE_APPLIED,
			array(
				self::FIELD_PREFIX . 'changed'        => $report->changed(),
				self::FIELD_PREFIX . 'write_failures' => $report->writeFailures(),
				self::FIELD_PREFIX . 'log_failed'     => $report->logFailed() ? 1 : 0,
			)
		);
	}

	/**
	 * Nadaje stan `approved` kontom sprzed wdrożenia wtyczki, które nie mają żadnego stanu. To droga dla portalu,
	 * którego stara wtyczka nie trzymała stanu w metadanych (inaczej służy mapowanie importu). Wynik wraca
	 * do ekranu w adresie przekierowania (liczby, nie treść).
	 */
	public static function handle_backfill(): void {
		Request::guard( self::ACTION_BACKFILL );
		self::require_post();

		$result = Backfill::grant_approved( get_current_user_id() );

		Request::redirect(
			Menu::SLUG_IMPORT,
			self::NOTICE_BACKFILL_DONE,
			array(
				self::FIELD_PREFIX . 'granted'     => $result['granted'],
				self::FIELD_PREFIX . 'not_granted' => $result['not_granted'],
				self::FIELD_PREFIX . 'log_failed'  => $result['log_failed'] ? 1 : 0,
			)
		);
	}

	/**
	 * Podgląd, który może teraz autoryzować zapis, albo null. Trzy warunki bramki w jednym miejscu: ekran
	 * używa tej samej decyzji do pokazania przycisku i obsługa zapisu do wykonania.
	 *
	 * @param ImportMapping $mapping Mapowanie z `ImportMappingRepository::get()`; nic innego nie wolno tu hashować.
	 * @return array{hash: string, report: array<mixed>, at: int}|null
	 */
	private static function valid_preview( ImportMapping $mapping ): ?array {
		$preview = ImportMappingRepository::preview();
		if ( null === $preview || ! hash_equals( $mapping->hash(), $preview['hash'] ) ) {
			return null;
		}

		// Czas z przyszłości (zegar, zmanipulowany zapis) też nie otwiera bramki.
		$age = time() - $preview['at'];
		if ( $age < 0 || $age >= self::PREVIEW_MAX_AGE ) {
			return null;
		}

		return $preview;
	}

	/**
	 * Wypisuje ekran: tabelę skanu, formularz mapowania i raport z trybu próbnego.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		// Pola grupy ACF i mapowanie czytamy raz na wyświetlenie ekranu i podajemy dalej.
		$acfFields = self::source()->fields();
		$mapping   = ImportMappingRepository::get( $acfFields );

		echo '<div class="wrap"><h1>' . esc_html__( 'Import danych', 'portal-users' ) . '</h1>';
		self::render_notices();
		self::render_backfill();

		// Porażka skanu nie może wyglądać jak pusty portal: administrator nic by nie zmapował i nie wiedział czemu.
		$stats = array();
		try {
			$stats = MetaKeyScanner::scan();
			self::render_scan( $stats );
		} catch ( \RuntimeException $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}

		self::render_form( $stats, $mapping, $acfFields );
		self::render_report( $mapping );
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
		if ( self::NOTICE_PREVIEW_READY === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Raport z trybu próbnego jest gotowy. Nic nie zostało zapisane w kontach.', 'portal-users' ) . '</p></div>';
		}
		if ( self::NOTICE_STALE_PREVIEW === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nic nie zapisano: brak aktualnego raportu z trybu próbnego dla tego mapowania. Uruchom tryb próbny ponownie.', 'portal-users' ) . '</p></div>';
		}
		if ( self::NOTICE_NOTHING_TO_RUN === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nic nie zapisano: żadne pole ani klucz nie jest zmapowane, więc nie ma czego importować.', 'portal-users' ) . '</p></div>';
		}
		if ( self::NOTICE_APPLIED === $notice ) {
			self::render_applied_notices();
		}
		if ( self::NOTICE_BACKFILL_DONE === $notice ) {
			self::render_backfill_notices();
		}
	}

	/**
	 * Wynik nadania stanu z adresu przekierowania: liczba kont i, gdy wiersza nie udało się zapisać w dzienniku,
	 * ostrzeżenie w tym samym kształcie co przy imporcie. Liczby pochodzą z adresu, więc wychodzą przez esc_html().
	 */
	private static function render_backfill_notices(): void {
		$granted = self::query_count( 'granted' );

		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: %d: liczba kont, którym nadano stan */
				_n( 'Nadano stan „zatwierdzone” kontom: %d.', 'Nadano stan „zatwierdzone” kontom: %d.', $granted, 'portal-users' ),
				$granted
			)
		) . '</p></div>';

		$notGranted = self::query_count( 'not_granted' );
		if ( $notGranted > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %d: liczba kont, którym nie udało się nadać stanu */
					_n(
						'Nadanie stanu nie powiodło się dla %d konta: nadal nie ma stanu. Ponowne uruchomienie jest bezpieczne, bo takie konta zostają w zakresie i pomija ono te, które już mają stan.',
						'Nadanie stanu nie powiodło się dla %d kont: nadal nie mają stanu. Ponowne uruchomienie jest bezpieczne, bo takie konta zostają w zakresie i pomija ono te, które już mają stan.',
						$notGranted,
						'portal-users'
					),
					$notGranted
				)
			) . '</p></div>';
		}

		if ( self::query_count( 'log_failed' ) > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Stany zostały nadane, ale nie udało się zapisać wpisu w dzienniku zdarzeń: nie ma śladu, kto uruchomił to nadanie. Zapisz tę informację osobno.', 'portal-users' ) . '</p></div>';
		}
	}

	/**
	 * Sekcja z przyciskiem nadania stanu kontom sprzed wdrożenia. Bez kont w zakresie nie ma czego nadawać,
	 * więc nie ma przycisku. Przycisk wymaga potwierdzenia, bo nadaje dostęp wszystkim kontom naraz.
	 */
	private static function render_backfill(): void {
		$count = Backfill::stateless_count();
		if ( 0 === $count ) {
			return;
		}

		echo '<div class="pusers-backfill"><h2>' . esc_html__( 'Konta sprzed wdrożenia bez stanu', 'portal-users' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: liczba kont bez stanu */
				__( 'Konta sprzed wdrożenia wtyczki bez stanu: %d.', 'portal-users' ),
				$count
			)
		) . ' ' . esc_html__( 'Jeśli stara wtyczka trzymała stan w metadanych, nadaj go mapowaniem poniżej: odróżni konta zatwierdzone od niezatwierdzonych. Przycisk poniżej nadaje „zatwierdzone” WSZYSTKIM tym kontom, także tym, których stara wtyczka nie zatwierdziła; jest dla portalu, który stanu nie trzymał.', 'portal-users' ) . '</p>';

		$confirm = esc_js( __( 'Nadać stan „zatwierdzone” wszystkim kontom bez stanu sprzed wdrożenia? Tego nie da się cofnąć jednym kliknięciem.', 'portal-users' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . $confirm . '\');">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $confirm przeszło przez esc_js().
		self::form_header( self::ACTION_BACKFILL );
		submit_button( __( 'Zatwierdź wszystkie konta bez stanu', 'portal-users' ), 'secondary' );
		echo '</form></div>';
	}

	/**
	 * Wynik zapisu danych z adresu przekierowania: sukces z liczbą kont oraz ostrzeżenia o częściowym zapisie
	 * i o braku śladu w dzienniku zdarzeń. Liczby pochodzą z adresu, więc są tylko cyframi i wychodzą przez esc_html().
	 */
	private static function render_applied_notices(): void {
		$changed  = self::query_count( 'changed' );
		$failures = self::query_count( 'write_failures' );

		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: %d: liczba zmienionych kont */
				_n( 'Import zapisany. Zmienione konta: %d.', 'Import zapisany. Zmienione konta: %d.', $changed, 'portal-users' ),
				$changed
			)
		) . '</p></div>';

		if ( $failures > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %d: liczba kont, w których zapis się nie udał */
					_n(
						'Zapis nie powiódł się dla %d konta, więc mogło zostać zapisane tylko częściowo. Ponowne uruchomienie importu jest bezpieczne: pomija wartości, które już się zgadzają. Uruchom tryb próbny i zapisz jeszcze raz.',
						'Zapis nie powiódł się dla %d kont, więc mogły zostać zapisane tylko częściowo. Ponowne uruchomienie importu jest bezpieczne: pomija wartości, które już się zgadzają. Uruchom tryb próbny i zapisz jeszcze raz.',
						$failures,
						'portal-users'
					),
					$failures
				)
			) . '</p></div>';
		}

		if ( self::query_count( 'log_failed' ) > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Dane zostały zapisane, ale nie udało się zapisać wpisu w dzienniku zdarzeń: nie ma śladu, kto uruchomił ten import. Zapisz tę informację osobno.', 'portal-users' ) . '</p></div>';
		}
	}

	/**
	 * Nieujemna liczba całkowita z adresu strony albo zero. Tylko cyfry i rozsądna długość: `absint('-5')` dałoby 5.
	 *
	 * @param string $name Nazwa argumentu bez prefiksu.
	 */
	private static function query_count( string $name ): int {
		$key = self::FIELD_PREFIX . $name;
		// Odczyt liczby do wyświetlenia po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}
		$raw = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sprawdzane niżej: tylko cyfry.
		return ( ctype_digit( $raw ) && strlen( $raw ) <= 9 ) ? (int) $raw : 0;
	}

	/**
	 * Raport z trybu próbnego z przyciskiem zapisu. Przycisk pojawia się tylko wtedy, gdy bramka byłaby otwarta;
	 * nieaktualny raport jest opisany, ale nie można go zatwierdzić.
	 *
	 * @param ImportMapping $mapping Mapowanie z `ImportMappingRepository::get()`, to samo, które trafia do bramki.
	 */
	private static function render_report( ImportMapping $mapping ): void {
		echo '<div class="pusers-import-report"><h2>' . esc_html__( 'Raport z trybu próbnego', 'portal-users' ) . '</h2>';
		if ( $mapping->isEmpty() ) {
			echo '<p>' . esc_html__( 'Nic nie jest zmapowane, więc nie ma czego importować. Zapisz mapowanie, żeby uruchomić tryb próbny.', 'portal-users' ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Tryb próbny liczy, co zmieniłby import dla zapisanego mapowania, i niczego nie zapisuje. Najpierw zapisz mapowanie, potem uruchom tryb próbny.', 'portal-users' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_header( self::ACTION_PREVIEW );
		submit_button( __( 'Uruchom tryb próbny', 'portal-users' ), 'secondary' );
		echo '</form>';

		$preview = ImportMappingRepository::preview();
		if ( null === $preview ) {
			echo '</div>';
			return;
		}

		if ( null === self::valid_preview( $mapping ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ostatni raport jest nieaktualny: mapowanie zmieniło się albo raport ma ponad godzinę. Uruchom tryb próbny ponownie.', 'portal-users' ) . '</p></div></div>';
			return;
		}

		$report = $preview['report'];
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Przeskanowane konta', 'portal-users' ) . '</th><td>' . esc_html( (string) self::report_int( $report, 'scanned' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Konta do zmiany', 'portal-users' ) . '</th><td>' . esc_html( (string) self::report_int( $report, 'changed' ) ) . '</td></tr>';
		echo '</tbody></table>';

		self::render_issues( $report );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_header( self::ACTION_APPLY );
		submit_button( __( 'Zapisz import', 'portal-users' ) );
		echo '</form></div>';
	}

	/**
	 * Zastrzeżenia raportu: licznik każdego z próbką kont jako linkami do profilu, a nad nimi ostrzeżenie,
	 * gdy są konta bez profesji albo z niepoprawnym numerem.
	 *
	 * @param array<mixed> $report Raport z zapisanego podglądu (dane z bazy, więc czytane defensywnie).
	 */
	private static function render_issues( array $report ): void {
		$issues = isset( $report['issues'] ) && is_array( $report['issues'] ) ? $report['issues'] : array();
		$counts = array();
		foreach ( self::issue_labels() as $code => $label ) {
			$count = isset( $issues[ $code ] ) && is_int( $issues[ $code ] ) && $issues[ $code ] > 0 ? $issues[ $code ] : 0;
			if ( $count > 0 ) {
				$counts[ $code ] = $count;
			}
		}
		if ( array() === $counts ) {
			return;
		}

		$serious = array( AccountChange::ISSUE_PROFESSION_UNMAPPED, AccountChange::ISSUE_PROFESSION_MISSING, AccountChange::ISSUE_CREDENTIAL_INVALID, AccountChange::ISSUE_STATUS_MISSING );
		if ( array() !== array_intersect( $serious, array_keys( $counts ) ) ) {
			if ( array() !== array_intersect( array( AccountChange::ISSUE_PROFESSION_UNMAPPED, AccountChange::ISSUE_PROFESSION_MISSING, AccountChange::ISSUE_CREDENTIAL_INVALID ), array_keys( $counts ) ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Część kont nie ma rozpoznanej profesji albo ma niepoprawny numer. Sprawdź je przed zapisem: takie konta nie dostaną tych danych.', 'portal-users' ) . '</p></div>';
			}
			if ( isset( $counts[ AccountChange::ISSUE_STATUS_MISSING ] ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Część kont ze starej wtyczki nie ma wartości stanu, więc ich stan rozstrzygnie ustawienie „Konto bez rozpoznanego stanu”. Sprawdź je przed zapisem: to ono zdecyduje, czy takie konta wejdą na portal, trafią do wniosków, czy zostaną bez stanu.', 'portal-users' ) . '</p></div>';
			}
		}

		$samples = isset( $report['samples'] ) && is_array( $report['samples'] ) ? $report['samples'] : array();
		echo '<h3>' . esc_html__( 'Zastrzeżenia', 'portal-users' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Zastrzeżenie', 'portal-users' ) . '</th><th scope="col">' . esc_html__( 'Liczba kont', 'portal-users' ) . '</th><th scope="col">' . esc_html__( 'Przykładowe konta', 'portal-users' ) . '</th></tr></thead><tbody>';
		foreach ( self::issue_labels() as $code => $label ) {
			if ( ! isset( $counts[ $code ] ) ) {
				continue;
			}
			$sample = isset( $samples[ $code ] ) && is_array( $samples[ $code ] ) ? $samples[ $code ] : array();
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $counts[ $code ] ) . '</td><td>';
			self::render_account_links( $sample );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Identyfikatory kont jako linki do profilu. Przyjmowane są tylko dodatnie liczby całkowite; reszta odpada.
	 *
	 * @param array<mixed> $ids Identyfikatory z raportu.
	 */
	private static function render_account_links( array $ids ): void {
		$links = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				continue;
			}
			$links[] = '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . absint( $id ) ) ) . '">' . esc_html( (string) absint( $id ) ) . '</a>';
		}
		echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- każdy element złożony wyżej z esc_url() i esc_html().
	}

	/**
	 * Etykiety zastrzeżeń raportu w kolejności wyświetlania.
	 *
	 * @return array<string, string>
	 */
	private static function issue_labels(): array {
		return array(
			AccountChange::ISSUE_PROFESSION_UNMAPPED => __( 'Wartość profesji bez przypisania w mapowaniu', 'portal-users' ),
			AccountChange::ISSUE_PROFESSION_MISSING  => __( 'Konto bez profesji w danych źródłowych', 'portal-users' ),
			AccountChange::ISSUE_CREDENTIAL_INVALID  => __( 'Niepoprawny numer uprawnień', 'portal-users' ),
			AccountChange::ISSUE_STATUS_UNMAPPED     => __( 'Wartość stanu bez przypisania w mapowaniu', 'portal-users' ),
			AccountChange::ISSUE_STATUS_MISSING      => __( 'Konto bez stanu w danych źródłowych', 'portal-users' ),
			AccountChange::ISSUE_VALUE_UNUSABLE      => __( 'Wartość nie do użycia', 'portal-users' ),
		);
	}

	/**
	 * Liczba z zapisanego raportu albo zero.
	 *
	 * @param array<mixed> $report Raport z podglądu.
	 * @param string       $key    Klucz liczby.
	 */
	private static function report_int( array $report, string $key ): int {
		return isset( $report[ $key ] ) && is_int( $report[ $key ] ) && $report[ $key ] > 0 ? $report[ $key ] : 0;
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
	 * @param array<int, MetaKeyStat> $stats     Wynik skanu; pusty także wtedy, gdy skan się nie powiódł.
	 * @param ImportMapping           $mapping   Zapisane mapowanie.
	 * @param array<string, string>   $acfFields Pola grupy ACF: klucz => etykieta.
	 */
	private static function render_form( array $stats, ImportMapping $mapping, array $acfFields ): void {
		$stored = $mapping->toArray();

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
		// Bez wp_nonce_field(): ekran ma kilka formularzy, a ta funkcja nadaje każdemu to samo id.
		echo '<input type="hidden" name="' . esc_attr( Request::NONCE_FIELD ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
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
