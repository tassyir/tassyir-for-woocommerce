<?php
/**
 * The COD form's settings (WooCommerce → Tassyir → Checkout form).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

defined( 'ABSPATH' ) || exit;

/**
 * One option, autoload off. Delivery fees and desk availability are not settings: they come from
 * the merchant's Tassyir fee group and carrier (one source of truth).
 */
final class Settings {

	const OPTION = 'tassyir_cod_form';

	const PLACEMENT_MODES = array( 'replace', 'hide_cart', 'keep' );
	const SUCCESS_MODES   = array( 'order_received', 'custom', 'inline' );
	const LANGUAGES       = array( 'auto', 'ar', 'fr', 'en' );
	const DIRECTIONS      = array( 'auto', 'rtl', 'ltr' );
	const COLORS          = array(
		'color_primary'     => '#111111',
		'color_button_text' => '#ffffff',
		'color_bg'          => '#ffffff',
		'color_text'        => '#111111',
		'color_border'      => '#ebebeb',
	);
	const TOGGLES         = array(
		'show_quantity'       => true,
		'show_phone2'         => false,
		'show_address'        => true,
		'require_address'     => false,
		'show_note'           => false,
		'show_icons'          => true,
		'show_stopdesk'       => true,
		'show_offers'         => true,
		'show_bundle_savings' => true,
		'behind_cloudflare'   => false,
	);

	/**
	 * Saved settings over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return array_replace( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Saves sanitized settings.
	 *
	 * @param array<string, mixed> $settings From sanitize().
	 */
	public static function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Defaults. The pixels are off when PixelYourSite is active: it already sends Purchase from
	 * WooCommerce's order-received page, so a second one would double count.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$pixels = ! self::pixel_plugin_active();
		return array_merge(
			array(
				'product_pages'  => false,
				'placement_mode' => 'replace',
				'language'       => 'auto',
				'direction'      => 'auto',
				'border_radius'  => 8,
				'success_mode'   => 'order_received',
				'success_url'    => '',
				'pixel_meta'     => $pixels,
				'pixel_tiktok'   => $pixels,
				'texts'          => array(),
			),
			self::COLORS,
			self::TOGGLES
		);
	}

	/**
	 * Whether PixelYourSite (free or pro) is active.
	 */
	public static function pixel_plugin_active(): bool {
		return defined( 'PYS_VERSION' ) || defined( 'PYS_FREE_VERSION' ) || function_exists( 'PixelYourSite\PYS' ) || class_exists( 'PixelYourSite\PYS' );
	}

	/**
	 * Settings from the admin form, every value checked.
	 *
	 * @param array<string, mixed> $input Raw (unslashed) $_POST values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$out = self::defaults();

		$out['product_pages']  = ! empty( $input['product_pages'] );
		$out['placement_mode'] = self::pick( $input, 'placement_mode', self::PLACEMENT_MODES );
		$out['language']       = self::pick( $input, 'language', self::LANGUAGES );
		$out['direction']      = self::pick( $input, 'direction', self::DIRECTIONS );
		$out['success_mode']   = self::pick( $input, 'success_mode', self::SUCCESS_MODES );
		$out['success_url']    = isset( $input['success_url'] ) ? esc_url_raw( trim( (string) $input['success_url'] ), array( 'http', 'https' ) ) : '';
		$out['pixel_meta']     = ! empty( $input['pixel_meta'] );
		$out['pixel_tiktok']   = ! empty( $input['pixel_tiktok'] );
		$out['border_radius']  = isset( $input['border_radius'] ) ? max( 0, min( 24, (int) $input['border_radius'] ) ) : 8;

		foreach ( self::COLORS as $key => $fallback ) {
			$color       = isset( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : '';
			$out[ $key ] = $color ? $color : $fallback;
		}
		foreach ( array_keys( self::TOGGLES ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}

		$texts = array();
		$given = isset( $input['texts'] ) && is_array( $input['texts'] ) ? $input['texts'] : array();
		foreach ( Texts::LANGUAGES as $lang ) {
			foreach ( Texts::EDITABLE as $key ) {
				$value = isset( $given[ $lang ][ $key ] ) ? sanitize_text_field( (string) $given[ $lang ][ $key ] ) : '';
				if ( '' !== $value ) {
					$texts[ $lang ][ $key ] = $value;
				}
			}
		}
		$out['texts'] = $texts;
		return $out;
	}

	/**
	 * The form's language: the setting, or the site's (Arabic when it is none of the three).
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public static function language( array $settings ): string {
		$lang = (string) $settings['language'];
		if ( 'auto' !== $lang ) {
			return $lang;
		}
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		foreach ( array( 'fr', 'en' ) as $candidate ) {
			if ( 0 === strpos( (string) $locale, $candidate ) ) {
				return $candidate;
			}
		}
		return 'ar';
	}

	/**
	 * Text direction: the setting, or the language's.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $lang     Resolved language.
	 */
	public static function direction( array $settings, string $lang ): string {
		$dir = (string) $settings['direction'];
		if ( 'auto' !== $dir ) {
			return $dir;
		}
		return 'ar' === $lang ? 'rtl' : 'ltr';
	}

	/**
	 * The form's texts in a language: built-in, with the merchant's overrides.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $lang     Resolved language.
	 * @return array<string, string>
	 */
	public static function texts( array $settings, string $lang ): array {
		$texts     = Texts::defaults( $lang );
		$overrides = isset( $settings['texts'][ $lang ] ) && is_array( $settings['texts'][ $lang ] ) ? $settings['texts'][ $lang ] : array();
		foreach ( $overrides as $key => $value ) {
			if ( in_array( $key, Texts::EDITABLE, true ) && is_string( $value ) && '' !== $value ) {
				$texts[ $key ] = $value;
			}
		}
		return $texts;
	}

	/**
	 * One allowed value from the input, else the default.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param string               $key     Key.
	 * @param string[]             $allowed Allowed values, the first is the default.
	 */
	private static function pick( array $input, string $key, array $allowed ): string {
		$value = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
		return in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}
}
