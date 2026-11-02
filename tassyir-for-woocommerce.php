<?php
/**
 * Plugin Name:          Tassyir for WooCommerce
 * Description:          Send WooCommerce orders and products to Tassyir, with a cash-on-delivery order form. Every order is queued and retried until Tassyir confirms it.
 * Version:              1.0.1
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Tassyir
 * Author URI:           https://tassyir.io
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          tassyir-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package Tassyir\WooCommerce
 */

defined( 'ABSPATH' ) || exit; // Never run when the file is opened directly.

define( 'TASSYIR_WC_VERSION', '1.0.1' );
define( 'TASSYIR_WC_FILE', __FILE__ );
define( 'TASSYIR_WC_DIR', plugin_dir_path( __FILE__ ) );

// TASSYIR_API_URL and TASSYIR_APP_URL may be defined in wp-config.php to point the plugin at a
// local Tassyir. They are read by Tassyir\WooCommerce\Plugin::api_url() and ::app_url().

/*
 * Composer autoloaders: ours (PSR-4, includes/) and the namespace-prefixed vendor libraries.
 * A zip built with bin/build.sh always contains both; a git checkout needs `composer install`.
 */
if ( ! is_readable( TASSYIR_WC_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Tassyir for WooCommerce is incomplete (missing vendor files). Please reinstall it from the zip downloaded from your Tassyir dashboard.', 'tassyir-for-woocommerce' );
			echo '</p></div>';
		}
	);
	return;
}
require_once TASSYIR_WC_DIR . 'vendor/autoload.php';
if ( is_readable( TASSYIR_WC_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once TASSYIR_WC_DIR . 'vendor-prefixed/autoload.php';
}

// Tell WooCommerce we support HPOS (custom order tables) and the cart/checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TASSYIR_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TASSYIR_WC_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \Tassyir\WooCommerce\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Tassyir\WooCommerce\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Tassyir\WooCommerce\Plugin::class, 'boot' ) );
