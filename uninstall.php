<?php
/**
 * Runs when the merchant DELETES the plugin (contract §G): tell Tassyir (best effort), delete
 * our options and scheduled jobs. Order meta (_tassyir_*) is kept on purpose.
 *
 * @package Tassyir\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'TASSYIR_WC_VERSION' ) ) {
	define( 'TASSYIR_WC_VERSION', '1.0.1' );
}

$tassyir_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $tassyir_autoload ) ) {
	require_once $tassyir_autoload;

	$tassyir_connection = new \Tassyir\WooCommerce\Connection();
	if ( $tassyir_connection->is_connected() ) {
		( new \Tassyir\WooCommerce\Api_Client( $tassyir_connection ) )->post( '/woo-plugin/disconnect', new \stdClass() );
	}
}

// Action Scheduler is part of WooCommerce: only reachable while WooCommerce is active.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'tassyir' );
}

$tassyir_options = array(
	'tassyir_connection_id',
	'tassyir_secret',
	'tassyir_connection',
	'tassyir_settings',
	'tassyir_failures',
	'tassyir_pending_acks',
	'tassyir_done_commands',
	'tassyir_cron_last_run',
	'tassyir_heartbeat_interval',
	'tassyir_cod_form',
);
foreach ( $tassyir_options as $tassyir_option ) {
	delete_option( $tassyir_option );
}
delete_transient( 'tassyir_activation_redirect' );
delete_metadata( 'user', 0, 'tassyir_connect_notice_dismissed', '', true );
