<?php
/**
 * Small helpers around WooCommerce's Action Scheduler (the database job queue).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Every job of the plugin lives in the Action Scheduler group `tassyir`, visible under
 * Tools → Scheduled Actions.
 */
final class Queue {

	const OPTION_CRON_RUN = 'tassyir_cron_last_run';

	/**
	 * Schedules a job in $delay seconds (0 = as soon as the queue runs).
	 *
	 * @param string            $hook  Action hook.
	 * @param array<int, mixed> $args  Arguments passed to the callback.
	 * @param int               $delay Seconds from now.
	 */
	public static function schedule( string $hook, array $args, int $delay = 0 ): void {
		if ( $delay <= 0 ) {
			as_enqueue_async_action( $hook, $args, Plugin::GROUP );
			return;
		}
		as_schedule_single_action( time() + $delay, $hook, $args, Plugin::GROUP );
	}

	/**
	 * Schedules a job unless the same job (hook + args) is already pending. A job that is
	 * running right now does not count: it may have read the data before the latest change.
	 *
	 * @param string            $hook  Action hook.
	 * @param array<int, mixed> $args  Arguments.
	 * @param int               $delay Seconds from now.
	 * @return bool True when a job was scheduled.
	 */
	public static function schedule_once( string $hook, array $args, int $delay = 0 ): bool {
		if ( self::has_pending( $hook, $args ) ) {
			return false;
		}
		self::schedule( $hook, $args, $delay );
		return true;
	}

	/**
	 * Whether a pending job with this hook and exactly these args exists.
	 *
	 * @param string            $hook Action hook.
	 * @param array<int, mixed> $args Arguments.
	 */
	public static function has_pending( string $hook, array $args ): bool {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => Plugin::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		return ! empty( $ids );
	}

	/**
	 * Number of pending jobs of a hook (capped at $cap to stay cheap).
	 *
	 * @param string $hook Action hook.
	 * @param int    $cap  Maximum counted.
	 */
	public static function count_pending( string $hook, int $cap = 5000 ): int {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => Plugin::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => $cap,
			),
			'ids'
		);
		return count( $ids );
	}

	/**
	 * First argument of the oldest pending jobs of a hook (e.g. order ids).
	 *
	 * @param string $hook  Action hook.
	 * @param int    $limit Maximum returned.
	 * @return int[]
	 */
	public static function pending_ids( string $hook, int $limit ): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => Plugin::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => $limit,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);
		$ids     = array();
		foreach ( (array) $actions as $action ) {
			$args = $action instanceof \ActionScheduler_Action ? $action->get_args() : array();
			if ( isset( $args[0] ) ) {
				$ids[] = (int) $args[0];
			}
		}
		return $ids;
	}

	/**
	 * Asks Action Scheduler to run due jobs soon, in a request of their own: the loopback Action
	 * Scheduler itself sends after wp-admin page loads, which storefront requests don't get. The
	 * COD form calls it after creating an order, so the order's push doesn't wait for the next
	 * WP-Cron run; it never runs in the customer's request.
	 */
	public static function run_soon(): void {
		if ( false === has_action( 'shutdown', array( self::class, 'dispatch_runner' ) ) ) {
			add_action( 'shutdown', array( self::class, 'dispatch_runner' ), 9999 );
		}
	}

	/**
	 * `shutdown`, last: ends the response, then dispatches Action Scheduler's async queue runner
	 * (with its own once-a-minute lock). Only where PHP can end the response early (PHP-FPM,
	 * LiteSpeed): the "non-blocking" loopback still costs the request 10 ms to 1 s (some DNS
	 * resolvers round its 0.01 s timeout up to a second). Elsewhere WP-Cron runs the job: the
	 * order-received page load that follows spawns it.
	 */
	public static function dispatch_runner(): void {
		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_AsyncRequest_QueueRunner' ) ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} else {
			return;
		}
		$lock = \ActionScheduler::lock();
		if ( $lock->is_locked( 'async-request-runner' ) || ! $lock->set( 'async-request-runner' ) ) {
			return; // A runner was dispatched less than a minute ago; WP-Cron covers the rest.
		}
		( new \ActionScheduler_AsyncRequest_QueueRunner( \ActionScheduler::store() ) )->maybe_dispatch();
	}

	/**
	 * Removes every job of the plugin.
	 */
	public static function cancel_all(): void {
		as_unschedule_all_actions( '', array(), Plugin::GROUP );
	}

	/**
	 * Remembers when WP-Cron last ran (at most one write per minute). Hooked on `init`.
	 */
	public static function note_cron_run(): void {
		if ( ! wp_doing_cron() ) {
			return;
		}
		$last = (int) get_option( self::OPTION_CRON_RUN, 0 );
		if ( time() - $last >= MINUTE_IN_SECONDS ) {
			update_option( self::OPTION_CRON_RUN, time(), false );
		}
	}

	/**
	 * Unix time of the last WP-Cron run we saw (0 = never).
	 */
	public static function last_cron_run(): int {
		return (int) get_option( self::OPTION_CRON_RUN, 0 );
	}
}
