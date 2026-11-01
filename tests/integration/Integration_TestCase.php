<?php
/**
 * Base class: a connected plugin and a fake HTTP layer (no request leaves the test).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Integration;

use Tassyir\WooCommerce\Plugin;
use Tassyir\WooCommerce\Signer;

/**
 * Every wp_remote_*() call is captured in $this->requests and answered from $this->responses.
 */
abstract class Integration_TestCase extends \WP_UnitTestCase {

	const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

	/**
	 * Captured requests: [ url, args ].
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	protected $requests = array();

	/**
	 * Queued answers: [ status, body, headers ] or a \WP_Error.
	 *
	 * @var array<int, mixed>
	 */
	protected $responses = array();

	public function set_up(): void {
		parent::set_up();
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );

		update_option( 'tassyir_connection_id', '777', false );
		update_option( 'tassyir_secret', self::SECRET, false );
		as_unschedule_all_actions( '', array(), Plugin::GROUP );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		as_unschedule_all_actions( '', array(), Plugin::GROUP );
		parent::tear_down();
	}

	/**
	 * Queues one answer.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, mixed>  $body    JSON body.
	 * @param array<string, string> $headers Response headers (lowercase names).
	 */
	protected function answer( int $status, array $body = array(), array $headers = array() ): void {
		$this->responses[] = array( $status, $body, $headers );
	}

	/**
	 * The pre_http_request filter.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$next             = array_shift( $this->responses );
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		$next = $next ? $next : array( 200, array(), array() );
		return array(
			'headers'  => $next[2],
			'body'     => wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Decoded JSON body of a captured request.
	 *
	 * @param int $index Request index.
	 * @return array<string, mixed>
	 */
	protected function sent_body( int $index = 0 ): array {
		return json_decode( $this->requests[ $index ]['args']['body'], true );
	}

	/**
	 * Asserts a captured request carries a valid §A signature.
	 *
	 * @param int $index Request index.
	 */
	protected function assertSigned( int $index = 0 ): void {
		$args    = $this->requests[ $index ]['args'];
		$headers = $args['headers'];
		$path    = (string) wp_parse_url( $this->requests[ $index ]['url'], PHP_URL_PATH );

		$this->assertSame( '777', $headers['X-Tassyir-Connection'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $headers['X-Tassyir-Nonce'] );
		$this->assertEqualsWithDelta( time(), (int) $headers['X-Tassyir-Timestamp'], 5 );
		$canonical = Signer::canonical( (int) $headers['X-Tassyir-Timestamp'], $headers['X-Tassyir-Nonce'], $args['method'], $path, $args['body'] );
		$this->assertSame( Signer::signature( self::SECRET, $canonical ), $headers['X-Tassyir-Signature'] );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $args ) );
	}

	/**
	 * Pending Action Scheduler jobs of a hook: [ args, scheduled unix time ].
	 *
	 * @param string $hook Hook.
	 * @return array<int, array{args: array<int, mixed>, at: int}>
	 */
	protected function pending( string $hook ): array {
		$found = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => Plugin::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			)
		) as $action ) {
			$date    = $action->get_schedule()->get_date();
			$found[] = array(
				'args' => $action->get_args(),
				'at'   => $date ? $date->getTimestamp() : 0,
			);
		}
		return $found;
	}

	/**
	 * A saved simple product.
	 *
	 * @param string $name Name.
	 */
	protected function product( string $name = 'Mixer' ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '2500' );
		$product->set_sku( 'SKU-' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->save();
		return $product;
	}

	/**
	 * A saved processing order with one line, an address and shipping.
	 */
	protected function order(): \WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->product(), 2 );
		$order->set_address(
			array(
				'first_name' => 'Amine',
				'last_name'  => 'Benali',
				'phone'      => '0550123456',
				'address_1'  => 'Cité 20 août',
				'city'       => 'Bab Ezzouar',
				'state'      => 'DZ-16',
				'country'    => 'DZ',
			),
			'billing'
		);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Livraison à domicile' );
		$shipping->set_total( '400' );
		$order->add_item( $shipping );
		$order->set_payment_method( 'cod' );
		$order->set_customer_note( 'Appeler avant' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		return $order;
	}
}
