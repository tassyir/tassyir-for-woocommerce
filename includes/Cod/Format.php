<?php
/**
 * Pure helpers of the COD form (no WordPress calls): unit-tested.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

defined( 'ABSPATH' ) || exit;

/**
 * Wilaya codes, phones, money and the rate-limit window.
 */
final class Format {

	/**
	 * Arabic-Indic and Eastern Arabic-Indic digits → ASCII.
	 */
	const DIGITS = array(
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
	);

	/**
	 * WooCommerce's state code of a wilaya: DZ- and two digits (16 → DZ-16, 9 → DZ-09).
	 *
	 * @param int $province Wilaya code 1-58.
	 */
	public static function state_code( int $province ): string {
		return sprintf( 'DZ-%02d', $province );
	}

	/**
	 * An Algerian mobile number as 0XXXXXXXXX (05/06/07), or '' when it is not one. Same rule as
	 * Tassyir's (and the form's) check; Tassyir normalizes it again and is the source of truth.
	 *
	 * @param string $raw As typed.
	 */
	public static function mobile( string $raw ): string {
		$digits = (string) preg_replace( '/[\s\-.()]/u', '', strtr( $raw, self::DIGITS ) );
		if ( 0 === strpos( $digits, '00213' ) ) {
			$digits = '0' . substr( $digits, 5 );
		} elseif ( 0 === strpos( $digits, '+213' ) ) {
			$digits = '0' . substr( $digits, 4 );
		}
		return preg_match( '/^0[567]\d{8}$/', $digits ) ? $digits : '';
	}

	/**
	 * A price in the subunit the form's script expects (it divides by 100, like Shopify's).
	 *
	 * @param float|int|string $price Price in DZD.
	 */
	public static function cents( $price ): int {
		return (int) round( (float) $price * 100 );
	}

	/**
	 * Whole dinars as WooCommerce stores them ("1500" or "1500.5").
	 *
	 * @param float|int|string $amount Amount.
	 */
	public static function amount( $amount ): string {
		$value = round( (float) $amount, 2 );
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * One hit on a fixed-window counter.
	 *
	 * @param array{0?: int, 1?: int}|mixed $state  [count, window start] or anything else (new window).
	 * @param int                           $now    Unix time.
	 * @param int                           $max    Hits allowed per window.
	 * @param int                           $window Window length in seconds.
	 * @return array{0: bool, 1: array{0: int, 1: int}} [allowed, new state]
	 */
	public static function hit( $state, int $now, int $max, int $window ): array {
		$count = is_array( $state ) && isset( $state[0], $state[1] ) ? (int) $state[0] : 0;
		$start = is_array( $state ) && isset( $state[0], $state[1] ) ? (int) $state[1] : $now;
		if ( $now - $start >= $window || $start > $now ) {
			$count = 0;
			$start = $now;
		}
		if ( $count >= $max ) {
			return array( false, array( $count, $start ) );
		}
		return array( true, array( $count + 1, $start ) );
	}
}
