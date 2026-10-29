<?php
/**
 * Most ACF do metadanych wtyczki i audyt zmiany numeru uprawnień.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Rejestracja zapisuje wartości pól pod przedrostkiem (`pusers_acf_<nazwa>`), a ACF na ekranie
 * edycji użytkownika pod własnymi nazwami (`<nazwa>`, `_<nazwa>`). Bez mostu poprawka
 * administratora nigdy nie dotarłaby do listy wniosków, panelu, maila do redakcji i eksportu.
 * Most jest jednokierunkowy (ACF do metadanych wtyczki): rejestracja pisze tylko pod przedrostkiem.
 */
final class ProfileSync {

	/**
	 * Akcja logu: administrator zmienił numer uprawnień konta.
	 */
	public const LOG_CREDENTIAL_CHANGED = 'credential_changed';

	/**
	 * Podpina most. ACF zapisuje wartości na priorytecie 10, więc 20 czyta stan już po zapisie;
	 * `profile_update` się nie nadaje, bo odpala się w `wp_update_user()`, czyli przed zapisem ACF.
	 */
	public static function register(): void {
		add_action( 'acf/save_post', array( self::class, 'on_acf_save' ), 20 );
	}

	/**
	 * Przenosi wartości pól ACF konta do metadanych wtyczki.
	 *
	 * @param mixed $postId Identyfikator ekranu ACF; interesuje nas wyłącznie `user_<liczba>`.
	 */
	public static function on_acf_save( mixed $postId ): void {
		// Ten sam hook odpala się dla wpisów (`post_15`), opcji (`options`) i liczb; tylko użytkownik jest naszym celem.
		if ( ! is_string( $postId ) || 1 !== preg_match( '/^user_(\d+)$/D', $postId, $m ) ) {
			return;
		}
		$userId = (int) $m[1];
		if ( $userId <= 0 || false === get_userdata( $userId ) ) {
			return;
		}

		// Bez profesji nie ma macierzy, po której można orzec o widoczności pól.
		$profession = get_user_meta( $userId, UserMeta::PROFESSION, true );
		if ( ! is_string( $profession ) || '' === $profession ) {
			return;
		}

		// Fail-closed jak front, walidacja i panel: przenosimy tylko przecięcie pól grupy z polami
		// widocznymi dla profesji. Most nie może być czwartym, luźniejszym zdaniem o widoczności.
		$source  = new AcfFieldSource( SettingsRepository::get()->acfGroupKey );
		$visible = FieldMatrixRepository::get()->visibleFields( $profession );
		$names   = array_values( array_intersect( array_map( 'strval', array_keys( $source->fields() ) ), $visible ) );

		$found           = ProfessionRepository::get()->get( $profession );
		$credentialField = null === $found ? '' : $found->credentialField;

		foreach ( $names as $name ) {
			// Brak metadanej oznacza, że ACF jej nie zapisał; nie ma czym nadpisać wartości z rejestracji.
			if ( ! metadata_exists( 'user', $userId, $name ) ) {
				continue;
			}

			$value = self::sanitized( get_user_meta( $userId, $name, true ) );
			$key   = RegistrationHandler::ACF_META_PREFIX . $name;

			if ( '' !== $credentialField && $name === $credentialField ) {
				$old = get_user_meta( $userId, $key, true );
				$old = is_scalar( $old ) ? (string) $old : '';
				// Log tylko przy faktycznej zmianie: log rosnący przy każdym otwarciu profilu jest bezużyteczny.
				if ( is_string( $value ) && $old !== $value ) {
					EventLog::write(
						self::LOG_CREDENTIAL_CHANGED,
						$userId,
						get_current_user_id(),
						array(
							'old' => $old,
							'new' => $value,
						)
					);
				}
			}

			update_user_meta( $userId, $key, $value );
		}
	}

	/**
	 * Sanityzuje wartość: skalar jako tekst, tablicę element po elemencie (zostaje tablicą).
	 *
	 * @param mixed $value Surowa wartość z metadanej ACF.
	 * @return string|array<int|string, string>
	 */
	private static function sanitized( mixed $value ): string|array {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $k => $item ) {
				if ( is_scalar( $item ) ) {
					$clean[ $k ] = sanitize_text_field( (string) $item );
				}
			}
			return $clean;
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}
