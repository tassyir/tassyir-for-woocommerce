<?php
/**
 * What the COD form's public routes check before calling Tassyir: the nonce, the per-IP rate
 * limit, and who the customer is (IP, forwarded headers, device).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce, rate limits and the customer's network identity.
 */
final class Request_Guard {

	const NONCE_ACTION = 'tassyir_cod_form';
	const NONCE_HEADER = 'X-Tassyir-Nonce';

	/**
	 * Hits allowed per window, by bucket: [max, window seconds].
	 */
	const LIMITS = array(
		'orders' => array( 8, 10 * MINUTE_IN_SECONDS ),
		'quote'  => array( 120, 10 * MINUTE_IN_SECONDS ),
		'upload' => array( 20, 10 * MINUTE_IN_SECONDS ),
	);

	/**
	 * Forwarded-for headers, in the order their first hop is taken (unverified).
	 */
	const FORWARDED = array(
		'HTTP_CF_CONNECTING_IP' => 'cf-connecting-ip',
		'HTTP_X_FORWARDED_FOR'  => 'x-forwarded-for',
		'HTTP_X_REAL_IP'        => 'x-real-ip',
	);

	/**
	 * A fresh nonce (for GET /nonce; the HTML never carries one, cached pages would go stale).
	 */
	public static function nonce(): string {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * Whether the request carries a valid nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function nonce_ok( \WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( self::NONCE_HEADER );
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * One hit for this customer in a bucket; false once the bucket is full.
	 *
	 * @param string $bucket orders | quote | upload.
	 */
	public static function allow( string $bucket ): bool {
		list( $max, $window )    = self::LIMITS[ $bucket ] ?? array( 30, 10 * MINUTE_IN_SECONDS );
		$key                     = 'tassyir_rl_' . md5( $bucket . '|' . self::rate_key() );
		list( $allowed, $state ) = Format::hit( get_transient( $key ), time(), (int) $max, (int) $window );
		set_transient( $key, $state, (int) $window );
		return $allowed;
	}

	/**
	 * REMOTE_ADDR: the address that really connected (a proxy's, behind a CDN).
	 */
	public static function remote_addr(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * The first hop of the forwarded-for headers (Cloudflare's first), or ''. Any client can set
	 * these headers: Tassyir uses it only under its own conditions (§H6 "The customer IP").
	 */
	public static function forwarded_ip(): string {
		foreach ( array_keys( self::FORWARDED ) as $server_key ) {
			if ( ! isset( $_SERVER[ $server_key ] ) ) {
				continue;
			}
			$first = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) )[0] );
			if ( false !== filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return '';
	}

	/**
	 * The user agent, cut to 500 characters.
	 */
	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
	}

	/**
	 * The `customer` block of §H6: REMOTE_ADDR, the forwarded first hop (unverified), whether the
	 * merchant says the site is behind Cloudflare, the user agent and the form's device id.
	 *
	 * @param string $device_id        Device id from the form ('' for none).
	 * @param bool   $behind_cloudflare Merchant setting.
	 * @return array<string, mixed>
	 */
	public static function customer( string $device_id, bool $behind_cloudflare ): array {
		$customer  = array( 'ip' => self::remote_addr() );
		$forwarded = self::forwarded_ip();
		if ( '' !== $forwarded ) {
			$customer['forwardedIp'] = $forwarded;
		}
		$customer['behindCloudflare'] = $behind_cloudflare;
		$agent                        = self::user_agent();
		if ( '' !== $agent ) {
			$customer['userAgent'] = $agent;
		}
		if ( '' !== $device_id ) {
			$customer['deviceId'] = $device_id;
		}
		return $customer;
	}

	/**
	 * The rate-limit key: REMOTE_ADDR plus the first forwarded hop, so customers behind one CDN
	 * address do not share a bucket. (Spoofing the header only buys a new bucket; Tassyir's spam
	 * gate, not this counter, is the real protection.)
	 */
	private static function rate_key(): string {
		return self::remote_addr() . '|' . self::forwarded_ip();
	}
}
