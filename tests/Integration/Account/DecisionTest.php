<?php
/**
 * Testy serwisu rozstrzygnięcia wniosku.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Account;

use PortalUsers\Account\Decision;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\Mailer;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Tests\Integration\Support\QueuedMailsTrait;
use WP_UnitTestCase;

final class DecisionTest extends WP_UnitTestCase {

	use QueuedMailsTrait;

	private int $admin = 0;

	public function set_up(): void {
		parent::set_up();
		$this->admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->forget_mails();
	}

	private function account( ?AccountStatus $status ): int {
		$id = (int) self::factory()->user->create( array( 'user_email' => 'wnioskodawca@example.test' ) );
		if ( null !== $status ) {
			AccountStatusRepository::set( $id, $status );
		}
		$this->forget_mails();
		return $id;
	}

	/**
	 * @return list<object>
	 */
	private function logRows( string $action ): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return array_values( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id ASC", $action ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * Ile wierszy logu o akcji rozstrzygnięcia (każdej) dotyczy konta.
	 */
	private function decisionLogCount( int $userId ): int {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE subject_user_id = %d AND action LIKE %s", $userId, 'application\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * @return array<string, mixed>
	 */
	private function context( object $row ): array {
		$decoded = json_decode( (string) $row->context, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public function test_zatwierdzenie_zmienia_stan_pisze_log_i_wysyla_mail(): void {
		$id = $this->account( AccountStatus::Pending );

		$result = Decision::approve( $id, $this->admin );

		$this->assertSame( Decision::RESULT_OK, $result );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ) );
		$rows = $this->logRows( Decision::LOG_APPROVED );
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->admin, (int) $rows[0]->actor_user_id );
		$this->assertSame( $id, (int) $rows[0]->subject_user_id );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( MailKind::Approved->value, $mails[0]['kind'] );
	}

	public function test_odrzucenie_zapisuje_powod_w_metadanej_i_w_logu(): void {
		$id = $this->account( AccountStatus::Pending );

		$result = Decision::reject( $id, $this->admin, 'numer nieprawidłowy' );

		$this->assertSame( Decision::RESULT_OK, $result );
		$this->assertSame( AccountStatus::Rejected, AccountStatusRepository::get( $id ) );
		$this->assertSame( 'numer nieprawidłowy', get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
		$rows = $this->logRows( Decision::LOG_REJECTED );
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->admin, (int) $rows[0]->actor_user_id );
		$this->assertSame( 'numer nieprawidłowy', $this->context( $rows[0] )['reason'] ?? null );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( MailKind::Rejected->value, $mails[0]['kind'] );
		$this->assertStringContainsString( 'numer nieprawidłowy', $mails[0]['message'] );
	}

	public function test_drugie_zatwierdzenie_nie_robi_nic(): void {
		$id = $this->account( AccountStatus::Pending );

		$first  = Decision::approve( $id, $this->admin );
		$second = Decision::approve( $id, $this->admin );

		$this->assertSame( Decision::RESULT_OK, $first );
		$this->assertSame( Decision::RESULT_UNCHANGED, $second );
		$this->assertCount( 1, $this->logRows( Decision::LOG_APPROVED ), 'Log nie dedupuje sam, więc powtórka nie może dopisać wiersza.' );
		$this->assertCount( 1, $this->mails(), 'Powtórka nie może dokładać maila.' );
	}

	public function test_zatwierdzenie_konta_bez_potwierdzonego_adresu_jest_odmawiane(): void {
		$id = $this->account( AccountStatus::EmailUnconfirmed );

		$this->assertSame( Decision::RESULT_WRONG_STATE, Decision::approve( $id, $this->admin ) );
		$this->assertSame( Decision::RESULT_WRONG_STATE, Decision::reject( $id, $this->admin, 'powód' ) );

		$this->assertSame( AccountStatus::EmailUnconfirmed, AccountStatusRepository::get( $id ) );
		$this->assertSame( 0, $this->decisionLogCount( $id ) );
		$this->assertSame( array(), $this->mails() );
		$this->assertSame( '', get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
	}

	public function test_konto_bez_stanu_wtyczki_nie_jest_rozstrzygane(): void {
		$id = $this->account( null );

		$this->assertSame( Decision::RESULT_NO_STATUS, Decision::approve( $id, $this->admin ) );
		$this->assertSame( Decision::RESULT_NO_STATUS, Decision::reject( $id, $this->admin, 'powód' ) );

		$this->assertNull( AccountStatusRepository::get( $id ), 'Konto bez stanu ma zostać bez stanu: brama wpuszcza je właśnie dlatego.' );
		$this->assertSame( 0, $this->decisionLogCount( $id ) );
		$this->assertSame( array(), $this->mails() );
	}

	public function test_nieistniejacy_uzytkownik_daje_not_found_bez_wyjatku(): void {
		$this->assertSame( Decision::RESULT_NOT_FOUND, Decision::approve( 999999, $this->admin ) );
		$this->assertSame( Decision::RESULT_NOT_FOUND, Decision::reject( 999999, $this->admin, 'powód' ) );
		$this->assertSame( array(), $this->mails() );
	}

	public function test_odrzucenie_bez_powodu_jest_odmawiane(): void {
		$id = $this->account( AccountStatus::Pending );

		foreach ( array( '', "   \n\t" ) as $reason ) {
			$this->assertSame( Decision::RESULT_EMPTY_REASON, Decision::reject( $id, $this->admin, $reason ) );
		}

		$this->assertSame( AccountStatus::Pending, AccountStatusRepository::get( $id ) );
		$this->assertSame( 0, $this->decisionLogCount( $id ) );
		$this->assertSame( array(), $this->mails() );
	}

	public function test_powod_jest_obcinany_i_pozbawiany_znacznikow(): void {
		$id = $this->account( AccountStatus::Pending );

		Decision::reject( $id, $this->admin, '<script>alert(1)</script>Brak dokumentów ' . str_repeat( 'ą', 600 ) );

		$meta = (string) get_user_meta( $id, UserMeta::REJECTION_REASON, true );
		$this->assertStringNotContainsString( '<script', $meta );
		$this->assertSame( Decision::REASON_MAX, mb_strlen( $meta ) );
		$logged = $this->context( $this->logRows( Decision::LOG_REJECTED )[0] )['reason'] ?? '';
		$this->assertIsString( $logged );
		$this->assertStringNotContainsString( '<script', $logged );
		$this->assertSame( Decision::REASON_MAX, mb_strlen( $logged ) );
		$this->assertSame( $meta, $logged );
	}

	public function test_znak_mniejszosci_w_powodzie_jest_zamieniany_na_encje(): void {
		// Przypięte rozmyślnie: rdzeń woła wp_pre_kses_less_than() przed usunięciem znaczników. Mail i panel
		// eskejpują wyjście, więc to zachowanie jest przyjęte; zmiana sanityzacji ma tu złamać test.
		$id = $this->account( AccountStatus::Pending );

		Decision::reject( $id, $this->admin, 'Wiek < 30 lat' );

		$this->assertSame( 'Wiek &lt; 30 lat', get_user_meta( $id, UserMeta::REJECTION_REASON, true ) );
	}

	public function test_nieudany_zapis_stanu_nie_wysyla_maila(): void {
		$id = $this->account( AccountStatus::Pending );
		// Filtr przejmuje zapis metadanej stanu: update_metadata() zwraca jego wartość i niczego nie zapisuje.
		$block = static function ( $check, $object_id, $meta_key ) use ( $id ) {
			return ( $id === (int) $object_id && UserMeta::STATUS === $meta_key ) ? true : $check;
		};
		add_filter( 'update_user_metadata', $block, 10, 3 );

		$result = Decision::approve( $id, $this->admin );

		remove_filter( 'update_user_metadata', $block, 10 );
		$this->assertSame( Decision::RESULT_STORAGE_FAILED, $result );
		$this->assertSame( AccountStatus::Pending, AccountStatusRepository::get( $id ) );
		$this->assertSame( array(), $this->mails(), 'Mail o zatwierdzeniu dla konta, które nie wpuszcza, jest szkodą.' );
		$this->assertCount( 0, $this->logRows( Decision::LOG_APPROVED ) );
		$failed = $this->logRows( Decision::LOG_STORAGE_FAILED );
		$this->assertCount( 1, $failed );
		$this->assertSame( $this->admin, (int) $failed[0]->actor_user_id );
	}

	public function test_odmowa_kolejki_mailowej_nie_cofa_stanu(): void {
		$id = $this->account( AccountStatus::Pending );
		// Kolejka odmawia przyjęcia: ten sam klucz odporności w tej samej minucie co mail już zakolejkowany.
		$freeze = static fn() => '200001010000';
		add_filter( 'pusers_internal_test_seam_minute_bucket', $freeze );
		$this->assertTrue( Mailer::approved( $id ), 'Fixtura: pierwszy mail musi wejść do kolejki.' );
		$this->forget_mails();

		$result = Decision::approve( $id, $this->admin );

		remove_filter( 'pusers_internal_test_seam_minute_bucket', $freeze );
		$this->assertSame( Decision::RESULT_MAIL_FAILED, $result );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ), 'Stan konta jest prawdą, mail tylko powiadomieniem.' );
		$this->assertCount( 1, $this->logRows( Decision::LOG_APPROVED ) );
	}

	public function test_odrzucenie_konta_zatwierdzonego_jest_dozwolone(): void {
		$id = $this->account( AccountStatus::Approved );

		$this->assertSame( Decision::RESULT_OK, Decision::reject( $id, $this->admin, 'cofnięcie zatwierdzenia' ) );

		$this->assertSame( AccountStatus::Rejected, AccountStatusRepository::get( $id ) );
	}

	public function test_profesja_usunieta_z_ustawien_nie_blokuje_rozstrzygniecia(): void {
		$id = $this->account( AccountStatus::Pending );
		update_user_meta( $id, UserMeta::PROFESSION, 'zawod-ktorego-juz-nie-ma' );
		ProfessionRepository::save( ProfessionCollection::fromArray( array() ) );

		$this->assertSame( Decision::RESULT_OK, Decision::approve( $id, $this->admin ) );
		$this->assertSame( AccountStatus::Approved, AccountStatusRepository::get( $id ) );
	}
}
