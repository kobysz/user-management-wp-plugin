<?php
/**
 * Zmienne szablonu maila budowane z konta i ustawień portalu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Mail;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Mail\Placeholders;
use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Front\PageUrl;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

/**
 * Most między kontem a szablonem: zamienia konto na mapę nazwa => wartość.
 *
 * Numer uprawnień i wynik jego walidacji NIE zależą od macierzy pól: numer jest podstawą decyzji
 * redakcji, a nie polem formularza, więc ukrycie pola go nie ukrywa. Zmienne `pole_<klucz>` ją
 * respektują: pole ukryte dla profesji nie należy do zgłoszenia i w mailu byłoby wyciekiem.
 */
final class PlaceholderFactory {

	private const FIELD_PREFIX = 'pole_';

	/**
	 * Zmienne dla konta; `$extra` dokłada i nadpisuje to, czego fabryka nie wie.
	 *
	 * Konto bywa usunięte (cron czyszczący) między zakolejkowaniem maila a jego renderem. Render
	 * nie może wtedy wywrócić wysyłki, więc brak konta daje same zmienne portalu i puste zmienne konta.
	 *
	 * @param int                   $userId Identyfikator użytkownika.
	 * @param array<string, string> $extra  Zmienne od wołającego (powód odrzucenia, linki z tokenem), bez klamr.
	 */
	public static function for_user( int $userId, array $extra = array() ): Placeholders {
		$values = array_merge( self::site_variables(), self::account_variables( $userId ) );

		return new Placeholders( array_merge( $values, $extra ) );
	}

	/**
	 * Zmienne niezależne od konta.
	 *
	 * @return array<string, string>
	 */
	private static function site_variables(): array {
		return array(
			// Nazwa trafia do maila tekstowego: encje HTML byłyby tam widoczne dosłownie.
			'nazwa_portalu'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'adres_portalu'     => home_url( '/' ),
			'link_logowania'    => PageUrl::for( SettingsRepository::get()->loginPageId ),
			// Plan 4 podmienia ten adres filtrem na własną stronę resetu, więc nie trzeba go tu rozstrzygać.
			'link_odzyskiwania' => wp_lostpassword_url(),
		);
	}

	/**
	 * Zmienne zależne od konta: dane, profesja, numer uprawnień i pola zgłoszenia.
	 *
	 * @param int $userId Identyfikator użytkownika.
	 * @return array<string, string>
	 */
	private static function account_variables( int $userId ): array {
		$user = get_userdata( $userId );
		if ( false === $user ) {
			return array(
				'email'           => '',
				'login'           => '',
				'nazwa_konta'     => '',
				'profesja'        => '',
				'numer_uprawnien' => '',
				'wynik_walidacji' => '',
				'link_wniosku'    => '',
				'pola_zgloszenia' => '',
			);
		}

		$key = get_user_meta( $userId, UserMeta::PROFESSION, true );
		$key = is_string( $key ) ? $key : '';
		// Profesja wycofana z ustawień zostaje w koncie: wtedy jej klucz jest jedyną etykietą.
		$profession = '' === $key ? null : ProfessionRepository::get()->get( $key );
		$number     = self::credential_number( $userId, $profession );
		$fields     = self::submitted_fields( $userId, FieldMatrixRepository::get()->visibleFields( $key ) );

		$out = array(
			'email'           => $user->user_email,
			'login'           => $user->user_login,
			'nazwa_konta'     => $user->display_name,
			'profesja'        => null === $profession ? $key : $profession->label,
			'numer_uprawnien' => $number,
			'wynik_walidacji' => self::validation_result( $profession, $number ),
			// Do Planu 6: ekran wniosków wskaże tu własny adres, a edycja użytkownika jest tylko zastępstwem.
			'link_wniosku'    => admin_url( 'user-edit.php?user_id=' . $userId ),
			'pola_zgloszenia' => self::as_list( $fields ),
		);
		foreach ( $fields as $name => $value ) {
			$out[ self::FIELD_PREFIX . $name ] = $value;
		}

		return $out;
	}

	/**
	 * Zapisany numer uprawnień; pusty, gdy profesja go nie ma albo nikt go nie podał.
	 *
	 * @param int             $userId     Identyfikator użytkownika.
	 * @param Profession|null $profession Profesja konta.
	 */
	private static function credential_number( int $userId, ?Profession $profession ): string {
		if ( null === $profession || CredentialType::None === $profession->credentialType || '' === $profession->credentialField ) {
			return '';
		}
		$stored = get_user_meta( $userId, RegistrationHandler::ACF_META_PREFIX . $profession->credentialField, true );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Wynik walidacji liczony ponownie: walidator mógł się zmienić po rejestracji, więc zapisanemu wtedy wynikowi nie ufamy.
	 *
	 * Cztery wartości, nigdy pustka: redakcja czyta ten werdykt w mailu i musi widzieć różnicę między
	 * „ta profesja numeru nie ma" (wniosek w porządku) a „numeru nie podano" (wniosek do odrzucenia).
	 * Jedna pustka na oba przypadki zwijała dwa różne fakty w jeden i nie odpowiadała na żadne pytanie.
	 *
	 * Fakt orzeka `CredentialVerdict`; tu kod jest tylko tłumaczony na słowo do maila.
	 *
	 * @param Profession|null $profession Profesja konta.
	 * @param string          $number     Zapisany numer.
	 */
	private static function validation_result( ?Profession $profession, string $number ): string {
		// Surowy kod walidatora (`invalid_format`, `invalid_checksum`) zostaje tu CELOWO poza treścią:
		// to maszynowy żargon po angielsku w polskim mailu do człowieka, a jego następny ruch jest ten
		// sam bez względu na to, do czego walidator się przyczepił — sprawdzić numer w rejestrze.
		// Szczegółowy powód należy do ekranu wniosków, obok odnośnika do rejestru. Nie przywracać.
		return match ( CredentialVerdict::for( $profession, $number ) ) {
			CredentialVerdict::VALID          => __( 'poprawny', 'portal-users' ),
			CredentialVerdict::MISSING        => __( 'brak numeru', 'portal-users' ),
			CredentialVerdict::NOT_APPLICABLE => __( 'nie dotyczy', 'portal-users' ),
			default                           => __( 'niepoprawny', 'portal-users' ),
		};
	}

	/**
	 * Pola zgłoszenia widoczne dla profesji, każde zwinięte do jednej linii, posortowane po kluczu.
	 *
	 * Jeden odczyt wszystkich metadanych zamiast zapytania na pole; sama WARTOŚĆ idzie potem przez
	 * `get_user_meta()` z nazwą pola. To nie jest zapytanie na pole: odczyt zbiorczy zapełnił już
	 * pamięć podręczną metadanych, więc wywołanie z nazwą czyta z niej. Powód, dla którego nie bierzemy
	 * wartości wprost z tablicy zbiorczej: tam leżą napisy PROSTO Z BAZY, bez odserializowania (robi je
	 * dopiero odczyt z nazwą), więc pole wielowartościowe ACF — checkbox, multi-select — dawało
	 * w mailu do redakcji `a:2:{i:0;s:7:"Szpital";…}` zamiast wartości.
	 *
	 * @param int                $userId        Identyfikator użytkownika.
	 * @param array<int, string> $visibleFields Pola widoczne dla profesji.
	 * @return array<string, string>
	 */
	private static function submitted_fields( int $userId, array $visibleFields ): array {
		$out     = array();
		$prefix  = RegistrationHandler::ACF_META_PREFIX;
		$visible = array_flip( $visibleFields );
		$all     = get_user_meta( $userId );
		foreach ( is_array( $all ) ? $all : array() as $metaKey => $values ) {
			$metaKey = (string) $metaKey;
			if ( ! str_starts_with( $metaKey, $prefix ) || ! is_array( $values ) ) {
				continue;
			}
			$name = substr( $metaKey, strlen( $prefix ) );
			if ( ! isset( $visible[ $name ] ) ) {
				continue;
			}
			$out[ $name ] = self::one_line( get_user_meta( $userId, $metaKey, true ) );
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Wartość pola zgłoszenia w jednej linii.
	 *
	 * Pole wielowartościowe (checkbox, multi-select) jest tablicą i idzie do maila jako wyliczenie
	 * po przecinku: redakcja czyta ten mail jako treść wniosku, a pusta wartość przy polu, które
	 * wnioskodawca wypełnił, jest gorsza niż brak zmiennej. Plan 5 jest pierwszym, który reklamuje
	 * `{pole_<klucz>}` administratorowi na ekranie szablonów, więc dopiero teraz ma to wyzwalacz.
	 *
	 * Element nieskalarny (tablica w tablicy, obiekt) wypada: w mailu do człowieka nie ma dla niego
	 * sensownej postaci, a `(string)` na tablicy to ostrzeżenie PHP.
	 *
	 * @param mixed $value Wartość metadanej.
	 */
	private static function one_line( mixed $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$parts[] = (string) $item;
				}
			}
			$value = implode( ', ', $parts );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	/**
	 * Pola zgłoszenia jako lista `- klucz: wartość`, po jednej w linii.
	 *
	 * @param array<string, string> $fields Pola zgłoszenia.
	 */
	private static function as_list( array $fields ): string {
		$lines = array();
		foreach ( $fields as $name => $value ) {
			$lines[] = '- ' . $name . ': ' . $value;
		}

		return implode( "\n", $lines );
	}
}
