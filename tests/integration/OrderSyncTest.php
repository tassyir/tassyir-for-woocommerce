<?php
/**
 * Order hooks, the push job and every answer of §C.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Admin\Order_Admin;
use Tassyir\WooCommerce\Failure_Log;
use Tassyir\WooCommerce\Order_Sync;
use Tassyir\WooCommerce\Plugin;

final class OrderSyncTest extends Integration_TestCase {

	/**
	 * Order sync under test.
	 *
	 * @var Order_Sync
	 */
	private $sync;

	public function set_up(): void {
		parent::set_up();
		$this->sync = Plugin::instance()->orders;
		Failure_Log::clear();
	}

	public function test_new_order_is_queued_once(): void {
		$order = $this->order(); // Fires woocommerce_new_order and status_changed.
		$jobs  = $this->pending( Order_Sync::HOOK );
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( $order->get_id() ), $jobs[0]['args'] );
		$this->assertSame( 'queued', $this->sync->state( $order ) );
	}

	public function test_drafts_and_disconnected_sites_are_not_queued(): void {
		// Like the block checkout: the order is created as a draft.
		$draft = new \WC_Order();
		$draft->set_status( 'checkout-draft' );
		$draft->save();
		$this->assertFalse( $this->sync->enqueue( $draft ) );
		$this->assertCount( 0, $this->pending( Order_Sync::HOOK ) );

		delete_option( 'tassyir_secret' );
		$order = wc_create_order();
		$this->assertFalse( $this->sync->enqueue( $order ) );
		$this->assertCount( 0, $this->pending( Order_Sync::HOOK ) );
	}

	public function test_push_sends_signed_rest_json_and_stores_created(): void {
		$order = $this->order();
		$this->answer(
			200,
			array(
				'status'         => 'created',
				'tassyirOrderId' => '9001',
				'orderNumber'    => '#00000123',
				'reason'         => null,
				'retry'          => false,
			)
		);

		$this->sync->push( $order->get_id() );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.tassyir.test/woo-plugin/orders', $this->requests[0]['url'] );
		$this->assertSame( 20, $this->requests[0]['args']['timeout'] );
		$this->assertSigned();
		$body = $this->sent_body();
		$this->assertSame( 'plugin', $body['channel'] );
		$this->assertSame( 1, $body['attempt'] );
		$this->assertSame( $order->get_id(), $body['order']['id'] );
		$this->assertSame( 'DZ-16', $body['order']['billing']['state'] );
		$this->assertSame( WC()->countries->get_states( 'DZ' )['DZ-16'], $body['order']['billing']['state_name'] );
		$this->assertArrayNotHasKey( 'state_name', $body['order']['shipping'], 'no shipping state, no label' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'sent', $order->get_meta( '_tassyir_status' ) );
		$this->assertSame( '9001', $order->get_meta( '_tassyir_order_id' ) );
		$this->assertSame( '#00000123', $order->get_meta( '_tassyir_order_number' ) );
		$this->assertSame( '1', (string) $order->get_meta( '_tassyir_attempts' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $order->get_meta( '_tassyir_sent_at' ) );
	}

	public function test_dashboard_link_uses_business_and_locale(): void {
		$order = $this->order();
		$order->update_meta_data( '_tassyir_order_id', '100000000000000123' );
		$order->save_meta_data();
		$admin = new Order_Admin( $this->sync, Plugin::instance()->connection );

		$this->assertSame( '', $admin->tassyir_order_url( $order ), 'no business id yet' );
		Plugin::instance()->connection->set(
			array(
				'business' => array(
					'id'   => '100000000000000001',
					'name' => 'Shop',
				),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'locale' => 'fr_FR' ) ) );
		$this->assertSame( 'https://app.tassyir.io/ar/dashboard/100000000000000001/orders/100000000000000123', $admin->tassyir_order_url( $order ) );

		wp_set_current_user( self::factory()->user->create( array( 'locale' => 'en_US' ) ) );
		$this->assertStringStartsWith( 'https://app.tassyir.io/en/dashboard/', $admin->tassyir_order_url( $order ) );
	}

	public function test_skipped_and_final_failed_answers(): void {
		$order = $this->order();
		$this->answer(
			200,
			array(
				'status' => 'skipped',
				'reason' => 'STATUS_NOT_IMPORTED',
				'retry'  => false,
			)
		);
		$this->sync->push( $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'skipped', $order->get_meta( '_tassyir_status' ) );
		$this->assertSame( 'STATUS_NOT_IMPORTED', $order->get_meta( '_tassyir_reason' ) );

		$this->answer(
			200,
			array(
				'status' => 'failed',
				'reason' => 'PRODUCT_UNMAPPED',
				'retry'  => false,
			)
		);
		$this->sync->push( $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_meta( '_tassyir_status' ) );
		$this->assertSame( 'PRODUCT_UNMAPPED', Failure_Log::all()[0]['code'] );
		$this->assertSame( 1, $this->sync->failed_count() );
	}

	public function test_transient_failures_follow_the_backoff(): void {
		$order = $this->order();
		as_unschedule_all_actions( Order_Sync::HOOK );

		$this->answer(
			200,
			array(
				'status' => 'failed',
				'reason' => 'DB_BUSY',
				'retry'  => true,
			)
		);
		$this->sync->push( $order->get_id() );
		$jobs = $this->pending( Order_Sync::HOOK );
		$this->assertCount( 1, $jobs );
		$this->assertEqualsWithDelta( time() + 60, $jobs[0]['at'], 3 );
		$this->assertSame( 'queued', wc_get_order( $order->get_id() )->get_meta( '_tassyir_status' ) );

		as_unschedule_all_actions( Order_Sync::HOOK );
		$this->answer( 503, array( 'code' => 'SERVICE_UNAVAILABLE' ) );
		$this->sync->push( $order->get_id() );
		$jobs = $this->pending( Order_Sync::HOOK );
		$this->assertEqualsWithDelta( time() + 300, $jobs[0]['at'], 3 );
		$this->assertSame( 2, $this->sent_body( 1 )['attempt'] );

		as_unschedule_all_actions( Order_Sync::HOOK );
		$this->responses[] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->sync->push( $order->get_id() );
		$jobs = $this->pending( Order_Sync::HOOK );
		$this->assertEqualsWithDelta( time() + 900, $jobs[0]['at'], 3 );
		$this->assertSame( 'NETWORK_ERROR', wc_get_order( $order->get_id() )->get_meta( '_tassyir_reason' ) );
	}

	public function test_lock_and_rate_limit_wait_without_counting_a_failure(): void {
		$order = $this->order();
		as_unschedule_all_actions( Order_Sync::HOOK );

		$this->answer(
			202,
			array(
				'status' => 'queued',
				'retry'  => true,
			)
		);
		$this->sync->push( $order->get_id() );
		$this->assertEqualsWithDelta( time() + 30, $this->pending( Order_Sync::HOOK )[0]['at'], 3 );

		as_unschedule_all_actions( Order_Sync::HOOK );
		$this->answer( 429, array( 'code' => 'RATE_LIMITED' ), array( 'retry-after' => '120' ) );
		$this->sync->push( $order->get_id() );
		$this->assertEqualsWithDelta( time() + 120, $this->pending( Order_Sync::HOOK )[0]['at'], 3 );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_tassyir_failures' ) );
	}

	public function test_invalid_order_is_final_and_three_days_end_retries(): void {
		$order = $this->order();
		as_unschedule_all_actions( Order_Sync::HOOK );
		$this->answer( 400, array( 'code' => 'WOO_PLUGIN_ORDER_INVALID' ) );
		$this->sync->push( $order->get_id() );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_meta( '_tassyir_status' ) );
		$this->assertCount( 0, $this->pending( Order_Sync::HOOK ) );

		// A cycle that started 3 days ago: the next failure is the last one.
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_tassyir_first_try_at', time() - 3 * DAY_IN_SECONDS + 30 );
		$order->update_meta_data( '_tassyir_failures', 9 );
		$order->save_meta_data();
		$this->answer( 500 );
		$this->sync->push( $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $order->get_meta( '_tassyir_status' ) );
		$this->assertSame( 'HTTP_500', $order->get_meta( '_tassyir_reason' ) );
		$this->assertCount( 0, $this->pending( Order_Sync::HOOK ) );
	}

	public function test_resend_replaces_pending_job_and_sets_channel(): void {
		$order = $this->order();
		$this->assertTrue( $this->sync->resend( $order->get_id() ) );
		$this->assertCount( 1, $this->pending( Order_Sync::HOOK ) );

		$this->answer(
			200,
			array(
				'status'         => 'duplicate',
				'tassyirOrderId' => '1',
				'orderNumber'    => '#1',
				'retry'          => false,
			)
		);
		$this->sync->push( $order->get_id() );
		$this->assertSame( 'plugin_resend', $this->sent_body()['channel'] );
		$this->assertSame( 'sent', wc_get_order( $order->get_id() )->get_meta( '_tassyir_status' ) );

		// A sent order is not queued again by a status change.
		as_unschedule_all_actions( Order_Sync::HOOK ); // The job resend() scheduled (we ran push() by hand).
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'on-hold' );
		$order->save();
		$this->assertCount( 0, $this->pending( Order_Sync::HOOK ) );
	}

	public function test_backfill_queues_unsent_orders_with_channel(): void {
		$sent  = $this->order();
		$other = $this->order();
		as_unschedule_all_actions( Order_Sync::HOOK );
		$sent->update_meta_data( '_tassyir_status', 'sent' );
		$sent->save_meta_data();

		$since = $this->sync->start_backfill( 0 );
		$this->assertEqualsWithDelta( time() - 90 * DAY_IN_SECONDS, $since, 5 );
		$this->sync->backfill_batch( $since, 1 );

		$ids = wp_list_pluck( $this->pending( Order_Sync::HOOK ), 'args' );
		$this->assertSame( array( array( $other->get_id() ) ), $ids );
		$this->assertSame( 'plugin_backfill', wc_get_order( $other->get_id() )->get_meta( '_tassyir_channel' ) );
	}
}
