<?php
/**
 * Phase 2, the COD form: REST routes, WooCommerce order creation, spam paths, cache headers,
 * placement (block, shortcode, product pages) and the disconnected state.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Cod\Form;
use Tassyir\WooCommerce\Cod\Order_Creator;
use Tassyir\WooCommerce\Cod\Receipt;
use Tassyir\WooCommerce\Cod\Request_Guard;
use Tassyir\WooCommerce\Cod\Rest;
use Tassyir\WooCommerce\Cod\Settings;
use Tassyir\WooCommerce\Order_Sync;
use Tassyir\WooCommerce\Queue;

final class CodFormTest extends Integration_TestCase {

	/**
	 * Product used by the form.
	 *
	 * @var \WC_Product_Simple
	 */
	private $mixer;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.1';
		$_SERVER['HTTP_USER_AGENT']      = 'PHPUnit browser';
		delete_option( Settings::OPTION );
		$this->delete_rate_limits();
		$this->mixer = $this->product( 'Mixeur plongeant' );
		// The store-API hooks of the Phase 1 test product must not leave jobs behind.
		as_unschedule_all_actions( '', array(), 'tassyir' );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_USER_AGENT'] );
		$this->delete_rate_limits();
		parent::tear_down();
	}

	/**
	 * Clears the rate-limit counters.
	 */
	private function delete_rate_limits(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%tassyir_rl_%' OR option_name LIKE '%transient%tassyir_sf_%'" );
		wp_cache_flush();
	}

	/**
	 * A REST request to our namespace.
	 *
	 * @param string               $method GET | POST.
	 * @param string               $route  Route after /tassyir/v1.
	 * @param array<string, mixed> $body   JSON body (POST) or query params (GET).
	 * @param string|null          $nonce  Nonce header (null = a valid one).
	 */
	private function call( string $method, string $route, array $body = array(), ?string $nonce = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/tassyir/v1' . $route );
		if ( 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( Request_Guard::NONCE_HEADER, null === $nonce ? Request_Guard::nonce() : $nonce );
			$request->set_body( (string) wp_json_encode( $body ) );
		} else {
			$request->set_query_params( $body );
		}
		$response = rest_do_request( $request );
		return Rest::no_cache_response( $response, rest_get_server(), $request );
	}

	/**
	 * A valid form submission.
	 *
	 * @param array<string, mixed> $extra Overrides.
	 * @return array<string, mixed>
	 */
	private function submission( array $extra = array() ): array {
		return array_merge(
			array(
				'name'             => 'Amine Ben Ali',
				'phone'            => '0555 12 34 56',
				'phone2'           => '0661234501',
				'address'          => 'Cité 20 août, bâtiment 4',
				'note'             => 'Appeler avant',
				'provinceCode'     => 16,
				'townOns'          => 1621,
				'stopdesk'         => false,
				'submissionId'     => 'sub-' . wp_generate_password( 12, false ),
				'deviceId'         => 'device-1234-abcd',
				'items'            => array(
					array(
						'wooProductId' => $this->mixer->get_id(),
						'quantity'     => 2,
					),
				),
				'customFields'     => array(),
				'customFieldRules' => true,
			),
			$extra
		);
	}

	/**
	 * Tassyir's submit answer.
	 *
	 * @param string $verdict  allow | flag | hold | drop.
	 * @param bool   $stopdesk Desk delivery.
	 */
	private function answer_submit( string $verdict, bool $stopdesk = false ): void {
		$this->answer(
			200,
			array(
				'verdict'       => $verdict,
				'quoteId'       => in_array( $verdict, array( 'allow', 'flag' ), true ) ? 'wq_77' : null,
				'phone'         => '+213555123456',
				'destination'   => array(
					'provinceCode'       => 16,
					'provinceName'       => 'Alger',
					'provinceArabicName' => 'الجزائر',
					'townOns'            => 1621,
					'townName'           => 'Bab Ezzouar',
					'townArabicName'     => 'باب الزوار',
					'stopdesk'           => $stopdesk,
				),
				'lines'         => array(
					array(
						'wooProductId'   => $this->mixer->get_id(),
						'wooVariationId' => 0,
						'quantity'       => 2,
						'unitPrice'      => 2400,
						'total'          => 4800,
					),
				),
				'shippingPrice' => 600,
				'freeShipping'  => false,
				'total'         => 5400,
			)
		);
	}

	/**
	 * Config and towns answers (the wilaya and commune names).
	 */
	private function answer_geo(): void {
		$this->answer(
			200,
			array(
				'active'    => true,
				'provinces' => array(
					array(
						'code'       => 16,
						'name'       => 'Alger',
						'arabicName' => 'الجزائر',
					),
				),
				'fees'      => array(),
			)
		);
		$this->answer(
			200,
			array(
				'towns' => array(
					array(
						'codeOns'    => 1621,
						'name'       => 'Bab Ezzouar',
						'arabicName' => 'باب الزوار',
						'stopdesk'   => true,
					),
				),
			)
		);
	}

	/**
	 * Orders created by the form.
	 *
	 * @return \WC_Order[]
	 */
	private function form_orders(): array {
		return wc_get_orders(
			array(
				'created_via' => Order_Creator::CREATED_VIA,
				'limit'       => -1,
				'status'      => 'any',
			)
		);
	}

	public function test_allowed_submission_creates_the_woocommerce_order_and_phase_1_queues_it(): void {
		$this->answer_submit( 'allow' );
		$response = $this->call( 'POST', '/orders', $this->submission() );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'wq_77', $data['eventId'], 'the pixel event id is the quote id' );

		// One signed submit (§H6) with exactly its fields: an unknown one is a 400 there.
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.tassyir.test/woo-plugin/storefront/submit', $this->requests[0]['url'] );
		$this->assertSigned( 0 );
		$sent = $this->sent_body( 0 );
		$this->assertSame(
			array( 'submissionId', 'name', 'phone', 'provinceCode', 'townOns', 'stopdesk', 'items', 'customFields', 'customFieldRules', 'customer', 'phone2', 'address', 'note' ),
			array_keys( $sent )
		);
		// §H6 phone2 (amended 2026-10-30): sent as typed, the backend normalizes or drops it.
		$this->assertSame( '0661234501', $sent['phone2'] );
		$this->assertSame(
			array(
				'ip'               => '203.0.113.7',
				'forwardedIp'      => '198.51.100.9',
				'behindCloudflare' => false,
				'userAgent'        => 'PHPUnit browser',
				'deviceId'         => 'device-1234-abcd',
			),
			$sent['customer']
		);
		$this->assertSame(
			array(
				'wooProductId' => $this->mixer->get_id(),
				'quantity'     => 2,
				'title'        => 'Mixeur plongeant',
				'sku'          => $this->mixer->get_sku(),
				'price'        => 2500,
			),
			$sent['items'][0]
		);

		$orders = $this->form_orders();
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( $order->get_checkout_order_received_url(), $data['redirect'] );
		$this->assertSame( (string) $order->get_order_number(), $data['orderId'] );

		$items = array_values( $order->get_items() );
		$this->assertCount( 1, $items );
		$this->assertSame( $this->mixer->get_id(), $items[0]->get_product_id() );
		$this->assertSame( 2, $items[0]->get_quantity() );
		$this->assertSame( '4800', (string) $items[0]->get_total() );
		$this->assertSame( 600.0, (float) $order->get_shipping_total() );
		$this->assertSame( 5400.0, (float) $order->get_total() );

		$this->assertSame( 'Amine Ben Ali', $order->get_billing_first_name(), '§H6: first name = name' );
		$this->assertSame( '', $order->get_billing_last_name() );
		$this->assertSame( '+213555123456', $order->get_billing_phone(), "Tassyir's normalized phone" );
		$this->assertSame( 'DZ-16', $order->get_billing_state(), "WooCommerce's wilaya code (it shows the name)" );
		$this->assertSame( 'Bab Ezzouar', $order->get_billing_city(), 'the commune name' );
		$this->assertSame( 'Cité 20 août, bâtiment 4', $order->get_billing_address_1() );
		$this->assertSame( 'DZ', $order->get_billing_country() );
		$this->assertSame( 'Bab Ezzouar', $order->get_shipping_city() );
		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertSame( 'processing', $order->get_status(), 'the status WooCommerce gives a COD order' );
		$this->assertSame( 'Appeler avant', $order->get_customer_note() );
		$this->assertSame( '203.0.113.7', $order->get_customer_ip_address() );

		$this->assertSame( 'wq_77', $order->get_meta( '_tassyir_quote_id' ) );
		$this->assertSame( 'Home delivery', array_values( $order->get_items( 'shipping' ) )[0]->get_method_title() );
		$this->assertSame( '16', $order->get_meta( '_tassyir_province_code' ) );
		$this->assertSame( '1621', $order->get_meta( '_tassyir_town_ons' ) );
		$this->assertSame( '0', $order->get_meta( '_tassyir_stopdesk' ) );
		$this->assertSame( 'device-1234-abcd', $order->get_meta( '_tassyir_device_id' ) );
		$this->assertSame( '203.0.113.7', $order->get_meta( '_tassyir_client_ip' ) );
		$this->assertSame( '0661234501', $order->get_meta( '_tassyir_phone2' ) );

		// Phase 1 pushes it like any other order, and the importer sees the exact codes.
		$this->assertSame( array( array( $order->get_id() ) ), array_column( $this->pending( Order_Sync::HOOK ), 'args' ) );
		$meta = wp_list_pluck( \Tassyir\WooCommerce\Rest_Renderer::order( $order )['meta_data'], 'value', 'key' );
		$this->assertSame( '1621', $meta['_tassyir_town_ons'] );
		$this->assertSame( 'wq_77', $meta['_tassyir_quote_id'] );
	}

	public function test_flagged_order_is_created_with_a_note_and_a_desk_line(): void {
		$this->answer_submit( 'flag', true );
		update_option(
			Settings::OPTION,
			array(
				'language'          => 'ar',
				'behind_cloudflare' => true,
			),
			false
		);
		$response = $this->call( 'POST', '/orders', $this->submission( array( 'stopdesk' => true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$order = $this->form_orders()[0];
		$this->assertSame( 'flag', $order->get_meta( '_tassyir_verdict' ) );
		$this->assertSame( '1', $order->get_meta( '_tassyir_stopdesk' ) );
		$this->assertSame( 'DZ-16', $order->get_billing_state() );
		$this->assertTrue( $this->sent_body( 0 )['customer']['behindCloudflare'] );
		$shipping = array_values( $order->get_items( 'shipping' ) )[0];
		$this->assertSame( 'Stop desk - توصيل للمكتب', $shipping->get_method_title(), '§H6 title, then the translation' );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'suspicious', implode( ' ', wp_list_pluck( $notes, 'content' ) ) );
	}

	public function test_submit_queues_the_push_and_never_sends_it_in_the_request(): void {
		$this->answer_submit( 'allow' );
		$response = $this->call( 'POST', '/orders', $this->submission() );
		$this->assertSame( 200, $response->get_status() );
		$order = $this->form_orders()[0];

		// The only Tassyir call of the request is §H6 submit: the order push (§C) is a queued job.
		$this->assertSame( array( 'https://api.tassyir.test/woo-plugin/storefront/submit' ), array_column( $this->requests, 'url' ) );
		$this->assertSame( 0, did_action( Order_Sync::HOOK ) );
		$this->assertSame( array( array( $order->get_id() ) ), array_column( $this->pending( Order_Sync::HOOK ), 'args' ) );

		// It asks Action Scheduler to run soon, last thing at shutdown, and only once the response
		// can be ended first (PHP-FPM / LiteSpeed). Here (CLI) that means nothing at all happens.
		$this->assertSame( 9999, has_action( 'shutdown', array( Queue::class, 'dispatch_runner' ) ) );
		Queue::dispatch_runner();
		$this->assertCount( 1, $this->requests, 'no loopback, no push' );
		$this->assertSame( 0, did_action( Order_Sync::HOOK ) );
		$this->assertCount( 1, $this->pending( Order_Sync::HOOK ) );
		remove_action( 'shutdown', array( Queue::class, 'dispatch_runner' ), 9999 );
	}

	public function test_held_submission_answers_like_an_order_without_creating_one(): void {
		$this->answer_submit( 'allow' );
		$allowed = $this->call( 'POST', '/orders', $this->submission() )->get_data();
		$order   = $this->form_orders()[0];
		as_unschedule_all_actions( '', array(), 'tassyir' );

		$numbers = array();
		foreach ( array( 'hold', 'drop' ) as $verdict ) {
			$this->answer_submit( $verdict );
			$data = $this->call( 'POST', '/orders', $this->submission() )->get_data();

			$this->assertSame( array_keys( $allowed ), array_keys( $data ), $verdict . ': the same answer' );
			$this->assertTrue( $data['success'] );
			$this->assertSame( '', $data['eventId'], $verdict . ': no event id, so the form fires no Purchase' );
			$this->assertSame( 5400.0, $data['total'] );
			$this->assertSame( 600.0, $data['shippingPrice'] );
			$this->assertSame( $this->url_shape( $allowed ), $this->url_shape( $data ), $verdict . ': an order-received URL like any order' );
			$this->assertMatchesRegularExpression( '/[?&]key=wc_order_[A-Za-z0-9]{13}$/', (string) $data['redirect'] );
			$this->assertFalse( wc_get_order( (int) $data['orderId'] ), 'no order behind the number' );
			parse_str( (string) wp_parse_url( (string) $data['redirect'], PHP_URL_QUERY ), $query );
			$this->assertSame( 0, (int) wc_get_order_id_by_order_key( (string) $query['key'] ), 'nor behind the key (what pixel plugins look up)' );
			$numbers[] = (int) $data['orderId'];
		}
		$this->assertCount( 1, $this->form_orders() );
		$this->assertSame( array(), $this->pending( Order_Sync::HOOK ) );

		// Receipt numbers are ids taken like an order's: increasing, and never an order's later.
		$this->assertGreaterThan( $order->get_id(), $numbers[0] );
		$this->assertGreaterThan( $numbers[0], $numbers[1] );
		$this->answer_submit( 'allow' );
		$this->call( 'POST', '/orders', $this->submission() );
		$ids = array_map(
			static function ( \WC_Order $created ): int {
				return $created->get_id();
			},
			$this->form_orders()
		);
		$this->assertGreaterThan( $numbers[1], max( $ids ) );
		remove_action( 'shutdown', array( Queue::class, 'dispatch_runner' ), 9999 );
	}

	/**
	 * A success answer's redirect with its order number and key blanked out.
	 *
	 * @param array<string, mixed> $data Success answer.
	 */
	private function url_shape( array $data ): string {
		return (string) preg_replace( array( '/wc_order_[A-Za-z0-9]+/', '/(?<=[=\/])' . preg_quote( (string) $data['orderId'], '/' ) . '(?=[&\/]|$)/' ), array( 'KEY', 'N' ), (string) $data['redirect'] );
	}

	public function test_held_submission_pressed_twice_gets_the_same_receipt(): void {
		$body = $this->submission();
		$this->answer_submit( 'hold' );
		$first  = $this->call( 'POST', '/orders', $body )->get_data();
		$calls  = count( $this->requests );
		$second = $this->call( 'POST', '/orders', $body )->get_data();

		$this->assertTrue( $second['duplicate'] );
		$this->assertSame( $first['orderId'], $second['orderId'] );
		$this->assertSame( $first['redirect'], $second['redirect'] );
		$this->assertCount( $calls, $this->requests, 'Tassyir is not asked again' );
		$this->assertSame( array(), $this->form_orders() );
	}

	public function test_held_submission_follows_the_custom_and_inline_success_modes(): void {
		update_option(
			Settings::OPTION,
			array(
				'success_mode' => 'custom',
				'success_url'  => 'https://shop.example.com/merci',
			),
			false
		);
		$this->answer_submit( 'hold' );
		$data = $this->call( 'POST', '/orders', $this->submission() )->get_data();
		$this->assertSame( 'https://shop.example.com/merci?order=' . $data['orderId'] . '&total=5400&currency=DZD', $data['redirect'], 'same URL as a real order' );

		update_option( Settings::OPTION, array( 'success_mode' => 'inline' ), false );
		$this->answer_submit( 'hold' );
		$data = $this->call( 'POST', '/orders', $this->submission() )->get_data();
		$this->assertNull( $data['redirect'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', $data['orderId'], 'the inline panel shows a number too' );
	}

	public function test_receipt_page_shows_the_order_without_order_hooks(): void {
		$this->answer_submit( 'hold' );
		$data = $this->call( 'POST', '/orders', $this->submission() )->get_data();
		parse_str( (string) wp_parse_url( (string) $data['redirect'], PHP_URL_QUERY ), $query );
		$thankyou = did_action( 'woocommerce_thankyou' ) + did_action( 'woocommerce_before_thankyou' );

		// A wrong key, or the right key on another number: WooCommerce's own page, untouched.
		set_query_var( 'order-received', $data['orderId'] );
		$_GET['key'] = 'wc_order_aaaaaaaaaaaaa';
		Receipt::detect();
		$this->assertNull( Receipt::current() );
		set_query_var( 'order-received', (int) $data['orderId'] + 1 );
		$_GET['key'] = $query['key'];
		Receipt::detect();
		$this->assertNull( Receipt::current() );

		set_query_var( 'order-received', $data['orderId'] );
		Receipt::detect();
		$this->assertNotNull( Receipt::current() );

		// Block themes: WooCommerce's order-confirmation blocks, filled from the receipt.
		$html = do_blocks(
			'<!-- wp:woocommerce/order-confirmation-status /-->'
			. '<!-- wp:woocommerce/order-confirmation-summary /-->'
			. '<!-- wp:woocommerce/order-confirmation-totals /-->'
			. '<!-- wp:woocommerce/order-confirmation-billing-wrapper --><div class="wp-block-woocommerce-order-confirmation-billing-wrapper"><!-- wp:heading --><h2 class="wp-block-heading">Billing address</h2><!-- /wp:heading --><!-- wp:woocommerce/order-confirmation-billing-address /--></div><!-- /wp:woocommerce/order-confirmation-billing-wrapper -->'
			. '<!-- wp:woocommerce/order-confirmation-downloads /-->'
		);
		$this->assertStringContainsString( '<h1>Order received</h1><p>Thank you. Your order has been received.</p>', $html );
		$this->assertStringContainsString( 'wc-block-order-confirmation-summary-list-item__value">' . $data['orderId'] . '</span>', $html );
		$this->assertStringContainsString( 'Mixeur plongeant', $html );
		$this->assertStringContainsString( '&times;&nbsp;2', $html );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Billing address</h2>', $html, 'the wrapper keeps its heading' );
		$this->assertStringContainsString( 'Bab Ezzouar', $html );
		$this->assertStringContainsString( '+213555123456', $html );
		$this->assertStringContainsString( 'class="wp-block-woocommerce-order-confirmation-summary wc-block-order-confirmation-summary alignwide', $html, "WooCommerce's wrapper, default attributes applied" );
		$this->assertStringNotContainsString( 'order-confirmation-downloads', $html );

		// Classic themes: the thank-you template.
		$template = apply_filters( 'wc_get_template', 'woocommerce/templates/checkout/thankyou.php', 'checkout/thankyou.php', array(), '', '' );
		$this->assertStringEndsWith( 'views/receipt-classic.php', $template );
		$this->assertSame( 'x.php', apply_filters( 'wc_get_template', 'x.php', 'checkout/form-checkout.php', array(), '', '' ) );
		ob_start();
		include $template;
		$classic = (string) ob_get_clean();
		$this->assertStringContainsString( 'woocommerce-order-overview__order order', $classic );
		$this->assertStringContainsString( '<strong>' . $data['orderId'] . '</strong>', $classic );
		$this->assertStringContainsString( 'woocommerce-table--order-details', $classic );
		$this->assertStringContainsString( 'Mixeur plongeant', $classic );

		$this->assertSame( $thankyou, did_action( 'woocommerce_thankyou' ) + did_action( 'woocommerce_before_thankyou' ), 'no order hook ran' );
		$this->assertSame( array(), $this->form_orders(), 'and still no order' );

		unset( $_GET['key'] );
		set_query_var( 'order-received', '' );
		Receipt::reset();
	}

	public function test_same_submission_is_not_ordered_twice(): void {
		$body = $this->submission();
		$this->answer_submit( 'allow' );
		$first  = $this->call( 'POST', '/orders', $body );
		$calls  = count( $this->requests );
		$second = $this->call( 'POST', '/orders', $body );

		$this->assertTrue( $second->get_data()['duplicate'] );
		$this->assertSame( $first->get_data()['orderId'], $second->get_data()['orderId'] );
		$this->assertCount( $calls, $this->requests, 'Tassyir is not asked again' );
		$this->assertCount( 1, $this->form_orders() );
	}

	public function test_honeypot_bad_nonce_rate_limit_and_bad_input_stop_before_tassyir(): void {
		$response = $this->call( 'POST', '/orders', $this->submission( array( 'website' => 'http://spam.example' ) ) );
		$this->assertSame( 400, $response->get_status(), 'honeypot' );

		$response = $this->call( 'POST', '/orders', $this->submission(), 'not-a-nonce' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'tassyir_bad_nonce', $response->get_data()['code'] );

		$response = $this->call( 'POST', '/orders', $this->submission( array( 'phone' => '0455123456' ) ) );
		$this->assertSame( 400, $response->get_status(), 'landline' );

		$response = $this->call(
			'POST',
			'/orders',
			$this->submission(
				array(
					'items' => array(
						array(
							'wooProductId' => 999999,
							'quantity'     => 1,
						),
					),
				)
			)
		);
		$this->assertSame( 400, $response->get_status(), 'unknown product' );

		$this->assertSame( array(), $this->requests, 'Tassyir never asked' );

		for ( $i = 0; $i < Request_Guard::LIMITS['orders'][0]; $i++ ) {
			Request_Guard::allow( 'orders' );
		}
		$response = $this->call( 'POST', '/orders', $this->submission() );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $this->form_orders() );

		// Another customer behind the same proxy address has its own bucket.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.44';
		$this->assertTrue( Request_Guard::allow( 'orders' ) );
	}

	public function test_every_route_answers_no_store_and_config_is_cached(): void {
		$this->answer_geo();
		$config = $this->call( 'GET', '/config' );
		$towns  = $this->call( 'GET', '/towns', array( 'province' => 16 ) );
		$nonce  = $this->call( 'GET', '/nonce' );
		$denied = $this->call( 'POST', '/quote', array(), 'stale' );

		foreach ( array( $config, $towns, $nonce, $denied ) as $response ) {
			$headers = $response->get_headers();
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
			$this->assertSame( 'no-cache', $headers['X-LiteSpeed-Cache-Control'] );
		}
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertSame( 'Alger', $config->get_data()['provinces'][0]['name'] );
		$this->assertSame( 1621, $towns->get_data()['towns'][0]['codeOns'] );
		$this->assertSame( array( 'province' => 16 ), $this->sent_body( 1 ) );
		$this->assertSame( 1, wp_verify_nonce( $nonce->get_data()['nonce'], Request_Guard::NONCE_ACTION ) );

		$this->call( 'GET', '/config' );
		$this->call( 'GET', '/towns', array( 'province' => 16 ) );
		$this->assertCount( 2, $this->requests, 'config and towns come from the 10-minute cache' );
		$this->assertSame( 400, $this->call( 'GET', '/towns', array( 'province' => 99 ) )->get_status() );
	}

	public function test_quote_is_forwarded_signed_without_prices(): void {
		$this->answer(
			200,
			array(
				'available'        => false,
				'orderPricingMode' => 'off',
				'reason'           => 'pricing_not_on',
			)
		);
		$response = $this->call(
			'POST',
			'/quote',
			array(
				'items'        => array(
					array(
						'wooProductId' => $this->mixer->get_id(),
						'quantity'     => 3,
						'price'        => 1,
					),
				),
				'provinceCode' => 31,
				'stopdesk'     => true,
			)
		);
		$this->assertSame( 'pricing_not_on', $response->get_data()['reason'] );
		$this->assertSigned( 0 );
		$sent = $this->sent_body( 0 );
		$this->assertSame( 31, $sent['provinceCode'] );
		$this->assertTrue( $sent['stopdesk'] );
		$this->assertSame(
			array(
				'wooProductId' => $this->mixer->get_id(),
				'quantity'     => 3,
				'sku'          => $this->mixer->get_sku(),
			),
			$sent['items'][0],
			'§H5 items: identity and quantity, never a price'
		);
		$this->assertSame( '203.0.113.7', $sent['customerIp'] );
	}

	public function test_disconnected_site_hides_the_form_and_keeps_add_to_cart(): void {
		update_option( Settings::OPTION, array( 'product_pages' => true ), false );
		delete_option( 'tassyir_connection_id' );
		delete_option( 'tassyir_secret' );

		$this->assertSame( '', Form::render( $this->mixer ) );
		$this->assertSame( '', do_shortcode( '[tassyir_cod_form product="' . $this->mixer->get_id() . '"]' ) );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:tassyir/cod-form {"productId":' . $this->mixer->get_id() . '} /-->' ) ) );
		$this->assertFalse( $this->call( 'GET', '/config' )->get_data()['active'] );
		$this->assertSame( 404, $this->call( 'POST', '/orders', $this->submission() )->get_status() );

		$this->go_to( get_permalink( $this->mixer->get_id() ) );
		( new \Tassyir\WooCommerce\Cod\Placement( new \Tassyir\WooCommerce\Connection() ) )->product_page_hooks();
		$this->assertSame( 30, has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' ) );
	}

	public function test_product_pages_replace_add_to_cart_when_connected(): void {
		update_option( Settings::OPTION, array( 'product_pages' => true ), false );
		$this->go_to( get_permalink( $this->mixer->get_id() ) );
		$GLOBALS['product'] = $this->mixer; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the product page's global.
		$placement          = new \Tassyir\WooCommerce\Cod\Placement( new \Tassyir\WooCommerce\Connection() );
		$placement->product_page_hooks();

		$this->assertFalse( has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' ) );
		$this->assertSame( 25, has_action( 'woocommerce_single_product_summary', array( $placement, 'print_form' ) ) );

		// A block theme may run both the summary hook and the Add to cart block: one form only.
		ob_start();
		$placement->print_form();
		$this->assertStringContainsString( 'class="tsy-cod-form"', (string) ob_get_clean() );
		$this->assertSame( '', $placement->filter_add_to_cart_block( '<button>Add to cart</button>' ), 'no second form, no Add to cart' );
		$fresh = new \Tassyir\WooCommerce\Cod\Placement( new \Tassyir\WooCommerce\Connection() );
		$this->assertStringContainsString( 'class="tsy-cod-form"', $fresh->filter_add_to_cart_block( '<button>Add to cart</button>' ) );
		$this->assertStringNotContainsString( 'Add to cart', $fresh->filter_add_to_cart_block( '<button>Add to cart</button>' ) );
		add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		remove_action( 'woocommerce_single_product_summary', array( $placement, 'print_form' ), 25 );
	}

	public function test_block_and_shortcode_render_the_form(): void {
		$shortcode = do_shortcode( '[tassyir_cod_form product="' . $this->mixer->get_id() . '"]' );
		$block     = do_blocks( '<!-- wp:tassyir/cod-form {"productId":' . $this->mixer->get_id() . '} /-->' );

		foreach ( array( $shortcode, $block ) as $html ) {
			$this->assertStringContainsString( 'class="tsy-cod-form"', $html );
			$this->assertStringContainsString( 'data-tsy-honeypot', $html );
			$this->assertStringContainsString( 'data-tsy-rest="' . esc_url( rest_url( 'tassyir/v1/' ) ) . '"', $html );
			$this->assertStringNotContainsString( '<script', $html, 'no inline script' );
			$this->assertStringNotContainsString( 'wp_rest', $html, 'no nonce in cacheable HTML' );
		}
		preg_match( '/data-tsy-product="([^"]+)"/', $shortcode, $match );
		$data = json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
		$this->assertSame( $this->mixer->get_id(), $data['productId'] );
		$this->assertSame( 250000, $data['variants'][0]['price'], 'cents, like the Shopify block' );
		$this->assertStringContainsString( 'dir="ltr"', $shortcode, 'site language en_US' );
		$this->assertTrue( wp_script_is( Form::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Form::HANDLE, 'enqueued' ) );

		update_option( Settings::OPTION, array( 'language' => 'ar' ), false );
		$arabic = do_shortcode( '[tassyir_cod_form product="' . $this->mixer->get_id() . '"]' );
		$this->assertStringContainsString( 'dir="rtl"', $arabic );
		$this->assertStringContainsString( 'اطلب الآن', $arabic );
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'tassyir/cod-form' ) );
	}

	public function test_variable_product_lists_its_variations_and_the_order_keeps_the_pick(): void {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Taille' );
		$attribute->set_options( array( 'S', 'M' ) );
		$attribute->set_variation( true );
		$color = new \WC_Product_Attribute();
		$color->set_name( 'Couleur' );
		$color->set_options( array( 'Rouge', 'Bleu' ) );
		$color->set_variation( true );
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'T-shirt' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute, $color ) );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '1200' );
		$variation->set_attributes(
			array(
				'taille'  => 'M',
				'couleur' => '',
			)
		);
		$variation->save();
		$parent = wc_get_product( $parent->get_id() );

		$data = Form::product_data( $parent );
		$this->assertSame( array( 'Taille', 'Couleur' ), $data['optionNames'] );
		$this->assertCount( 2, $data['variants'], '"Any colour" expands to one entry per colour' );
		$this->assertSame( array( 'M', 'Bleu' ), $data['variants'][1]['options'] );
		$this->assertSame( $variation->get_id(), $data['variants'][1]['wooVariationId'] );
		$this->assertSame( array( 'attribute_couleur' => 'Bleu' ), $data['variants'][1]['attributes'] );
		$this->assertSame( 120000, $data['variants'][1]['price'] );

		$this->answer(
			200,
			array(
				'verdict'       => 'allow',
				'quoteId'       => 'wq_v',
				'destination'   => array(
					'provinceCode' => 16,
					'townOns'      => 1621,
					'townName'     => 'Bab Ezzouar',
					'stopdesk'     => false,
				),
				'lines'         => array(
					array(
						'wooProductId'   => $parent->get_id(),
						'wooVariationId' => $variation->get_id(),
						'quantity'       => 1,
						'total'          => 1100,
					),
				),
				'shippingPrice' => 500,
				'total'         => 1600,
			)
		);
		$response = $this->call(
			'POST',
			'/orders',
			$this->submission(
				array(
					'items' => array(
						array(
							'wooProductId'   => $parent->get_id(),
							'wooVariationId' => $variation->get_id(),
							'quantity'       => 1,
							'attributes'     => array( 'attribute_couleur' => 'Bleu' ),
						),
					),
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$item = array_values( $this->form_orders()[0]->get_items() )[0];
		$this->assertSame( $variation->get_id(), $item->get_variation_id() );
		$this->assertSame( 'Bleu', $item->get_meta( 'couleur' ) );
		$this->assertSame( '1100', (string) $item->get_total() );

		// A variable product needs its variation; another product's variation is refused.
		$this->assertNull(
			Rest::clean_items(
				array(
					array(
						'wooProductId' => $parent->get_id(),
						'quantity'     => 1,
					),
				)
			)
		);
		$this->assertNull(
			Rest::clean_items(
				array(
					array(
						'wooProductId'   => $this->mixer->get_id(),
						'wooVariationId' => $variation->get_id(),
						'quantity'       => 1,
					),
				)
			)
		);
	}

	public function test_attribution_is_kept_cleaned_and_never_rejects_an_order(): void {
		$clean = Rest::clean_submission(
			$this->submission(
				array(
					'attribution' => array(
						'source'     => ' facebook ',
						'medium'     => 'paid_social',
						'campaign'   => 'ramadan',
						'content'    => '',
						'campaignId' => '120210000000001',
						'contentId'  => '120210000000002',
						'fbclid'     => 'IwAR-click-id',
						'gclid'      => 'abc',
						'extra'      => 'nope',
					),
				)
			)
		);
		$this->assertIsArray( $clean );
		$this->assertSame(
			array(
				'source'     => 'facebook',
				'medium'     => 'paid_social',
				'campaign'   => 'ramadan',
				'campaignId' => '120210000000001',
				'contentId'  => '120210000000002',
			),
			$clean['attribution']
		);

		// Non-scalars, nested arrays and over-long values never reject the order.
		$messy = Rest::clean_submission(
			$this->submission(
				array(
					'attribution' => array(
						'source'   => array( 'facebook' ),
						'medium'   => 42,
						'campaign' => str_repeat( 'x', 400 ),
						'content'  => null,
					),
				)
			)
		);
		$this->assertIsArray( $messy );
		$this->assertSame( array( 'campaign' => str_repeat( 'x', 255 ) ), $messy['attribution'] );

		// Not an array, or nothing usable: no key at all, still a valid submission.
		foreach ( array( 'utm_source=x', 7, array(), array( 'unknown' => 'x' ), array( 'source' => ' ' ) ) as $bad ) {
			$out = Rest::clean_submission( $this->submission( array( 'attribution' => $bad ) ) );
			$this->assertIsArray( $out, 'a bad attribution must never be an error' );
			$this->assertArrayNotHasKey( 'attribution', $out );
		}
		$this->assertArrayNotHasKey( 'attribution', (array) Rest::clean_submission( $this->submission() ) );
	}

	public function test_submit_body_carries_attribution_only_when_present(): void {
		$with = Rest::clean_submission( $this->submission( array( 'attribution' => array( 'source' => 'tiktok' ) ) ) );
		$this->assertIsArray( $with );
		$this->assertSame( array( 'source' => 'tiktok' ), Rest::submit_body( $with, false )['attribution'] );

		$without = Rest::clean_submission( $this->submission() );
		$this->assertIsArray( $without );
		$this->assertArrayNotHasKey( 'attribution', Rest::submit_body( $without, false ) );
	}

	public function test_a_refused_attribution_never_costs_the_order(): void {
		$this->answer( 400, array( 'message' => array( 'property attribution should not exist' ) ) );
		$this->answer_submit( 'allow' );
		$sent     = $this->submission( array( 'attribution' => array( 'source' => 'facebook' ) ) );
		$response = $this->call( 'POST', '/orders', $sent );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->form_orders() );
		$this->assertCount( 2, $this->requests );
		$first  = json_decode( (string) $this->requests[0]['args']['body'], true );
		$second = json_decode( (string) $this->requests[1]['args']['body'], true );
		$this->assertSame( array( 'source' => 'facebook' ), $first['attribution'] );
		$this->assertArrayNotHasKey( 'attribution', $second );
		$this->assertSame( $first['submissionId'], $second['submissionId'] );
	}

	public function test_any_other_400_is_not_sent_twice(): void {
		$this->answer( 400, array( 'message' => array( 'phone must be shorter' ) ) );
		$response = $this->call( 'POST', '/orders', $this->submission( array( 'attribution' => array( 'source' => 'facebook' ) ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_attribution_script_loads_on_every_page_when_connected_only(): void {
		$placement = new \Tassyir\WooCommerce\Cod\Placement( new \Tassyir\WooCommerce\Connection() );

		// Other tests render a form in this process, which leaves its script in the queue.
		wp_dequeue_script( Form::HANDLE );
		wp_dequeue_script( Form::ATTRIBUTION_HANDLE );
		$placement->enqueue_assets();
		$this->assertTrue( wp_script_is( Form::ATTRIBUTION_HANDLE, 'enqueued' ), 'a page without any form still captures the campaign' );
		$this->assertFalse( wp_script_is( Form::HANDLE, 'enqueued' ), 'the form script stays on form pages' );
		$this->assertContains( Form::ATTRIBUTION_HANDLE, wp_scripts()->registered[ Form::HANDLE ]->deps );

		wp_dequeue_script( Form::ATTRIBUTION_HANDLE );
		delete_option( 'tassyir_connection_id' );
		delete_option( 'tassyir_secret' );
		$placement->enqueue_assets();
		$this->assertFalse( wp_script_is( Form::ATTRIBUTION_HANDLE, 'enqueued' ), 'disconnected: nothing is loaded' );
	}
}
