<?php
/**
 * The outcome of one call to Tassyir.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Plain value object returned by Api_Client. Never contains request headers or the secret.
 */
final class Api_Response {

	/**
	 * No HTTP response at all (DNS, TLS, timeout, firewall…).
	 */
	const NETWORK_ERROR = 'NETWORK_ERROR';

	/**
	 * Tassyir answered 401 and its Date header is far from our clock: the host's clock is wrong.
	 */
	const CLOCK_SKEW = 'CLOCK_SKEW';

	/**
	 * HTTP status, 0 when there was no response.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Decoded JSON body ([] when empty or not JSON).
	 *
	 * @var array<string, mixed>
	 */
	public $body;

	/**
	 * '' on success, else the error code: Tassyir's `code`, NETWORK_ERROR, CLOCK_SKEW or HTTP_<status>.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * Seconds from a Retry-After header (0 when absent).
	 *
	 * @var int
	 */
	public $retry_after;

	/**
	 * Technical detail for the admin log (e.g. "cURL error 28: timed out"). Never a Tassyir message.
	 *
	 * @var string
	 */
	public $detail;

	/**
	 * Builds a response.
	 *
	 * @param int                  $status      HTTP status (0 = none).
	 * @param array<string, mixed> $body        Decoded body.
	 * @param string               $code        Error code ('' on success).
	 * @param int                  $retry_after Retry-After seconds.
	 * @param string               $detail      Technical detail.
	 */
	public function __construct( int $status, array $body, string $code = '', int $retry_after = 0, string $detail = '' ) {
		$this->status      = $status;
		$this->body        = $body;
		$this->code        = $code;
		$this->retry_after = $retry_after;
		$this->detail      = $detail;
	}

	/**
	 * True for any 2xx answer.
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * True when trying again later may succeed: network errors, 5xx, 408, 429, and auth/version
	 * refusals (they recover once the merchant reconnects or updates the plugin).
	 */
	public function retryable(): bool {
		if ( 0 === $this->status || $this->status >= 500 ) {
			return true;
		}
		return in_array( $this->status, array( 401, 408, 409, 426, 429 ), true );
	}
}
