<?php
/**
 * Renders orders and products exactly as the WooCommerce REST API v3 does (context=edit).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Tassyir's backend already maps REST v3 JSON (mapWooOrder, product sync), so we send that exact
 * shape, produced by WooCommerce's own REST controllers, without any HTTP request.
 */
final class Rest_Renderer {

	/**
	 * REST v3 order JSON (what GET /wp-json/wc/v3/orders/<id>?context=edit returns, minus _links).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function order( \WC_Order $order ): array {
		$controller = new \WC_REST_Orders_Controller();
		$response   = $controller->prepare_object_for_response( $order, self::request( '/wc/v3/orders/' . $order->get_id(), $order->get_id() ) );
		return self::to_array( $response );
	}

	/**
	 * Adds `state_name` to the billing and shipping addresses (§C): WooCommerce's label for the
	 * state code, e.g. "Alger" for DZ-16, so Tassyir can save the wilaya the customer saw.
	 * Only when the country has a non-empty label for that code; everything else is untouched.
	 *
	 * @param array<string, mixed> $order Rendered order (Rest_Renderer::order()).
	 * @return array<string, mixed>
	 */
	public static function with_state_names( array $order ): array {
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! isset( $order[ $type ] ) || ! is_array( $order[ $type ] ) ) {
				continue;
			}
			$name = self::state_name( (string) ( $order[ $type ]['country'] ?? '' ), (string) ( $order[ $type ]['state'] ?? '' ) );
			if ( '' !== $name ) {
				$order[ $type ]['state_name'] = $name;
			}
		}
		return $order;
	}

	/**
	 * WooCommerce's display label of a state code ('' when it has none).
	 *
	 * @param string $country Country code, e.g. DZ.
	 * @param string $state   State code, e.g. DZ-16.
	 */
	private static function state_name( string $country, string $state ): string {
		if ( '' === $country || '' === $state || ! function_exists( 'WC' ) ) {
			return '';
		}
		$states = WC()->countries->get_states( $country );
		$name   = is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : '';
		return is_string( $name ) ? trim( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) : '';
	}

	/**
	 * REST v3 product JSON.
	 *
	 * @param \WC_Product $product Product (not a variation).
	 * @return array<string, mixed>
	 */
	public static function product( \WC_Product $product ): array {
		$controller = new \WC_REST_Products_Controller();
		$response   = $controller->prepare_object_for_response( $product, self::request( '/wc/v3/products/' . $product->get_id(), $product->get_id() ) );
		return self::to_array( $response );
	}

	/**
	 * REST v3 variation JSON.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array<string, mixed>
	 */
	public static function variation( \WC_Product_Variation $variation ): array {
		$controller = new \WC_REST_Product_Variations_Controller();
		$route      = '/wc/v3/products/' . $variation->get_parent_id() . '/variations/' . $variation->get_id();
		$response   = $controller->prepare_object_for_response( $variation, self::request( $route, $variation->get_id() ) );
		return self::to_array( $response );
	}

	/**
	 * A GET request with context=edit, as the REST server would build it.
	 *
	 * @param string $route Route the request pretends to be.
	 * @param int    $id    Object id.
	 */
	private static function request( string $route, int $id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'id', $id );
		return $request;
	}

	/**
	 * The response data as plain arrays (WC_Meta_Data, WC_DateTime… serialised exactly as the
	 * REST server's JSON output would).
	 *
	 * @param mixed $response Return value of prepare_object_for_response().
	 * @return array<string, mixed>
	 */
	private static function to_array( $response ): array {
		if ( ! $response instanceof \WP_REST_Response ) {
			return array();
		}
		$data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		return is_array( $data ) ? $data : array();
	}
}
