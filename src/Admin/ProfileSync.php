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
 * Zapis idzie wyłącznie z ACF do metadanych wtyczki; w drugą stronę ACF jest tylko zasilany
 * przy odczycie (`acf/load_value`), żeby ekran pokazywał to, co wpisał lekarz.
 */
final class ProfileSync {

	/**
	 * Akcja logu: administrator zmienił numer uprawnień konta.
	 */
	public const LOG_CREDENTIAL_CHANGED = 'credential_changed';

	/**
	 * Podpina zasilanie ACF przy odczycie i przeniesienie przy zapisie. ACF zapisuje wartości na
	 * priorytecie 10, więc 20 czyta stan już po zapisie; `profile_update` się nie nadaje, bo odpala
	 * się w `wp_update_user()`, czyli przed zapisem ACF.
	 */
	public static function register(): void {
		add_action( 'acf/save_post', array( self::class, 'on_acf_save' ), 20 );
		add_filter( 'acf/load_value', array( self::class, 'on_load_value' ), 10, 3 );
	}

	/**
	 * Zasila pole ACF wartością z rejestracji.
	 *
	 * Rejestracja pisze wyłącznie pod przedrostkiem, a ACF czyta własne nazwy, których nikt nie zasilił:
	 * bez tego ekran renderowałby puste pola, a zwykły zapis profilu wpisałby puste wartości. Podstawiamy
	 * tylko wtedy, gdy ACF nie ma jeszcze własnej metadanej, i tylko pola z przecięcia grupy z macierzą.
	 *
	 * @param mixed $value  Wartość z ACF.
	 * @param mixed $postId Identyfikator ekranu ACF.
	 * @param mixed $field  Definicja pola ACF.
	 * @return mixed
	 */
	public static function on_load_value( mixed $value, mixed $postId, mixed $field ): mixed {
		$userId = self::user_id( $postId );
		if ( 0 === $userId || ! is_array( $field ) || ! isset( $field['name'] ) || ! is_string( $field['name'] ) ) {
			return $value;
		}
		$name = $field['name'];
		if ( ! in_array( $name, self::synced_names( $userId ), true ) ) {
			return $value;
		}
		if ( metadata_exists( 'user', $userId, $name ) ) {
			return $value;
		}
		$key = RegistrationHandler::ACF_META_PREFIX . $name;
		if ( ! metadata_exists( 'user', $userId, $key ) ) {
			return $value;
		}

		return get_user_meta( $userId, $key, true );
	}

	/**
	 * Przenosi wartości pól ACF konta do metadanych wtyczki.
	 *
	 * @param mixed $postId Identyfikator ekranu ACF; interesuje nas wyłącznie `user_<liczba>`.
	 */
	public static function on_acf_save( mixed $postId ): void {
		$userId = self::user_id( $postId );
		if ( 0 === $userId ) {
			return;
		}

		$names = self::synced_names( $userId );
		if ( array() === $names ) {
			return;
		}

		$profession      = get_user_meta( $userId, UserMeta::PROFESSION, true );
		$profession      = is_string( $profession ) ? $profession : '';
		$found           = ProfessionRepository::get()->get( $profession );
		$credentialField = null === $found ? '' : $found->credentialField;

		foreach ( $names as $name ) {
			// Brak metadanej oznacza, że ACF nie zapisał tego pola.
			if ( ! metadata_exists( 'user', $userId, $name ) ) {
				continue;
			}

			$value = self::sanitized( get_user_meta( $userId, $name, true ) );
			$key   = RegistrationHandler::ACF_META_PREFIX . $name;
			$old   = get_user_meta( $userId, $key, true );

			// Pusta wartość z ACF nie nadpisuje niepustej wartości z rejestracji. Dopóki zachowanie ACF
			// nie zostanie potwierdzone na dev z prawdziwym ACF Pro, ciche zniszczenie danych lekarza jest
			// gorszą awarią niż to, że świadome wyczyszczenie pola przez administratora nie przejdzie tą drogą.
			// Znany, zapisany koszt; nie zmieniać bez decyzji właściciela.
			if ( ( array() === $value || '' === $value ) && array() !== $old && '' !== $old && false !== $old ) {
				continue;
			}

			if ( '' !== $credentialField && $name === $credentialField && is_string( $value ) ) {
				$oldText = is_scalar( $old ) ? (string) $old : '';
				// Log tylko przy faktycznej zmianie: log rosnący przy każdym otwarciu profilu jest bezużyteczny.
				if ( $oldText !== $value ) {
					EventLog::write(
						self::LOG_CREDENTIAL_CHANGED,
						$userId,
						get_current_user_id(),
						array(
							'old' => $oldText,
							'new' => $value,
						)
					);
				}
			}

			update_user_meta( $userId, $key, $value );
		}
	}

	/**
	 * Identyfikator użytkownika z identyfikatora ekranu ACF; 0, gdy to nie istniejący użytkownik.
	 *
	 * Ten sam hook odpala się dla wpisów (`post_15`), opcji (`options`) i liczb; tylko użytkownik jest celem.
	 *
	 * @param mixed $postId Identyfikator ekranu ACF.
	 */
	private static function user_id( mixed $postId ): int {
		if ( ! is_string( $postId ) || 1 !== preg_match( '/^user_(\d+)$/D', $postId, $m ) ) {
			return 0;
		}
		$userId = (int) $m[1];

		return $userId > 0 && false !== get_userdata( $userId ) ? $userId : 0;
	}

	/**
	 * Nazwy pól objętych mostem: przecięcie grupy ACF z polami widocznymi dla profesji konta.
	 * Fail-closed jak front, walidacja i panel; most nie może być czwartym, luźniejszym zdaniem o widoczności.
	 * Konto bez profesji nie ma macierzy, po której można orzec o widoczności, więc wynik jest pusty.
	 *
	 * @param int $userId Identyfikator konta.
	 * @return list<string>
	 */
	private static function synced_names( int $userId ): array {
		$profession = get_user_meta( $userId, UserMeta::PROFESSION, true );
		if ( ! is_string( $profession ) || '' === $profession ) {
			return array();
		}

		$source  = new AcfFieldSource( SettingsRepository::get()->acfGroupKey );
		$visible = FieldMatrixRepository::get()->visibleFields( $profession );

		return array_values( array_intersect( array_map( 'strval', array_keys( $source->fields() ) ), $visible ) );
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
