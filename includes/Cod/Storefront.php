<?php
/**
 * Tassyir's storefront routes for the COD form (contract §H), called server-side and signed.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

use Tassyir\WooCommerce\Api_Client;
use Tassyir\WooCommerce\Api_Response;
use Tassyir\WooCommerce\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * The browser never talks to Tassyir: the plugin's REST routes call these, signed with the
 * connection secret (§A). Contract §H: every route is a signed POST; the shapes are the Shopify
 * App Proxy's (config, towns, product-fields, upload, quote), keyed by the plugin connection
 * instead of a shop domain, plus `submit` (the verdict). An unknown body field is a 400, so the
 * bodies here carry exactly §H's fields.
 */
final class Storefront {

	const PATH_CONFIG = '/woo-plugin/storefront/config';
	const PATH_TOWNS  = '/woo-plugin/storefront/towns';
	const PATH_FIELDS = '/woo-plugin/storefront/product-fields';
	const PATH_UPLOAD = '/woo-plugin/storefront/upload';
	const PATH_QUOTE  = '/woo-plugin/storefront/quote';
	const PATH_SUBMIT = '/woo-plugin/storefront/submit';

	/**
	 * Config and towns are cached this long (seconds).
	 */
	const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Transient prefix of the cached answers.
	 */
	const CACHE_PREFIX = 'tassyir_sf_';

	/**
	 * HTTP client.
	 *
	 * @var Api_Client
	 */
	private $api;

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the client.
	 *
	 * @param Api_Client $api        HTTP client.
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Api_Client $api, Connection $connection ) {
		$this->api        = $api;
		$this->connection = $connection;
	}

	/**
	 * Provinces, fees and pricing mode; cached 10 minutes (only successful answers).
	 */
	public function config(): Api_Response {
		return $this->cached( 'config', self::PATH_CONFIG, new \stdClass() );
	}

	/**
	 * The communes of a wilaya the merchant's carrier serves; cached 10 minutes.
	 *
	 * @param int $province Wilaya code 1-58.
	 */
	public function towns( int $province ): Api_Response {
		return $this->cached( 'towns_' . $province, self::PATH_TOWNS, array( 'province' => $province ) );
	}

	/**
	 * Custom fields and quantity offer of a WooCommerce product (Tassyir finds its product
	 * through Phase 1's product link). Not cached here: Tassyir caches it, and it changes
	 * whenever the merchant edits the product's offer.
	 *
	 * @param int $woo_product_id WooCommerce product id.
	 */
	public function product_fields( int $woo_product_id ): Api_Response {
		return $this->api->post(
			self::PATH_FIELDS,
			array(
				'wooProductId' => $woo_product_id,
				'rules'        => true,
			)
		);
	}

	/**
	 * The server's price of the cart (never cached).
	 *
	 * @param array<string, mixed> $body Quote body.
	 */
	public function quote( array $body ): Api_Response {
		return $this->api->post( self::PATH_QUOTE, $body );
	}

	/**
	 * Uploads a custom-field image (§H4: file part `image`, text part `customerIp`).
	 *
	 * @param string $name        File name.
	 * @param string $mime        MIME type.
	 * @param string $bytes       File contents.
	 * @param string $customer_ip The customer's REMOTE_ADDR (Tassyir's per-IP limit).
	 */
	public function upload( string $name, string $mime, string $bytes, string $customer_ip ): Api_Response {
		$fields = '' !== $customer_ip ? array( 'customerIp' => $customer_ip ) : array();
		return $this->api->post_multipart( self::PATH_UPLOAD, $fields, $name, $mime, $bytes, 'image' );
	}

	/**
	 * The order the customer submitted: Tassyir prices it and gives its spam verdict.
	 *
	 * @param array<string, mixed> $body Submit body.
	 */
	public function submit( array $body ): Api_Response {
		return $this->api->post( self::PATH_SUBMIT, $body );
	}

	/**
	 * A cached POST: successful answers are kept CACHE_TTL, keyed by connection (a reconnect
	 * starts with an empty cache).
	 *
	 * @param string $key  Cache key part.
	 * @param string $path Request path.
	 * @param mixed  $body Request body.
	 */
	private function cached( string $key, string $path, $body ): Api_Response {
		$transient = self::CACHE_PREFIX . md5( $this->connection->id() . '|' . $key );
		$hit       = get_transient( $transient );
		if ( is_array( $hit ) ) {
			return new Api_Response( 200, $hit );
		}
		$response = $this->api->post( $path, $body );
		if ( $response->ok() ) {
			set_transient( $transient, $response->body, self::CACHE_TTL );
		}
		return $response;
	}
}
