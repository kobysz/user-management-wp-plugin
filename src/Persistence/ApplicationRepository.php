<?php
/**
 * Repozytorium wniosków: konta z wtyczkowym stanem, czytane z metadanych użytkownika.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Application\ApplicationRow;
use WP_User;
use WP_User_Query;

/**
 * Wnioskiem jest konto z rozpoznanym `pusers_status`; konto bez niego (administrator dodany ręcznie)
 * i z wartością nierozpoznaną nie należy do żadnej zakładki.
 */
final class ApplicationRepository {

	/**
	 * Liczba kont w każdym stanie, jednym zapytaniem; każdy z czterech stanów jest obecny.
	 *
	 * Jedno zapytanie zamiast czterech, bo ekran liczy wszystkie zakładki przy każdym renderze.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;

		$counts = array();
		foreach ( AccountStatus::cases() as $case ) {
			$counts[ $case->value ] = 0;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, COUNT(*) AS c FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value",
				UserMeta::STATUS
			)
		);

		foreach ( (array) $rows as $row ) {
			$status = AccountStatus::tryFromStored( (string) $row->meta_value );
			if ( null !== $status ) {
				$counts[ $status->value ] += (int) $row->c;
			}
		}

		return $counts;
	}

	/**
	 * Liczba wniosków w stanie, opcjonalnie zawężona do profesji.
	 *
	 * @param AccountStatus $status        Stan.
	 * @param string        $professionKey Klucz profesji; pusty = wszystkie.
	 * @return int
	 */
	public static function total( AccountStatus $status, string $professionKey = '' ): int {
		// `number` wynosi 1, nie 0: zero oznacza w rdzeniu brak limitu, więc wczytałby i zhydratował
		// każde dopasowane konto. Pełną liczbę daje `get_total()` z osobnego zapytania liczącego,
		// którego LIMIT nie dotyczy; identyfikatory wystarczą, bo obiektów nikt nie czyta.
		return (int) self::query( $status, $professionKey, 1, 0, 'ID' )->get_total();
	}

	/**
	 * Jedna strona wniosków, od najnowszego.
	 *
	 * @param AccountStatus $status        Stan.
	 * @param string        $professionKey Klucz profesji; pusty = wszystkie.
	 * @param int           $perPage       Rozmiar strony.
	 * @param int           $offset        Przesunięcie.
	 * @return array<int, ApplicationRow>
	 */
	public static function paginate( AccountStatus $status, string $professionKey, int $perPage, int $offset ): array {
		$query  = self::query( $status, $professionKey, $perPage, $offset );
		$fields = self::credential_fields();
		$rows   = array();
		foreach ( $query->get_results() as $user ) {
			if ( $user instanceof WP_User ) {
				$rows[] = self::build_row( $user, $status, $fields );
			}
		}
		return $rows;
	}

	/**
	 * Jeden wniosek do widoku szczegółów; null, gdy konta nie ma albo nie ma rozpoznanego stanu.
	 *
	 * @param int $userId Identyfikator konta.
	 * @return ApplicationRow|null
	 */
	public static function one( int $userId ): ?ApplicationRow {
		$user = get_userdata( $userId );
		if ( ! $user instanceof WP_User ) {
			return null;
		}
		$status = AccountStatus::tryFromStored( self::meta_string( $userId, UserMeta::STATUS ) );
		if ( null === $status ) {
			return null;
		}
		return self::build_row( $user, $status, self::credential_fields() );
	}

	/**
	 * Zapytanie o konta w stanie, od najnowszego.
	 *
	 * @param AccountStatus $status        Stan.
	 * @param string        $professionKey Klucz profesji; pusty = bez filtra.
	 * @param int           $number        Ile kont pobrać.
	 * @param int           $offset        Przesunięcie.
	 * @param string        $fields        Pobierane pola: 'all' (obiekty) albo 'ID'.
	 * @return WP_User_Query
	 */
	private static function query( AccountStatus $status, string $professionKey, int $number, int $offset, string $fields = 'all' ): WP_User_Query {
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => UserMeta::STATUS,
				'value' => $status->value,
			),
		);
		if ( '' !== $professionKey ) {
			$meta_query[] = array(
				'key'   => UserMeta::PROFESSION,
				'value' => $professionKey,
			);
		}

		return new WP_User_Query(
			array(
				'meta_query' => $meta_query,
				'orderby'    => 'registered',
				'order'      => 'DESC',
				'number'     => $number,
				'offset'     => $offset,
				'fields'     => $fields,
			)
		);
	}

	/**
	 * Metadana jako tekst; brak albo wartość nietekstowa to pusty napis.
	 *
	 * @param int    $userId Identyfikator konta.
	 * @param string $key    Klucz metadanej.
	 * @return string
	 */
	private static function meta_string( int $userId, string $key ): string {
		$value = get_user_meta( $userId, $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Klucz profesji => pole z numerem uprawnień (tylko profesje, które je mają).
	 *
	 * @return array<string, string>
	 */
	private static function credential_fields(): array {
		$fields = array();
		foreach ( ProfessionRepository::get()->all() as $profession ) {
			if ( '' !== $profession->credentialField ) {
				$fields[ $profession->key ] = $profession->credentialField;
			}
		}
		return $fields;
	}

	/**
	 * Składa wiersz; profesja usunięta z ustawień zostawia numer pusty, ale nie gubi wniosku.
	 *
	 * @param WP_User              $user   Konto.
	 * @param AccountStatus        $status Stan.
	 * @param array<string,string> $fields Mapa profesja => pole numeru.
	 * @return ApplicationRow
	 */
	private static function build_row( WP_User $user, AccountStatus $status, array $fields ): ApplicationRow {
		$profession = self::meta_string( $user->ID, UserMeta::PROFESSION );
		$number     = '';
		if ( isset( $fields[ $profession ] ) ) {
			$number = self::meta_string( $user->ID, UserMeta::ACF_PREFIX . $fields[ $profession ] );
		}

		return new ApplicationRow(
			(int) $user->ID,
			(string) $user->user_login,
			(string) $user->user_email,
			(string) $user->display_name,
			$profession,
			$number,
			(string) $user->user_registered,
			$status
		);
	}
}
