<?php
/**
 * Ekran szablonów maili: temat i treść każdego rodzaju oraz ich zapis.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;
use PortalUsers\Mail\DefaultTemplates;
use PortalUsers\Persistence\MailTemplateRepository;

/**
 * Ekran szablonów renderowany serwerowo, osiem sekcji po jednej na rodzaj maila. Puste pole
 * znaczy „użyj domyślnego": tę regułę egzekwuje MailTemplateRepository::save(), a ekran tylko
 * pokazuje ją administratorowi (domyślny tekst jako placeholder).
 */
final class MailTemplatesScreen {

	public const ACTION = 'pusers_save_mail_templates';

	private const FIELD_PREFIX = 'pusers_tpl_';

	/**
	 * Podpina podstronę oraz obsługę zapisu.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
	}

	/**
	 * Dodaje podstronę szablonów pod menu Użytkownicy.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Portal: szablony maili', 'portal-users' ),
			__( 'Portal: szablony maili', 'portal-users' ),
			Menu::CAPABILITY,
			Menu::SLUG_MAIL_TEMPLATES,
			array( self::class, 'render' )
		);
	}

	/**
	 * Zapisuje szablony wszystkich rodzajów: uprawnienia, nonce, metoda, zapis, przekierowanie.
	 */
	public static function handle_save(): void {
		Request::guard( self::ACTION );

		// Zapis zmienia treść maili, więc GET (admin_post_* odpala się dla każdej metody) odpada.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		foreach ( MailKind::cases() as $kind ) {
			$subject = self::posted( $kind, 'subject' );
			$body    = self::posted( $kind, 'body' );
			// Rodzaj, którego pól żądanie w ogóle nie niosło, zostaje nietknięty: brak pola to nie
			// to samo co puste pole, a to drugie kasowałoby nadpisanie.
			if ( null === $subject && null === $body ) {
				continue;
			}
			$current = MailTemplateRepository::override( $kind );

			// Treść maila jest zwykłym tekstem: HtmlBody::wrap() eskejpuje wszystko, więc wpisany
			// znacznik dojechałby do lekarza dosłownie. Zdejmowanie go przy zapisie mówi prawdę
			// zamiast obiecywać HTML, którego nie ma. sanitize_textarea_field() zachowuje łamania linii.
			MailTemplateRepository::save(
				$kind,
				new MailTemplate(
					null === $subject ? $current->subject : sanitize_text_field( $subject ),
					null === $body ? $current->body : sanitize_textarea_field( str_replace( array( "\r\n", "\r" ), "\n", $body ) )
				)
			);
		}

		Request::redirect( Menu::SLUG_MAIL_TEMPLATES, Request::NOTICE_SAVED );
	}

	/**
	 * Wypisuje ekran szablonów.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Portal: szablony maili', 'portal-users' ) . '</h1>';
		if ( Request::NOTICE_SAVED === Request::notice() ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Szablony zostały zapisane.', 'portal-users' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Puste pole oznacza wartość domyślną, widoczną w polu jako podpowiedź. Treść jest zwykłym tekstem: znaczniki HTML zostaną usunięte przy zapisie.', 'portal-users' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION, Request::NONCE_FIELD );

		foreach ( MailKind::cases() as $kind ) {
			self::render_section( $kind );
		}

		submit_button();
		echo '</form></div>';
	}

	/**
	 * Sekcja jednego rodzaju maila.
	 *
	 * @param MailKind $kind Rodzaj maila.
	 */
	private static function render_section( MailKind $kind ): void {
		$default  = DefaultTemplates::for( $kind );
		$override = MailTemplateRepository::override( $kind );
		$subject  = self::name( $kind, 'subject' );
		$body     = self::name( $kind, 'body' );

		echo '<h2>' . esc_html( self::label( $kind ) ) . ' <code>' . esc_html( $kind->value ) . '</code></h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="large-text" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" /></td></tr>',
			esc_attr( $subject ),
			esc_html__( 'Temat', 'portal-users' ),
			esc_attr( $override->subject ),
			esc_attr( $default->subject )
		);
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea class="large-text" rows="10" id="%1$s" name="%1$s" placeholder="%4$s">%3$s</textarea>',
			esc_attr( $body ),
			esc_html__( 'Treść', 'portal-users' ),
			esc_textarea( $override->body ),
			esc_attr( $default->body )
		);

		echo '<p class="description">' . esc_html__( 'Zmienne tego maila:', 'portal-users' ) . '</p><ul>';
		foreach ( $kind->variables() as $variable ) {
			echo '<li><code>' . esc_html( '{' . $variable . '}' ) . '</code>';
			if ( 'link_hasla' === $variable ) {
				// Temat maila resetu składa inny filtr rdzenia, który nie dostaje klucza resetu.
				echo ' ' . esc_html__( '(działa tylko w treści; w temacie zostałyby dosłowne klamry)', 'portal-users' );
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Dowolne pole formularza rejestracji wstawisz jako {pole_<klucz>}, gdzie klucz to klucz pola; tych zmiennych nie da się wyliczyć, bo zależą od konfiguracji pól, a nie od rodzaju maila.', 'portal-users' ) . '</p>';

		echo '</td></tr></tbody></table>';
	}

	/**
	 * Nazwa rodzaju maila w panelu.
	 *
	 * @param MailKind $kind Rodzaj maila.
	 */
	private static function label( MailKind $kind ): string {
		return match ( $kind ) {
			MailKind::Confirmation        => __( 'Potwierdzenie adresu e-mail', 'portal-users' ),
			MailKind::Editorial           => __( 'Zgłoszenie do redakcji', 'portal-users' ),
			MailKind::EditorialAuto       => __( 'Zgłoszenie do redakcji (konto zatwierdzone automatycznie)', 'portal-users' ),
			MailKind::ExistingAccount     => __( 'Rejestracja na istniejący adres', 'portal-users' ),
			MailKind::ApplicationReceived => __( 'Wniosek przyjęty', 'portal-users' ),
			MailKind::Approved            => __( 'Konto zatwierdzone', 'portal-users' ),
			MailKind::Rejected            => __( 'Wniosek odrzucony', 'portal-users' ),
			MailKind::PasswordReset       => __( 'Odzyskiwanie hasła', 'portal-users' ),
		};
	}

	/**
	 * Nazwa pola formularza: jedno miejsce dla renderu i zapisu.
	 *
	 * @param MailKind $kind  Rodzaj maila.
	 * @param string   $field `subject` albo `body`.
	 */
	private static function name( MailKind $kind, string $field ): string {
		return self::FIELD_PREFIX . $kind->value . '_' . $field;
	}

	/**
	 * Wartość pola z żądania zapisu albo null, gdy pola nie przesłano. Nonce i uprawnienia
	 * sprawdza Request::guard() na początku handle_save().
	 *
	 * @param MailKind $kind  Rodzaj maila.
	 * @param string   $field `subject` albo `body`.
	 */
	private static function posted( MailKind $kind, string $field ): ?string {
		$name = self::name( $kind, $field );
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w handle_save().
			return null;
		}
		return (string) wp_unslash( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() w handle_save(); wartość czyszczy wywołujący.
	}
}
