<?php
/**
 * The order payload is exactly what the WooCommerce REST API v3 returns with context=edit.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Rest_Renderer;

final class RenderingTest extends Integration_TestCase {

	/**
	 * GET /wc/v3/<route>?context=edit through the real REST server, as an admin, without _links.
	 *
	 * @param string $route Route.
	 * @return array<string, mixed>
	 */
	private function rest_get( string $route ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'context', 'edit' );
		$data = rest_get_server()->response_to_data( rest_do_request( $request ), false );
		unset( $data['_links'] );
		return json_decode( (string) wp_json_encode( $data ), true );
	}

	public function test_order_matches_rest_api_v3_edit_context(): void {
		$order = $this->order();
		$order->update_meta_data( '_billing_wilaya', 'Alger' );
		$order->save();
		$rendered = Rest_Renderer::order( wc_get_order( $order->get_id() ) );

		$this->assertSame( $this->rest_get( '/wc/v3/orders/' . $order->get_id() ), $rendered );

		foreach ( array( 'id', 'number', 'status', 'date_created_gmt', 'billing', 'shipping', 'line_items', 'shipping_lines', 'coupon_lines', 'meta_data', 'total', 'shipping_total', 'customer_note', 'payment_method' ) as $key ) {
			$this->assertArrayHasKey( $key, $rendered, $key );
		}
		$this->assertSame( $order->get_id(), $rendered['id'] );
		$this->assertSame( 'processing', $rendered['status'] );
		$this->assertSame( '0550123456', $rendered['billing']['phone'] );
		$this->assertSame( 2, $rendered['line_items'][0]['quantity'] );
		$this->assertSame( 'Livraison à domicile', $rendered['shipping_lines'][0]['method_title'] );
		$this->assertSame( 'Appeler avant', $rendered['customer_note'] );
		$meta = wp_list_pluck( $rendered['meta_data'], 'value', 'key' );
		$this->assertSame( 'Alger', $meta['_billing_wilaya'] );
	}

	public function test_state_names_are_added_for_both_addresses(): void {
		$order = $this->order();
		$order->set_address(
			array(
				'city'    => 'Es Senia',
				'state'   => 'DZ-31',
				'country' => 'DZ',
			),
			'shipping'
		);
		$order->save();
		$plain    = Rest_Renderer::order( wc_get_order( $order->get_id() ) );
		$rendered = Rest_Renderer::with_state_names( $plain );

		$states = WC()->countries->get_states( 'DZ' );
		$this->assertSame( 'Algiers', $states['DZ-16'], 'WooCommerce English label' );
		$this->assertSame( $states['DZ-16'], $rendered['billing']['state_name'] );
		$this->assertSame( $states['DZ-31'], $rendered['shipping']['state_name'] );
		$this->assertSame( 'DZ-16', $rendered['billing']['state'] );
		$this->assertSame( 'DZ-31', $rendered['shipping']['state'] );
		unset( $rendered['billing']['state_name'], $rendered['shipping']['state_name'] );
		$this->assertSame( $plain, $rendered, 'nothing else changes' );
	}

	public function test_no_state_name_without_a_label(): void {
		$order = $this->order();
		// France has no state list; a typed state and an unknown code have no label either.
		$order->set_address(
			array(
				'state'   => 'Île-de-France',
				'country' => 'FR',
			),
			'billing'
		);
		$order->set_address(
			array(
				'state'   => 'DZ-99',
				'country' => 'DZ',
			),
			'shipping'
		);
		$order->save();
		$rendered = Rest_Renderer::with_state_names( Rest_Renderer::order( wc_get_order( $order->get_id() ) ) );

		$this->assertArrayNotHasKey( 'state_name', $rendered['billing'] );
		$this->assertArrayNotHasKey( 'state_name', $rendered['shipping'] );
		$this->assertSame( 'Île-de-France', $rendered['billing']['state'] );

		$typed = Rest_Renderer::with_state_names(
			array(
				'billing' => array(
					'country' => 'DZ',
					'state'   => 'Alger centre',
				),
			)
		);
		$this->assertArrayNotHasKey( 'state_name', $typed['billing'] );
		$this->assertArrayNotHasKey( 'shipping', $typed );
	}

	public function test_product_and_variation_match_rest_api(): void {
		$simple = $this->product( 'Blender' );
		$this->assertSame( $this->rest_get( '/wc/v3/products/' . $simple->get_id() ), Rest_Renderer::product( wc_get_product( $simple->get_id() ) ) );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'T-shirt' );
		$parent->set_status( 'publish' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '1200' );
		$variation->save();

		$this->assertSame(
			$this->rest_get( '/wc/v3/products/' . $parent->get_id() . '/variations/' . $variation->get_id() ),
			Rest_Renderer::variation( wc_get_product( $variation->get_id() ) )
		);
	}
}
