<?php
/**
 * Testy backfillu stanów i znacznika wdrożenia.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Account;

use PortalUsers\Account\Backfill;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class BackfillTest extends WP_UnitTestCase {

	/**
	 * Moment wdrożenia używany w testach: pięć dni temu.
	 *
	 * @var int
	 */
	private int $deployed = 0;

	public function set_up(): void {
		parent::set_up();
		$this->deployed = time() - 5 * DAY_IN_SECONDS;
		delete_option( OptionStore::OPTION_DEPLOYED_AT );
	}

	/**
	 * Zapisuje znacznik wdrożenia na podany moment.
	 *
	 * @param int $at Moment wdrożenia.
	 */
	private function deployedAt( int $at ): void {
		OptionStore::write( OptionStore::OPTION_DEPLOYED_AT, array( Backfill::KEY_AT => $at ) );
	}

	/**
	 * Konto bez stanu zarejestrowane przed albo po wdrożeniu. Domyślna data fabryki to "teraz",
	 * więc data rejestracji jest podawana zawsze jawnie.
	 *
	 * @param bool $beforeDeployment Czy konto powstało przed wdrożeniem.
	 */
	private function account( bool $beforeDeployment ): int {
		$registered = $beforeDeployment ? $this->deployed - 10 * DAY_IN_SECONDS : $this->deployed + 2 * DAY_IN_SECONDS;
		return (int) self::factory()->user->create( array( 'user_registered' => gmdate( 'Y-m-d H:i:s', $registered ) ) );
	}

	/**
	 * Wiersze logu zdarzeń od najstarszego.
	 *
	 * @return list<object>
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_aktywacja_zapisuje_znacznik_gdy_go_nie_ma(): void {
		$this->assertSame( 0, Backfill::deployed_at() );
		$before = time();

		Backfill::record_activation();

		$this->assertGreaterThanOrEqual( $before, Backfill::deployed_at() );
		$this->assertLessThanOrEqual( time(), Backfill::deployed_at() );
	}

	public function test_aktywacja_zapisuje_znacznik_tylko_raz(): void {
		Backfill::record_activation();
		// Znacznik z odległej przeszłości, wyraźnie różny od "teraz": ponowna aktywacja w tej samej
		// sekundzie nie odróżniłaby nadpisania od zachowania.
		$this->deployedAt( 12345 );
		$first = Backfill::deployed_at();

		Backfill::record_activation();

		$this->assertSame( 12345, $first );
		$this->assertSame( $first, Backfill::deployed_at() );
	}

	public function test_liczy_tylko_konta_bez_stanu_sprzed_wdrozenia(): void {
		$this->deployedAt( $this->deployed );
		$this->account( true );
		$this->account( false );
		$withStatus = $this->account( true );
		AccountStatusRepository::set( $withStatus, AccountStatus::Pending );

		$this->assertSame( 1, Backfill::stateless_count() );
	}

	public function test_nadanie_zatwierdzonego_rusza_tylko_konta_z_zakresu(): void {
		$this->deployedAt( $this->deployed );
		$old      = $this->account( true );
		$later    = $this->account( false );
		$pending  = $this->account( true );
		$rejected = $this->account( true );
		AccountStatusRepository::set( $pending, AccountStatus::Pending );
		AccountStatusRepository::set( $rejected, AccountStatus::Rejected );
		$actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		$result = Backfill::grant_approved( $actor );

		$this->assertSame( 1, $result['granted'] );
		$this->assertFalse( $result['log_failed'] );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $old ) );
		$this->assertNull( AccountStatusRepository::get( $later ), 'Konto po wdrożeniu zostaje bez stanu.' );
		$this->assertFalse( metadata_exists( 'user', $later, UserMeta::STATUS ) );
		$this->assertSame( AccountStatus::Pending, AccountStatusRepository::get( $pending ), 'Istniejący stan nie jest nadpisywany.' );
		$this->assertSame( AccountStatus::Rejected, AccountStatusRepository::get( $rejected ) );
	}

	public function test_pusta_wartosc_stanu_liczy_sie_jak_brak_stanu_a_niepusta_nierozpoznana_nie(): void {
		$this->deployedAt( $this->deployed );
		$empty = $this->account( true );
		update_user_meta( $empty, UserMeta::STATUS, '' );
		$garbage = $this->account( true );
		update_user_meta( $garbage, UserMeta::STATUS, 'cos_nieznanego' );
		$laterEmpty = $this->account( false );
		update_user_meta( $laterEmpty, UserMeta::STATUS, '' );

		$this->assertSame( 1, Backfill::stateless_count() );
		$result = Backfill::grant_approved( 1 );

		$this->assertSame( 1, $result['granted'] );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $empty ) );
		$this->assertSame( 'cos_nieznanego', get_user_meta( $garbage, UserMeta::STATUS, true ), 'Cudzej wartości nie nadpisujemy.' );
		$this->assertSame( '', get_user_meta( $laterEmpty, UserMeta::STATUS, true ), 'Po wdrożeniu nic nie ruszamy.' );
	}

	public function test_nieudany_zapis_stanu_jest_zgloszony_i_nie_zapetla_obchodu(): void {
		$this->deployedAt( $this->deployed );
		$bad  = $this->account( true );
		$good = $this->account( true );
		$block = static fn ( $check, int $id, string $key ) => ( UserMeta::STATUS === $key && $id === $bad ) ? false : $check;
		add_filter( 'update_user_metadata', $block, 10, 3 );

		try {
			$result = Backfill::grant_approved( 1 );
		} finally {
			remove_filter( 'update_user_metadata', $block, 10 );
		}

		$this->assertSame( 1, $result['granted'] );
		$this->assertSame( 1, $result['not_granted'] );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $good ) );
		$this->assertNull( AccountStatusRepository::get( $bad ) );
		$this->assertSame( 1, Backfill::stateless_count(), 'Nieudane konto zostaje w zakresie.' );
	}

	public function test_nadanie_zostawia_jeden_wiersz_w_logu_z_aktorem_i_liczba(): void {
		$this->deployedAt( $this->deployed );
		$this->account( true );
		$this->account( true );
		$this->account( true );
		$actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		Backfill::grant_approved( $actor );

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows, 'Jeden wiersz na przebieg, nie na konto.' );
		$this->assertSame( Backfill::LOG_GRANTED, $rows[0]->action );
		$this->assertSame( $actor, (int) $rows[0]->actor_user_id );
		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( 3, $context['granted'] );
	}

	public function test_powtorne_nadanie_nic_nie_robi(): void {
		$this->deployedAt( $this->deployed );
		$this->account( true );
		$actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( 1, Backfill::grant_approved( $actor )['granted'] );

		$second = Backfill::grant_approved( $actor );

		$this->assertSame( 0, $second['granted'] );
		$this->assertFalse( $second['log_failed'] );
		$this->assertCount( 1, $this->log_rows(), 'Pusty przebieg nie zostawia wiersza w logu.' );
		$this->assertSame( 0, Backfill::stateless_count() );
	}

	public function test_nadanie_bez_znacznika_wdrozenia_nie_rusza_niczego(): void {
		$old = $this->account( true );
		$new = $this->account( false );
		$this->assertSame( 0, Backfill::deployed_at() );

		$result = Backfill::grant_approved( 1 );

		$this->assertSame( 0, $result['granted'] );
		$this->assertSame( 0, Backfill::stateless_count(), 'Brak granicy to brak zakresu.' );
		$this->assertNull( AccountStatusRepository::get( $old ) );
		$this->assertNull( AccountStatusRepository::get( $new ) );
		$this->assertSame( array(), $this->log_rows() );
	}

	public function test_obchodzi_konta_ponad_jedna_partie(): void {
		$this->deployedAt( $this->deployed );
		$ids = array();
		for ( $i = 0; $i < Backfill::BATCH + 3; $i++ ) {
			$ids[] = $this->account( true );
		}
		$this->assertSame( Backfill::BATCH + 3, Backfill::stateless_count() );

		$result = Backfill::grant_approved( 1 );

		$this->assertSame( Backfill::BATCH + 3, $result['granted'] );
		$this->assertSame( 0, Backfill::stateless_count() );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $ids[ Backfill::BATCH + 2 ] ), 'Ostatnie konto leży na drugiej partii.' );
	}

	public function test_porazka_zapisu_logu_jest_zgloszona_a_stany_zostaja(): void {
		global $wpdb;
		$this->deployedAt( $this->deployed );
		$id       = $this->account( true );
		$table    = Migrations::table( 'event_log' );
		$break    = static fn ( string $query ): string => str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, "`{$table}`" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;
		$previous = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', $break );

		try {
			$result = Backfill::grant_approved( 1 );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $previous );
		}

		$this->assertSame( 1, $result['granted'] );
		$this->assertTrue( $result['log_failed'] );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ) );
	}
}
