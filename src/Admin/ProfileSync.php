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
	 * Nazwy pól objętych mostem, zapamiętane na czas żądania renderu `user-edit.php`.
	 *
	 * `acf/load_value` woła się dla każdego pola ekranu, a wyliczenie czyta ustawienia, macierz i definicje
	 * pól ACF. Klucz niesie identyfikator konta i jego profesję, więc dwa konta w jednym procesie (testy,
	 * partie) się nie mieszają. Pamięć jest czyszczona przy rejestracji i przy każdym zapisie ACF,
	 * a zapis nigdy z niej nie korzysta: tylko odczyt na ekranie toleruje nazwy sprzed zmiany ustawień.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $namesMemo = array();

	/**
	 * Podpina zasilanie ACF przy odczycie i przeniesienie przy zapisie. ACF zapisuje wartości na
	 * priorytecie 10, więc 20 czyta stan już po zapisie; `profile_update` się nie nadaje, bo odpala
	 * się w `wp_update_user()`, czyli przed zapisem ACF.
	 */
	public static function register(): void {
		self::$namesMemo = array();
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
		if ( ! in_array( $name, self::synced_names_for_screen( $userId ), true ) ) {
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

		// Zapis liczy nazwy zawsze od nowa i unieważnia pamięć odczytu.
		self::$namesMemo = array();
		$names           = self::synced_names( $userId );
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
			// gorszą awarią niż to, że czyszczenie pola nie przejdzie tą drogą.
			//
			// Koszt jest TRWAŁY i niewidoczny, nie tylko „nieprzechodzące czyszczenie": administrator czyści
			// pole, ACF zapisuje '' pod nazwą nieprzedrostkowaną, my robimy `continue`. Od tej chwili ta
			// metadana istnieje (i jest pusta), więc `on_load_value()` nigdy już nie podstawi wartości z
			// rejestracji. `user-edit.php` pokazuje wtedy pole PUSTE, a ekran wniosków, mail do redakcji i
			// eksport dalej pokazują STARĄ wartość, bez żadnego komunikatu; administrator widzi wyczyszczone
			// pole i wierzy, że je wyczyścił. Zdjęcie tej osłony wymaga (1) potwierdzenia zachowania ACF
			// na dev z prawdziwym ACF Pro i (2) zasiania metadanej ACF przy odczycie. Decyzja właściciela.
			if ( '' === $value && '' !== $old && false !== $old && array() !== $old ) {
				continue;
			}

			if ( '' !== $credentialField && $name === $credentialField ) {
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
	 * Sanityzuje wartość i spłaszcza ją do tekstu, tak samo jak rejestracja.
	 *
	 * Kształt jest wspólny z `RegistrationHandler::stored_values()`: do metadanych wtyczki wchodzi wyłącznie
	 * tekst, a pole wielowartościowe jedzie jako lista po przecinkach. Tablica pod tym samym kluczem dałaby
	 * dwa kształty zależnie od tego, kto pisał ostatni, a `ApplicationRepository::meta_string()` zwraca
	 * `''` dla nie-napisu, więc numer uprawnień po edycji administratora cicho by znikał. Sanityzacja idzie
	 * element po elemencie przed złączeniem. Rejestracja używa dla wszystkich pól `sanitize_text_field()`
	 * (bez rozróżnienia pól wielolinijkowych), więc most robi to samo; zgodność kształtu jest ważniejsza.
	 *
	 * @param mixed $value Surowa wartość z metadanej ACF.
	 */
	private static function sanitized( mixed $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				$text = self::sanitized( $item );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
			return implode( ', ', $parts );
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Nazwy pól objętych mostem, z pamięcią na czas żądania; tylko dla odczytu na ekranie.
	 *
	 * @param int $userId Identyfikator konta.
	 * @return list<string>
	 */
	private static function synced_names_for_screen( int $userId ): array {
		$profession = get_user_meta( $userId, UserMeta::PROFESSION, true );
		$key        = $userId . ':' . ( is_string( $profession ) ? $profession : '' );
		if ( ! isset( self::$namesMemo[ $key ] ) ) {
			self::$namesMemo[ $key ] = self::synced_names( $userId );
		}

		return self::$namesMemo[ $key ];
	}
}
