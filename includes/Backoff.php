<?php
/**
 * Retry schedule of every queued call (contract §G). Pure functions, unit-tested.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * After a failed try, wait 1 min, 5 min, 15 min, 1 h, 6 h, then every 6 h; give up (status
 * `failed`, resendable) once the next try would fall more than 3 days after the first one.
 */
final class Backoff {

	/**
	 * Waits after the 1st, 2nd, … failure, in seconds.
	 */
	const STEPS = array( 60, 300, 900, 3600, 21600 );

	/**
	 * Wait after every later failure (6 h).
	 */
	const EVERY = 21600;

	/**
	 * Nothing is retried more than 3 days after its first try.
	 */
	const MAX_AGE = 259200;

	/**
	 * Wait before the next try after $failures failures (1 = the first failure).
	 *
	 * @param int $failures Failures so far, ≥ 1.
	 */
	public static function delay( int $failures ): int {
		$index = max( 1, $failures ) - 1;
		return self::STEPS[ $index ] ?? self::EVERY;
	}

	/**
	 * Wait before the next try, or null when the 3-day window is over and the job must fail.
	 *
	 * @param int $failures     Failures so far, ≥ 1.
	 * @param int $first_try_at Unix time of the first try.
	 * @param int $now          Unix time now.
	 */
	public static function next_delay( int $failures, int $first_try_at, int $now ): ?int {
		$delay = self::delay( $failures );
		if ( $now + $delay > $first_try_at + self::MAX_AGE ) {
			return null;
		}
		return $delay;
	}
}
