<?php
/**
 * Leftover manual webhooks after an upgrade (§B3 manualWebhooks).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Connection;
use Tassyir\WooCommerce\Manual_Webhooks;

final class ManualWebhooksTest extends Integration_TestCase {

	/**
	 * A saved WooCommerce webhook.
	 *
	 * @param string $url Delivery URL.
	 */
	private function webhook( string $url ): int {
		$webhook = new \WC_Webhook();
		$webhook->set_name( 'Hook ' . $url );
		$webhook->set_topic( 'order.created' );
		$webhook->set_delivery_url( $url );
		$webhook->set_status( 'active' );
		$webhook->set_user_id( 1 );
		return $webhook->save();
	}

	public function test_finds_counts_and_deletes_only_ours(): void {
		$connection = new Connection();
		$connection->set( array( 'webhookPrefix' => 'https://api.tassyir.io/woocommerce-webhook/77/' ) );
		$ours  = $this->webhook( 'https://api.tassyir.io/woocommerce-webhook/77/token-a' );
		$ours2 = $this->webhook( 'https://api.tassyir.io/woocommerce-webhook/77/token-b' );
		$other = $this->webhook( 'https://hooks.example.com/x' );

		$found = array_map(
			static function ( \WC_Webhook $webhook ) {
				return $webhook->get_id();
			},
			Manual_Webhooks::find( (string) $connection->get( 'webhookPrefix' ) )
		);
		sort( $found );
		$this->assertSame( array( $ours, $ours2 ), $found );
		$this->assertSame( 2, Manual_Webhooks::refresh_count( $connection ) );
		$this->assertSame( 2, $connection->get( 'manualWebhooksLeft' ) );

		$this->assertSame( 2, Manual_Webhooks::delete_all( $connection ) );
		$this->assertSame( 0, $connection->get( 'manualWebhooksLeft' ) );
		$this->assertInstanceOf( \WC_Webhook::class, wc_get_webhook( $other ) );
		$this->assertSame( array(), Manual_Webhooks::find( '' ) );
	}
}
