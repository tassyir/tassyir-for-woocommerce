<?php
/**
 * Order queue: hooks enqueue one job per order, the job pushes it to Tassyir (contract §C, §G).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Every new or changed order gets ONE pending `tassyir_push_order(order_id)` job. The job
 * renders the order as REST v3 JSON, posts it, stores the outcome in order meta, and retries
 * transient failures with Backoff until Tassyir confirms it or 3 days have passed.
 */
class Order_Sync {

	const HOOK          = 'tassyir_push_order';
	const BACKFILL_HOOK = 'tassyir_backfill_orders';

	// Order meta read by the backend's support tools and shown in wp-admin (contract §G).
	const META_STATUS   = '_tassyir_status';       // queued | sent | skipped | failed.
	const META_ORDER_ID = '_tassyir_order_id';
	const META_NUMBER   = '_tassyir_order_number';
	const META_REASON   = '_tassyir_reason';
	const META_ATTEMPTS = '_tassyir_attempts';
	const META_SENT_AT  = '_tassyir_sent_at';

	// Internal retry state of the current send cycle.
	const META_CHANNEL   = '_tassyir_channel';
	const META_FIRST_TRY = '_tassyir_first_try_at';
	const META_FAILURES  = '_tassyir_failures';

	const CHANNEL_NEW      = 'plugin';
	const CHANNEL_RESEND   = 'plugin_resend';
	const CHANNEL_BACKFILL = 'plugin_backfill';

	/**
	 * Statuses of orders that are not real orders yet (never pushed).
	 */
	const DRAFT_STATUSES = array( 'checkout-draft', 'auto-draft', 'draft', 'trash' );

	/**
	 * Oldest order a backfill may ask for, in days (same rule as the history import).
	 */
	const BACKFILL_MAX_DAYS = 90;

	const BACKFILL_BATCH = 50;

	/**
	 * HTTP client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the order sync.
	 *
	 * @param Api_Client $api        HTTP client.
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Api_Client $api, Connection $connection ) {
		$this->api        = $api;
		$this->connection = $connection;
	}

	/**
	 * Hooks: classic checkout, block checkout, any new order (admin, REST, other plugins),
	 * status changes, and the queue jobs.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_created' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_created' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'on_order_created' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 3 );

		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( self::class, 'cpt_query_vars' ), 10, 2 );

		add_action( self::HOOK, array( $this, 'push' ), 10, 1 );
		add_action( self::BACKFILL_HOOK, array( $this, 'backfill_batch' ), 10, 2 );
	}

	/**
	 * A new order (any source). The store-API hook passes the order object, the others its id.
	 *
	 * @param int|\WC_Order $order_or_id Order or order id.
	 */
	public function on_order_created( $order_or_id ): void {
		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( $order instanceof \WC_Order ) {
			$this->enqueue( $order );
		}
	}

	/**
	 * An order changed status: push it again if it may have become importable
	 * (e.g. pending payment → processing). Orders already sent are left alone.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public function on_status_changed( $order_id, $from, $to ): void {
		if ( ! in_array( (string) $to, $this->connection->settings()['importStatuses'], true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->enqueue( $order );
		}
	}

	/**
	 * Schedules the push of an order, unless one is already pending or it was already sent.
	 * Writes nothing to the order: hooks may run in the middle of WooCommerce saving it.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool True when a job was scheduled.
	 */
	public function enqueue( \WC_Order $order ): bool {
		if ( ! $this->connection->is_connected()
			|| in_array( $order->get_status(), self::DRAFT_STATUSES, true )
			|| 'sent' === $order->get_meta( self::META_STATUS ) ) {
			return false;
		}
		return Queue::schedule_once( self::HOOK, array( $order->get_id() ) );
	}

	/**
	 * Sends an order (again) now, whatever its state: bulk action, meta box, Tassyir command.
	 *
	 * @param int    $order_id Order id.
	 * @param string $channel  plugin_resend or plugin_backfill.
	 * @param bool   $force    True: replace a pending (backed-off) job and resend even if sent.
	 */
	public function resend( int $order_id, string $channel = self::CHANNEL_RESEND, bool $force = true ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->connection->is_connected()
			|| in_array( $order->get_status(), self::DRAFT_STATUSES, true ) ) {
			return false;
		}
		if ( ! $force && ( 'sent' === $order->get_meta( self::META_STATUS ) || Queue::has_pending( self::HOOK, array( $order_id ) ) ) ) {
			return false;
		}

		as_unschedule_all_actions( self::HOOK, array( $order_id ), Plugin::GROUP );
		$order->update_meta_data( self::META_STATUS, 'queued' );
		$order->update_meta_data( self::META_CHANNEL, $channel );
		$order->update_meta_data( self::META_REASON, '' );
		$order->delete_meta_data( self::META_FIRST_TRY );
		$order->delete_meta_data( self::META_FAILURES );
		$order->save_meta_data();

		Queue::schedule( self::HOOK, array( $order_id ) );
		return true;
	}

	/**
	 * The job: push one order and record the outcome.
	 *
	 * @param int $order_id Order id.
	 */
	public function push( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->connection->is_connected() ) {
			return;
		}

		$first_try = (int) $order->get_meta( self::META_FIRST_TRY );
		$new_cycle = 0 === $first_try;
		$attempt   = $new_cycle ? 1 : (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
		$channel   = (string) $order->get_meta( self::META_CHANNEL );
		$channel   = '' === $channel ? self::CHANNEL_NEW : $channel;

		if ( $new_cycle ) {
			$first_try = time();
			$order->update_meta_data( self::META_FIRST_TRY, (string) $first_try );
			$order->delete_meta_data( self::META_FAILURES );
		}
		$order->update_meta_data( self::META_ATTEMPTS, (string) $attempt );

		try {
			$payload = array(
				'order'   => Rest_Renderer::with_state_names( Rest_Renderer::order( $order ) ),
				'channel' => $channel,
				'attempt' => $attempt,
			);
		} catch ( \Throwable $e ) {
			$this->finish( $order, 'failed', 'RENDER_ERROR', array(), get_class( $e ) );
			return;
		}

		$this->handle_response( $order, $this->api->post( '/woo-plugin/orders', $payload ), $first_try );
	}

	/**
	 * Maps Tassyir's answer to the order's state (§C). Public for tests.
	 *
	 * @param \WC_Order    $order     Order.
	 * @param Api_Response $response  Answer.
	 * @param int          $first_try Unix time of the first try of this cycle.
	 */
	public function handle_response( \WC_Order $order, Api_Response $response, int $first_try ): void {
		$body = $response->body;

		if ( 200 === $response->status ) {
			$status = isset( $body['status'] ) ? (string) $body['status'] : '';
			$reason = isset( $body['reason'] ) ? (string) $body['reason'] : '';
			if ( in_array( $status, array( 'created', 'duplicate' ), true ) ) {
				$this->finish( $order, 'sent', $reason, $body );
				return;
			}
			if ( 'skipped' === $status ) {
				$this->finish( $order, 'skipped', $reason, $body );
				return;
			}
			if ( 'failed' === $status && empty( $body['retry'] ) ) {
				$this->finish( $order, 'failed', '' === $reason ? 'FAILED' : $reason, $body );
				return;
			}
			$this->retry( $order, '' === $reason ? 'RETRY' : $reason, $first_try );
			return;
		}

		if ( 202 === $response->status ) { // Per-order lock held on Tassyir's side: try again in 30 s.
			$this->wait( $order, 30, 'LOCKED' );
			return;
		}
		if ( 429 === $response->status ) { // Rate limited: wait what Tassyir asks.
			$this->wait( $order, $response->retry_after > 0 ? $response->retry_after : 60, 'RATE_LIMITED' );
			return;
		}
		if ( $response->retryable() ) {
			$this->retry( $order, $response->code, $first_try, $response->detail );
			return;
		}
		// Any other 4xx (e.g. WOO_PLUGIN_ORDER_INVALID) is final.
		$this->finish( $order, 'failed', $response->code, array(), $response->detail );
	}

	/**
	 * Ends the send cycle with a final state.
	 *
	 * @param \WC_Order            $order  Order.
	 * @param string               $state  sent | skipped | failed.
	 * @param string               $reason Reason code ('' when none).
	 * @param array<string, mixed> $body   Tassyir's answer.
	 * @param string               $detail Technical detail for the log.
	 */
	private function finish( \WC_Order $order, string $state, string $reason, array $body, string $detail = '' ): void {
		$order->update_meta_data( self::META_STATUS, $state );
		$order->update_meta_data( self::META_REASON, $reason );
		if ( 'sent' === $state ) {
			$order->update_meta_data( self::META_ORDER_ID, isset( $body['tassyirOrderId'] ) ? (string) $body['tassyirOrderId'] : '' );
			$order->update_meta_data( self::META_NUMBER, isset( $body['orderNumber'] ) ? (string) $body['orderNumber'] : '' );
			$order->update_meta_data( self::META_SENT_AT, Plugin::iso( time() ) );
			$this->connection->set( array( 'lastOrderSentAt' => time() ) );
		}
		$order->delete_meta_data( self::META_FIRST_TRY );
		$order->delete_meta_data( self::META_FAILURES );
		$order->delete_meta_data( self::META_CHANNEL );
		$order->save_meta_data();

		if ( 'failed' === $state ) {
			Failure_Log::add( 'order', $order->get_id(), $reason, $detail );
		}
	}

	/**
	 * A transient failure: schedule the next try per Backoff, or fail after 3 days.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $reason    Error code.
	 * @param int       $first_try Unix time of the first try.
	 * @param string    $detail    Technical detail for the log.
	 */
	private function retry( \WC_Order $order, string $reason, int $first_try, string $detail = '' ): void {
		$failures = (int) $order->get_meta( self::META_FAILURES ) + 1;
		$delay    = Backoff::next_delay( $failures, $first_try, time() );
		if ( null === $delay ) {
			$this->finish( $order, 'failed', $reason, array(), $detail );
			return;
		}
		$order->update_meta_data( self::META_FAILURES, (string) $failures );
		$this->wait( $order, $delay, $reason );
	}

	/**
	 * Keeps the order queued and schedules its next try.
	 *
	 * @param \WC_Order $order  Order.
	 * @param int       $delay  Seconds.
	 * @param string    $reason Why it waits (shown in wp-admin).
	 */
	private function wait( \WC_Order $order, int $delay, string $reason ): void {
		$order->update_meta_data( self::META_STATUS, 'queued' );
		$order->update_meta_data( self::META_REASON, $reason );
		$order->save_meta_data();
		Queue::schedule_once( self::HOOK, array( $order->get_id() ), $delay );
	}

	/**
	 * Starts "send orders since…": queues every order created since $since (at most 90 days back)
	 * that was not sent yet, in batches.
	 *
	 * @param int $since Unix time.
	 * @return int The start time actually used.
	 */
	public function start_backfill( int $since ): int {
		$since = max( $since, time() - self::BACKFILL_MAX_DAYS * DAY_IN_SECONDS );
		Queue::schedule( self::BACKFILL_HOOK, array( $since, 1 ) );
		return $since;
	}

	/**
	 * The backfill job: one page of orders, then the next page.
	 *
	 * @param int $since Unix time.
	 * @param int $page  Page number, from 1.
	 */
	public function backfill_batch( $since, $page ): void {
		$ids = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'date_created' => '>=' . (int) $since,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'limit'        => self::BACKFILL_BATCH,
				'page'         => max( 1, (int) $page ),
				'return'       => 'ids',
			)
		);
		/** @var int[] $ids `return => ids` gives order ids. */
		$ids = is_array( $ids ) ? $ids : array();
		foreach ( $ids as $id ) {
			$this->resend( (int) $id, self::CHANNEL_BACKFILL, false );
		}
		if ( count( $ids ) === self::BACKFILL_BATCH ) {
			Queue::schedule( self::BACKFILL_HOOK, array( (int) $since, (int) $page + 1 ), 5 );
		}
	}

	/**
	 * Display state of an order: queued (a job is pending), sent, skipped, failed, or none.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function state( \WC_Order $order ): string {
		if ( Queue::has_pending( self::HOOK, array( $order->get_id() ) ) ) {
			return 'queued';
		}
		$state = (string) $order->get_meta( self::META_STATUS );
		return in_array( $state, array( 'queued', 'sent', 'skipped', 'failed' ), true ) ? $state : 'none';
	}

	/**
	 * Number of orders whose push failed for good.
	 */
	public function failed_count(): int {
		$args = array(
			'type'     => 'shop_order',
			'limit'    => 1,
			'paginate' => true,
			'return'   => 'ids',
		);
		// HPOS understands meta_query; the legacy posts store gets it from cpt_query_vars().
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args['meta_query'] = self::failed_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small admin/heartbeat count.
		} else {
			$args['tassyir_status'] = 'failed';
		}
		$result = wc_get_orders( $args );
		return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * Legacy (posts) order store: turns our `tassyir_status` query var into a meta query, the
	 * way WooCommerce documents custom order query vars for that store.
	 *
	 * @param array<string, mixed> $query      WP_Query args.
	 * @param array<string, mixed> $query_vars wc_get_orders() args.
	 * @return array<string, mixed>
	 */
	public static function cpt_query_vars( $query, $query_vars ): array {
		if ( isset( $query_vars['tassyir_status'] ) && 'failed' === $query_vars['tassyir_status'] ) {
			$query['meta_query']   = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small admin/heartbeat count.
			$query['meta_query'][] = self::failed_meta_query()[0];
		}
		// The COD form's idempotency lookup (Cod\Order_Creator::find_existing()).
		if ( isset( $query_vars['tassyir_submission_id'] ) && '' !== (string) $query_vars['tassyir_submission_id'] ) {
			$query['meta_query']   = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per form submit.
			$query['meta_query'][] = array(
				'key'   => '_tassyir_submission_id',
				'value' => (string) $query_vars['tassyir_submission_id'],
			);
		}
		return $query;
	}

	/**
	 * Meta query matching orders whose push failed for good.
	 *
	 * @return array<int, array{key: string, value: string}>
	 */
	private static function failed_meta_query(): array {
		return array(
			array(
				'key'   => self::META_STATUS,
				'value' => 'failed',
			),
		);
	}

	/**
	 * Orders whose push job is pending: count and creation date of the oldest one.
	 *
	 * @return array{pending: int, oldestPendingAt: string|null}
	 */
	public function pending_stats(): array {
		$pending = Queue::count_pending( self::HOOK );
		$oldest  = null;
		foreach ( Queue::pending_ids( self::HOOK, 20 ) as $id ) {
			$order   = wc_get_order( $id );
			$created = $order instanceof \WC_Order ? $order->get_date_created() : null;
			if ( $created && ( null === $oldest || $created->getTimestamp() < $oldest ) ) {
				$oldest = $created->getTimestamp();
			}
		}
		return array(
			'pending'         => $pending,
			'oldestPendingAt' => null === $oldest ? null : Plugin::iso( $oldest ),
		);
	}

	/**
	 * The 50 most recent orders with their Tassyir state, for the heartbeat's gap repair (§E).
	 *
	 * @return array<int, array{id: int, status: string, createdAt: string|null, tassyir: string}>
	 */
	public function recent_orders(): array {
		$orders = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'limit'   => 50,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$recent = array();
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			$created  = $order->get_date_created();
			$recent[] = array(
				'id'        => $order->get_id(),
				'status'    => $order->get_status(),
				'createdAt' => $created ? Plugin::iso( $created->getTimestamp() ) : null,
				'tassyir'   => $this->state( $order ),
			);
		}
		return $recent;
	}

	/**
	 * Requeues recent orders that say `queued` but lost their job (e.g. the queue was cleared).
	 *
	 * @param array<int, array{id: int, tassyir: string}> $recent Output of recent_orders().
	 */
	public function flush_stale( array $recent ): void {
		foreach ( $recent as $row ) {
			if ( 'queued' !== $row['tassyir'] || Queue::has_pending( self::HOOK, array( $row['id'] ) ) ) {
				continue;
			}
			Queue::schedule( self::HOOK, array( $row['id'] ) );
		}
	}
}
