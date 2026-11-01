<?php
/**
 * Integration tests: the WordPress test suite with WooCommerce and this plugin loaded.
 * Run inside wp-env (tests-cli), where WP_TESTS_DIR points at the WordPress test library.
 *
 * @package Tassyir\WooCommerce
 */

$tassyir_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

require_once dirname( __DIR__ ) . '/vendor/autoload.php'; // PHPUnit polyfills, our classes.
require_once $tassyir_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		define( 'TASSYIR_API_URL', 'https://api.tassyir.test' );
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once dirname( __DIR__ ) . '/tassyir-for-woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		// Create WooCommerce's tables and pages once, like WooCommerce's own test bootstrap.
		\WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles after install.
		wp_roles();

		// TASSYIR_HPOS=1 runs the suite with orders in the HPOS tables, otherwise in wp_posts.
		$hpos = (bool) getenv( 'TASSYIR_HPOS' );
		if ( $hpos ) {
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		}
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		update_option( 'woocommerce_feature_custom_order_tables_enabled', $hpos ? 'yes' : 'no' );
		fwrite( STDOUT, 'Order storage: ' . ( $hpos ? 'HPOS' : 'legacy posts' ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
);

tests_add_filter(
	'init',
	static function () {
		if ( (bool) getenv( 'TASSYIR_HPOS' ) !== \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			fwrite( STDERR, "Order storage was not switched as asked.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			exit( 1 );
		}
	}
);

require $tassyir_tests_dir . '/includes/bootstrap.php';
