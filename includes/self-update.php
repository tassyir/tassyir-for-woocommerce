<?php
/**
 * Self-hosted build only (Composer "files" autoload): updates from Tassyir (contract §F4).
 * The wordpress.org build ships without this file, Updater.php and the update-checker library.
 *
 * @package Tassyir\WooCommerce
 */

// Composer loads this file with every autoloader, also in tests before WordPress exists.
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'add_action' ) ) {
	return;
}

// On init, not earlier: the checker may schedule its cron event, and WooCommerce's
// cron_schedules filter translates strings, which WordPress refuses before init.
add_action( 'init', array( \Tassyir\WooCommerce\Updater::class, 'register' ) );
