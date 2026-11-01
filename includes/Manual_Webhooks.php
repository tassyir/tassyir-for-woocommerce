<?php
/**
 * The merchant's manual "Tassyir orders" WooCommerce webhooks, left over from webhook-only mode.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * After an upgrade (connect/exchange answered `upgradedFrom`), Tassyir gives a delivery URL
 * prefix; every WooCommerce webhook whose delivery URL starts with it is ours and now redundant.
 */
final class Manual_Webhooks {

	/**
	 * WooCommerce webhooks whose delivery URL starts with $prefix.
	 *
	 * @param string $prefix Delivery URL prefix ('' finds nothing).
	 * @return \WC_Webhook[]
	 */
	public static function find( string $prefix ): array {
		if ( '' === $prefix ) {
			return array();
		}
		/** @var \WC_Webhook_Data_Store $store WC_Data_Store forwards the call to the webhook store. */
		$store = \WC_Data_Store::load( 'webhook' );
		$ids   = $store->search_webhooks( array( 'limit' => -1 ) );
		$found = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$webhook = wc_get_webhook( (int) $id );
			if ( $webhook instanceof \WC_Webhook && 0 === strpos( $webhook->get_delivery_url(), $prefix ) ) {
				$found[] = $webhook;
			}
		}
		return $found;
	}

	/**
	 * Counts them and remembers the count (read by admin notices and the heartbeat).
	 *
	 * @param Connection $connection Stored connection.
	 */
	public static function refresh_count( Connection $connection ): int {
		$count = count( self::find( (string) $connection->get( 'webhookPrefix', '' ) ) );
		$connection->set( array( 'manualWebhooksLeft' => $count ) );
		return $count;
	}

	/**
	 * Deletes them for good (the admin clicked "Delete them").
	 *
	 * @param Connection $connection Stored connection.
	 * @return int Number deleted.
	 */
	public static function delete_all( Connection $connection ): int {
		$deleted = 0;
		foreach ( self::find( (string) $connection->get( 'webhookPrefix', '' ) ) as $webhook ) {
			if ( $webhook->delete( true ) ) {
				++$deleted;
			}
		}
		self::refresh_count( $connection );
		return $deleted;
	}
}
