<?php
/**
 * The ONE door to Tassyir: every HTTP call of the plugin goes through this class.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Sends JSON (or multipart) requests with wp_remote_request(), signed per contract §A, and maps
 * every answer to an Api_Response. Never logs or displays the secret or the signed headers.
 */
class Api_Client {

	const TIMEOUT = 20;

	/**
	 * Stored connection (id + secret).
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the client.
	 *
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * POST /woo-plugin/connect/exchange — unsigned, the one-time code is the credential (§B3).
	 *
	 * @param array<string, mixed> $data Request body.
	 */
	public function exchange( array $data ): Api_Response {
		return $this->send( '/woo-plugin/connect/exchange', self::encode( $data ), 'application/json', false );
	}

	/**
	 * Signed JSON POST.
	 *
	 * @param string                      $path Path such as /woo-plugin/orders.
	 * @param array<string, mixed>|object $data Body; pass `new \stdClass()` to send `{}`.
	 */
	public function post( string $path, $data ): Api_Response {
		return $this->send( $path, self::encode( $data ), 'application/json', true );
	}

	/**
	 * Signed multipart/form-data POST with one file part (§D2). The body hash covers the whole
	 * multipart body, exactly as sent.
	 *
	 * @param string                    $path      Path such as /woo-plugin/images.
	 * @param array<string, string|int> $fields    Plain form fields.
	 * @param string                    $file_name File name sent in the file part.
	 * @param string                    $mime      File MIME type.
	 * @param string                    $bytes     File content.
	 * @param string                    $part      Name of the file part (`file` for §D2, `image` for §H4).
	 */
	public function post_multipart( string $path, array $fields, string $file_name, string $mime, string $bytes, string $part = 'file' ): Api_Response {
		$boundary = 'tassyir-' . Signer::new_nonce();
		$body     = '';
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $part . '"; filename="' . str_replace( array( '"', "\r", "\n" ), '', $file_name ) . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body .= $bytes . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		return $this->send( $path, $body, 'multipart/form-data; boundary=' . $boundary, true );
	}

	/**
	 * JSON-encodes a body the same way for every request (UTF-8 and slashes left readable).
	 *
	 * @param mixed $data Body.
	 */
	public static function encode( $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	/**
	 * Sends one request and maps the answer.
	 *
	 * @param string $path         Request path.
	 * @param string $body         Raw body bytes.
	 * @param string $content_type Content-Type header.
	 * @param bool   $signed       Whether to add the §A signing headers.
	 */
	private function send( string $path, string $body, string $content_type, bool $signed ): Api_Response {
		$headers = array(
			'Content-Type' => $content_type,
			'Accept'       => 'application/json',
		);

		if ( $signed ) {
			if ( ! $this->connection->is_connected() ) {
				return new Api_Response( 0, array(), 'NOT_CONNECTED' );
			}
			$headers += Signer::headers( $this->connection->id(), $this->connection->secret(), 'POST', $path, $body );
		} else {
			$headers['X-Tassyir-Plugin'] = TASSYIR_WC_VERSION;
		}

		$raw = wp_remote_request(
			Plugin::api_url() . $path,
			array(
				'method'      => 'POST',
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
				'data_format' => 'body',
			)
		);

		$response = self::map( $raw );

		if ( $signed ) {
			$this->connection->note_signed_response( $response );
		}
		return $response;
	}

	/**
	 * Maps a wp_remote_request() result to an Api_Response.
	 *
	 * @param array<string, mixed>|\WP_Error $raw Result of wp_remote_request().
	 */
	public static function map( $raw ): Api_Response {
		if ( is_wp_error( $raw ) ) {
			return new Api_Response( 0, array(), Api_Response::NETWORK_ERROR, 0, $raw->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $raw );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $raw ), true );
		$body    = is_array( $decoded ) ? $decoded : array();
		$retry   = (int) wp_remote_retrieve_header( $raw, 'retry-after' );

		if ( $status >= 200 && $status < 300 ) {
			return new Api_Response( $status, $body, '', $retry );
		}

		$code = isset( $body['code'] ) && is_string( $body['code'] ) && preg_match( '/^[A-Z0-9_]{1,64}$/', $body['code'] )
			? $body['code']
			: 'HTTP_' . $status;

		// A signature refused while our clock is off by minutes: say so, a reconnect would not help.
		if ( 401 === $status ) {
			$server_time = strtotime( (string) wp_remote_retrieve_header( $raw, 'date' ) );
			if ( false !== $server_time && abs( $server_time - time() ) > 240 ) {
				return new Api_Response( $status, $body, Api_Response::CLOCK_SKEW, $retry, sprintf( '%+d s', time() - $server_time ) );
			}
		}

		return new Api_Response( $status, $body, $code, $retry );
	}
}
