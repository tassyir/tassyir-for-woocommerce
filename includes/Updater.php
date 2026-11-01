<?php
/**
 * Self-update from Tassyir (contract §F4) via the plugin-update-checker library.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress shows the usual "Update now" link when /woo-plugin/update.json announces a newer
 * version. The wordpress.org build must not self-update: it ships without vendor-prefixed/, so
 * the library class is missing and this does nothing.
 */
final class Updater {

	/**
	 * Library factory, namespace-prefixed by Strauss (see composer.json "extra.strauss").
	 */
	const FACTORY = 'Tassyir\\WooCommerce\\Vendor\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';

	/**
	 * Registers the update checker.
	 */
	public static function register(): void {
		// Composer skips a "files" autoload whose id another plugin already loaded (same library,
		// unprefixed): load our prefixed copy directly in that case.
		$loader = TASSYIR_WC_DIR . 'vendor-prefixed/yahnis-elsts/plugin-update-checker/load-v5p7.php';
		if ( ! class_exists( self::FACTORY ) && is_readable( $loader ) ) {
			require_once $loader;
		}
		if ( ! class_exists( self::FACTORY ) ) {
			return;
		}
		call_user_func(
			array( self::FACTORY, 'buildUpdateChecker' ),
			Plugin::api_url() . '/woo-plugin/update.json',
			TASSYIR_WC_FILE,
			'tassyir-for-woocommerce'
		);
	}
}
