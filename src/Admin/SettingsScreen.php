<?php
/**
 * Ekran ustawień ogólnych: formularz i jego zapis.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfGroupList;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Config\SettingsIssue;
use PortalUsers\Persistence\SettingsRepository;
use WP_Post;

/**
 * Ekran ustawień ogólnych renderowany serwerowo. Pola formularza mają prefiks `pusers_`,
 * a klucze tablicy przekazywanej do Settings::fromArray() to snake_case bez prefiksu.
 */
final class SettingsScreen {

	public const ACTION               = 'pusers_save_settings';
	public const NOTICE_INVALID_GROUP = 'acf_group_invalid';

	private const FIELD_PREFIX = 'pusers_';

	/**
	 * Pola logiczne. Niezaznaczony checkbox nie wysyła nic, a Settings::fromArray()
	 * mapuje brak klucza na wartość domyślną (dla akceptacji: włączone), więc zapis
	 * zawsze podstawia jawne `0`.
	 */
	private const FLAG_FIELDS = array( 'approval_enabled', 'delete_data_on_uninstall' );

	/**
	 * Pola jednowierszowe przekazywane do domeny w postaci tekstu (liczby i identyfikatory
	 * normalizuje Settings::fromArray()).
	 */
	private const TEXT_FIELDS = array(
		'login_page_id',
		'register_page_id',
		'reset_page_id',
		'confirmation_token_hours',
		'unconfirmed_purge_days',
		'rate_limit_per_hour',
		'time_trap_seconds',
		'trusted_proxy_header',
		'acf_group_key',
	);

	/**
	 * Pola wielowierszowe: po jednej wartości w linii, domena oczekuje tablicy.
	 */
	private const LIST_FIELDS = array( 'notify_emails', 'rejection_reasons' );

	/**
	 * Podpina obsługę zapisu formularza.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
	}

	/**
	 * Zapisuje ustawienia: uprawnienia, nonce, zapis, przekierowanie.
	 */
	public static function handle_save(): void {
		Request::guard( self::ACTION );

		$raw = array();

		foreach ( self::FLAG_FIELDS as $key ) {
			$raw[ $key ] = '1' === self::posted( $key ) ? '1' : '0';
		}
		foreach ( self::TEXT_FIELDS as $key ) {
			$value = self::posted( $key );
			if ( null !== $value ) {
				$raw[ $key ] = sanitize_text_field( $value );
			}
		}
		// Grupa pól to zamknięta lista z zaufanego źródła: klucz spoza listy to manipulacja
		// albo zepsuta konfiguracja, więc zapis jest odrzucany, nie degradowany. Pusty przechodzi.
		if ( isset( $raw['acf_group_key'] ) && '' !== $raw['acf_group_key'] && ! array_key_exists( $raw['acf_group_key'], AcfGroupList::groups() ) ) {
			Request::redirect( Menu::SLUG_SETTINGS, self::NOTICE_INVALID_GROUP );
			return;
		}

		// Domena oczekuje tablicy: surowy tekst po cichu dałby pustą listę.
		foreach ( self::LIST_FIELDS as $key ) {
			$value = self::posted( $key );
			if ( null !== $value ) {
				$raw[ $key ] = self::lines( $value );
			}
		}

		// Zapis bez zmian zwraca false z update_option(), więc wyniku nie interpretujemy.
		SettingsRepository::save( Settings::fromArray( $raw ) );

		Request::redirect( Menu::SLUG_SETTINGS, Request::NOTICE_SAVED );
	}

	/**
	 * Problemy ze wskazanymi stronami: klucz ustawienia => kod. Pusty identyfikator
	 * (0) nie jest problemem: administrator po prostu jeszcze nie wybrał strony.
	 *
	 * @return array<string, string>
	 */
	public static function page_issues(): array {
		$settings = SettingsRepository::get();
		$pages    = array(
			'login_page_id'    => $settings->loginPageId,
			'register_page_id' => $settings->registerPageId,
			'reset_page_id'    => $settings->resetPageId,
		);
		$issues   = array();

		foreach ( $pages as $key => $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
				$issues[ $key ] = 'page_missing';
			} elseif ( 'publish' !== $post->post_status ) {
				$issues[ $key ] = 'page_not_published';
			}
		}

		return $issues;
	}

	/**
	 * Wypisuje ekran ustawień.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		$settings = SettingsRepository::get();

		echo '<div class="wrap"><h1>' . esc_html__( 'Portal: ustawienia', 'portal-users' ) . '</h1>';

		if ( Request::NOTICE_SAVED === Request::notice() ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ustawienia zostały zapisane.', 'portal-users' ) . '</p></div>';
		}
		if ( self::NOTICE_INVALID_GROUP === Request::notice() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nie zapisano ustawień: wybrana grupa pól ACF nie istnieje.', 'portal-users' ) . '</p></div>';
		}
		self::render_page_issues();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION, Request::NONCE_FIELD );

		echo '<table class="form-table" role="presentation"><tbody>';
		self::render_approval_section( $settings );
		self::render_pages_section( $settings );
		self::render_notifications_section( $settings );
		self::render_antispam_section( $settings );
		self::render_fields_section( $settings );
		self::render_uninstall_section( $settings );
		echo '</tbody></table>';

		submit_button();
		echo '</form></div>';
	}

	/**
	 * Ostrzeżenia o stronach, które nie istnieją albo nie są opublikowane.
	 */
	private static function render_page_issues(): void {
		$labels = array(
			'login_page_id'    => __( 'Strona logowania', 'portal-users' ),
			'register_page_id' => __( 'Strona rejestracji', 'portal-users' ),
			'reset_page_id'    => __( 'Strona resetu hasła', 'portal-users' ),
		);
		foreach ( self::page_issues() as $key => $code ) {
			$issue = SettingsIssue::warning( $code, array( 'page' => $labels[ $key ] ?? $key ) );
			echo '<div class="notice notice-warning"><p>' . esc_html( IssueMessages::text( $issue ) ) . '</p></div>';
		}
	}

	/**
	 * Sekcja akceptacji kont.
	 *
	 * @param Settings $settings Bieżące ustawienia.
	 */
	private static function render_approval_section( Settings $settings ): void {
		self::row_start( __( 'Akceptacja kont', 'portal-users' ), 'approval_enabled' );
		self::checkbox( 'approval_enabled', __( 'Nowe konta wymagają akceptacji redakcji', 'portal-users' ), $settings->approvalEnabled );
		self::row_end();
	}

	/**
	 * Sekcja stron logowania, rejestracji i resetu hasła.
	 *
	 * @param Settings $settings Bieżące ustawienia.
	 */
	private static function render_pages_section( Settings $settings ): void {
		$pages = array(
			'login_page_id'    => array( __( 'Strona logowania', 'portal-users' ), $settings->loginPageId ),
			'register_page_id' => array( __( 'Strona rejestracji', 'portal-users' ), $settings->registerPageId ),
			'reset_page_id'    => array( __( 'Strona resetu hasła', 'portal-users' ), $settings->resetPageId ),
		);
		foreach ( $pages as $key => $page ) {
			self::row_start( $page[0], $key );
			// wp_dropdown_pages() sama eskejpuje nazwę i etykiety opcji; PHPCS widzi tylko argumenty.
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_dropdown_pages(
				array(
					'name'              => self::name( $key ),
					'id'                => self::name( $key ),
					'selected'          => $page[1],
					'show_option_none'  => esc_html__( '— nie wybrano —', 'portal-users' ),
					'option_none_value' => '0',
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			self::row_end();
		}
	}

	/**
	 * Sekcja adresów redakcji.
	 *
	 * @param Settings $settings Bieżące ustawienia.
	 */
	private static function render_notifications_section( Settings $settings ): void {
		self::row_start( __( 'Adresy redakcji', 'portal-users' ), 'notify_emails' );
		self::textarea( 'notify_emails', $settings->notifyEmails );
		self::description( __( 'Po jednym adresie e-mail w linii. Na te adresy trafiają powiadomienia o nowych zgłoszeniach.', 'portal-users' ) );
		self::row_end();

		self::row_start( __( 'Powody odrzucenia', 'portal-users' ), 'rejection_reasons' );
		self::textarea( 'rejection_reasons', $settings->rejectionReasons );
		self::description( __( 'Po jednym powodzie w linii. Redakcja wybiera je przy odrzucaniu zgłoszenia.', 'portal-users' ) );
		self::row_end();
	}

	/**
	 * Sekcja progów antyspamu i ważności tokenów.
	 *
	 * @param Settings $settings Bieżące ustawienia.
	 */
	private static function render_antispam_section( Settings $settings ): void {
		$numbers = array(
			'confirmation_token_hours' => array( __( 'Ważność linku potwierdzającego (godziny)', 'portal-users' ), $settings->confirmationTokenHours, 1 ),
			'unconfirmed_purge_days'   => array( __( 'Usuwanie niepotwierdzonych kont po (dniach)', 'portal-users' ), $settings->unconfirmedPurgeDays, 1 ),
			'rate_limit_per_hour'      => array( __( 'Limit zgłoszeń z jednego adresu na godzinę', 'portal-users' ), $settings->rateLimitPerHour, 1 ),
			'time_trap_seconds'        => array( __( 'Minimalny czas wypełniania formularza (sekundy, 0 = wyłączone)', 'portal-users' ), $settings->timeTrapSeconds, 0 ),
		);
		foreach ( $numbers as $key => $number ) {
			self::row_start( $number[0], $key );
			printf(
				'<input type="number" class="small-text" id="%1$s" name="%1$s" value="%2$s" min="%3$s" step="1" />',
				esc_attr( self::name( $key ) ),
				esc_attr( (string) $number[1] ),
				esc_attr( (string) $number[2] )
			);
			self::row_end();
		}

		self::row_start( __( 'Nagłówek zaufanego proxy', 'portal-users' ), 'trusted_proxy_header' );
		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
			esc_attr( self::name( 'trusted_proxy_header' ) ),
			esc_attr( $settings->trustedProxyHeader )
		);
		self::description( __( 'Nazwa nagłówka z adresem klienta, np. X-Forwarded-For. Wypełnij tylko wtedy, gdy serwer stoi za zaufanym proxy; puste pole oznacza adres połączenia.', 'portal-users' ) );
		self::row_end();
	}

	/**
	 * Sekcja wyboru grupy pól ACF.
	 *
	 * @param Settings $settings Bieżące ustawienia.
	 */
	private static function render_fields_section( Settings $settings ): void {
		self::row_start( __( 'Grupa pól ACF', 'portal-users' ), 'acf_group_key' );
		printf( '<select id="%1$s" name="%1$s">', esc_attr( self::name( 'acf_group_key' ) ) );
		printf( '<option value="">%s</option>', esc_html__( '— nie wybrano —', 'portal-users' ) );
		foreach ( AcfGroupList::groups() as $key => $title ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $key ),
				selected( $settings->acfGroupKey, (string) $key, false ),
				esc_html( $title )
			);
		}
		echo '</select>';
		self::description( __( 'Pola z tej grupy tworzą formularz rejestracji.', 'portal-users' ) );
		self::row_end();
	}

	/**
	 * Sekcja bramki usuwania danych przy odinstalowaniu.
	 *
	 * @param Settings $settings Bieżące ustawienia.
	 */
	private static function render_uninstall_section( Settings $settings ): void {
		self::row_start( __( 'Odinstalowanie', 'portal-users' ), 'delete_data_on_uninstall' );
		self::checkbox( 'delete_data_on_uninstall', __( 'Usuń dane wtyczki przy jej odinstalowaniu', 'portal-users' ), $settings->deleteDataOnUninstall );
		echo '<p class="description"><strong>' . esc_html__( 'Uwaga:', 'portal-users' ) . '</strong> '
			. esc_html__( 'po zaznaczeniu tej opcji usunięcie wtyczki nieodwracalnie skasuje wszystkie jej ustawienia: ustawienia ogólne, listę profesji i macierz pól. Bez zaznaczenia dane zostają w bazie.', 'portal-users' )
			. '</p>';
		self::row_end();
	}

	/**
	 * Nazwa pola formularza z prefiksem.
	 *
	 * @param string $key Klucz ustawienia (snake_case).
	 */
	private static function name( string $key ): string {
		return self::FIELD_PREFIX . $key;
	}

	/**
	 * Wartość pola z żądania zapisu albo null, gdy pola nie przesłano. Nonce i uprawnienia
	 * sprawdza Request::guard() na początku handle_save().
	 *
	 * @param string $key Klucz ustawienia (snake_case).
	 */
	private static function posted( string $key ): ?string {
		$name = self::name( $key );
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w handle_save().
			return null;
		}
		return (string) wp_unslash( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() w handle_save(); wartość czyszczą wywołujący.
	}

	/**
	 * Dzieli tekst na niepuste linie.
	 *
	 * @param string $text Tekst z pola wielowierszowego.
	 * @return array<int, string>
	 */
	private static function lines( string $text ): array {
		// Nie \R: bez flagi /u dopasowuje bajt 0x85, który jest częścią polskiego „ą” w UTF-8.
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		if ( false === $lines ) {
			return array();
		}
		$out = array();
		foreach ( $lines as $line ) {
			$clean = sanitize_text_field( $line );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Otwiera wiersz tabeli formularza.
	 *
	 * @param string $label Etykieta.
	 * @param string $key   Klucz ustawienia, do którego odnosi się etykieta.
	 */
	private static function row_start( string $label, string $key ): void {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>', esc_attr( self::name( $key ) ), esc_html( $label ) );
	}

	/**
	 * Zamyka wiersz tabeli formularza.
	 */
	private static function row_end(): void {
		echo '</td></tr>';
	}

	/**
	 * Wypisuje opis pod polem.
	 *
	 * @param string $text Treść.
	 */
	private static function description( string $text ): void {
		echo '<p class="description">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Pole logiczne z jawnym zerem: ukryte pole o tej samej nazwie poprzedza checkbox,
	 * więc niezaznaczone pole wysyła `0`.
	 *
	 * @param string $key     Klucz ustawienia.
	 * @param string $label   Etykieta przy checkboxie.
	 * @param bool   $checked Czy zaznaczone.
	 */
	private static function checkbox( string $key, string $label, bool $checked ): void {
		printf(
			'<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" value="1" id="%1$s"%2$s /> %3$s</label>',
			esc_attr( self::name( $key ) ),
			$checked ? ' checked="checked"' : '',
			esc_html( $label )
		);
	}

	/**
	 * Pole wielowierszowe, po jednej wartości w linii.
	 *
	 * @param string             $key    Klucz ustawienia.
	 * @param array<int, string> $values Wartości.
	 */
	private static function textarea( string $key, array $values ): void {
		printf(
			'<textarea class="large-text" rows="5" id="%1$s" name="%1$s">%2$s</textarea>',
			esc_attr( self::name( $key ) ),
			esc_textarea( implode( "\n", $values ) )
		);
	}
}
