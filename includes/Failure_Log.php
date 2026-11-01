<?php
/**
 * The last 20 failures, shown on the settings page.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * A tiny ring buffer in one option (autoload off). Entries hold codes, never Tassyir messages
 * or request headers.
 */
final class Failure_Log {

	const OPTION = 'tassyir_failures';
	const SIZE   = 20;

	/**
	 * Adds an entry, newest first.
	 *
	 * @param string $type      What failed: order, product, image, heartbeat, connect.
	 * @param int    $object_id WooCommerce id (0 when not about one object).
	 * @param string $code      Error or reason code.
	 * @param string $detail    Optional technical detail (e.g. a cURL error).
	 */
	public static function add( string $type, int $object_id, string $code, string $detail = '' ): void {
		$entries = self::all();
		array_unshift(
			$entries,
			array(
				'time'   => time(),
				'type'   => $type,
				'id'     => $object_id,
				'code'   => $code,
				'detail' => mb_substr( $detail, 0, 300 ),
			)
		);
		update_option( self::OPTION, array_slice( $entries, 0, self::SIZE ), false );
	}

	/**
	 * Every entry, newest first.
	 *
	 * @return array<int, array{time: int, type: string, id: int, code: string, detail: string}>
	 */
	public static function all(): array {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Empties the log.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
