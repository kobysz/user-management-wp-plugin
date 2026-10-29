<?php
/**
 * Testy repozytorium wniosków w prawdziwej bazie.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ApplicationRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class ApplicationRepositoryTest extends WP_UnitTestCase {

	/**
	 * Zapisuje profesje z numerem uprawnień w polu `pwz` dla lekarza.
	 */
	private function seed_professions(): void {
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'              => 'lekarz',
						'label'            => 'Lekarz',
						'credential_type'  => 'pwz',
						'credential_field' => 'pwz',
					),
					array(
						'key'             => 'pielegniarka',
						'label'           => 'Pielęgniarka',
						'credential_type' => 'none',
					),
				)
			)
		);
	}

	/**
	 * Tworzy konto w danym stanie i profesji.
	 *
	 * @param string $status     Surowa wartość stanu.
	 * @param string $profession Klucz profesji.
	 * @param string $registered Data rejestracji (UTC); pusta = teraz.
	 * @return int
	 */
	private function account( string $status, string $profession = 'lekarz', string $registered = '' ): int {
		$args = array();
		if ( '' !== $registered ) {
			$args['user_registered'] = $registered;
		}
		$id = self::factory()->user->create( $args );
		update_user_meta( $id, UserMeta::STATUS, $status );
		update_user_meta( $id, UserMeta::PROFESSION, $profession );
		return $id;
	}

	public function test_liczniki_maja_wszystkie_cztery_stany_takze_zerowe(): void {
		$this->account( 'pending' );
		$this->account( 'pending' );
		$this->account( 'pending' );
		$this->account( 'approved' );

		// assertSame na całej tablicy: brakujący klucz wywala zakładkę z licznikiem na ekranie.
		$this->assertSame(
			array(
				'email_unconfirmed' => 0,
				'pending'           => 3,
				'approved'          => 1,
				'rejected'          => 0,
			),
			ApplicationRepository::counts()
		);
	}

	public function test_konto_bez_stanu_wtyczki_nie_jest_wnioskiem(): void {
		// Administrator dodany ręcznie nie ma metadanej stanu.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->account( 'pending' );

		$this->assertSame( 1, array_sum( ApplicationRepository::counts() ) );
		$ids = array_map( static fn ( $row ) => $row->userId, ApplicationRepository::paginate( AccountStatus::Pending, '', 20, 0 ) );
		$this->assertNotContains( $admin, $ids );
		$this->assertCount( 1, $ids );
	}

	public function test_stan_nierozpoznany_nie_zaklamuje_licznika(): void {
		$this->account( 'cokolwiek' );
		$this->account( 'approved' );

		$this->assertSame(
			array(
				'email_unconfirmed' => 0,
				'pending'           => 0,
				'approved'          => 1,
				'rejected'          => 0,
			),
			ApplicationRepository::counts()
		);
	}

	public function test_filtr_profesji_zwiezla_liste(): void {
		$this->account( 'pending', 'lekarz' );
		$this->account( 'pending', 'lekarz' );
		$this->account( 'pending', 'pielegniarka' );

		$this->assertCount( 2, ApplicationRepository::paginate( AccountStatus::Pending, 'lekarz', 20, 0 ) );
		$this->assertSame( 2, ApplicationRepository::total( AccountStatus::Pending, 'lekarz' ) );
		$this->assertSame( 3, ApplicationRepository::total( AccountStatus::Pending ) );
	}

	public function test_strona_i_kolejnosc_po_dacie_rejestracji_malejaco(): void {
		$oldest = $this->account( 'pending', 'lekarz', '2026-01-01 10:00:00' );
		$middle = $this->account( 'pending', 'lekarz', '2026-02-01 10:00:00' );
		$newest = $this->account( 'pending', 'lekarz', '2026-03-01 10:00:00' );

		$first = ApplicationRepository::paginate( AccountStatus::Pending, '', 2, 0 );
		$this->assertSame( array( $newest, $middle ), array_map( static fn ( $row ) => $row->userId, $first ) );

		$second = ApplicationRepository::paginate( AccountStatus::Pending, '', 2, 2 );
		$this->assertSame( array( $oldest ), array_map( static fn ( $row ) => $row->userId, $second ) );
		$this->assertSame( 3, ApplicationRepository::total( AccountStatus::Pending ) );
	}

	public function test_konta_z_ta_sama_sekunda_rejestracji_nie_powtarzaja_sie_ani_nie_ginie_miedzy_stronami(): void {
		$ids = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = $this->account( 'pending', 'lekarz', '2026-05-01 10:00:00' );
		}

		$first  = array_map( static fn ( $row ) => $row->userId, ApplicationRepository::paginate( AccountStatus::Pending, '', 3, 0 ) );
		$second = array_map( static fn ( $row ) => $row->userId, ApplicationRepository::paginate( AccountStatus::Pending, '', 3, 3 ) );

		$all = array_merge( $first, $second );
		$this->assertCount( 6, array_unique( $all ), 'Żadne konto nie wystąpiło dwa razy.' );
		$sorted = $all;
		sort( $sorted );
		$this->assertSame( $ids, $sorted, 'Suma stron to pełny zbiór kont.' );
		// Rozstrzygacz po ID jest jawny: to on czyni kolejność deterministyczną.
		$this->assertSame( array_reverse( $ids ), $all );
	}

	public function test_total_zwraca_pelna_liczbe_wnioskow_a_nie_rozmiar_strony(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->account( 'pending' );
		}
		$this->account( 'approved' );

		$this->assertCount( 2, ApplicationRepository::paginate( AccountStatus::Pending, '', 2, 0 ) );
		$this->assertSame( 5, ApplicationRepository::total( AccountStatus::Pending ) );
		$this->assertSame( 1, ApplicationRepository::total( AccountStatus::Approved ) );
	}

	public function test_wiersz_niesie_numer_uprawnien_z_metadanej_z_przedrostkiem(): void {
		$this->seed_professions();
		$id = $this->account( 'pending', 'lekarz', '2026-03-01 10:00:00' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );

		$row = ApplicationRepository::paginate( AccountStatus::Pending, '', 20, 0 )[0];
		$this->assertSame( '5425740', $row->credentialNumber );
		$this->assertSame( $id, $row->userId );
		$this->assertSame( 'lekarz', $row->professionKey );
		$this->assertSame( AccountStatus::Pending, $row->status );
		$this->assertSame( '2026-03-01 10:00:00', $row->registeredAtUtc );
	}

	public function test_profesja_usunieta_z_ustawien_nie_gubi_wiersza(): void {
		$this->seed_professions();
		$this->account( 'pending', 'znikla' );

		$rows = ApplicationRepository::paginate( AccountStatus::Pending, '', 20, 0 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'znikla', $rows[0]->professionKey );
		$this->assertSame( '', $rows[0]->credentialNumber );
	}

	public function test_one_zwraca_null_dla_konta_bez_stanu_i_dla_nieistniejacego(): void {
		$bare = self::factory()->user->create();
		$this->assertNull( ApplicationRepository::one( $bare ) );
		$this->assertNull( ApplicationRepository::one( 99999999 ) );

		$id  = $this->account( 'rejected' );
		$row = ApplicationRepository::one( $id );
		$this->assertNotNull( $row );
		$this->assertSame( AccountStatus::Rejected, $row->status );
	}
}
