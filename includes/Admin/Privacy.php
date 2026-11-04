<?php
/**
 * Suggested text for the site's privacy policy (Settings → Privacy → Policy Guide).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The same facts as readme.txt's "External services" section, for the shop's own policy.
 */
final class Privacy {

	/**
	 * Hooks the suggested text.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	/**
	 * Adds the suggested text to the Privacy Policy Guide.
	 */
	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'Tassyir for WooCommerce', wp_kses_post( wpautop( self::text(), false ) ) );
	}

	/**
	 * The suggested text (translatable).
	 */
	public static function text(): string {
		$paragraphs = array(
			__( 'This store sends its orders and products to Tassyir (https://tassyir.io), the service the store uses to confirm and deliver orders. Nothing is sent before the store owner connects the store to Tassyir.', 'tassyir-for-woocommerce' ),
			__( 'When you place an order, these details are sent to Tassyir: your name, phone number, email address, billing and shipping addresses, the products ordered, the totals, the payment method and your order note. They are sent again when the order status changes.', 'tassyir-for-woocommerce' ),
			__( 'Tassyir also receives the store’s products and product photos, and technical information about the store (software versions and the size of the sending queue).', 'tassyir-for-woocommerce' ),
			__( 'When you order with the store’s cash-on-delivery form, Tassyir also receives your IP address, your browser’s user agent and a random device identifier stored in your browser (local storage and a cookie named tsy_device_id), used to price the order and protect the store against fake orders.', 'tassyir-for-woocommerce' ),
			__( 'The store also remembers, in your browser (local storage, or a cookie named tsy_attr when storage is blocked) for 30 days, the advertising campaign you arrived with (utm_* parameters, campaign and ad ids, and the name of the ad network). If you order with the cash-on-delivery form, this is sent to Tassyir so the order can be attributed to its campaign. Ad click identifiers are never stored or sent.', 'tassyir-for-woocommerce' ),
			/* translators: %s: URL of Tassyir's privacy policy. */
			sprintf( __( 'Tassyir’s privacy policy: %s', 'tassyir-for-woocommerce' ), 'https://tassyir.io/en/privacy-policy' ),
		);
		return implode( "\n\n", $paragraphs );
	}
}
