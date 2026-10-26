<?php
/**
 * Testy repozytorium stanu konta.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Admin\ProfessionsScreen;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class AccountStatusRepositoryTest extends WP_UnitTestCase {

	public function test_status_round_trips_through_user_meta(): void {
		$id = self::factory()->user->create();
		AccountStatusRepository::set( $id, AccountStatus::Pending );
		$this->assertSame( AccountStatus::Pending, AccountStatusRepository::get( $id ) );
		$this->assertSame( 'pending', get_user_meta( $id, UserMeta::STATUS, true ) );
	}

	public function test_account_without_a_stored_status_reads_as_null(): void {
		$id = self::factory()->user->create();
		$this->assertNull( AccountStatusRepository::get( $id ) );
	}

	public function test_unrecognised_stored_value_reads_as_null_and_does_not_throw(): void {
		$id = self::factory()->user->create();
		update_user_meta( $id, UserMeta::STATUS, 'cokolwiek' );
		$this->assertNull( AccountStatusRepository::get( $id ) );
	}

	public function test_absent_meta_is_no_state_not_corruption(): void {
		$id = self::factory()->user->create();
		$this->assertFalse( AccountStatusRepository::isUnrecognised( $id ) );
		$this->assertNull( AccountStatusRepository::get( $id ) );
	}

	public function test_unrecognised_value_is_flagged_as_corruption(): void {
		$id = self::factory()->user->create();
		update_user_meta( $id, UserMeta::STATUS, 'cokolwiek' );
		$this->assertTrue( AccountStatusRepository::isUnrecognised( $id ) );
		$this->assertNull( AccountStatusRepository::get( $id ) );
	}

	public function test_empty_value_is_no_state_not_corruption(): void {
		$id = self::factory()->user->create();
		update_user_meta( $id, UserMeta::STATUS, '' );
		$this->assertFalse( AccountStatusRepository::isUnrecognised( $id ) );
		$this->assertNull( AccountStatusRepository::get( $id ) );
	}

	public function test_non_string_value_is_flagged_as_corruption(): void {
		$id = self::factory()->user->create();
		update_user_meta( $id, UserMeta::STATUS, array( 'pending' ) );
		$this->assertTrue( AccountStatusRepository::isUnrecognised( $id ) );
		$this->assertNull( AccountStatusRepository::get( $id ) );
	}

	public function test_every_valid_state_is_recognised(): void {
		foreach ( AccountStatus::cases() as $status ) {
			$id = self::factory()->user->create();
			AccountStatusRepository::set( $id, $status );
			$this->assertFalse( AccountStatusRepository::isUnrecognised( $id ), $status->value );
			$this->assertSame( $status, AccountStatusRepository::get( $id ), $status->value );
		}
	}

	/**
	 * Porównanie z UserMeta::PROFESSION byłoby tautologią (USER_META jest do niej przypisane),
	 * więc przeżyłoby zmianę wartości. Literał pilnuje wartości, którą klienci mają w bazach.
	 */
	public function test_professions_screen_constant_keeps_the_stored_name_and_shares_the_source(): void {
		$this->assertSame( 'pusers_profession', ProfessionsScreen::USER_META );
		$this->assertSame( UserMeta::PROFESSION, ProfessionsScreen::USER_META );
	}
}
