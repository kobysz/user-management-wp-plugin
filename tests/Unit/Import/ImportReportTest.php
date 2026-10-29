<?php
/**
 * Testy raportu importu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Import\AccountChange;
use PortalUsers\Domain\Import\ImportReport;

final class ImportReportTest extends TestCase {

	public function test_liczy_przeskanowane_i_zmienione_osobno(): void {
		$report = ImportReport::empty()
			->with( new AccountChange( 1, array( 'field_a' => 'x' ), null, null, array() ) )
			->with( new AccountChange( 2, array(), null, null, array( AccountChange::ISSUE_PROFESSION_MISSING ) ) )
			->with( new AccountChange( 3, array(), null, AccountStatus::Approved, array() ) );

		$this->assertSame( 3, $report->scanned() );
		$this->assertSame( 2, $report->changed() );
	}

	public function test_probka_zastrzezenia_urywa_sie_na_limicie_a_licznik_rosnie_dalej(): void {
		$report = ImportReport::empty();

		// Kolejność niemonotoniczna: próbka musi zachować kolejność dopisania, a nie sortować identyfikatory.
		$ids = array( 9, 3, 12, 1, 7, 15, 2, 11, 5, 14, 4, 6, 8, 10, 13 );

		foreach ( $ids as $userId ) {
			$report = $report->with(
				new AccountChange( $userId, array(), null, null, array( AccountChange::ISSUE_PROFESSION_UNMAPPED ) )
			);
		}

		$this->assertSame( 15, $report->issueCount( AccountChange::ISSUE_PROFESSION_UNMAPPED ) );
		$this->assertCount( 10, $report->issueSample( AccountChange::ISSUE_PROFESSION_UNMAPPED ) );
		$this->assertSame(
			array( 9, 3, 12, 1, 7, 15, 2, 11, 5, 14 ),
			$report->issueSample( AccountChange::ISSUE_PROFESSION_UNMAPPED )
		);
	}

	public function test_nieobecny_kod_ma_zero_i_pusta_probke(): void {
		$report = ImportReport::empty();

		$this->assertSame( 0, $report->issueCount( AccountChange::ISSUE_STATUS_MISSING ) );
		$this->assertSame( array(), $report->issueSample( AccountChange::ISSUE_STATUS_MISSING ) );
	}

	public function test_with_nie_zmienia_poprzedniego_raportu(): void {
		$profession = AccountChange::ISSUE_PROFESSION_UNMAPPED;
		$status     = AccountChange::ISSUE_STATUS_UNMAPPED;

		$a = ImportReport::empty();
		$b = $a->with( new AccountChange( 1, array( 'field_a' => 'x' ), null, null, array( $profession ) ) );

		$this->assertSame( 0, $a->scanned() );
		$this->assertSame( 0, $a->changed() );
		$this->assertSame( 0, $a->issueCount( $profession ) );
		$this->assertSame( array(), $a->issueSample( $profession ) );
		$this->assertSame( ImportReport::empty()->toArray(), $a->toArray() );

		$this->assertSame( 1, $b->scanned() );
		$this->assertSame( 1, $b->changed() );
		$this->assertSame( 1, $b->issueCount( $profession ) );
		$this->assertSame( array( 1 ), $b->issueSample( $profession ) );

		// Kolejne with() i porażka na $b nie mogą ruszyć ani zastrzeżeń, ani próbek $b.
		$beforeB = $b->toArray();
		$c       = $b->with( new AccountChange( 2, array(), null, null, array( $profession, $status ) ) );
		$d       = $b->withWriteFailure( 3 );

		$this->assertSame( 1, $b->issueCount( $profession ) );
		$this->assertSame( array( 1 ), $b->issueSample( $profession ) );
		$this->assertSame( $beforeB, $b->toArray() );
		$this->assertSame( 0, $b->writeFailures() );
		$this->assertSame( array(), $b->writeFailureSample() );

		$this->assertSame( 2, $c->issueCount( $profession ) );
		$this->assertSame( 1, $d->writeFailures() );
	}

	public function test_porazka_zapisu_nie_liczy_sie_jako_zmiana(): void {
		$report = ImportReport::empty()
			->with( new AccountChange( 7, array( 'field_a' => 'x' ), null, null, array() ) );
		$failed = $report->withWriteFailure( 7 );

		$this->assertSame( 1, $failed->writeFailures() );
		$this->assertSame( array( 7 ), $failed->writeFailureSample() );
		$this->assertSame( $report->changed(), $failed->changed() );
		$this->assertSame( 0, $report->writeFailures() );
		$this->assertSame( array(), $report->writeFailureSample() );
	}

	public function test_probka_porazek_urywa_sie_na_limicie_a_licznik_rosnie_dalej(): void {
		$report = ImportReport::empty();

		// Kolejność niemonotoniczna: próbka ma kolejność dopisania, ucięta do SAMPLE_LIMIT.
		$ids = array( 9, 3, 12, 1, 7, 11, 2, 10, 5, 8, 4, 6 );

		foreach ( $ids as $userId ) {
			$report = $report->withWriteFailure( $userId );
		}

		$this->assertSame( 12, $report->writeFailures() );
		$this->assertSame(
			array( 9, 3, 12, 1, 7, 11, 2, 10, 5, 8 ),
			$report->writeFailureSample()
		);
	}

	public function test_porazka_logu_oznacza_nowy_raport_a_poprzedni_zostaje(): void {
		$before = ImportReport::empty();
		$after  = $before->withLogFailure();

		$this->assertFalse( $before->logFailed() );
		$this->assertTrue( $after->logFailed() );
		$this->assertTrue( $after->toArray()['log_failed'] );
	}

	public function test_to_array_niesie_wszystkie_liczniki_i_probki(): void {
		$report = ImportReport::empty()
			->with( new AccountChange( 9, array(), null, null, array( AccountChange::ISSUE_VALUE_UNUSABLE ) ) )
			->with( new AccountChange( 2, array(), null, null, array( AccountChange::ISSUE_CREDENTIAL_INVALID ) ) )
			->with( new AccountChange( 5, array( 'field_a' => 'x' ), null, null, array( AccountChange::ISSUE_VALUE_UNUSABLE ) ) )
			->withWriteFailure( 9 );

		$this->assertSame(
			array(
				'scanned'        => 3,
				'changed'        => 1,
				'write_failures' => 1,
				'issues'         => array(
					AccountChange::ISSUE_CREDENTIAL_INVALID => 1,
					AccountChange::ISSUE_VALUE_UNUSABLE     => 2,
				),
				'samples'        => array(
					AccountChange::ISSUE_CREDENTIAL_INVALID => array( 2 ),
					AccountChange::ISSUE_VALUE_UNUSABLE     => array( 9, 5 ),
				),
				'write_failure_sample' => array( 9 ),
				'log_failed'           => false,
			),
			$report->toArray()
		);
	}

	public function test_to_array_pustego_raportu_ma_puste_zastrzezenia_i_probki(): void {
		$this->assertSame(
			array(
				'scanned'        => 0,
				'changed'        => 0,
				'write_failures' => 0,
				'issues'         => array(),
				'samples'        => array(),
				'write_failure_sample' => array(),
				'log_failed'           => false,
			),
			ImportReport::empty()->toArray()
		);
	}
}
