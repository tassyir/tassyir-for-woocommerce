<?php
/**
 * Contract §G back-off: 1 m, 5 m, 15 m, 1 h, 6 h, then every 6 h up to 3 days.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tassyir\WooCommerce\Backoff;

final class BackoffTest extends TestCase {

	public function test_steps(): void {
		$this->assertSame( 60, Backoff::delay( 1 ) );
		$this->assertSame( 300, Backoff::delay( 2 ) );
		$this->assertSame( 900, Backoff::delay( 3 ) );
		$this->assertSame( 3600, Backoff::delay( 4 ) );
		$this->assertSame( 21600, Backoff::delay( 5 ) );
		$this->assertSame( 21600, Backoff::delay( 6 ) );
		$this->assertSame( 21600, Backoff::delay( 40 ) );
		$this->assertSame( 60, Backoff::delay( 0 ) );
	}

	/**
	 * Simulates a job that always fails: it is tried 16 times over just under 3 days, then fails.
	 */
	public function test_full_schedule_stops_within_three_days(): void {
		$first    = 1000000;
		$now      = $first;
		$tries    = 1;
		$failures = 0;
		$waits    = array();
		while ( true ) {
			++$failures;
			$delay = Backoff::next_delay( $failures, $first, $now );
			if ( null === $delay ) {
				break;
			}
			$waits[] = $delay;
			$now    += $delay;
			++$tries;
		}

		$this->assertSame( array( 60, 300, 900, 3600, 21600 ), array_slice( $waits, 0, 5 ) );
		$this->assertSame( array( 21600 ), array_values( array_unique( array_slice( $waits, 5 ) ) ) );
		$this->assertSame( 16, $tries );
		$this->assertLessThanOrEqual( 3 * 86400, $now - $first );
		$this->assertGreaterThan( 3 * 86400, $now - $first + 21600 );
	}

	public function test_gives_up_after_three_days(): void {
		$this->assertNull( Backoff::next_delay( 3, 0, 3 * 86400 ) );
		$this->assertSame( 900, Backoff::next_delay( 3, 0, 3 * 86400 - 900 ) );
	}
}
