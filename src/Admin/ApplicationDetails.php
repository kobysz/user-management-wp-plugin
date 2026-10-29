<?php
/**
 * Panel szczegółów wniosku, tylko do czytania.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Application\ApplicationRow;
use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Domain\Application\RegistryLink;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

/**
 * Widok jednego wniosku. Jedyny formularz to rozstrzygnięcie; danych tu się nie edytuje: poprawia administrator na standardowym
 * ekranie edycji użytkownika, gdzie ACF renderuje te same pola, więc panel nie jest drugim miejscem edycji.
 */
final class ApplicationDetails {

	/**
	 * Wypisuje panel wniosku.
	 *
	 * @param ApplicationRow $row Wniosek.
	 */
	public static function render( ApplicationRow $row ): void {
		$profession = '' === $row->professionKey ? null : ProfessionRepository::get()->get( $row->professionKey );

		echo '<div class="wrap pusers-application-details"><h1>' . esc_html( $row->displayName ) . '</h1>';
		echo '<p><a href="' . esc_url( Menu::url( Menu::SLUG_APPLICATIONS ) ) . '">' . esc_html__( '← Wróć do listy wniosków', 'portal-users' ) . '</a> | ';
		echo '<a href="' . esc_url( get_edit_user_link( $row->userId ) ) . '">' . esc_html__( 'Edytuj dane użytkownika', 'portal-users' ) . '</a></p>';

		echo '<table class="widefat striped"><tbody>';
		self::row( __( 'Stan', 'portal-users' ), self::status_label( $row->status ) );
		self::row( __( 'Login', 'portal-users' ), $row->login );
		self::row( __( 'E-mail', 'portal-users' ), $row->email );
		self::row( __( 'Profesja', 'portal-users' ), null === $profession ? $row->professionKey : $profession->label );
		self::row( __( 'Data rejestracji', 'portal-users' ), '' === $row->registeredAtUtc ? '' : get_date_from_gmt( $row->registeredAtUtc, 'Y-m-d H:i' ) );

		$code = CredentialVerdict::for( $profession, $row->credentialNumber );
		if ( null === $profession || CredentialVerdict::NOT_APPLICABLE !== $code ) {
			$link = null === $profession ? '' : RegistryLink::for( $profession->registryUrl, $row->credentialNumber );
			self::row( __( 'Numer uprawnień', 'portal-users' ), trim( $row->credentialNumber . ' ' . VerdictLabels::text( $code ) ), $link );
		}

		// Brak zapisu mówi inaczej niż pusta komórka: nie wiadomo, czy zgody nie było, czy tylko jej nie zapisano.
		self::row( __( 'Wersja regulaminu', 'portal-users' ), self::meta_text( $row->userId, UserMeta::TERMS_VERSION ) );
		$consent = self::meta_text( $row->userId, UserMeta::CONSENT_AT );
		self::row( __( 'Zgoda wyrażona', 'portal-users' ), '' === $consent ? '' : get_date_from_gmt( $consent, 'Y-m-d H:i' ) );
		self::row( __( 'Adres IP rejestracji', 'portal-users' ), self::meta_text( $row->userId, UserMeta::REGISTRATION_IP ) );

		if ( AccountStatus::Rejected === $row->status ) {
			self::row( __( 'Powód odrzucenia', 'portal-users' ), self::meta_text( $row->userId, UserMeta::REJECTION_REASON ) );
		}
		echo '</tbody></table>';

		self::render_decision( $row );
		self::render_fields( $row );
		echo '</div>';
	}

	/**
	 * Przyciski rozstrzygnięcia: ten sam punkt wejścia i nonce co lista. Zatwierdzenie zatwierdzonego konta
	 * się nie pojawia; konto odrzucone dostaje „Popraw powód odrzucenia" zamiast „Odrzuć". Konto niepotwierdzone
	 * nie ma żadnego przycisku (nie jest wnioskiem).
	 *
	 * @param ApplicationRow $row Wniosek.
	 */
	private static function render_decision( ApplicationRow $row ): void {
		if ( AccountStatus::EmailUnconfirmed === $row->status ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pusers-decision-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ApplicationsScreen::ACTION_DECIDE ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( ApplicationsScreen::FIELD_USERS ) . '[]" value="' . esc_attr( (string) $row->userId ) . '" />';
		wp_nonce_field( ApplicationsScreen::ACTION_DECIDE, Request::NONCE_FIELD );
		ApplicationsScreen::render_return_fields();

		// Konto odrzucone też dostaje pole powodu: to jedyna droga poprawy pomylonego powodu, bo metadana
		// nie jest polem ACF i ekran edycji użytkownika jej nie pokazuje.
		ApplicationsScreen::render_reason_field();
		echo '<p>';
		if ( AccountStatus::Approved !== $row->status ) {
			echo '<button type="submit" class="button button-primary" name="' . esc_attr( ApplicationsScreen::FIELD_DECISION ) . '" value="' . esc_attr( ApplicationsScreen::DECIDE_APPROVE ) . '">' . esc_html__( 'Zatwierdź', 'portal-users' ) . '</button> ';
		}
		$reject_label = AccountStatus::Rejected === $row->status ? __( 'Popraw powód odrzucenia', 'portal-users' ) : __( 'Odrzuć', 'portal-users' );
		echo '<button type="submit" class="button" name="' . esc_attr( ApplicationsScreen::FIELD_DECISION ) . '" value="' . esc_attr( ApplicationsScreen::DECIDE_REJECT ) . '">' . esc_html( $reject_label ) . '</button>';
		echo '</p></form>';
	}

	/**
	 * Pola wniosku widoczne dla profesji. Widoczność rozstrzyga macierz (fail-closed), nie to, co leży w
	 * metadanych: front i walidacja orzekają tak samo, a panel nie może być trzecim, luźniejszym zdaniem.
	 *
	 * @param ApplicationRow $row Wniosek.
	 */
	private static function render_fields( ApplicationRow $row ): void {
		echo '<h2>' . esc_html__( 'Dane z formularza', 'portal-users' ) . '</h2>';

		$source = new AcfFieldSource( SettingsRepository::get()->acfGroupKey );
		// Ekran działa także przy wyłączonym ACF Pro, więc brak grupy musi być powiedziany wprost, nie pustką.
		if ( ! $source->groupExists() ) {
			echo '<p>' . esc_html__( 'Pól wniosku nie da się pokazać: grupa pól ACF jest niedostępna (ACF Pro wyłączony albo grupa usunięta).', 'portal-users' ) . '</p>';
			return;
		}

		$labels  = $source->fields();
		$visible = FieldMatrixRepository::get()->visibleFields( $row->professionKey );

		echo '<table class="widefat striped"><tbody>';
		$shown = false;
		foreach ( $visible as $name ) {
			if ( ! isset( $labels[ $name ] ) ) {
				continue;
			}
			$shown = true;
			// Odczyt po nazwie: odczyt zbiorczy niesie napisy z bazy bez odserializowania.
			$value = get_user_meta( $row->userId, UserMeta::ACF_PREFIX . $name, true );
			self::row( $labels[ $name ], self::flatten( $value ), '', __( 'nie podano', 'portal-users' ) );
		}
		if ( ! $shown ) {
			echo '<tr><td>' . esc_html__( 'Dla tej profesji nie ma pól do pokazania.', 'portal-users' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Wiersz tabeli; pusta wartość zastępowana jest wyjaśnieniem, nigdy pustą komórką.
	 *
	 * @param string $label    Etykieta.
	 * @param string $value    Wartość.
	 * @param string $link     Opcjonalny odnośnik do rejestru.
	 * @param string $fallback Tekst dla pustej wartości; domyślnie „nie zapisano".
	 */
	private static function row( string $label, string $value, string $link = '', string $fallback = '' ): void {
		$text = '' === $value ? ( '' === $fallback ? __( 'nie zapisano', 'portal-users' ) : $fallback ) : $value;

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $text );
		if ( '' !== $link ) {
			echo ' <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sprawdź w rejestrze', 'portal-users' ) . '</a>';
		}
		echo '</td></tr>';
	}

	/**
	 * Metadana konta jako tekst; pusty, gdy jej nie ma.
	 *
	 * @param int    $userId Identyfikator konta.
	 * @param string $key    Klucz metadanej.
	 */
	private static function meta_text( int $userId, string $key ): string {
		$value = get_user_meta( $userId, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Wartość pola do jednej linii; lista (checkbox, multi-select) schodzi do wartości rozdzielonych przecinkami.
	 *
	 * @param mixed $value Wartość z metadanej.
	 */
	private static function flatten( mixed $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) && '' !== (string) $item ) {
					$parts[] = (string) $item;
				}
			}
			return implode( ', ', $parts );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Nazwa stanu w panelu.
	 *
	 * @param AccountStatus $status Stan.
	 */
	private static function status_label( AccountStatus $status ): string {
		return match ( $status ) {
			AccountStatus::Pending          => __( 'Oczekujące', 'portal-users' ),
			AccountStatus::Approved         => __( 'Zatwierdzone', 'portal-users' ),
			AccountStatus::Rejected         => __( 'Odrzucone', 'portal-users' ),
			AccountStatus::EmailUnconfirmed => __( 'Niepotwierdzone', 'portal-users' ),
		};
	}
}
