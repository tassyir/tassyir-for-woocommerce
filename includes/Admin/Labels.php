<?php
/**
 * Human text for codes (reasons, errors, states). Tassyir's own messages are never shown.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

use Tassyir\WooCommerce\Api_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Translated labels for every code the plugin can display.
 */
final class Labels {

	/**
	 * Text for a reason or error code; unknown codes are shown as they are.
	 *
	 * @param string $code Code.
	 */
	public static function reason( string $code ): string {
		$labels = array(
			// Order import reasons (§C).
			'STATUS_NOT_IMPORTED'            => __( 'Order status is not imported (see your Tassyir settings)', 'tassyir-for-woocommerce' ),
			'BEFORE_IMPORT_FROM'             => __( 'Order is older than the connection', 'tassyir-for-woocommerce' ),
			'PRODUCT_UNMAPPED'               => __( 'A product is not linked to a Tassyir product', 'tassyir-for-woocommerce' ),
			'NO_LINES'                       => __( 'Order has no products', 'tassyir-for-woocommerce' ),
			'NO_ACTIVE_STORE'                => __( 'No active store in Tassyir', 'tassyir-for-woocommerce' ),
			'ORDER_LIMIT_REACHED'            => __( 'Tassyir order limit reached', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_ORDER_INVALID'       => __( 'Tassyir could not read this order', 'tassyir-for-woocommerce' ),
			'LOCKED'                         => __( 'Tassyir is already processing this order', 'tassyir-for-woocommerce' ),
			'RATE_LIMITED'                   => __( 'Waiting: too many requests', 'tassyir-for-woocommerce' ),
			'RETRY'                          => __( 'Temporary error in Tassyir, will retry', 'tassyir-for-woocommerce' ),
			'FAILED'                         => __( 'Tassyir could not import it', 'tassyir-for-woocommerce' ),
			'RENDER_ERROR'                   => __( 'The order could not be read in WooCommerce', 'tassyir-for-woocommerce' ),
			// Connection and transport.
			Api_Response::NETWORK_ERROR      => __( 'Could not reach Tassyir (network or host firewall)', 'tassyir-for-woocommerce' ),
			Api_Response::CLOCK_SKEW         => __( 'Your server clock is wrong, so Tassyir refuses the requests', 'tassyir-for-woocommerce' ),
			'NOT_CONNECTED'                  => __( 'Not connected to Tassyir', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_SIGNATURE_INVALID'   => __( 'Tassyir refused the connection: please reconnect', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_NOT_PLUGIN_MODE'     => __( 'This site is connected to Tassyir another way: please reconnect', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_VERSION_UNSUPPORTED' => __( 'This plugin version is too old: please update it', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_UNAVAILABLE'         => __( 'Tassyir is temporarily unavailable, will retry', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_BODY_INVALID'        => __( 'Tassyir could not read the data sent', 'tassyir-for-woocommerce' ),
			'HTTP_413'                       => __( 'Too large for Tassyir', 'tassyir-for-woocommerce' ),
			'PAYLOAD_TOO_LARGE'              => __( 'Too large for Tassyir', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_RATE_LIMITED'        => __( 'Waiting: too many requests', 'tassyir-for-woocommerce' ),
			'WOO_SITE_ALREADY_LINKED'        => __( 'This site is already linked to another Tassyir business. Disconnect it there first, or contact Tassyir support.', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_CODE_INVALID'        => __( 'The connection link is invalid or was already used. Please try again.', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_CODE_EXPIRED'        => __( 'The connection link expired. Please try again.', 'tassyir-for-woocommerce' ),
			'STATE_INVALID'                  => __( 'The connection attempt could not be verified (it expired or was started by another user). Please try again.', 'tassyir-for-woocommerce' ),
			'CANCELLED'                      => __( 'Connection cancelled.', 'tassyir-for-woocommerce' ),
			'HOST_MISMATCH'                  => __( 'Your site address and your WordPress admin address differ (domain, http/https or port), so Tassyir cannot connect. Make them match (Settings → General) and try again.', 'tassyir-for-woocommerce' ),
			'EXCHANGE_INVALID'               => __( 'Tassyir sent an unexpected answer. Please try again.', 'tassyir-for-woocommerce' ),
			// Products and images (§D).
			'WOO_PLUGIN_IMAGE_INVALID'       => __( 'Tassyir refused the image (type or size)', 'tassyir-for-woocommerce' ),
			'WOO_PLUGIN_PRODUCT_UNKNOWN'     => __( 'The product is not linked in Tassyir', 'tassyir-for-woocommerce' ),
			'IMAGE_TYPE_UNSUPPORTED'         => __( 'Image type not supported (JPEG, PNG, WebP or GIF only)', 'tassyir-for-woocommerce' ),
			'IMAGE_TOO_LARGE'                => __( 'Image larger than 10 MB', 'tassyir-for-woocommerce' ),
			'IMAGE_FILE_MISSING'             => __( 'Image file missing on the server', 'tassyir-for-woocommerce' ),
			'IMAGE_FILE_UNREADABLE'          => __( 'Image file could not be read', 'tassyir-for-woocommerce' ),
		);

		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		if ( preg_match( '/^HTTP_(\d{3})$/', $code, $match ) ) {
			/* translators: %s: HTTP status code, e.g. 503. */
			return sprintf( __( 'Tassyir error (HTTP %s)', 'tassyir-for-woocommerce' ), $match[1] );
		}
		return $code;
	}

	/**
	 * Text for an order's Tassyir state.
	 *
	 * @param string $state queued | sent | skipped | failed | none.
	 */
	public static function state( string $state ): string {
		switch ( $state ) {
			case 'queued':
				return __( 'Queued', 'tassyir-for-woocommerce' );
			case 'sent':
				return __( 'Sent', 'tassyir-for-woocommerce' );
			case 'skipped':
				return __( 'Skipped', 'tassyir-for-woocommerce' );
			case 'failed':
				return __( 'Failed', 'tassyir-for-woocommerce' );
			default:
				return __( 'Not sent', 'tassyir-for-woocommerce' );
		}
	}

	/**
	 * Text for a failure-log type.
	 *
	 * @param string $type order | product | product_sync | image | heartbeat | connect.
	 */
	public static function type( string $type ): string {
		switch ( $type ) {
			case 'order':
				return __( 'Order', 'tassyir-for-woocommerce' );
			case 'product':
			case 'product_sync':
				return __( 'Product', 'tassyir-for-woocommerce' );
			case 'image':
				return __( 'Image', 'tassyir-for-woocommerce' );
			case 'heartbeat':
				return __( 'Status check', 'tassyir-for-woocommerce' );
			default:
				return __( 'Connection', 'tassyir-for-woocommerce' );
		}
	}
}
