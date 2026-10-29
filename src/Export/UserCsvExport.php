<?php
/**
 * Składanie eksportu wniosków do CSV.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Export;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Admin\VerdictLabels;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Application\ApplicationRow;
use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Domain\Export\CsvDocument;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ApplicationRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use RuntimeException;

/**
 * Zamienia konta z filtra ekranu wniosków w gotowy tekst pliku. Neutralizację komórek robi `CsvDocument`,
 * więc tu wartości idą surowe: druga neutralizacja dałaby w arkuszu podwójny apostrof.
 *
 * Zasada „surowy kod, nie etykieta" obowiązuje WYŁĄCZNIE kolumnę stanu konta. Werdykt numeru i profesja są
 * etykietami z konieczności: pierwszy nie ma kodu w domenie, a druga jest tekstem wpisanym przez administratora.
 */
final class UserCsvExport {

	/**
	 * Rozmiar partii czytanej z bazy. Cała lista naraz zhydratowałaby każde konto w pamięci jednego żądania.
	 */
	public const BATCH = 200;

	/**
	 * Tekst pliku (z BOM-em) dla zakładki i profesji z ekranu wniosków.
	 *
	 * @param AccountStatus $status        Stan, czyli zakładka.
	 * @param string        $professionKey Klucz profesji albo '' dla wszystkich.
	 * @throws RuntimeException Gdy dokument nie złoży się w pamięci.
	 */
	public static function for_filter( AccountStatus $status, string $professionKey ): string {
		// Wszystkie pola grupy, nie tylko widoczne dla profesji: plik ma pokazać, co jest zapisane, bo dane
		// przyjęte przed zmianą macierzy inaczej znikałyby bez śladu. Grupa niedostępna daje zero kolumn pól.
		$fields = ( new AcfFieldSource( SettingsRepository::get()->acfGroupKey ) )->fields();

		$document = new CsvDocument( self::headers( $fields ) );

		$professions = ProfessionRepository::get();
		$offset      = 0;
		$names       = array_keys( $fields );
		do {
			$rows = ApplicationRepository::paginate( $status, $professionKey, self::BATCH, $offset );
			foreach ( $rows as $row ) {
				$document->addRow( self::cells( $row, $names, $professions ) );
			}
			$offset += self::BATCH;
			// Krótsza partia jest ostatnia; pełna może mieć następną.
			$full = count( $rows ) >= self::BATCH;
		} while ( $full );

		return $document->render();
	}

	/**
	 * Nagłówek: kolumny stałe, potem etykieta każdego pola grupy.
	 *
	 * @param array<string, string> $fields Nazwa pola => etykieta.
	 * @return array<int, string>
	 */
	private static function headers( array $fields ): array {
		return array_merge(
			array(
				__( 'Login', 'portal-users' ),
				__( 'Adres e-mail', 'portal-users' ),
				__( 'Nazwa wyświetlana', 'portal-users' ),
				__( 'Stan', 'portal-users' ),
				__( 'Profesja', 'portal-users' ),
				__( 'Numer uprawnień', 'portal-users' ),
				__( 'Werdykt numeru', 'portal-users' ),
				__( 'Data rejestracji', 'portal-users' ),
				__( 'Zgoda udzielona', 'portal-users' ),
				__( 'Wersja regulaminu', 'portal-users' ),
				__( 'Adres IP rejestracji', 'portal-users' ),
			),
			array_values( $fields )
		);
	}

	/**
	 * Komórki jednego konta w kolejności nagłówka.
	 *
	 * @param ApplicationRow         $row         Konto.
	 * @param array<int, string|int> $fieldNames  Nazwy pól grupy w kolejności nagłówka.
	 * @param ProfessionCollection   $professions Profesje z ustawień.
	 * @return array<int, string>
	 */
	private static function cells( ApplicationRow $row, array $fieldNames, ProfessionCollection $professions ): array {
		$profession = $professions->get( $row->professionKey );

		$cells = array(
			$row->login,
			$row->email,
			$row->displayName,
			// Surowy kod stanu to decyzja, nie przeoczenie: plik trafia do arkuszy i dalszej obróbki, a kod jest
			// stabilny, w odróżnieniu od przetłumaczonej etykiety, którą zmieni pierwsza korekta tłumaczenia.
			$row->status->value,
			// Profesja usunięta z ustawień zostaje w koncie; surowy klucz mówi administratorowi, o którą chodzi.
			null === $profession ? $row->professionKey : $profession->label,
			$row->credentialNumber,
			VerdictLabels::text( CredentialVerdict::for( $profession, $row->credentialNumber ) ),
			$row->registeredAtUtc,
			self::meta( $row->userId, UserMeta::CONSENT_AT ),
			self::meta( $row->userId, UserMeta::TERMS_VERSION ),
			self::meta( $row->userId, UserMeta::REGISTRATION_IP ),
		);
		foreach ( $fieldNames as $name ) {
			$cells[] = self::meta( $row->userId, UserMeta::ACF_PREFIX . $name );
		}

		return $cells;
	}

	/**
	 * Metadana do jednej komórki; lista (checkbox, wielokrotny select) schodzi do wartości rozdzielonych
	 * przecinkiem, żeby pole wielowartościowe nie rozbijało wiersza.
	 *
	 * @param int    $userId Identyfikator konta.
	 * @param string $key    Klucz metadanej.
	 */
	private static function meta( int $userId, string $key ): string {
		$value = get_user_meta( $userId, $key, true );
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
}
