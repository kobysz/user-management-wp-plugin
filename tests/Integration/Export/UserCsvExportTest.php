<?php
/**
 * Testy składania eksportu wniosków do CSV: kolumny, neutralizacja, filtr ekranu i paginacja.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Export;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Export\CsvDocument;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Export\UserCsvExport;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class UserCsvExportTest extends WP_UnitTestCase {

	private const FIXED_COLUMNS = 11;

	public function set_up(): void {
		parent::set_up();
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'              => 'lekarz',
						'label'            => 'Lekarz',
						'credential_type'  => 'physician_pwz',
						'credential_field' => 'pwz',
					),
					array(
						'key'             => 'student',
						'label'           => 'Student',
						'credential_type' => 'none',
					),
				)
			)
		);
		$this->withAcfGroup();
	}

	/**
	 * Podstawia grupę ACF z polami `pwz` i `zainteresowania` (ACF Pro nie ma w środowisku testowym).
	 */
	private function withAcfGroup(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'Numer PWZ',
				),
				array(
					'name'  => 'zainteresowania',
					'label' => 'Zainteresowania',
				),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
	}

	/**
	 * Konto w danym stanie i profesji.
	 *
	 * @param string               $status     Surowy stan.
	 * @param string               $profession Klucz profesji.
	 * @param array<string, mixed> $meta       Dodatkowe metadane (pełne klucze).
	 */
	private function account( string $status, string $profession = 'lekarz', array $meta = array() ): int {
		$id = (int) self::factory()->user->create();
		update_user_meta( $id, UserMeta::STATUS, $status );
		update_user_meta( $id, UserMeta::PROFESSION, $profession );
		foreach ( $meta as $key => $value ) {
			update_user_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Parsuje wyjście na wiersze komórek, po sprawdzeniu, że zaczyna się od BOM-u.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function parse( string $csv ): array {
		$this->assertStringStartsWith( CsvDocument::BOM, $csv );
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, substr( $csv, strlen( CsvDocument::BOM ) ) );
		rewind( $handle );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			$rows[] = $row;
		}
		fclose( $handle );
		return $rows;
	}

	public function test_naglowek_ma_kolumny_stale_i_pola_acf_w_kolejnosci_grupy(): void {
		$rows = $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) );

		$this->assertSame(
			array(
				'Login',
				'Adres e-mail',
				'Nazwa wyświetlana',
				'Stan',
				'Profesja',
				'Numer uprawnień',
				'Werdykt numeru',
				'Data rejestracji',
				'Zgoda udzielona',
				'Wersja regulaminu',
				'Adres IP rejestracji',
				'Numer PWZ',
				'Zainteresowania',
			),
			$rows[0]
		);
		$this->assertCount( 1, $rows, 'Bez kont jest sam nagłówek.' );
	}

	public function test_wiersz_niesie_dane_konta_i_wartosci_pol(): void {
		$id   = $this->account(
			'pending',
			'lekarz',
			array(
				UserMeta::CONSENT_AT                     => '2026-10-01 10:00:00',
				UserMeta::TERMS_VERSION                  => 'v3',
				UserMeta::REGISTRATION_IP                => '203.0.113.7',
				UserMeta::ACF_PREFIX . 'pwz'             => '1234567',
				UserMeta::ACF_PREFIX . 'zainteresowania' => 'kardiologia',
			)
		);
		$user = get_userdata( $id );

		$rows = $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) );

		$this->assertCount( 2, $rows );
		$row = $rows[1];
		$this->assertSame( $user->user_login, $row[0] );
		$this->assertSame( $user->user_email, $row[1] );
		$this->assertSame( $user->display_name, $row[2] );
		$this->assertSame( 'pending', $row[3] );
		$this->assertSame( 'Lekarz', $row[4] );
		$this->assertSame( '1234567', $row[5] );
		$this->assertNotSame( '', $row[6], 'Werdykt numeru jest tekstem z etykiet, nie pustką.' );
		$this->assertSame( $user->user_registered, $row[7] );
		$this->assertSame( '2026-10-01 10:00:00', $row[8] );
		$this->assertSame( 'v3', $row[9] );
		$this->assertSame( '203.0.113.7', $row[10] );
		$this->assertSame( '1234567', $row[11] );
		$this->assertSame( 'kardiologia', $row[12] );
	}

	public function test_wartosc_pola_z_formula_jest_zneutralizowana(): void {
		$this->account( 'pending', 'lekarz', array( UserMeta::ACF_PREFIX . 'zainteresowania' => '=2+2' ) );

		$rows = $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) );

		$this->assertSame( "'=2+2", $rows[1][12], 'Dokładnie jeden apostrof: neutralizację robi dokument, nie eksport.' );
	}

	public function test_pole_wielowartosciowe_jest_jedna_komorka(): void {
		$this->account( 'pending', 'lekarz', array( UserMeta::ACF_PREFIX . 'zainteresowania' => array( 'kardiologia', 'neurologia', 'a,b' ) ) );

		$rows = $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) );

		$this->assertCount( 2, $rows );
		$this->assertCount( self::FIXED_COLUMNS + 2, $rows[1] );
		$this->assertSame( 'kardiologia, neurologia, a,b', $rows[1][12] );
	}

	public function test_eksport_honoruje_zakladke_i_filtr_profesji(): void {
		$pendingDoctor  = $this->account( 'pending', 'lekarz' );
		$pendingStudent = $this->account( 'pending', 'student' );
		$approved       = $this->account( 'approved', 'lekarz' );

		$all = array_column( array_slice( $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) ), 1 ), 0 );
		$this->assertEqualsCanonicalizing(
			array( get_userdata( $pendingDoctor )->user_login, get_userdata( $pendingStudent )->user_login ),
			$all
		);

		$students = array_column( array_slice( $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, 'student' ) ), 1 ), 0 );
		$this->assertSame( array( get_userdata( $pendingStudent )->user_login ), $students );

		$approvedRows = array_column( array_slice( $this->parse( UserCsvExport::for_filter( AccountStatus::Approved, '' ) ), 1 ), 0 );
		$this->assertSame( array( get_userdata( $approved )->user_login ), $approvedRows );
	}

	public function test_profesja_usunieta_z_ustawien_nie_wywala_eksportu(): void {
		$this->account( 'pending', 'usunieta' );

		$rows = $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'usunieta', $rows[1][4], 'Profesji nie ma w ustawieniach, więc kolumna niesie surowy klucz.' );
	}

	public function test_grupa_acf_niedostepna_daje_plik_z_samymi_kolumnami_stalymi(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		$this->account( 'pending', 'lekarz', array( UserMeta::ACF_PREFIX . 'pwz' => '1234567' ) );

		$rows = $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) );

		$this->assertCount( 2, $rows );
		$this->assertCount( self::FIXED_COLUMNS, $rows[0] );
		$this->assertCount( self::FIXED_COLUMNS, $rows[1] );
	}

	public function test_konto_bez_zgody_i_bez_ip_ma_komorki_puste_nie_brakujace(): void {
		$this->account( 'pending', 'student' );

		$rows = $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) );

		$this->assertCount( count( $rows[0] ), $rows[1] );
		$this->assertSame( '', $rows[1][8] );
		$this->assertSame( '', $rows[1][9] );
		$this->assertSame( '', $rows[1][10] );
		$this->assertSame( '', $rows[1][11], 'Konto bez wartości pola ma komórkę pustą.' );
		$this->assertSame( '', $rows[1][12] );
	}

	public function test_eksport_obejmuje_wszystkie_strony_wynikow(): void {
		$total = UserCsvExport::BATCH + 50;
		for ( $i = 0; $i < $total; $i++ ) {
			$this->account( 'pending', 'student' );
		}

		$data = array_slice( $this->parse( UserCsvExport::for_filter( AccountStatus::Pending, '' ) ), 1 );

		$this->assertCount( $total, $data, 'Pętla po stronach nie może urwać się na pierwszej partii.' );
		$this->assertCount( $total, array_unique( array_column( $data, 0 ) ), 'Żadne konto nie wystąpiło dwa razy.' );
	}
}
