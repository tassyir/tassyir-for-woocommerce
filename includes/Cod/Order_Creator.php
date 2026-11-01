<?php
/**
 * Creates the WooCommerce order of a COD form submission Tassyir allowed (plan §4.2 step 3).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Decision D1: the form creates a normal WooCommerce order (stock, emails, reports and other
 * plugins keep working), which then reaches Tassyir through Phase 1's order push like any other.
 * Prices are the ones Tassyir's `submit` returned; the exact codes go in `_tassyir_*` meta, which
 * the backend importer reads first.
 */
final class Order_Creator {

	const META_QUOTE_ID      = '_tassyir_quote_id';
	const META_PROVINCE_CODE = '_tassyir_province_code';
	const META_TOWN_ONS      = '_tassyir_town_ons';
	const META_STOPDESK      = '_tassyir_stopdesk';
	const META_DEVICE_ID     = '_tassyir_device_id';
	const META_CLIENT_IP     = '_tassyir_client_ip';
	const META_SUBMISSION_ID = '_tassyir_submission_id';
	const META_VERDICT       = '_tassyir_verdict';
	const META_PHONE2        = '_tassyir_phone2';
	const META_CUSTOM_FIELDS = '_tassyir_custom_fields';

	const CREATED_VIA = 'tassyir_cod_form';

	/**
	 * The order already created for a submission id (a double click, a retried request).
	 *
	 * @param string $submission_id Form submission id.
	 */
	public static function find_existing( string $submission_id ): ?\WC_Order {
		$args = array(
			'type'   => 'shop_order',
			'limit'  => 1,
			'status' => 'any',
			'return' => 'objects',
		);
		// HPOS understands meta_query; the legacy posts store gets it from Order_Sync::cpt_query_vars().
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per form submit.
				array(
					'key'   => self::META_SUBMISSION_ID,
					'value' => $submission_id,
				),
			);
		} else {
			$args['tassyir_submission_id'] = $submission_id;
		}
		$orders = wc_get_orders( $args );
		return isset( $orders[0] ) && $orders[0] instanceof \WC_Order ? $orders[0] : null;
	}

	/**
	 * Creates the order.
	 *
	 * Contract §H6 "allow": one line per answer line at its total, one shipping line of
	 * `shippingPrice`, total = `total`, billing name / phone / DZ-xx state / commune / address /
	 * note from the answer and the form, then the `_tassyir_*` meta.
	 *
	 * @param array<string, mixed> $submission The checked form submission (Rest::clean_submission()).
	 * @param array<string, mixed> $answer     Tassyir's submit answer (verdict allow or flag).
	 * @param array<string, mixed> $context    lang (form language), ip, user_agent.
	 * @return \WC_Order
	 * @throws \RuntimeException When an ordered product no longer exists.
	 */
	public static function create( array $submission, array $answer, array $context ): \WC_Order {
		$order = self::build( $submission, $answer, (string) $context['lang'] );
		$order->set_created_via( self::CREATED_VIA );
		$order->set_customer_id( get_current_user_id() );
		$order->set_customer_ip_address( (string) $context['ip'] );
		$order->set_customer_user_agent( (string) ( $context['user_agent'] ?? '' ) );

		$destination = isset( $answer['destination'] ) && is_array( $answer['destination'] ) ? $answer['destination'] : array();
		$order->update_meta_data( self::META_QUOTE_ID, (string) ( $answer['quoteId'] ?? '' ) );
		$order->update_meta_data( self::META_PROVINCE_CODE, (string) (int) ( $destination['provinceCode'] ?? $submission['provinceCode'] ) );
		$order->update_meta_data( self::META_TOWN_ONS, (string) (int) ( $destination['townOns'] ?? $submission['townOns'] ) );
		$order->update_meta_data( self::META_STOPDESK, self::stopdesk( $submission, $answer ) ? '1' : '0' );
		$order->update_meta_data( self::META_DEVICE_ID, (string) ( $submission['deviceId'] ?? '' ) );
		$order->update_meta_data( self::META_CLIENT_IP, (string) $context['ip'] );
		$order->update_meta_data( self::META_SUBMISSION_ID, (string) $submission['submissionId'] );
		$order->update_meta_data( self::META_VERDICT, (string) ( $answer['verdict'] ?? 'allow' ) );
		if ( '' !== (string) ( $submission['phone2'] ?? '' ) ) {
			$order->update_meta_data( self::META_PHONE2, (string) $submission['phone2'] );
		}
		if ( ! empty( $submission['customFields'] ) ) {
			$order->update_meta_data( self::META_CUSTOM_FIELDS, (string) wp_json_encode( $submission['customFields'] ) );
		}

		$order->calculate_totals( false ); // Saves the order: the first save, so `woocommerce_new_order` sees it whole.
		// §H6: the order total is Tassyir's `total` (the lines and shipping already add up to it).
		if ( isset( $answer['total'] ) && abs( (float) $order->get_total() - (float) $answer['total'] ) > 0.005 ) {
			$order->set_total( Format::amount( $answer['total'] ) );
		}
		$order->save();

		$notes = array();
		if ( 'flag' === ( $answer['verdict'] ?? '' ) ) {
			$notes[] = __( 'Tassyir flagged this order as suspicious: check it before shipping.', 'tassyir-for-woocommerce' );
		}
		if ( '' !== (string) ( $submission['phone2'] ?? '' ) ) {
			/* translators: %s: second phone number. */
			$notes[] = sprintf( __( 'Second phone: %s', 'tassyir-for-woocommerce' ), (string) $submission['phone2'] );
		}
		foreach ( $notes as $note ) {
			$order->add_order_note( $note );
		}

		// The status WooCommerce's own COD gateway gives (processing, or on-hold for downloads);
		// the status change sends the usual emails and reduces stock.
		$status = apply_filters( 'woocommerce_cod_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		$order->update_status( (string) $status, __( 'Order placed with the Tassyir COD form.', 'tassyir-for-woocommerce' ) );
		return $order;
	}

	/**
	 * The order §H6 describes, in memory only: lines, shipping, addresses, payment method, note and
	 * totals. create() saves it; Receipt shows a held submission with it and never saves it (so
	 * nothing here may save: no calculate_totals(), which does).
	 *
	 * @param array<string, mixed> $submission The checked form submission.
	 * @param array<string, mixed> $answer     Tassyir's submit answer.
	 * @param string               $lang       Form language (shipping title translation).
	 * @throws \RuntimeException When an ordered product no longer exists.
	 */
	public static function build( array $submission, array $answer, string $lang ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );

		$subtotal = 0.0;
		foreach ( self::lines( $submission, $answer ) as $line ) {
			$order->add_item( self::product_item( $line ) );
			$subtotal += (float) $line['total'];
		}

		$destination = isset( $answer['destination'] ) && is_array( $answer['destination'] ) ? $answer['destination'] : array();
		$stopdesk    = self::stopdesk( $submission, $answer );
		$province    = (int) ( $destination['provinceCode'] ?? $submission['provinceCode'] );
		$texts       = Settings::texts( Settings::get(), $lang );
		$label       = $stopdesk ? $texts['stopdesk_label'] : $texts['home_label'];
		$title       = $stopdesk ? 'Stop desk' : 'Home delivery';
		$shipping    = new \WC_Order_Item_Shipping();
		// §H6: "Stop desk" / "Home delivery", a translation may follow.
		$shipping->set_method_title( 'en' === $lang ? $title : $title . ' - ' . $label );
		$shipping->set_method_id( 'tassyir_' . ( $stopdesk ? 'stopdesk' : 'home' ) );
		$shipping->set_total( Format::amount( $answer['shippingPrice'] ?? 0 ) );
		$order->add_item( $shipping );
		$order->set_shipping_total( Format::amount( $answer['shippingPrice'] ?? 0 ) );

		$phone   = (string) ( $answer['phone'] ?? '' );
		$address = array(
			'first_name' => (string) $submission['name'],
			'last_name'  => '',
			'phone'      => '' !== $phone ? $phone : (string) $submission['phone'],
			'address_1'  => (string) ( $submission['address'] ?? '' ),
			'city'       => (string) ( $destination['townName'] ?? '' ),
			'state'      => Format::state_code( $province ),
			'country'    => 'DZ',
		);
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );

		$gateways = WC()->payment_gateways()->payment_gateways();
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( isset( $gateways['cod'] ) ? $gateways['cod']->get_title() : __( 'Cash on delivery', 'tassyir-for-woocommerce' ) );
		$order->set_customer_note( (string) ( $submission['note'] ?? '' ) );
		$order->set_total( Format::amount( $answer['total'] ?? $subtotal + (float) ( $answer['shippingPrice'] ?? 0 ) ) );
		return $order;
	}

	/**
	 * Delivery to a stop desk: Tassyir's answer, else the form.
	 *
	 * @param array<string, mixed> $submission The checked form submission.
	 * @param array<string, mixed> $answer     Tassyir's submit answer.
	 */
	private static function stopdesk( array $submission, array $answer ): bool {
		$destination = isset( $answer['destination'] ) && is_array( $answer['destination'] ) ? $answer['destination'] : array();
		return isset( $destination['stopdesk'] ) ? (bool) $destination['stopdesk'] : ! empty( $submission['stopdesk'] );
	}

	/**
	 * The lines to book. §H6: one per answer line (that product / variation, quantity, total),
	 * restricted to the products the customer submitted. Without answer lines, the form's items
	 * at the WooCommerce price (an order is never lost for a missing line).
	 *
	 * @param array<string, mixed> $submission Checked submission.
	 * @param array<string, mixed> $answer     Submit answer.
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException When a product no longer exists or was not submitted.
	 */
	public static function lines( array $submission, array $answer ): array {
		$submitted = array();
		foreach ( (array) $submission['items'] as $item ) {
			$submitted[ (int) $item['wooProductId'] . ':' . (int) ( $item['wooVariationId'] ?? 0 ) ] = $item;
		}

		$out    = array();
		$priced = isset( $answer['lines'] ) && is_array( $answer['lines'] ) ? array_filter( $answer['lines'], 'is_array' ) : array();
		foreach ( $priced as $line ) {
			$key = (int) ( $line['wooProductId'] ?? 0 ) . ':' . (int) ( $line['wooVariationId'] ?? 0 );
			if ( ! isset( $submitted[ $key ] ) ) {
				throw new \RuntimeException( 'LINE_NOT_SUBMITTED' );
			}
			$out[] = self::line( $submitted[ $key ], (int) ( $line['quantity'] ?? 0 ), isset( $line['total'] ) ? (float) $line['total'] : null, (string) ( $line['bundleId'] ?? '' ) );
		}
		if ( array() === $out ) {
			foreach ( $submitted as $item ) {
				$out[] = self::line( $item, (int) $item['quantity'], null, (string) ( $item['bundleId'] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * One line to book.
	 *
	 * @param array<string, mixed> $item     Submitted item (its product, variation and attributes).
	 * @param int                  $quantity Quantity.
	 * @param float|null           $total    Line total (null: WooCommerce price × quantity).
	 * @param string               $bundle   Tassyir offer id.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the product no longer exists.
	 */
	private static function line( array $item, int $quantity, ?float $total, string $bundle ): array {
		$id      = (int) ( $item['wooVariationId'] ?? 0 ) > 0 ? (int) $item['wooVariationId'] : (int) $item['wooProductId'];
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product || $quantity < 1 ) {
			throw new \RuntimeException( 'PRODUCT_GONE' );
		}
		$total = null === $total ? (float) $product->get_price() * $quantity : $total;
		return array(
			'product'    => $product,
			'quantity'   => $quantity,
			'total'      => $total,
			'subtotal'   => $total,
			'attributes' => isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array(),
			'bundleId'   => $bundle,
		);
	}

	/**
	 * A product line item at the given price.
	 *
	 * @param array<string, mixed> $line From lines().
	 */
	private static function product_item( array $line ): \WC_Order_Item_Product {
		/** @var \WC_Product $product */
		$product = $line['product'];
		$item    = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( (int) $line['quantity'] );
		$item->set_subtotal( Format::amount( max( (float) $line['subtotal'], (float) $line['total'] ) ) );
		$item->set_total( Format::amount( $line['total'] ) );

		// Attributes a variation leaves open ("Any size"): the value the customer picked.
		if ( $product instanceof \WC_Product_Variation ) {
			foreach ( $product->get_variation_attributes() as $key => $value ) {
				$name   = str_replace( 'attribute_', '', (string) $key );
				$picked = isset( $line['attributes'][ $key ] ) ? (string) $line['attributes'][ $key ] : '';
				if ( '' === (string) $value && '' !== $picked ) {
					// A taxonomy attribute keeps the term slug, a custom one its text (as the cart does).
					$item->add_meta_data( $name, taxonomy_exists( $name ) ? sanitize_title( $picked ) : $picked, true );
				}
			}
		}
		if ( '' !== $line['bundleId'] ) {
			$item->add_meta_data( '_tassyir_bundle_id', (string) $line['bundleId'], true );
		}
		return $item;
	}
}
