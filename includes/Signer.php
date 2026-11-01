<?php
/**
 * Request signing, contract §A. Pure functions: no WordPress calls, unit-tested.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the X-Tassyir-* headers of a signed request.
 *
 * signature = base64( HMAC-SHA256( base64url_decode(secret), canonical ) ), where canonical is
 * "<timestamp>\n<nonce>\n<METHOD>\n<path>\n<sha256 hex of the raw body>".
 */
final class Signer {

	/**
	 * The five canonical lines joined with "\n" (no trailing newline).
	 *
	 * @param int    $timestamp Unix seconds.
	 * @param string $nonce     32 lowercase hex chars.
	 * @param string $method    HTTP method (upper-cased here).
	 * @param string $path      Path exactly as sent, without query string, e.g. /woo-plugin/orders.
	 * @param string $body      Raw request body bytes ('' when there is no body).
	 */
	public static function canonical( int $timestamp, string $nonce, string $method, string $path, string $body ): string {
		return implode(
			"\n",
			array(
				(string) $timestamp,
				$nonce,
				strtoupper( $method ),
				$path,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Base64 HMAC-SHA256 of the canonical string.
	 *
	 * @param string $secret    The secret from connect/exchange, base64url (as stored).
	 * @param string $canonical Output of canonical().
	 */
	public static function signature( string $secret, string $canonical ): string {
		$key = self::base64url_decode( $secret );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API contract defines the signature as base64.
		return base64_encode( hash_hmac( 'sha256', $canonical, $key, true ) );
	}

	/**
	 * Every signing header of one request. The secret itself is never part of the result.
	 *
	 * @param string      $connection_id Tassyir connection id.
	 * @param string      $secret        Base64url secret.
	 * @param string      $method        HTTP method.
	 * @param string      $path          Request path.
	 * @param string      $body          Raw body.
	 * @param int|null    $timestamp     Unix seconds (tests pass a fixed value).
	 * @param string|null $nonce         Nonce (tests pass a fixed value).
	 * @return array<string, string>
	 */
	public static function headers( string $connection_id, string $secret, string $method, string $path, string $body, ?int $timestamp = null, ?string $nonce = null ): array {
		$timestamp = null === $timestamp ? time() : $timestamp;
		$nonce     = null === $nonce ? self::new_nonce() : $nonce;

		return array(
			'X-Tassyir-Connection' => $connection_id,
			'X-Tassyir-Timestamp'  => (string) $timestamp,
			'X-Tassyir-Nonce'      => $nonce,
			'X-Tassyir-Signature'  => self::signature( $secret, self::canonical( $timestamp, $nonce, $method, $path, $body ) ),
			'X-Tassyir-Plugin'     => TASSYIR_WC_VERSION,
		);
	}

	/**
	 * 16 random bytes as 32 lowercase hex chars.
	 */
	public static function new_nonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Decodes base64url (RFC 4648 §5, padding optional) to raw bytes.
	 *
	 * @param string $value Base64url text.
	 */
	public static function base64url_decode( string $value ): string {
		$base64 = strtr( $value, '-_', '+/' );
		$pad    = strlen( $base64 ) % 4;
		if ( $pad > 0 ) {
			$base64 .= str_repeat( '=', 4 - $pad );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the API contract defines the secret as base64url.
		$raw = base64_decode( $base64, true );
		return false === $raw ? '' : $raw;
	}

	/**
	 * A random URL-safe token (base64url of $bytes random bytes, no padding).
	 *
	 * @param int $bytes Number of random bytes.
	 */
	public static function random_token( int $bytes = 32 ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe random token, not obfuscation.
		return rtrim( strtr( base64_encode( random_bytes( max( 1, $bytes ) ) ), '+/', '-_' ), '=' );
	}
}
