<?php
/**
 * Zmienne szablonu maila budowane z konta i ustawień portalu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Mail;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
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
	 * @param Profession|null $profession Profesja konta.
	 * @param string          $number     Zapisany numer.
	 */
	private static function validation_result( ?Profession $profession, string $number ): string {
		// Profesja wycofana z ustawień: nie wiadomo już, czego konto miało dowodzić, więc nie orzekamy.
		if ( null === $profession || CredentialType::None === $profession->credentialType || '' === $profession->credentialField ) {
			return __( 'nie dotyczy', 'portal-users' );
		}
		if ( '' === $number ) {
			return __( 'brak numeru', 'portal-users' );
		}
		$validator = ( new ValidatorRegistry() )->for( $profession->credentialType );
		if ( null === $validator ) {
			return __( 'nie dotyczy', 'portal-users' );
		}

		// Surowy kod walidatora (`invalid_format`, `invalid_checksum`) zostaje tu CELOWO poza treścią:
		// to maszynowy żargon po angielsku w polskim mailu do człowieka, a jego następny ruch jest ten
		// sam bez względu na to, do czego walidator się przyczepił — sprawdzić numer w rejestrze.
		// Szczegółowy powód należy do ekranu wniosków, obok odnośnika do rejestru. Nie przywracać.
		return $validator->validate( $number )->isValid()
			? __( 'poprawny', 'portal-users' )
			: __( 'niepoprawny', 'portal-users' );
	}

	/**
	 * Pola zgłoszenia widoczne dla profesji, każde zwinięte do jednej linii, posortowane po kluczu.
	 *
	 * Jeden odczyt wszystkich metadanych zamiast zapytania na pole.
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
			$value        = $values[0] ?? '';
			$out[ $name ] = is_string( $value ) ? trim( (string) preg_replace( '/\s+/', ' ', $value ) ) : '';
		}
		ksort( $out );

		return $out;
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
