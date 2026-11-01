<?php
/**
 * Heartbeat body, settings and command handling (§E).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Connection;
use Tassyir\WooCommerce\Heartbeat;
use Tassyir\WooCommerce\Order_Sync;
use Tassyir\WooCommerce\Plugin;
use Tassyir\WooCommerce\Product_Sync;

final class HeartbeatTest extends Integration_TestCase {

	/**
	 * Heartbeat under test.
	 *
	 * @var Heartbeat
	 */
	private $heartbeat;

	public function set_up(): void {
		parent::set_up();
		$this->heartbeat = Plugin::instance()->heartbeat;
		delete_option( Heartbeat::OPTION_ACKS );
		delete_option( Heartbeat::OPTION_DONE );
		delete_option( Connection::OPTION_INFO );
	}

	public function test_body_has_every_contract_field(): void {
		$order = $this->order();
		$this->answer( 200, array( 'serverTime' => '2026-10-29T10:00:00Z' ) );
		$this->heartbeat->run();

		$this->assertSame( 'https://api.tassyir.test/woo-plugin/heartbeat', $this->requests[0]['url'] );
		$this->assertSigned();
		$body = $this->sent_body();
		foreach ( array( 'pluginVersion', 'wpVersion', 'wcVersion', 'phpVersion', 'checkout', 'hpos', 'queue', 'cron', 'recentOrders', 'manualWebhooksLeft', 'ackCommandIds' ) as $key ) {
			$this->assertArrayHasKey( $key, $body, $key );
		}
		$this->assertContains( $body['checkout'], array( 'classic', 'blocks', 'both', 'unknown' ) );
		$this->assertIsBool( $body['hpos'] );
		$this->assertSame( 1, $body['queue']['pending'] );
		$this->assertNotNull( $body['queue']['oldestPendingAt'] );
		$this->assertSame( $order->get_id(), $body['recentOrders'][0]['id'] );
		$this->assertSame( 'queued', $body['recentOrders'][0]['tassyir'] );
		$this->assertSame( 'processing', $body['recentOrders'][0]['status'] );
		$this->assertSame( array(), $body['ackCommandIds'] );
		$this->assertGreaterThan( 0, ( new Connection() )->get( 'lastHeartbeatAt' ) );
	}

	public function test_commands_run_once_and_are_acknowledged_next_time(): void {
		$order = $this->order();
		as_unschedule_all_actions( Order_Sync::HOOK );

		$answer = array(
			'serverTime'          => '2026-10-29T10:00:00Z',
			'settings'            => array(
				'importStatuses'     => array( 'processing' ),
				'importFrom'         => '2026-10-01T00:00:00Z',
				'autoCreateProducts' => true,
				'heartbeatSeconds'   => 600,
			),
			'commands'            => array(
				array(
					'id'       => 'c1',
					'type'     => 'resend_orders',
					'orderIds' => array( $order->get_id() ),
				),
				array(
					'id'   => 'c2',
					'type' => 'sync_products',
				),
				array(
					'id'    => 'c3',
					'type'  => 'backfill_orders',
					'since' => gmdate( 'c', time() - 10 * DAY_IN_SECONDS ),
				),
				array(
					'id'   => 'c4',
					'type' => 'reconnect_required',
				),
				array(
					'id'   => 'c5',
					'type' => 'something_new',
				),
			),
			'minPluginVersion'    => '1.0.0',
			'latestPluginVersion' => '1.1.0',
		);
		$this->answer( 200, $answer );
		$this->heartbeat->run();

		$this->assertCount( 1, $this->pending( Order_Sync::HOOK ) );
		$this->assertSame( 'plugin_resend', wc_get_order( $order->get_id() )->get_meta( '_tassyir_channel' ) );
		$this->assertCount( 1, $this->pending( Product_Sync::BATCH_HOOK ) );
		$this->assertCount( 1, $this->pending( Order_Sync::BACKFILL_HOOK ) );
		$connection = new Connection();
		$this->assertSame( Connection::PROBLEM_RECONNECT, $connection->get( 'problem' ) );
		$this->assertSame( array( 'processing' ), $connection->settings()['importStatuses'] );
		$this->assertSame( 600, $connection->heartbeat_seconds() );
		$this->assertSame( '1.1.0', $connection->get( 'latestPluginVersion' ) );
		$this->assertSame( array( 'c1', 'c2', 'c3', 'c4' ), $this->heartbeat->pending_acks() );

		// Tassyir re-delivers c1 (our ack has not reached it yet): it is not run again.
		$this->sync_order_sent( $order );
		$this->answer( 200, array( 'commands' => array( $answer['commands'][0] ) ) );
		$this->heartbeat->run();
		$this->assertSame( array( 'c1', 'c2', 'c3', 'c4' ), $this->sent_body( 1 )['ackCommandIds'] );
		$this->assertCount( 0, $this->pending( Order_Sync::HOOK ) );
		// The second answer acknowledged c1–c4; c1 was delivered again, so it is acked once more.
		$this->assertSame( array( 'c1' ), $this->heartbeat->pending_acks() );

		$this->answer( 200, array() );
		$this->heartbeat->run();
		$this->assertSame( array( 'c1' ), $this->sent_body( 2 )['ackCommandIds'] );
		$this->assertSame( array(), $this->heartbeat->pending_acks() );
	}

	public function test_outdated_plugin_and_failed_heartbeat(): void {
		$this->answer( 200, array( 'minPluginVersion' => '9.0.0' ) );
		$this->heartbeat->run();
		$this->assertSame( Connection::PROBLEM_OUTDATED, ( new Connection() )->get( 'problem' ) );
		$this->assertSame( '9.0.0', ( new Connection() )->get( 'problemData' ) );

		$this->answer(
			426,
			array(
				'code'       => 'WOO_PLUGIN_VERSION_UNSUPPORTED',
				'minVersion' => '9.1.0',
			)
		);
		$this->heartbeat->run();
		$this->assertSame( 'WOO_PLUGIN_VERSION_UNSUPPORTED', ( new Connection() )->get( 'heartbeatError' ) );
		$this->assertSame( '9.1.0', ( new Connection() )->get( 'problemData' ) );
	}

	/**
	 * Marks the order sent and removes its pending job, as a successful push would.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function sync_order_sent( \WC_Order $order ): void {
		as_unschedule_all_actions( Order_Sync::HOOK );
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_tassyir_status', 'sent' );
		$order->save_meta_data();
	}
}
