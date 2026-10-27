<?php
/**
 * Testy crona czyszczącego niepotwierdzone konta, na prawdziwej bazie.
 *
 * Każdy test tworzy własne konta i sprawdza wyłącznie ich losy; niczego nie zakłada o kontach z bootstrapu.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Cron;

use PortalUsers\Cron\PurgeUnconfirmed;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Plugin;
use WP_UnitTestCase;

final class PurgeUnconfirmedTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		SettingsRepository::save( Settings::fromArray( array( 'unconfirmed_purge_days' => 7 ) ) );
		wp_clear_scheduled_hook( PurgeUnconfirmed::HOOK );
	}

	/**
	 * Tworzy konto zarejestrowane dawniej o podaną liczbę dni.
	 *
	 * @param int                 $daysAgo Wiek konta.
	 * @param AccountStatus|null  $status  Stan albo null (brak metadanej).
	 * @param array<string,mixed> $extra   Dodatkowe argumenty konta.
	 */
	private function account( int $daysAgo, ?AccountStatus $status, array $extra = array() ): int {
		$id = self::factory()->user->create(
			array_merge(
				array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $daysAgo * DAY_IN_SECONDS ) ),
				$extra
			)
		);
		if ( null !== $status ) {
			AccountStatusRepository::set( $id, $status );
		}
		return $id;
	}

	/**
	 * @return list<object> Wiersze logu o danej akcji.
	 */
	private function logRows( string $action ): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id ASC", $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * @return int Ile zdarzeń crona czeka na hooka.
	 */
	private function scheduledCount(): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ PurgeUnconfirmed::HOOK ] ) ) {
				$count += count( $hooks[ PurgeUnconfirmed::HOOK ] );
			}
		}
		return $count;
	}

	public function test_run_deletes_only_qualifying_accounts_and_frees_the_address(): void {
		$stale = $this->account( 10, AccountStatus::EmailUnconfirmed, array( 'user_email' => 'porzucony@example.com' ) );
		$this->assertSame( $stale, email_exists( 'porzucony@example.com' ) );

		$fresh      = $this->account( 2, AccountStatus::EmailUnconfirmed );
		$rejected   = $this->account( 400, AccountStatus::Rejected );
		$approved   = $this->account( 400, AccountStatus::Approved );
		$pending    = $this->account( 400, AccountStatus::Pending );
		$stateless  = $this->account( 400, null, array( 'role' => 'administrator' ) );
		$unreadable = $this->account( 400, null );
		update_user_meta( $unreadable, UserMeta::STATUS, 'uszkodzone' );

		PurgeUnconfirmed::run();

		$this->assertFalse( get_userdata( $stale ), 'Stare niepotwierdzone konto powinno zniknąć.' );
		$this->assertFalse( email_exists( 'porzucony@example.com' ) );
		$this->assertIsInt(
			wp_insert_user(
				array(
					'user_login' => 'nowy_lekarz',
					'user_pass'  => wp_generate_password(),
					'user_email' => 'porzucony@example.com',
				)
			),
			'Adres musi dać się zarejestrować ponownie.'
		);

		$survivors = array(
			'w oknie'                   => $fresh,
			'rejected'                  => $rejected,
			'approved'                  => $approved,
			'pending'                   => $pending,
			'bez stanu (administrator)' => $stateless,
			'stan nierozpoznany'        => $unreadable,
		);
		foreach ( $survivors as $label => $id ) {
			$this->assertNotFalse( get_userdata( $id ), 'Konto nie może zostać usunięte: ' . $label );
		}
	}

	public function test_account_without_a_status_survives_however_old_it_is(): void {
		$admin = $this->account( 5000, null, array( 'role' => 'administrator' ) );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $admin ) );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_account_with_an_unrecognised_status_survives_however_old_it_is(): void {
		$id = $this->account( 5000, null );
		update_user_meta( $id, UserMeta::STATUS, 'uszkodzone' );
		$this->assertTrue( AccountStatusRepository::isUnrecognised( $id ) );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ) );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_rejected_account_survives_however_old_it_is(): void {
		$id = $this->account( 5000, AccountStatus::Rejected );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ) );
	}

	public function test_unconfirmed_account_inside_the_window_survives(): void {
		$id = $this->account( 2, AccountStatus::EmailUnconfirmed );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ) );
	}

	public function test_run_deletes_nothing_when_no_account_qualifies(): void {
		$ids    = array(
			$this->account( 2, AccountStatus::EmailUnconfirmed ),
			$this->account( 400, AccountStatus::Rejected ),
			$this->account( 400, null, array( 'role' => 'administrator' ) ),
		);
		$before = count_users()['total_users'];

		PurgeUnconfirmed::run();

		$this->assertSame( $before, count_users()['total_users'] );
		foreach ( $ids as $id ) {
			$this->assertNotFalse( get_userdata( $id ) );
		}
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_run_writes_each_deletion_to_the_event_log(): void {
		$one = $this->account( 10, AccountStatus::EmailUnconfirmed );
		$two = $this->account( 30, AccountStatus::EmailUnconfirmed );
		$this->account( 400, AccountStatus::Rejected );

		PurgeUnconfirmed::run();

		$rows = $this->logRows( PurgeUnconfirmed::LOG_ACTION );
		$this->assertCount( 2, $rows );
		$subjects = array_map( static fn ( $row ): int => (int) $row->subject_user_id, $rows );
		sort( $subjects );
		$expected = array( $one, $two );
		sort( $expected );
		$this->assertSame( $expected, $subjects );
		$this->assertSame( 0, (int) $rows[0]->actor_user_id );
	}

	public function test_run_honours_the_configured_window(): void {
		$id = $this->account( 10, AccountStatus::EmailUnconfirmed );
		SettingsRepository::save( Settings::fromArray( array( 'unconfirmed_purge_days' => 30 ) ) );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ), 'Okno 30 dni nie obejmuje konta sprzed 10 dni.' );
	}

	public function test_activation_schedules_the_hook_and_deactivation_removes_it(): void {
		$this->assertFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );

		PurgeUnconfirmed::schedule();
		$this->assertNotFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( PurgeUnconfirmed::HOOK ) );

		PurgeUnconfirmed::unschedule();
		$this->assertFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );
	}

	public function test_schedule_does_not_duplicate_an_existing_event(): void {
		// Zdarzenie cykliczne o innym znaczniku czasu ma inny klucz i dodałoby się obok istniejącego;
		// istniejące stawiamy dobę dalej, więc tylko strażnik w schedule() może zapobiec dubletowi.
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', PurgeUnconfirmed::HOOK );

		PurgeUnconfirmed::schedule();

		$this->assertSame( 1, $this->scheduledCount() );
	}

	public function test_plugin_file_wires_activation_and_deactivation_to_the_schedule(): void {
		$base = plugin_basename( Plugin::plugin_file() );
		$this->assertNotFalse( has_action( 'activate_' . $base, array( PurgeUnconfirmed::class, 'schedule' ) ) );
		$this->assertNotFalse( has_action( 'deactivate_' . $base, array( PurgeUnconfirmed::class, 'unschedule' ) ) );
	}

	public function test_register_hooks_the_run_but_does_not_schedule_on_every_request(): void {
		// Administrator, który zdjął zdarzenie (WP Crontrol), nie może go odzyskiwać przy wejściu na stronę.
		remove_all_actions( PurgeUnconfirmed::HOOK );

		PurgeUnconfirmed::register();

		$this->assertNotFalse( has_action( PurgeUnconfirmed::HOOK, array( PurgeUnconfirmed::class, 'run' ) ) );
		$this->assertFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );
	}

	public function test_the_first_run_is_due_a_day_from_now_not_immediately(): void {
		PurgeUnconfirmed::schedule();

		$due = wp_next_scheduled( PurgeUnconfirmed::HOOK );
		$this->assertGreaterThanOrEqual( time() + DAY_IN_SECONDS - 60, $due );
	}

	public function test_an_update_schedules_the_hook_once_and_does_not_restore_it_later(): void {
		// Instalacja sprzed crona: zapisana wersja jest niższa, aktualizacja nie odpala aktywacji.
		update_option( Migrations::OPTION_DB_VERSION, 1 );
		$this->assertFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ) );

		Migrations::maybe_upgrade();
		$this->assertNotFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ), 'Aktualizacja zakłada harmonogram.' );

		wp_clear_scheduled_hook( PurgeUnconfirmed::HOOK );
		Migrations::maybe_upgrade();
		$this->assertFalse( wp_next_scheduled( PurgeUnconfirmed::HOOK ), 'Zdjęte zdarzenie nie wraca przy kolejnych żądaniach.' );
	}

	public function test_account_with_an_unparseable_registration_date_survives(): void {
		global $wpdb;
		$id = $this->account( 0, AccountStatus::EmailUnconfirmed );
		$wpdb->update( $wpdb->users, array( 'user_registered' => '0000-00-00 00:00:00' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixtura: zerowa data, jak w domyślnej wartości kolumny.
		clean_user_cache( $id );
		$this->assertSame( '0000-00-00 00:00:00', get_userdata( $id )->user_registered );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ), 'Konto z nieznaną datą nie może zniknąć, choć świeże.' );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_run_does_nothing_on_multisite_instead_of_logging_deletions_that_did_not_happen(): void {
		// Na sieci wp_delete_user() zwraca true, ale konto zostaje; bez strażnika log twierdziłby co dzień, że usunął.
		$id = $this->account( 10, AccountStatus::EmailUnconfirmed );
		add_filter( 'pusers_internal_test_seam_is_multisite', '__return_true' );

		PurgeUnconfirmed::run();

		$this->assertNotFalse( get_userdata( $id ) );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_the_multisite_exit_leaves_one_row_in_the_event_log_at_most_once_an_hour(): void {
		// Dowolna wtyczka zwracająca z tego filtra true wyłącza jedyny mechanizm zwalniający zajęte
		// adresy. Bez wpisu nikt by się o tym nie dowiedział, a skutkiem byłby dokładnie ten cel
		// wrogi, który cron ma zamykać: adres zajęty przez porzucone zgłoszenie.
		delete_option( OptionStore::OPTION_PURGE_SKIP_AT );
		add_filter( 'pusers_internal_test_seam_is_multisite', '__return_true' );

		PurgeUnconfirmed::run();
		PurgeUnconfirmed::run();

		$rows = $this->logRows( PurgeUnconfirmed::SKIPPED_LOG_ACTION );
		$this->assertCount( 1, $rows, 'Wiersz na każdy przebieg zapchałby tabelę audytu.' );
		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( 'multisite', $context['reason'] ?? null );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ), 'Przebieg nie usunął niczego, więc nie może tego twierdzić.' );

		// Po upływie okna kolejne wyjście znów trafia do logu: usterka nie przestaje być widoczna.
		update_option( OptionStore::OPTION_PURGE_SKIP_AT, time() - HOUR_IN_SECONDS - 1, false );
		PurgeUnconfirmed::run();
		$this->assertCount( 2, $this->logRows( PurgeUnconfirmed::SKIPPED_LOG_ACTION ) );

		delete_option( OptionStore::OPTION_PURGE_SKIP_AT );
	}

	public function test_a_run_on_a_single_site_writes_no_skip_row(): void {
		delete_option( OptionStore::OPTION_PURGE_SKIP_AT );
		$this->account( 10, AccountStatus::EmailUnconfirmed );

		PurgeUnconfirmed::run();

		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::SKIPPED_LOG_ACTION ) );
		$this->assertFalse( get_option( OptionStore::OPTION_PURGE_SKIP_AT ) );
	}

	public function test_the_network_decision_follows_wordpress_by_default(): void {
		$this->assertSame( is_multisite(), PurgeUnconfirmed::is_network() );
		$this->assertFalse( PurgeUnconfirmed::is_network(), 'Zestaw działa na pojedynczej witrynie.' );
	}

	public function test_run_pages_through_all_accounts_even_when_deletions_shift_the_pages(): void {
		add_filter( 'pusers_purge_batch_size', static fn (): int => 2 );
		$stale = array();
		$kept  = array();
		// Kolejność ID: do usunięcia, zostaje, do usunięcia, do usunięcia, zostaje, do usunięcia, do usunięcia, do usunięcia.
		foreach ( array( true, false, true, true, false, true, true, true ) as $isStale ) {
			if ( $isStale ) {
				$stale[] = $this->account( 10, AccountStatus::EmailUnconfirmed );
			} else {
				$kept[] = $this->account( 400, AccountStatus::Rejected );
			}
		}

		PurgeUnconfirmed::run();

		foreach ( $stale as $id ) {
			$this->assertFalse( get_userdata( $id ), 'Każde kwalifikujące się konto musi zostać usunięte mimo paczek.' );
		}
		foreach ( $kept as $id ) {
			$this->assertNotFalse( get_userdata( $id ) );
		}
		$this->assertCount( count( $stale ), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}

	public function test_run_reads_the_status_of_a_batch_with_one_metadata_query_not_one_per_account(): void {
		for ( $i = 0; $i < 8; $i++ ) {
			$this->account( 400, AccountStatus::Rejected );
		}
		global $wpdb;
		$meta_selects = 0;
		$counter      = static function ( string $query ) use ( &$meta_selects, $wpdb ): string {
			if ( 0 === stripos( ltrim( $query ), 'SELECT' ) && str_contains( $query, $wpdb->usermeta ) ) {
				++$meta_selects;
			}
			return $query;
		};
		add_filter( 'query', $counter );

		PurgeUnconfirmed::run();

		remove_filter( 'query', $counter );
		$this->assertLessThanOrEqual( 2, $meta_selects, 'Osiem kont w jednej paczce nie może dać ośmiu zapytań o metadane.' );
	}

	public function test_the_log_entry_carries_the_registration_date_and_a_hash_of_the_address_not_the_address(): void {
		$id         = $this->account( 10, AccountStatus::EmailUnconfirmed, array( 'user_email' => 'Doktor.X@Example.com' ) );
		$registered = get_userdata( $id )->user_registered;

		PurgeUnconfirmed::run();

		$rows = $this->logRows( PurgeUnconfirmed::LOG_ACTION );
		$this->assertCount( 1, $rows );
		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertSame( $registered, $context['registered_at'] );
		$this->assertSame( wp_hash( 'doktor.x@example.com' ), $context['email_hash'], 'Skrót z adresu w małych literach: administrator sprawdzi konkretny adres.' );
		$this->assertNotSame( hash( 'sha256', 'doktor.x@example.com' ), $context['email_hash'], 'Skrót musi być solony: nieposolony sha256 odwraca się słownikiem adresów.' );
		$this->assertNotSame( wp_hash( 'inny@example.com' ), $context['email_hash'] );
		$this->assertStringNotContainsString( 'example.com', (string) $rows[0]->context, 'Adresu nie przechowujemy.' );
	}

	public function test_account_whose_status_changes_after_the_batch_was_read_is_not_deleted(): void {
		global $wpdb;
		$id      = $this->account( 10, AccountStatus::EmailUnconfirmed );
		$flipped = false;
		// Pierwszy odczyt stanu tego konta (z zasilonego cache) widzi jeszcze email_unconfirmed;
		// tuż po nim baza dostaje approved, jak przy kliknięciu linku w trakcie przebiegu.
		$flip = function ( $check, $object_id, $meta_key ) use ( $id, &$flipped, $wpdb ) {
			if ( ! $flipped && $id === (int) $object_id && UserMeta::STATUS === $meta_key ) {
				$flipped = true;
				$wpdb->update( $wpdb->usermeta, array( 'meta_value' => AccountStatus::Approved->value ), array( 'user_id' => $id, 'meta_key' => UserMeta::STATUS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixtura: zmiana stanu poza cache.
			}
			return $check;
		};
		add_filter( 'get_user_metadata', $flip, 10, 3 );

		PurgeUnconfirmed::run();

		remove_filter( 'get_user_metadata', $flip, 10 );
		$this->assertTrue( $flipped, 'Fixtura nie zadziałała: stan nie został zmieniony w trakcie przebiegu.' );
		$this->assertNotFalse( get_userdata( $id ), 'Konto potwierdzone w trakcie przebiegu nie może zostać usunięte.' );
		$this->assertSame( array(), $this->logRows( PurgeUnconfirmed::LOG_ACTION ) );
	}
}
