<?php
/**
 * Heartbeat: health in, settings and commands out (contract §E).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A recurring job reports versions, queue size, cron health and the 50 latest orders. Tassyir
 * never calls the site: anything it wants (resend orders, sync products, backfill, reconnect)
 * comes back as a command in the answer. Commands are run once and acknowledged on the next
 * heartbeat.
 */
class Heartbeat {

	const HOOK = 'tassyir_heartbeat';

	/**
	 * One-off heartbeat (settings-page load, right after connecting).
	 */
	const NOW_HOOK = 'tassyir_heartbeat_now';

	/**
	 * Ids of commands started but not yet acknowledged to Tassyir.
	 */
	const OPTION_ACKS = 'tassyir_pending_acks';

	/**
	 * Ids of commands already run (id => unix time), so a re-delivered command is not run twice.
	 */
	const OPTION_DONE = 'tassyir_done_commands';

	/**
	 * Tassyir drops unacknowledged commands after 7 days; we remember run ones a little longer.
	 */
	const DONE_TTL = 8 * DAY_IN_SECONDS;

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
	 * Order sync (resend, backfill, recent orders).
	 *
	 * @var Order_Sync
	 */
	private $orders;

	/**
	 * Product sync (full sync).
	 *
	 * @var Product_Sync
	 */
	private $products;

	/**
	 * Builds the heartbeat.
	 *
	 * @param Api_Client   $api        HTTP client.
	 * @param Connection   $connection Stored connection.
	 * @param Order_Sync   $orders     Order sync.
	 * @param Product_Sync $products   Product sync.
	 */
	public function __construct( Api_Client $api, Connection $connection, Order_Sync $orders, Product_Sync $products ) {
		$this->api        = $api;
		$this->connection = $connection;
		$this->orders     = $orders;
		$this->products   = $products;
	}

	/**
	 * The job hook, and WP-Cron health tracking.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 0 );
		add_action( self::NOW_HOOK, array( $this, 'run' ), 10, 0 );
		add_action( 'init', array( Queue::class, 'note_cron_run' ) );
	}

	/**
	 * (Re)schedules the recurring heartbeat every $seconds.
	 *
	 * @param int $seconds Interval.
	 */
	public static function schedule( int $seconds ): void {
		as_unschedule_all_actions( self::HOOK, array(), Plugin::GROUP );
		as_schedule_recurring_action( time() + 10, $seconds, self::HOOK, array(), Plugin::GROUP );
		update_option( 'tassyir_heartbeat_interval', $seconds, false );
	}

	/**
	 * Asks for one heartbeat as soon as the queue runs (settings-page load, after connect).
	 * A recurring heartbeat is also made sure to exist.
	 */
	public function run_soon(): void {
		if ( ! $this->connection->is_connected() ) {
			return;
		}
		$seconds = $this->connection->heartbeat_seconds();
		if ( (int) get_option( 'tassyir_heartbeat_interval', 0 ) !== $seconds || ! Queue::has_pending( self::HOOK, array() ) ) {
			self::schedule( $seconds );
		}
		Queue::schedule_once( self::NOW_HOOK, array() );
	}

	/**
	 * The job: send one heartbeat and handle the answer.
	 */
	public function run(): void {
		if ( ! $this->connection->is_connected() ) {
			return;
		}

		$acks     = $this->pending_acks();
		$payload  = $this->build_payload( $acks );
		$response = $this->api->post( '/woo-plugin/heartbeat', $payload );

		if ( ! $response->ok() ) {
			if ( $this->connection->get( 'heartbeatError' ) !== $response->code ) {
				Failure_Log::add( 'heartbeat', 0, $response->code, $response->detail );
			}
			$this->connection->set(
				array(
					'heartbeatError'   => $response->code,
					'heartbeatErrorAt' => time(),
				)
			);
			return;
		}

		$this->handle_response( $response->body, $acks );
		$this->orders->flush_stale( $payload['recentOrders'] );
	}

	/**
	 * The heartbeat body (§E).
	 *
	 * @param string[] $acks Command ids to acknowledge.
	 * @return array<string, mixed>
	 */
	public function build_payload( array $acks ): array {
		$pending = $this->orders->pending_stats();

		return array_merge(
			Plugin::environment(),
			array(
				'queue'              => array(
					'pending'         => $pending['pending'],
					'failed'          => $this->orders->failed_count(),
					'oldestPendingAt' => $pending['oldestPendingAt'],
				),
				'cron'               => array(
					'lastRunAt' => Queue::last_cron_run() > 0 ? Plugin::iso( Queue::last_cron_run() ) : null,
					'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				),
				'recentOrders'       => $this->orders->recent_orders(),
				'manualWebhooksLeft' => Manual_Webhooks::refresh_count( $this->connection ),
				'ackCommandIds'      => array_values( $acks ),
			)
		);
	}

	/**
	 * Applies a 200 answer: settings, versions, commands. Public for tests.
	 *
	 * @param array<string, mixed> $body      Decoded answer.
	 * @param string[]             $sent_acks Ids acknowledged by the request just sent.
	 */
	public function handle_response( array $body, array $sent_acks ): void {
		// Acknowledged: forget them (new ones may have been added meanwhile).
		update_option( self::OPTION_ACKS, array_values( array_diff( $this->pending_acks(), $sent_acks ) ), false );

		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			$this->connection->update_settings( $body['settings'] );
			if ( (int) get_option( 'tassyir_heartbeat_interval', 0 ) !== $this->connection->heartbeat_seconds() ) {
				self::schedule( $this->connection->heartbeat_seconds() );
			}
		}

		$min = isset( $body['minPluginVersion'] ) ? (string) $body['minPluginVersion'] : '';
		$this->connection->set(
			array(
				'lastHeartbeatAt'     => time(),
				'serverTime'          => isset( $body['serverTime'] ) ? (string) $body['serverTime'] : '',
				'minPluginVersion'    => $min,
				'latestPluginVersion' => isset( $body['latestPluginVersion'] ) ? (string) $body['latestPluginVersion'] : '',
				'heartbeatError'      => null,
				'heartbeatErrorAt'    => null,
			)
		);
		if ( '' !== $min && version_compare( TASSYIR_WC_VERSION, $min, '<' ) ) {
			$this->connection->set(
				array(
					'problem'     => Connection::PROBLEM_OUTDATED,
					'problemData' => $min,
				)
			);
		}

		$commands = isset( $body['commands'] ) && is_array( $body['commands'] ) ? $body['commands'] : array();
		foreach ( $commands as $command ) {
			if ( is_array( $command ) ) {
				$this->handle_command( $command );
			}
		}
	}

	/**
	 * Runs one command once, then queues its id for acknowledgement.
	 *
	 * @param array<string, mixed> $command Command object.
	 * @return bool True when the command is known (run now or earlier).
	 */
	public function handle_command( array $command ): bool {
		$id   = isset( $command['id'] ) ? (string) $command['id'] : '';
		$type = isset( $command['type'] ) ? (string) $command['type'] : '';
		if ( '' === $id ) {
			return false;
		}

		$done = $this->done_commands();
		if ( ! isset( $done[ $id ] ) ) {
			switch ( $type ) {
				case 'resend_orders':
					$ids = isset( $command['orderIds'] ) && is_array( $command['orderIds'] ) ? array_slice( $command['orderIds'], 0, 100 ) : array();
					foreach ( $ids as $order_id ) {
						$this->orders->resend( absint( $order_id ), Order_Sync::CHANNEL_RESEND );
					}
					break;
				case 'sync_products':
					$this->products->start_full_sync();
					break;
				case 'backfill_orders':
					$since = isset( $command['since'] ) ? strtotime( (string) $command['since'] ) : false;
					if ( false === $since ) {
						return false;
					}
					$this->orders->start_backfill( $since );
					break;
				case 'reconnect_required':
					$this->connection->set(
						array(
							'problem'     => Connection::PROBLEM_RECONNECT,
							'problemData' => '',
						)
					);
					break;
				default:
					return false; // Unknown to this version: not acknowledged, Tassyir lets it expire.
			}
			$done[ $id ] = time();
			update_option( self::OPTION_DONE, $done, false );
		}

		// Acknowledge (again, if Tassyir did not get the previous acknowledgement).
		$acks = $this->pending_acks();
		if ( ! in_array( $id, $acks, true ) ) {
			$acks[] = $id;
			update_option( self::OPTION_ACKS, $acks, false );
		}
		return true;
	}

	/**
	 * Command ids waiting to be acknowledged.
	 *
	 * @return string[]
	 */
	public function pending_acks(): array {
		$acks = get_option( self::OPTION_ACKS, array() );
		return is_array( $acks ) ? array_values( array_map( 'strval', $acks ) ) : array();
	}

	/**
	 * Commands already run, without the expired ones.
	 *
	 * @return array<string, int>
	 */
	private function done_commands(): array {
		$done = get_option( self::OPTION_DONE, array() );
		$done = is_array( $done ) ? $done : array();
		$now  = time();
		return array_filter(
			$done,
			static function ( $at ) use ( $now ) {
				return $now - (int) $at < self::DONE_TTL;
			}
		);
	}
}
