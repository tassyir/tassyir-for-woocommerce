<?php
/**
 * First steps after activation: one redirect to the Tassyir page, then a "Connect" notice.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

use Tassyir\WooCommerce\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Merchants arrive from Tassyir → Apps → WooCommerce, install the zip, activate, and must land on
 * admin.php?page=tassyir to click Connect (§G). While the site is not connected:
 * - activation sends the admin there once (not for bulk, network, WP-CLI or AJAX activation);
 * - every other admin screen shows a notice linking there, until connected or dismissed (per user).
 */
final class Onboarding {

	/**
	 * Transient set by the activation hook, consumed by the next admin page load.
	 */
	const REDIRECT_TRANSIENT = 'tassyir_activation_redirect';

	/**
	 * User meta set when a user dismisses the connect notice.
	 */
	const DISMISSED_META = 'tassyir_connect_notice_dismissed';

	/**
	 * Admin-post / AJAX action that dismisses the notice.
	 */
	const DISMISS_ACTION = 'tassyir_dismiss_connect_notice';

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the onboarding hooks.
	 *
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Hooks the redirect, the notice and its dismissal.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Called by the activation hook: remembers to open the Tassyir page on the next admin page.
	 *
	 * @param bool $network_wide Network-wide activation (multisite).
	 * @param bool $cli          Activated from WP-CLI.
	 */
	public static function queue_redirect( bool $network_wide, bool $cli ): void {
		if ( $network_wide || $cli || wp_doing_ajax() || ( new Connection() )->is_connected() ) {
			return;
		}
		set_transient( self::REDIRECT_TRANSIENT, 1, 30 );
	}

	/**
	 * Where to send the browser now, or null. Consumes the activation flag, so it answers once.
	 */
	public function redirect_target(): ?string {
		if ( wp_doing_ajax() || is_network_admin() || ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return null;
		}
		delete_transient( self::REDIRECT_TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only: WordPress adds it after a bulk activation.
		if ( isset( $_GET['activate-multi'] ) || ! current_user_can( Settings_Page::CAPABILITY ) || $this->connection->is_connected() ) {
			return null;
		}
		return Connection::return_url();
	}

	/**
	 * On admin_init: the one redirect after activation.
	 */
	public function maybe_redirect(): void {
		$target = $this->redirect_target();
		if ( null !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * Whether the connect notice is shown to the current user on the current screen.
	 */
	public function notice_visible(): bool {
		if ( ! current_user_can( Settings_Page::CAPABILITY ) || $this->connection->is_connected() ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ! ( $screen instanceof \WP_Screen && false !== strpos( $screen->id, Settings_Page::SLUG ) );
	}

	/**
	 * The "Connect to Tassyir" notice on every other admin screen.
	 */
	public function render_notice(): void {
		if ( ! $this->notice_visible() ) {
			return;
		}
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
		$ajax_url    = wp_nonce_url( admin_url( 'admin-ajax.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
		printf(
			'<div class="notice notice-info is-dismissible tassyir-connect-notice" data-dismiss-url="%7$s"><p><strong>%2$s</strong> %3$s</p><p><a class="button button-primary" href="%4$s">%5$s</a> <a href="%1$s">%6$s</a></p></div>',
			esc_url( $dismiss_url ),
			esc_html__( 'Tassyir for WooCommerce is almost ready.', 'tassyir-for-woocommerce' ),
			esc_html__( 'Connect this store to start sending your orders to Tassyir.', 'tassyir-for-woocommerce' ),
			esc_url( Connection::return_url() ),
			esc_html__( 'Connect to Tassyir', 'tassyir-for-woocommerce' ),
			esc_html__( 'Don’t show again', 'tassyir-for-woocommerce' ),
			esc_url( $ajax_url )
		);
	}

	/**
	 * Where the notice shows: the script that makes its × button remember the choice.
	 */
	public function enqueue_assets(): void {
		if ( $this->notice_visible() ) {
			wp_enqueue_script( 'tassyir-connect-notice', plugins_url( 'assets/js/connect-notice.js', TASSYIR_WC_FILE ), array(), TASSYIR_WC_VERSION, true );
		}
	}

	/**
	 * Dismisses the notice for the current user (link, or the × button through fetch()).
	 */
	public function handle_dismiss(): void {
		check_admin_referer( self::DISMISS_ACTION );
		if ( current_user_can( Settings_Page::CAPABILITY ) ) {
			update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );
		}
		if ( wp_doing_ajax() ) {
			wp_send_json_success();
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
