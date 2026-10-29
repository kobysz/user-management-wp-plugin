<?php
/**
 * Testy przebiegu importu w prawdziwej bazie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Import;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Import\ImportMapping;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Import\ImportRunner;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class ImportRunnerTest extends WP_UnitTestCase {

	private const PWZ_FIELD = UserMeta::ACF_PREFIX . 'pwz';

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
				)
			)
		);
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array( 'pwz' => 'required' ),
				)
			)
		);
	}

	/**
	 * @return ImportMapping Mapowanie numeru, profesji i stanu ze starego systemu.
	 */
	private function mapping(): ImportMapping {
		return ImportMapping::fromArray(
			array(
				'fields'           => array( 'pwz' => 'wpmem_pwz' ),
				'professionKey'    => 'wpmem_role',
				'professionValues' => array( 'doctor' => 'lekarz' ),
				'statusKey'        => 'wpmem_status',
				'statusValues'     => array( 'active' => 'approved' ),
				'fallback'         => ImportMapping::FALLBACK_NONE,
			)
		);
	}

	/**
	 * @param string $pwz Numer w danych źródłowych.
	 * @return int Identyfikator konta z kompletem danych źródłowych.
	 */
	private function source_account( string $pwz = '1234567' ): int {
		$id = (int) self::factory()->user->create();
		update_user_meta( $id, 'wpmem_pwz', $pwz );
		update_user_meta( $id, 'wpmem_role', 'doctor' );
		update_user_meta( $id, 'wpmem_status', 'active' );

		return $id;
	}

	/**
	 * @return int Liczba wszystkich kont portalu.
	 */
	private function all_accounts(): int {
		return count( get_users( array( 'fields' => 'ID' ) ) );
	}

	/**
	 * @return int Liczba wierszy metadanych wtyczki (pusers_*) w całej bazie.
	 */
	private function plugin_meta_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( 'pusers_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test liczy wiersze tabeli rdzenia.
	}

	/**
	 * @return list<object> Wiersze logu zdarzeń od najstarszego.
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * @return int Identyfikator administratora, który zatwierdza import.
	 */
	private function actor(): int {
		return (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Uruchamia zapis z zepsutym wstawianiem numeru dla jednego konta; filtr i tryb błędów wracają w finally.
	 *
	 * @param int    $victim Konto, któremu INSERT metadanej ma się nie udać.
	 * @param string $key    Klucz metadanej, której zapis ma paść.
	 * @return \PortalUsers\Domain\Import\ImportReport
	 */
	private function apply_with_broken_insert( int $victim, string $key = self::PWZ_FIELD ): \PortalUsers\Domain\Import\ImportReport {
		global $wpdb;
		$filter = static fn( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' )
			&& str_contains( $query, "`{$wpdb->usermeta}`" )
			&& str_contains( $query, "({$victim}, '" . $key . "'" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;

		$previous_suppress = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			return ( new ImportRunner() )->apply( $this->mapping(), $this->actor() );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous_suppress );
		}
	}

	public function test_porazka_zapisu_logu_jest_w_raporcie_a_dane_zostaja_zapisane(): void {
		global $wpdb;
		$id     = $this->source_account();
		$actor  = $this->actor();
		$table  = Migrations::table( 'event_log' );
		$filter = static fn( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, "`{$table}`" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;

		$previous_suppress = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$report = ( new ImportRunner() )->apply( $this->mapping(), $actor );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous_suppress );
		}

		$this->assertTrue( $report->logFailed() );
		$this->assertSame( 1, $report->changed() );
		$this->assertSame( '1234567', get_user_meta( $id, self::PWZ_FIELD, true ) );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ) );
		$this->assertSame( array(), $this->log_rows() );
	}

	public function test_porazka_drugiego_wiersza_logu_tez_jest_w_raporcie(): void {
		global $wpdb;
		$victim = $this->source_account();
		$table  = Migrations::table( 'event_log' );
		$fail   = static fn( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, "`{$table}`" ) && str_contains( $query, 'import_write_failed' )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;
		$pwz    = static fn( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, "`{$wpdb->usermeta}`" ) && str_contains( $query, "({$victim}, '" . self::PWZ_FIELD . "'" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;

		$previous_suppress = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		add_filter( 'query', $pwz );
		try {
			$report = ( new ImportRunner() )->apply( $this->mapping(), $this->actor() );
		} finally {
			remove_filter( 'query', $fail );
			remove_filter( 'query', $pwz );
			$wpdb->suppress_errors( $previous_suppress );
		}

		$this->assertSame( 1, $report->writeFailures() );
		$this->assertTrue( $report->logFailed() );
		$this->assertSame( array( ImportRunner::LOG_APPLIED ), array_map( static fn( object $row ): string => (string) $row->action, $this->log_rows() ) );
	}

	public function test_porazka_logu_nie_jest_zgloszona_przy_zwyklym_zapisie_ani_w_trybie_probnym(): void {
		$this->source_account();
		$actor  = $this->actor();
		$runner = new ImportRunner();

		$this->assertFalse( $runner->dry_run( $this->mapping() )->logFailed() );
		$this->assertFalse( $runner->apply( $this->mapping(), $actor )->logFailed() );
		$this->assertCount( 1, $this->log_rows() );
	}

	public function test_tryb_probny_nie_zapisuje_nic(): void {
		$this->source_account();
		$this->source_account();
		$this->source_account();
		$meta_before = $this->plugin_meta_rows();
		$runner      = new ImportRunner();

		$report = $runner->dry_run( $this->mapping() );

		$this->assertSame( 3, $report->changed(), 'Podgląd widzi trzy konta do zmiany.' );
		$this->assertSame( $meta_before, $this->plugin_meta_rows() );
		$this->assertSame( array(), $this->log_rows() );

		// Ten sam stan, ten sam przebieg z zapisem naprawdę by coś zapisał; inaczej brak zapisu w podglądzie niczego nie dowodzi.
		$applied = $runner->apply( $this->mapping(), $this->actor() );
		$this->assertSame( 3, $applied->changed() );
		$this->assertGreaterThan( $meta_before, $this->plugin_meta_rows() );
	}

	public function test_tryb_probny_raportuje_te_same_liczby_co_zapis(): void {
		$this->source_account();
		$this->source_account( 'zly numer' );
		$this->source_account( '7654321' );
		// Konto, w którym wszystko już jest zapisane: obie ścieżki mają je pominąć tak samo.
		$done = $this->source_account();
		update_user_meta( $done, self::PWZ_FIELD, '1234567' );
		update_user_meta( $done, UserMeta::PROFESSION, 'lekarz' );
		AccountStatusRepository::set( $done, AccountStatus::Approved );
		// Aktor powstaje przed oboma przebiegami; konto utworzone między nimi zmieniłoby liczbę skanowanych.
		$actor  = $this->actor();
		$runner = new ImportRunner();

		$dry     = $runner->dry_run( $this->mapping() )->toArray();
		$applied = $runner->apply( $this->mapping(), $actor )->toArray();

		$this->assertSame( 3, $dry['changed'] );
		$this->assertGreaterThan( 0, $dry['scanned'] );
		$this->assertSame( $dry, $applied );
	}

	public function test_zapis_wpisuje_pola_profesje_i_stan(): void {
		$id = $this->source_account();

		( new ImportRunner() )->apply( $this->mapping(), $this->actor() );

		$this->assertSame( '1234567', get_user_meta( $id, self::PWZ_FIELD, true ) );
		$this->assertSame( 'lekarz', get_user_meta( $id, UserMeta::PROFESSION, true ) );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ) );
	}

	public function test_wartosc_identyczna_nie_liczy_sie_jako_zmiana_ani_jako_porazka(): void {
		$same = $this->source_account();
		update_user_meta( $same, self::PWZ_FIELD, '1234567' );
		update_user_meta( $same, UserMeta::PROFESSION, 'lekarz' );
		AccountStatusRepository::set( $same, AccountStatus::Approved );

		$report = ( new ImportRunner() )->apply( $this->mapping(), $this->actor() );

		$this->assertSame( 0, $report->changed() );
		$this->assertSame( 0, $report->writeFailures() );
		$this->assertSame( '1234567', get_user_meta( $same, self::PWZ_FIELD, true ) );

		// Kontrola: konto z inną wartością w kolejnym przebiegu jest zmienione, więc zero powyżej nie wynika z bezczynności.
		$other = $this->source_account( '7654321' );
		update_user_meta( $other, self::PWZ_FIELD, '1234567' );
		$report = ( new ImportRunner() )->apply( $this->mapping(), $this->actor() );
		$this->assertSame( 1, $report->changed() );
		$this->assertSame( 0, $report->writeFailures() );
		$this->assertSame( '7654321', get_user_meta( $other, self::PWZ_FIELD, true ) );
	}

	public function test_padniety_zapis_jest_zgloszony_a_konto_nie_jest_zmienione(): void {
		$victim = $this->source_account();
		$fine   = $this->source_account();

		$report = $this->apply_with_broken_insert( $victim );

		$this->assertSame( 1, $report->writeFailures() );
		$this->assertSame( array( $victim ), $report->writeFailureSample() );
		$this->assertSame( 1, $report->changed(), 'Zmienione jest tylko konto, któremu zapis się udał.' );
		$this->assertSame( '', get_user_meta( $victim, self::PWZ_FIELD, true ) );
		$this->assertSame( '1234567', get_user_meta( $fine, self::PWZ_FIELD, true ) );
		$this->assertSame( 'lekarz', get_user_meta( $victim, UserMeta::PROFESSION, true ), 'Po porażce jednej pozycji pozostałe są nadal zapisywane.' );

		$actions = array_map( static fn( object $row ): string => (string) $row->action, $this->log_rows() );
		$this->assertSame( array( ImportRunner::LOG_APPLIED, ImportRunner::LOG_WRITE_FAILED ), $actions );
	}

	public function test_padniety_zapis_stanu_jest_zgloszony_a_pozostale_pozycje_zapisane(): void {
		$victim = $this->source_account();

		$report = $this->apply_with_broken_insert( $victim, UserMeta::STATUS );

		$this->assertSame( 1, $report->writeFailures() );
		$this->assertSame( array( $victim ), $report->writeFailureSample() );
		$this->assertSame( 0, $report->changed() );
		$this->assertNull( AccountStatusRepository::get( $victim ) );
		$this->assertSame( '1234567', get_user_meta( $victim, self::PWZ_FIELD, true ) );
		$this->assertSame( 'lekarz', get_user_meta( $victim, UserMeta::PROFESSION, true ) );
	}

	public function test_wartosc_z_ukosnikiem_zapisuje_sie_dokladnie_i_drugi_przebieg_nic_nie_zmienia(): void {
		$id = (int) self::factory()->user->create();
		update_user_meta( $id, 'wpmem_name', wp_slash( 'a\b' ) );
		$this->assertSame( 'a\b', get_user_meta( $id, 'wpmem_name', true ) );
		$mapping = ImportMapping::fromArray( array( 'fields' => array( 'name' => 'wpmem_name' ) ) );
		$actor   = $this->actor();
		$runner  = new ImportRunner();

		$dry     = $runner->dry_run( $mapping );
		$applied = $runner->apply( $mapping, $actor );

		$this->assertSame( 'a\b', get_user_meta( $id, UserMeta::ACF_PREFIX . 'name', true ) );
		$this->assertSame( 1, $applied->changed() );
		$this->assertSame( 0, $applied->writeFailures() );
		$this->assertSame( $dry->toArray(), $applied->toArray(), 'Podgląd zgadza się z zapisem.' );

		$again = $runner->apply( $mapping, $actor );
		$this->assertSame( 0, $again->changed() );
		$this->assertSame( 0, $again->writeFailures() );
	}

	public function test_zapis_zostawia_jeden_wiersz_w_logu_z_aktorem_i_licznikami(): void {
		$id    = $this->source_account();
		$email = (string) get_userdata( $id )->user_email;
		$actor = $this->actor();

		( new ImportRunner() )->apply( $this->mapping(), $actor );

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( ImportRunner::LOG_APPLIED, $rows[0]->action );
		$this->assertSame( (string) $actor, $rows[0]->actor_user_id );
		$this->assertSame( '0', $rows[0]->subject_user_id );
		$this->assertStringNotContainsString( $email, (string) $rows[0]->context );

		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( 1, $context['changed'] );
		$this->assertSame( 0, $context['write_failures'] );
		$this->assertSame( array( 'wpmem_pwz', 'wpmem_role', 'wpmem_status' ), $context['source_keys'] );
		$this->assertArrayNotHasKey( 'samples', $context );
		$this->assertArrayNotHasKey( 'write_failure_sample', $context );
	}

	public function test_wiersz_porazki_zapisu_nie_niesie_identyfikatorow_kont(): void {
		$victim = $this->source_account();

		$this->apply_with_broken_insert( $victim );

		$rows = $this->log_rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( ImportRunner::LOG_WRITE_FAILED, $rows[1]->action );
		$this->assertSame( '0', $rows[1]->subject_user_id );
		$context = json_decode( (string) $rows[1]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( 1, $context['write_failures'] );
		$this->assertArrayNotHasKey( 'write_failure_sample', $context );
		$this->assertArrayNotHasKey( 'samples', $context );
		$this->assertStringNotContainsString( (string) $victim, (string) $rows[1]->context );

		$applied = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $applied );
		$keys = array_keys( $applied );
		sort( $keys );
		$this->assertSame( array( 'changed', 'issues', 'scanned', 'source_keys', 'write_failures' ), $keys );
		$this->assertSame( 1, $applied['write_failures'] );
	}

	public function test_przebieg_obchodzi_wszystkie_konta_ponad_jedna_partie(): void {
		$ids = self::factory()->user->create_many( ImportRunner::BATCH + 5 );
		foreach ( $ids as $id ) {
			update_user_meta( (int) $id, 'wpmem_status', 'active' );
		}
		$mapping = ImportMapping::fromArray(
			array(
				'statusKey'    => 'wpmem_status',
				'statusValues' => array( 'active' => 'approved' ),
			)
		);

		$report = ( new ImportRunner() )->apply( $mapping, $this->actor() );

		$this->assertGreaterThan( ImportRunner::BATCH, $report->scanned() );
		$this->assertSame( $this->all_accounts(), $report->scanned() );
		$this->assertSame( ImportRunner::BATCH + 5, $report->changed() );
		foreach ( $ids as $id ) {
			$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( (int) $id ) );
		}
	}

	public function test_konta_bez_zadnych_danych_zrodlowych_sa_skanowane_ale_nie_zmieniane(): void {
		self::factory()->user->create();
		self::factory()->user->create();
		$meta_before = $this->plugin_meta_rows();

		$report = ( new ImportRunner() )->apply( $this->mapping(), $this->actor() );

		$this->assertSame( $this->all_accounts(), $report->scanned() );
		$this->assertGreaterThanOrEqual( 2, $report->scanned() );
		$this->assertSame( 0, $report->changed() );
		$this->assertSame( $meta_before, $this->plugin_meta_rows() );
	}

	public function test_wartosc_tablicowa_w_danych_zrodlowych_jest_zgloszona_a_nie_zapisana(): void {
		$id = $this->source_account();
		// Stary system zapisał numer jako tablicę; baza trzyma ją jako tekst serializowany.
		update_user_meta( $id, 'wpmem_pwz', array( '1234567' ) );

		$report = ( new ImportRunner() )->apply( $this->mapping(), $this->actor() );

		$this->assertSame( 1, $report->issueCount( 'value_unusable' ) );
		$this->assertSame( '', get_user_meta( $id, self::PWZ_FIELD, true ) );
	}

	public function test_puste_mapowanie_nie_rusza_zadnego_konta(): void {
		$id          = $this->source_account();
		$meta_before = $this->plugin_meta_rows();
		$mapping     = ImportMapping::fromArray( array() );
		$this->assertTrue( $mapping->isEmpty() );

		$dry     = ( new ImportRunner() )->dry_run( $mapping );
		$applied = ( new ImportRunner() )->apply( $mapping, $this->actor() );

		$this->assertSame( 0, $dry->scanned() );
		$this->assertSame( 0, $applied->scanned() );
		$this->assertSame( $meta_before, $this->plugin_meta_rows() );
		$this->assertSame( '', get_user_meta( $id, UserMeta::PROFESSION, true ) );
		$this->assertSame( array(), $this->log_rows() );
	}
}
