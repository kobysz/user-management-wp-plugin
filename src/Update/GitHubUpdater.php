<?php
/**
 * Samodzielny aktualizator wtyczki oparty o wydania GitHuba.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Update;

use PortalUsers\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sprawdza najnowsze wydanie na GitHubie i podstawia je do mechanizmu aktualizacji WordPressa.
 */
final class GitHubUpdater {

	/**
	 * Repozytorium wydań. Ta sama wartość stoi w nagłówku `Update URI:` wtyczki — zgodność
	 * trzyma test, bo nazwa wskazująca nieistniejące repozytorium kończy się 404, buforem
	 * negatywnym i ciszą: administrator widzi „brak aktualizacji" bez żadnego ostrzeżenia.
	 */
	private const REPO = 'kobysz/user-management-wp-plugin';

	/**
	 * Nazwa assetu wydania. Workflow wydania buduje archiwum o dokładnie tej nazwie.
	 */
	private const ASSET_NAME = 'portal-users.zip';

	private const CACHE_KEY = 'pusers_update_latest';

	/**
	 * Bufor odpowiedzi udanej.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Bufor odpowiedzi nieudanej. Limit zapytań GitHuba bez uwierzytelnienia to 60 na godzinę
	 * na adres, a panel przelicza aktualizacje często — bez buforowania porażek portal za NAT-em
	 * potrafi wyczerpać limit dla wszystkich.
	 */
	private const CACHE_TTL_FAILURE = HOUR_IN_SECONDS;

	/**
	 * Dopuszczalny format tagu po zdjęciu wiodącego „v". Ten sam, którego pilnuje test nagłówka
	 * wtyczki i bramka workflow wydania.
	 */
	private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

	/**
	 * Basename wtyczki, np. `portal-users/portal-users.php`.
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Slug wtyczki, czyli katalog wtyczki.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Wyprowadza basename i slug z głównego pliku wtyczki.
	 */
	public function __construct() {
		$this->basename = plugin_basename( Plugin::plugin_file() );
		$this->slug     = dirname( $this->basename );
	}

	/**
	 * Podpina filtry sprawdzania aktualizacji i opisu wydania.
	 */
	public static function register(): void {
		$self = new self();
		add_filter( 'pre_set_site_transient_update_plugins', array( $self, 'inject_update' ) );
		add_filter( 'plugins_api', array( $self, 'plugins_api' ), 10, 3 );
	}

	/**
	 * Zgłasza wtyczkę do transienta aktualizacji: jako dostępną aktualizację albo jako aktualną.
	 *
	 * Wpis w `no_update` jest obowiązkowy dla wtyczek spoza wordpress.org: bez obecności
	 * w `response` LUB w `no_update` rdzeń uznaje wtyczkę za nieobsługującą aktualizacji
	 * (`update-supported = false`) i ukrywa przełącznik „Włącz automatyczne aktualizacje".
	 *
	 * @param mixed $transient Transient `update_plugins` (`stdClass`) albo inna wartość.
	 * @return mixed Zmieniony transient albo wartość wejściowa bez zmian.
	 */
	public function inject_update( $transient ) {
		// Rdzeń zawsze podaje tu `stdClass` (`wp_update_plugins()` buduje `new stdClass`), a wpisy
		// dopisujemy jako właściwości dynamiczne. Obiekt obcej klasy przepuszczamy nietknięty:
		// wtyczka nie zgłosi wtedy aktualizacji, co jest bezpieczniejsze od pisania właściwości
		// do cudzego obiektu o nieznanym kontrakcie.
		if ( ! $transient instanceof \stdClass ) {
			return $transient;
		}

		$latest = $this->fetch_latest();
		if ( null === $latest ) {
			// Brak wiarygodnej odpowiedzi: nie zgadujemy, czy jest nowsza wersja, ale wtyczka
			// MUSI zostać zgłoszona rdzeniowi jako obsługująca aktualizacje. Rdzeń ustawia
			// `update-supported` na true tylko dla wtyczki obecnej w `response` albo `no_update`
			// (`class-wp-plugins-list-table.php`), a na tej wartości wisi przełącznik „Włącz
			// automatyczne aktualizacje". Milczenie przy porażce gasiłoby ten przełącznik —
			// przy 403 z limitem zapytań na godzinę, a przy pomyłce w nazwie repozytorium
			// trwale i bez żadnego komunikatu.
			return $this->mark_supported( $transient );
		}

		// Świeży odczyt: w żądaniu przeliczającym aktualizacje pliki na dysku mogą być już nowe,
		// a wersja zapamiętana w pamięci procesu — stara. Odczyt z pamięci dawałby widmo
		// „dostępna aktualizacja" zaraz po udanej aktualizacji.
		$installed = Plugin::version( true );
		$newer     = version_compare( $latest['version'], $installed, '>' );

		$entry = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $newer ? $latest['version'] : $installed,
			'package'     => $latest['package'],
			'url'         => $latest['url'],
		);

		$target = $newer ? 'response' : 'no_update';
		$stale  = $newer ? 'no_update' : 'response';

		if ( ! isset( $transient->{$target} ) || ! is_array( $transient->{$target} ) ) {
			$transient->{$target} = array();
		}
		$transient->{$target}[ $this->basename ] = $entry;

		// Sprzątanie po poprzednim stanie: nieaktualny wpis o dostępnej aktualizacji potrafi
		// przeżyć w transiencie udaną aktualizację.
		if ( isset( $transient->{$stale} ) && is_array( $transient->{$stale} ) ) {
			unset( $transient->{$stale}[ $this->basename ] );
		}

		return $transient;
	}

	/**
	 * Zgłasza wtyczkę jako obsługującą aktualizacje, nie podając żadnej do pobrania.
	 *
	 * Wpis o już znanej aktualizacji zostaje nietknięty: mógł powstać z udanego sprawdzenia,
	 * a porażka następnego nie może zabrać administratorowi widoku aktualizacji, o której
	 * wtyczka wie.
	 *
	 * @param \stdClass $transient Transient `update_plugins`.
	 */
	private function mark_supported( \stdClass $transient ): \stdClass {
		if ( isset( $transient->response ) && is_array( $transient->response ) && isset( $transient->response[ $this->basename ] ) ) {
			return $transient;
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$transient->no_update[ $this->basename ] = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => Plugin::version( true ),
			'package'     => '',
			'url'         => 'https://github.com/' . self::REPO,
		);

		return $transient;
	}

	/**
	 * Dostarcza opis wydania do okna „Szczegóły wtyczki".
	 *
	 * @param mixed  $result Domyślny wynik filtra.
	 * @param string $action Żądana akcja `plugins_api`.
	 * @param mixed  $args   Argumenty żądania (obiekt ze slugiem).
	 * @return mixed Obiekt informacji o wtyczce albo wartość wejściowa bez zmian.
	 */
	public function plugins_api( $result, $action, $args ) {
		$slug = is_object( $args ) && isset( $args->slug ) ? (string) $args->slug : '';
		if ( 'plugin_information' !== $action || $slug !== $this->slug ) {
			return $result;
		}

		$latest = $this->fetch_latest();
		if ( null === $latest ) {
			return $result;
		}

		return (object) array(
			// Nazwa własna z nagłówka `Plugin Name`, nie napis do tłumaczenia.
			'name'          => 'Portal Users',
			'slug'          => $this->slug,
			'version'       => $latest['version'],
			'download_link' => $latest['package'],
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( $latest['changelog'] ) ) ),
		);
	}

	/**
	 * Pobiera najnowsze wydanie z GitHuba, z buforowaniem pozytywnym i negatywnym.
	 *
	 * @return array{version: string, package: string, changelog: string, url: string}|null
	 */
	private function fetch_latest(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( '' === $cached ) {
			// Zapamiętana porażka.
			return null;
		}
		if ( false !== $cached ) {
			$valid = self::valid_payload( $cached );
			if ( null !== $valid ) {
				return $valid;
			}
			// Wpis zapisany starszą wersją wtyczki ma kształt sprzed zmiany. Czytanie go
			// na ślepo kończyłoby się ostrzeżeniem w żądaniu panelu, więc traktujemy go
			// jak brak buforu i pytamy GitHuba.
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'portal-users-wp',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, '', self::CACHE_TTL_FAILURE );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			set_transient( self::CACHE_KEY, '', self::CACHE_TTL_FAILURE );
			return null;
		}

		$assets = isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array();
		$parsed = self::valid_payload(
			array(
				'version'   => ltrim( isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '', 'v' ),
				'package'   => self::pick_asset( $assets ),
				'changelog' => isset( $data['body'] ) ? (string) $data['body'] : '',
				'url'       => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			)
		);

		if ( null === $parsed ) {
			// Tag w obcym formacie albo wydanie bez naszego assetu. `version_compare()` uznaje
			// „0.2" i „2" za NOWSZE od „0.1.0" (zmierzone; „release-2" wypada odwrotnie, bo
			// człon mieszany sortuje się poniżej liczbowego), więc tag pomyłkowy skierowałby
			// wszystkie portale na przypadkowy plik. Wydanie bez assetu dałoby pobranie pod
			// pustym adresem.
			set_transient( self::CACHE_KEY, '', self::CACHE_TTL_FAILURE );
			return null;
		}

		set_transient( self::CACHE_KEY, $parsed, self::CACHE_TTL );
		return $parsed;
	}

	/**
	 * Sprawdza kształt danych wydania: cztery klucze, wszystkie napisami, wersja trzycyfrowa,
	 * adres paczki niepusty.
	 *
	 * @param mixed $value Dane z buforu albo świeżo złożone z odpowiedzi API.
	 * @return array{version: string, package: string, changelog: string, url: string}|null
	 */
	private static function valid_payload( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		foreach ( array( 'version', 'package', 'changelog', 'url' ) as $key ) {
			if ( ! isset( $value[ $key ] ) || ! is_string( $value[ $key ] ) ) {
				return null;
			}
		}

		if ( 1 !== preg_match( self::VERSION_PATTERN, $value['version'] ) || '' === $value['package'] ) {
			return null;
		}

		return array(
			'version'   => $value['version'],
			'package'   => $value['package'],
			'changelog' => $value['changelog'],
			'url'       => $value['url'],
		);
	}

	/**
	 * Wybiera adres pobierania paczki po dosłownej nazwie assetu.
	 *
	 * @param array<array-key, mixed> $assets Lista assetów wydania.
	 */
	private static function pick_asset( array $assets ): string {
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'] ) && self::ASSET_NAME === $asset['name'] ) {
				return isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			}
		}

		return '';
	}
}
