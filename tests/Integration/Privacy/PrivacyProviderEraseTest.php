<?php
/**
 * Testy erasera danych osobowych: co znika, co zostaje i czy pętla rdzenia się kończy.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Privacy;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Privacy\PrivacyProvider;
use WP_UnitTestCase;

final class PrivacyProviderEraseTest extends WP_UnitTestCase {

	/**
	 * Konto z kompletem metadanych wtyczki, także tych, które eraser ma usunąć.
	 */
	private function account( string $email = 'lekarz@example.com', string $status = 'rejected' ): int {
		$id = (int) self::factory()->user->create( array( 'user_email' => $email ) );
		update_user_meta( $id, UserMeta::STATUS, $status );
		update_user_meta( $id, UserMeta::PROFESSION, 'lekarz' );
		update_user_meta( $id, UserMeta::CONSENT_AT, '2026-10-01 10:00:00' );
		update_user_meta( $id, UserMeta::TERMS_VERSION, 'v3' );
		update_user_meta( $id, UserMeta::REGISTRATION_IP, '203.0.113.7' );
		update_user_meta( $id, UserMeta::REJECTION_REASON, 'brak uprawnień' );
		update_user_meta( $id, UserMeta::CONFIRM_HASH, 'hash-potwierdzenia' );
		update_user_meta( $id, UserMeta::CONFIRM_EXPIRES, 1893456000 );
		update_user_meta( $id, UserMeta::PASSWORD_TICKET_HASH, 'hash-biletu' );
		update_user_meta( $id, UserMeta::PASSWORD_TICKET_EXPIRES, 1893456000 );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '1234567' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'ukryte_dla_profesji', 'wartosc' );
		return $id;
	}

	private function mail( int $userId, string $key ): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		MailQueueRepository::insert(
			array(
				'created_at'   => $now,
				'scheduled_at' => $now,
				'kind'         => 'confirmation',
				'dedupe_key'   => $key,
				'user_id'      => $userId,
				'recipient'    => 'lekarz@example.com',
				'subject'      => 'Potwierdź adres',
				'body'         => 'Token: TOKEN-ZYWY',
			)
		);
	}

	private function unanonymized_rows( int $userId ): int {
		global $wpdb;

		$log   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE subject_user_id = %d AND anonymized = 0', Migrations::table( 'event_log' ), $userId ) );
		$queue = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND anonymized = 0', Migrations::table( 'mail_queue' ), $userId ) );

		return $log + $queue;
	}

	public function test_eraser_jest_zarejestrowany_pod_wlasnym_kluczem(): void {
		PrivacyProvider::register();
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( PrivacyProvider::SLUG, $erasers );
		$this->assertNotSame( '', $erasers[ PrivacyProvider::SLUG ]['eraser_friendly_name'] );
		$this->assertIsCallable( $erasers[ PrivacyProvider::SLUG ]['callback'] );
	}

	public function test_eraser_NIE_usuwa_stanu_konta(): void {
		$id = $this->account( 'lekarz@example.com', 'rejected' );

		PrivacyProvider::erase( 'lekarz@example.com' );

		$this->assertSame( 'rejected', get_user_meta( $id, UserMeta::STATUS, true ) );
		// Null znaczy „konto bez stanu", czyli wpuszczane przez bramę logowania.
		$this->assertNotNull( AccountStatusRepository::get( $id ) );
		$this->assertSame( AccountStatus::Rejected, AccountStatusRepository::get( $id ) );
	}

	public function test_eraser_usuwa_ip_powod_odrzucenia_i_sekrety_jednorazowe(): void {
		$id = $this->account();

		$result = PrivacyProvider::erase( 'lekarz@example.com' );

		foreach ( PrivacyProvider::ERASE_META as $key ) {
			$this->assertFalse( metadata_exists( 'user', $id, $key ), $key );
		}
		$this->assertTrue( $result['items_removed'] );
	}

	public function test_eraser_usuwa_wszystkie_wartosci_pol_zgloszenia(): void {
		$id = $this->account();

		PrivacyProvider::erase( 'lekarz@example.com' );

		// Także pole, którego macierz dziś nie pokazuje: dane są danymi niezależnie od panelu.
		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'pwz' ) );
		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'ukryte_dla_profesji' ) );
	}

	public function test_eraser_zostawia_zgode_i_profesje_i_mowi_o_tym(): void {
		$id = $this->account();

		$result = PrivacyProvider::erase( 'lekarz@example.com' );

		$this->assertTrue( $result['items_retained'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertSame( 'lekarz', get_user_meta( $id, UserMeta::PROFESSION, true ) );
		$this->assertSame( 'v3', get_user_meta( $id, UserMeta::TERMS_VERSION, true ) );
		$this->assertSame( '2026-10-01 10:00:00', get_user_meta( $id, UserMeta::CONSENT_AT, true ) );
	}

	public function test_eraser_anonimizuje_log_zdarzen_i_kolejke(): void {
		global $wpdb;
		$id = $this->account();
		EventLog::write( 'application_rejected', $id, 1, array( 'reason' => 'brak uprawnień' ) );
		$this->mail( $id, 'k1' );

		PrivacyProvider::erase( 'lekarz@example.com' );

		$context = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT context FROM %i WHERE subject_user_id = %d AND action = %s', Migrations::table( 'event_log' ), $id, 'application_rejected' ) );
		// Kontekst niesie listę usuniętych kluczy, więc pytamy o klucz, a nie o napis.
		$this->assertArrayNotHasKey( 'reason', (array) json_decode( $context, true ) );
		$this->assertStringNotContainsString( 'brak uprawnie', $context );
		$mail = $wpdb->get_row( $wpdb->prepare( 'SELECT recipient, subject, body, anonymized FROM %i WHERE user_id = %d', Migrations::table( 'mail_queue' ), $id ), ARRAY_A );
		$this->assertSame( '1', (string) $mail['anonymized'] );
		$this->assertStringNotContainsString( 'TOKEN-ZYWY', (string) $mail['body'] );
		$this->assertStringNotContainsString( 'lekarz@example.com', (string) $mail['recipient'] );
		$this->assertSame( 0, $this->unanonymized_rows( $id ) );
	}

	public function test_eraser_konczy_petle_a_nie_kreci_sie_bez_konca(): void {
		$id = $this->account();
		for ( $i = 0; $i < PrivacyProvider::PER_PAGE + 5; $i++ ) {
			EventLog::write( 'application_rejected', $id, 1, array( 'reason' => 'powod ' . $i ) );
		}

		// Pętla jak w rdzeniu, z twardym ogranicznikiem, żeby błąd nie zawiesił suity.
		$passes = 0;
		$page   = 1;
		do {
			$result = PrivacyProvider::erase( 'lekarz@example.com', $page );
			++$passes;
			++$page;
		} while ( ! $result['done'] && $passes < 10 );

		$this->assertTrue( $result['done'], 'Pętla ma się skończyć na done === true.' );
		// Anonimizacja nie stronicuje: pierwszy przebieg bierze wszystkie wiersze konta, a pętla kończy się,
		// gdy żaden nie zostaje z anonymized = 0. Jeden przebieg wystarcza; zapas na dwa chroni przed kruchością testu.
		$this->assertLessThanOrEqual( 2, $passes );
		$this->assertSame( 0, $this->unanonymized_rows( $id ), 'Żaden wiersz konta nie może zostać z danymi.' );
	}

	public function test_eraser_konczy_petle_z_komunikatem_gdy_przekroczy_limit_przebiegow(): void {
		$id = $this->account();
		EventLog::write( 'application_rejected', $id, 1, array( 'reason' => 'x' ) );

		$result = PrivacyProvider::erase( 'lekarz@example.com', PrivacyProvider::MAX_PASSES + 1 );

		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertFalse( $result['items_removed'], 'Przebieg odcięty limitem niczego nie usuwa, więc nie może tego zgłaszać.' );
	}

	public function test_trwala_porazka_zapisu_konczy_petle_przez_limit_a_nie_przez_done_z_pustego_przebiegu(): void {
		global $wpdb;
		$id = $this->account();
		EventLog::write( 'application_rejected', $id, 1, array( 'reason' => 'x' ) );
		$this->mail( $id, 'k-awaria' );

		$table            = Migrations::table( 'mail_queue' );
		$filter           = static fn( string $query ): string => str_starts_with( ltrim( $query ), 'UPDATE' ) && str_contains( $query, "`{$table}`" )
			? 'SELECT * FROM pusers_tabela_ktora_nie_istnieje'
			: $query;
		$previousSuppress = (bool) $wpdb->suppress_errors( true );
		$passes           = 0;
		$page             = 1;
		$messages         = array();
		$result           = array( 'done' => false );
		add_filter( 'query', $filter );
		try {
			// Pętla jak w rdzeniu, z twardym ogranicznikiem ponad MAX_PASSES, żeby błąd nie zawiesił suity.
			do {
				$result   = PrivacyProvider::erase( 'lekarz@example.com', $page );
				$messages = array_merge( $messages, $result['messages'] );
				++$passes;
				++$page;
			} while ( ! $result['done'] && $passes < PrivacyProvider::MAX_PASSES + 5 );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previousSuppress );
		}

		$this->assertTrue( $result['done'] );
		$this->assertSame( PrivacyProvider::MAX_PASSES + 1, $passes, 'Wiersz został z danymi, więc koniec pętli ma dać dopiero limit przebiegów.' );
		$this->assertContains(
			'Nie udało się zanonimizować części wpisów logu zdarzeń lub wysyłek tego konta. Powtórz żądanie albo zgłoś sprawę administratorowi.',
			$messages
		);
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT anonymized FROM %i WHERE user_id = %d', $table, $id ) ) );
	}

	public function test_eraser_nie_rusza_danych_innego_konta(): void {
		$this->account( 'lekarz@example.com' );
		$other = $this->account( 'inny@example.com', 'pending' );
		EventLog::write( 'application_approved', $other, 1, array( 'previous_status' => 'pending' ) );
		$this->mail( $other, 'k-inny' );

		PrivacyProvider::erase( 'lekarz@example.com' );

		$this->assertSame( '203.0.113.7', get_user_meta( $other, UserMeta::REGISTRATION_IP, true ) );
		$this->assertSame( '1234567', get_user_meta( $other, UserMeta::ACF_PREFIX . 'pwz', true ) );
		$this->assertSame( 2, $this->unanonymized_rows( $other ) );
	}

	public function test_adres_bez_konta_daje_done_i_zero_zmian(): void {
		$result = PrivacyProvider::erase( 'nikt@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_powtorzony_eraser_nie_zglasza_usuniecia_drugi_raz(): void {
		$id = $this->account();
		EventLog::write( 'application_rejected', $id, 1, array( 'reason' => 'x' ) );

		$first = PrivacyProvider::erase( 'lekarz@example.com' );
		$again = PrivacyProvider::erase( 'lekarz@example.com' );

		$this->assertTrue( $first['items_removed'] );
		$this->assertFalse( $again['items_removed'] );
		$this->assertTrue( $again['done'] );
		$this->assertTrue( $again['items_retained'], 'Zgoda i stan dalej leżą przy koncie, więc eraser dalej o tym mówi.' );
	}
}
