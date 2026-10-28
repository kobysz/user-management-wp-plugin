<?php
/**
 * Testy crona opróżniającego kolejkę maili: harmonogram minutowy, oba hooki i brak duplikatów.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Cron;

use PortalUsers\Cron\DispatchMail;
use PortalUsers\Mail\Dispatcher;
use PortalUsers\Mail\MailQueue;
use WP_UnitTestCase;

final class DispatchMailTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_clear_scheduled_hook( DispatchMail::HOOK );
		wp_clear_scheduled_hook( MailQueue::DISPATCH_HOOK );
	}

	/**
	 * Liczba zaplanowanych wystąpień hooka w tablicy crona.
	 *
	 * @param string $hook Nazwa hooka.
	 */
	private function occurrences( string $hook ): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				$count += count( (array) $events[ $hook ] );
			}
		}
		return $count;
	}

	public function test_the_minute_schedule_is_registered(): void {
		// Rdzeń nie ma nic krótszego niż `hourly`, a mail, na który człowiek czeka przy ekranie,
		// nie może leżeć w kolejce godzinę.
		DispatchMail::register();

		$this->assertSame( 60, wp_get_schedules()[ DispatchMail::SCHEDULE ]['interval'] );
	}

	public function test_register_binds_both_hooks_to_the_dispatcher(): void {
		DispatchMail::register();

		$this->assertNotFalse( has_action( DispatchMail::HOOK, array( Dispatcher::class, 'run' ) ) );
		$this->assertNotFalse( has_action( MailQueue::DISPATCH_HOOK, array( Dispatcher::class, 'run' ) ) );
	}

	public function test_register_does_not_create_the_event(): void {
		// Administrator, który zdjął zdarzenie (np. WP Crontrol), nie może go odzyskiwać przy
		// każdym żądaniu: harmonogram zakładają aktywacja i jednorazowa migracja.
		DispatchMail::register();

		$this->assertFalse( wp_next_scheduled( DispatchMail::HOOK ) );
	}

	public function test_scheduling_twice_does_not_duplicate_the_event(): void {
		DispatchMail::schedule();
		$first = wp_next_scheduled( DispatchMail::HOOK );

		DispatchMail::schedule();

		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( DispatchMail::HOOK ) );
		$this->assertSame( 1, $this->occurrences( DispatchMail::HOOK ) );
		$this->assertSame( DispatchMail::SCHEDULE, wp_get_schedule( DispatchMail::HOOK ) );
	}

	public function test_unschedule_clears_both_hooks(): void {
		// Po usunięciu wtyczki zostawione zdarzenie wołałoby nieistniejącą klasę. Zdarzeń
		// natychmiastowych może leżeć kilka, bo każde kolejkowanie zakłada własne.
		DispatchMail::schedule();
		wp_schedule_single_event( time() + 60, MailQueue::DISPATCH_HOOK );
		$this->assertNotFalse( wp_next_scheduled( DispatchMail::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );

		DispatchMail::unschedule();

		$this->assertFalse( wp_next_scheduled( DispatchMail::HOOK ) );
		$this->assertFalse( wp_next_scheduled( MailQueue::DISPATCH_HOOK ) );
	}
}
