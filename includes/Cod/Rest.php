<?php
/**
 * The COD form's REST routes: /wp-json/tassyir/v1/{config,towns,product-fields,nonce,quote,upload,orders}.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

use Tassyir\WooCommerce\Api_Response;
use Tassyir\WooCommerce\Connection;
use Tassyir\WooCommerce\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Public routes (the customer is a guest), same origin as the shop. Every Tassyir call behind
 * them is server-side and signed. No response may be cached: each one carries
 * `Cache-Control: no-store` and the LiteSpeed / WP Rocket / W3TC "do not cache" signals.
 */
final class Rest {

	const NAMESPACE_V1 = 'tassyir/v1';

	/**
	 * Largest custom-field image accepted (Tassyir's limit).
	 */
	const MAX_UPLOAD_BYTES = 10485760;

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Tassyir's storefront routes.
	 *
	 * @var Storefront
	 */
	private $storefront;

	/**
	 * Builds the routes.
	 *
	 * @param Connection $connection Stored connection.
	 * @param Storefront $storefront Tassyir client.
	 */
	public function __construct( Connection $connection, Storefront $storefront ) {
		$this->connection = $connection;
		$this->storefront = $storefront;
	}

	/**
	 * Hooks the routes and the no-cache headers.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'no_cache_response' ), 10, 3 );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$public = '__return_true';
		$nonce  = array( self::class, 'check_nonce' );
		$routes = array(
			'/config'         => array( \WP_REST_Server::READABLE, 'config', $public ),
			'/towns'          => array( \WP_REST_Server::READABLE, 'towns', $public ),
			'/product-fields' => array( \WP_REST_Server::READABLE, 'product_fields', $public ),
			'/nonce'          => array( \WP_REST_Server::READABLE, 'nonce', $public ),
			'/quote'          => array( \WP_REST_Server::CREATABLE, 'quote', $nonce ),
			'/upload'         => array( \WP_REST_Server::CREATABLE, 'upload', $nonce ),
			'/orders'         => array( \WP_REST_Server::CREATABLE, 'orders', $nonce ),
		);
		foreach ( $routes as $route => $spec ) {
			register_rest_route(
				self::NAMESPACE_V1,
				$route,
				array(
					'methods'             => $spec[0],
					'callback'            => array( $this, $spec[1] ),
					'permission_callback' => $spec[2],
				)
			);
		}
	}

	/**
	 * Permission callback of the POST routes: the nonce from GET /nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function check_nonce( \WP_REST_Request $request ) {
		self::no_cache_signals();
		if ( Request_Guard::nonce_ok( $request ) ) {
			return true;
		}
		return new \WP_Error( 'tassyir_bad_nonce', 'Expired form, please try again.', array( 'status' => 403 ) );
	}

	/**
	 * GET config: provinces and delivery fees.
	 */
	public function config(): \WP_REST_Response {
		if ( ! $this->connection->is_connected() ) {
			return self::respond( array( 'active' => false ) );
		}
		$response = $this->storefront->config();
		return $response->ok() ? self::respond( $response->body ) : self::upstream_error( $response );
	}

	/**
	 * GET towns?province=: the communes the carrier serves.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function towns( \WP_REST_Request $request ): \WP_REST_Response {
		$province = (int) $request->get_param( 'province' );
		if ( $province < 1 || $province > 58 ) {
			return self::error( 400, 'tassyir_invalid', 'Invalid province' );
		}
		if ( ! $this->connection->is_connected() ) {
			return self::respond( array( 'active' => false ) );
		}
		$response = $this->storefront->towns( $province );
		return $response->ok() ? self::respond( $response->body ) : self::upstream_error( $response );
	}

	/**
	 * GET product-fields?product=: custom fields and quantity offer of a product.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function product_fields( \WP_REST_Request $request ): \WP_REST_Response {
		$product = wc_get_product( (int) $request->get_param( 'product' ) );
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			return self::respond( array( 'fields' => array() ) );
		}
		if ( ! $this->connection->is_connected() ) {
			return self::respond( array( 'active' => false ) );
		}
		$response = $this->storefront->product_fields( $product->get_id() );
		return $response->ok() ? self::respond( $response->body ) : self::upstream_error( $response );
	}

	/**
	 * GET nonce: fetched by the script at load (cached pages carry stale nonces).
	 */
	public function nonce(): \WP_REST_Response {
		return self::respond( array( 'nonce' => Request_Guard::nonce() ) );
	}

	/**
	 * POST quote: the server's price of the cart on screen.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function quote( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->connection->is_connected() ) {
			return self::error( 404, 'tassyir_not_connected', 'Not connected' );
		}
		if ( ! Request_Guard::allow( 'quote' ) ) {
			return self::error( 429, 'tassyir_rate_limited', 'Too many requests' );
		}
		$params = (array) $request->get_json_params();
		$items  = self::clean_items( $params['items'] ?? array() );
		if ( null === $items ) {
			return self::error( 400, 'tassyir_invalid', 'Invalid items' );
		}
		$body     = array_filter(
			array(
				'items'        => self::wire_items( $items, false ),
				'provinceCode' => self::province( $params['provinceCode'] ?? null ),
				'stopdesk'     => isset( $params['stopdesk'] ) ? (bool) $params['stopdesk'] : null,
				'codes'        => self::codes( $params['codes'] ?? null ),
				'phone'        => self::text( $params['phone'] ?? '', 30 ),
				'customerIp'   => Request_Guard::remote_addr(),
			),
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
		$response = $this->storefront->quote( $body );
		return $response->ok() ? self::respond( $response->body ) : self::upstream_error( $response );
	}

	/**
	 * POST upload (multipart, field "image"): a custom-field image.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function upload( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->connection->is_connected() ) {
			return self::error( 404, 'tassyir_not_connected', 'Not connected' );
		}
		if ( ! Request_Guard::allow( 'upload' ) ) {
			return self::error( 429, 'tassyir_rate_limited', 'Too many requests' );
		}
		$files = $request->get_file_params();
		$file  = isset( $files['image'] ) ? (array) $files['image'] : array();
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return self::error( 400, 'tassyir_invalid', 'No file' );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
			return self::error( 413, 'FILE_TOO_LARGE', 'File too large' );
		}
		$name  = sanitize_file_name( (string) $file['name'] );
		$check = wp_check_filetype_and_ext(
			(string) $file['tmp_name'],
			$name,
			array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'webp'     => 'image/webp',
				'gif'      => 'image/gif',
			)
		);
		if ( empty( $check['type'] ) ) {
			return self::error( 400, 'tassyir_invalid', 'Not an image' );
		}
		$bytes    = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's uploaded temp file.
		$response = $this->storefront->upload( $name, (string) $check['type'], $bytes, Request_Guard::remote_addr() );
		return $response->ok() ? self::respond( $response->body ) : self::upstream_error( $response );
	}

	/**
	 * POST orders: honeypot, nonce (permission callback), rate limit, Tassyir `submit`, then the
	 * WooCommerce order on allow / flag; nothing on hold / drop.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function orders( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->connection->is_connected() ) {
			return self::error( 404, 'tassyir_not_connected', 'Not connected' );
		}
		$params = (array) $request->get_json_params();
		if ( '' !== trim( (string) ( $params['website'] ?? '' ) ) ) {
			return self::error( 400, 'tassyir_invalid', 'Invalid request' );
		}
		if ( ! Request_Guard::allow( 'orders' ) ) {
			return self::error( 429, 'tassyir_rate_limited', 'Too many requests' );
		}
		$submission = self::clean_submission( $params );
		if ( is_string( $submission ) ) {
			return self::error( 400, 'tassyir_invalid', $submission );
		}

		$settings = Settings::get();
		$lang     = Settings::language( $settings );

		$existing = Order_Creator::find_existing( (string) $submission['submissionId'] );
		if ( $existing instanceof \WC_Order ) {
			return self::respond( self::success( $existing, array(), $settings ) + array( 'duplicate' => true ) );
		}
		$receipt = Receipt::find_by_submission( (string) $submission['submissionId'] );
		$held    = null === $receipt ? null : Receipt::order( $receipt );
		if ( null !== $receipt && null !== $held ) {
			return self::respond( self::success( $held, (array) $receipt['answer'], $settings, $receipt ) + array( 'duplicate' => true ) );
		}

		$response = $this->storefront->submit( self::submit_body( $submission, ! empty( $settings['behind_cloudflare'] ) ) );
		if ( ! $response->ok() ) {
			return self::upstream_error( $response );
		}
		$answer  = $response->body;
		$verdict = (string) ( $answer['verdict'] ?? '' );

		if ( 'hold' === $verdict || 'drop' === $verdict ) {
			// Tassyir kept the submission in its Spam folder (or dropped it): no WooCommerce order,
			// but the same answer and the same kind of order-received page as an allowed one.
			$receipt = Receipt::create( $submission, $answer, $lang );
			$held    = Receipt::order( $receipt );
			if ( null === $held ) {
				return self::error( 400, 'tassyir_invalid', 'A product is no longer available' );
			}
			return self::respond( self::success( $held, $answer, $settings, $receipt ) );
		}
		if ( 'allow' !== $verdict && 'flag' !== $verdict ) {
			return self::error( 502, 'tassyir_bad_answer', 'Unexpected answer' );
		}

		try {
			$order = Order_Creator::create(
				$submission,
				$answer,
				array(
					'lang'       => $lang,
					'ip'         => Request_Guard::remote_addr(),
					'user_agent' => Request_Guard::user_agent(),
				)
			);
		} catch ( \RuntimeException $e ) {
			return self::error( 400, 'tassyir_invalid', 'A product is no longer available' );
		}
		// The push itself is the queued job Order_Sync scheduled when the order was saved; this
		// only asks Action Scheduler to run it in its own request right after this one.
		Queue::run_soon();
		return self::respond( self::success( $order, $answer, $settings ) );
	}

	/**
	 * Success body: the same keys for a created order and for a held submission's receipt, whose
	 * order number and order-received URL come from the receipt. Only an order has an `eventId`
	 * (Tassyir's quote id), and the form fires Purchase only with one.
	 *
	 * @param \WC_Order            $order    Order (in memory for a receipt).
	 * @param array<string, mixed> $answer   Submit answer ([] for a duplicate order).
	 * @param array<string, mixed> $settings Form settings.
	 * @param array<string, mixed> $receipt  Held submission's receipt ([] for an order).
	 * @return array<string, mixed>
	 */
	private static function success( \WC_Order $order, array $answer, array $settings, array $receipt = array() ): array {
		$total    = (float) $order->get_total();
		$number   = array() === $receipt ? (string) $order->get_order_number() : (string) $receipt['number'];
		$redirect = null;
		if ( 'order_received' === $settings['success_mode'] ) {
			$redirect = array() === $receipt ? $order->get_checkout_order_received_url() : Receipt::url( $receipt );
		} elseif ( 'custom' === $settings['success_mode'] ) {
			$redirect = self::custom_url( $settings, $number, $total );
		}
		return array(
			'success'       => true,
			'orderId'       => $number,
			'total'         => $total,
			'shippingPrice' => (float) $order->get_shipping_total(),
			'freeShipping'  => ! empty( $answer['freeShipping'] ),
			'eventId'       => array() === $receipt ? (string) $order->get_meta( Order_Creator::META_QUOTE_ID ) : '',
			'priceChanged'  => ! empty( $answer['priceChanged'] ),
			'rejectedCodes' => isset( $answer['rejectedCodes'] ) && is_array( $answer['rejectedCodes'] ) ? $answer['rejectedCodes'] : array(),
			'redirect'      => $redirect,
		);
	}

	/**
	 * The merchant's thank-you URL with order and total appended (null when unset).
	 *
	 * @param array<string, mixed> $settings Form settings.
	 * @param string               $order    Order number.
	 * @param float                $total    Total.
	 */
	private static function custom_url( array $settings, string $order, float $total ): ?string {
		$url = (string) $settings['success_url'];
		if ( '' === $url ) {
			return null;
		}
		return add_query_arg(
			array(
				'order'    => rawurlencode( $order ),
				'total'    => rawurlencode( Format::amount( $total ) ),
				'currency' => 'DZD',
			),
			$url
		);
	}

	/**
	 * The form submission, every field checked (the Shopify DTO's limits), or an error message.
	 *
	 * @param array<string, mixed> $params JSON body.
	 * @return array<string, mixed>|string
	 */
	public static function clean_submission( array $params ) {
		$name = self::text( $params['name'] ?? '', 120 );
		if ( '' === $name ) {
			return 'Name required';
		}
		$phone = self::text( $params['phone'] ?? '', 30 );
		if ( '' === Format::mobile( $phone ) ) {
			return 'Invalid phone number';
		}
		$phone2 = self::text( $params['phone2'] ?? '', 30 );
		if ( '' !== $phone2 && '' === Format::mobile( $phone2 ) ) {
			return 'Invalid phone number';
		}
		$province = self::province( $params['provinceCode'] ?? null );
		$town     = isset( $params['townOns'] ) ? (int) $params['townOns'] : 0;
		if ( null === $province || $town < 1 ) {
			return 'Invalid province or commune';
		}
		$submission_id = (string) ( $params['submissionId'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $submission_id ) ) {
			return 'Invalid submission';
		}
		$items = self::clean_items( $params['items'] ?? array() );
		if ( null === $items ) {
			return 'Invalid items';
		}
		$custom = array();
		foreach ( array_slice( (array) ( $params['customFields'] ?? array() ), 0, 20 ) as $field ) {
			if ( is_array( $field ) && '' !== self::text( $field['fieldId'] ?? '', 64 ) ) {
				$custom[] = array(
					'fieldId' => self::text( $field['fieldId'], 64 ),
					'value'   => self::text( $field['value'] ?? '', 2000 ),
				);
			}
		}
		$device = (string) ( $params['deviceId'] ?? '' );

		$out   = array(
			'name'             => $name,
			'phone'            => $phone,
			'phone2'           => $phone2,
			'address'          => self::text( $params['address'] ?? '', 500 ),
			'note'             => sanitize_textarea_field( substr( (string) ( $params['note'] ?? '' ), 0, 1000 ) ),
			'provinceCode'     => $province,
			'townOns'          => $town,
			'stopdesk'         => ! empty( $params['stopdesk'] ),
			'submissionId'     => $submission_id,
			'items'            => $items,
			'customFields'     => $custom,
			'customFieldRules' => ! empty( $params['customFieldRules'] ),
			'deviceId'         => preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $device ) ? $device : '',
		);
		$token = self::text( $params['quoteToken'] ?? '', 2000 );
		if ( '' !== $token ) {
			$out['quoteToken'] = $token;
		}
		$codes = self::codes( $params['codes'] ?? null );
		if ( null !== $codes ) {
			$out['codes'] = $codes;
		}
		return $out;
	}

	/**
	 * 1-10 order lines naming published WooCommerce products (and their own variations), or null.
	 * Each line gets the product's name and SKU for Tassyir.
	 *
	 * @param mixed $items Raw items.
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function clean_items( $items ): ?array {
		if ( ! is_array( $items ) || array() === $items || count( $items ) > 10 ) {
			return null;
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return null;
			}
			$quantity = (int) ( $item['quantity'] ?? 0 );
			$product  = wc_get_product( (int) ( $item['wooProductId'] ?? 0 ) );
			if ( $quantity < 1 || $quantity > 999 || ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
				return null;
			}
			$line         = array(
				'wooProductId' => $product->get_id(),
				'quantity'     => $quantity,
				'title'        => mb_substr( $product->get_name(), 0, 255 ),
			);
			$variation_id = (int) ( $item['wooVariationId'] ?? 0 );
			$sku_source   = $product;
			if ( $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation instanceof \WC_Product_Variation || $variation->get_parent_id() !== $product->get_id() ) {
					return null;
				}
				$line['wooVariationId'] = $variation_id;
				$sku_source             = $variation;
				$attributes             = array();
				foreach ( (array) ( $item['attributes'] ?? array() ) as $key => $value ) {
					if ( is_string( $key ) && 0 === strpos( $key, 'attribute_' ) && is_scalar( $value ) ) {
						$attributes[ sanitize_title( $key ) ] = self::text( $value, 200 );
					}
				}
				if ( array() !== $attributes ) {
					$line['attributes'] = $attributes;
				}
			} elseif ( $product->is_type( 'variable' ) ) {
				return null;
			}
			if ( '' !== (string) $sku_source->get_sku() ) {
				$line['sku'] = mb_substr( (string) $sku_source->get_sku(), 0, 120 );
			}
			if ( '' !== (string) $sku_source->get_price() ) {
				$price         = min( 10000000.0, max( 0.0, (float) $sku_source->get_price() ) );
				$line['price'] = floor( $price ) === $price ? (int) $price : $price;
			}
			$bundle = self::text( $item['bundleId'] ?? '', 64 );
			if ( '' !== $bundle ) {
				$line['bundleId'] = $bundle;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * The order lines as Tassyir takes them: quote (§H5) items carry identity and quantity only;
	 * submit (§H6) items also the title and the WooCommerce price (used only for a line Tassyir
	 * cannot map). The picked "Any" attributes stay in WordPress (they are not §H fields).
	 *
	 * @param array<int, array<string, mixed>> $items  From clean_items().
	 * @param bool                             $submit For submit (true) or quote (false).
	 * @return array<int, array<string, mixed>>
	 */
	public static function wire_items( array $items, bool $submit ): array {
		$keys = $submit
			? array( 'wooProductId', 'wooVariationId', 'sku', 'quantity', 'price', 'bundleId', 'title' )
			: array( 'wooProductId', 'wooVariationId', 'sku', 'quantity', 'bundleId' );
		$out  = array();
		foreach ( $items as $item ) {
			$out[] = array_intersect_key( $item, array_flip( $keys ) );
		}
		return $out;
	}

	/**
	 * The §H6 submit body: exactly its fields (an unknown one is a 400).
	 *
	 * @param array<string, mixed> $submission        From clean_submission().
	 * @param bool                 $behind_cloudflare Merchant setting.
	 * @return array<string, mixed>
	 */
	public static function submit_body( array $submission, bool $behind_cloudflare ): array {
		$body = array(
			'submissionId'     => $submission['submissionId'],
			'name'             => $submission['name'],
			'phone'            => $submission['phone'],
			'provinceCode'     => $submission['provinceCode'],
			'townOns'          => $submission['townOns'],
			'stopdesk'         => $submission['stopdesk'],
			'items'            => self::wire_items( $submission['items'], true ),
			'customFields'     => $submission['customFields'],
			'customFieldRules' => $submission['customFieldRules'],
			'customer'         => Request_Guard::customer( (string) $submission['deviceId'], $behind_cloudflare ),
		);
		foreach ( array( 'phone2', 'address', 'note', 'quoteToken', 'codes' ) as $key ) {
			if ( isset( $submission[ $key ] ) && '' !== $submission[ $key ] && array() !== $submission[ $key ] ) {
				$body[ $key ] = $submission[ $key ];
			}
		}
		return $body;
	}

	/**
	 * A wilaya code 1-58, or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function province( $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$code = (int) $value;
		return $code >= 1 && $code <= 58 ? $code : null;
	}

	/**
	 * At most one discount code, as typed; null when none.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]|null
	 */
	private static function codes( $value ): ?array {
		if ( ! is_array( $value ) || ! isset( $value[0] ) || ! is_scalar( $value[0] ) ) {
			return null;
		}
		$code = self::text( $value[0], 64 );
		return '' === $code ? null : array( $code );
	}

	/**
	 * A one-line text, trimmed and cut to a length.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Max characters.
	 */
	private static function text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( mb_substr( sanitize_text_field( (string) $value ), 0, $max ) );
	}

	/**
	 * A no-store response.
	 *
	 * @param array<string, mixed> $data   Body.
	 * @param int                  $status HTTP status.
	 */
	public static function respond( array $data, int $status = 200 ): \WP_REST_Response {
		self::no_cache_signals();
		$response = new \WP_REST_Response( $data, $status );
		foreach ( self::no_cache_headers() as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/**
	 * An error body the form understands ({ code, message }).
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function error( int $status, string $code, string $message ): \WP_REST_Response {
		return self::respond(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * Tassyir refused or could not be reached. Its own 400 / 409 / 429 bodies go through as they
	 * are (the form reads their code, message and fresh quote); anything else is a 502.
	 *
	 * @param Api_Response $response Tassyir's answer.
	 */
	private static function upstream_error( Api_Response $response ): \WP_REST_Response {
		if ( in_array( $response->status, array( 400, 409, 429 ), true ) ) {
			return self::respond( $response->body, $response->status );
		}
		if ( in_array( $response->status, array( 401, 403, 404 ), true ) ) {
			return self::error( 404, 'tassyir_not_connected', 'Not connected' );
		}
		return self::error( 502, 'tassyir_unavailable', 'Tassyir unavailable (' . $response->code . ')' );
	}

	/**
	 * The headers every route sends.
	 *
	 * @return array<string, string>
	 */
	public static function no_cache_headers(): array {
		return array(
			'Cache-Control'             => 'no-store, no-cache, must-revalidate, max-age=0, private',
			'Pragma'                    => 'no-cache',
			'Expires'                   => '0',
			'X-LiteSpeed-Cache-Control' => 'no-cache',
		);
	}

	/**
	 * Tells page caches not to keep this request: LiteSpeed's API, and DONOTCACHEPAGE (WP Rocket,
	 * W3 Total Cache, WP Super Cache and others read it).
	 */
	public static function no_cache_signals(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page caches' shared constant.
		}
		do_action( 'litespeed_control_set_nocache', 'tassyir cod form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's API.
	}

	/**
	 * rest_post_dispatch: the same headers on every response of our namespace, including the ones
	 * WordPress builds itself (a refused nonce, an unknown route).
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server   Server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function no_cache_response( $response, $server, $request ) {
		if ( $response instanceof \WP_REST_Response && 0 === strpos( ltrim( $request->get_route(), '/' ), self::NAMESPACE_V1 . '/' ) ) {
			self::no_cache_signals();
			foreach ( self::no_cache_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}
}
