<?php
/**
 * Admin notices shown on every wp-admin screen to shop managers.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

use Tassyir\WooCommerce\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce missing, reconnect needed, update needed, clock wrong, manual webhooks left.
 */
final class Notices {

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the notices.
	 *
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Hooks the notices.
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Shown instead of everything else when WooCommerce (≥ 8.0) is not active.
	 */
	public static function woocommerce_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Tassyir for WooCommerce needs WooCommerce 8.0 or newer. Please install and activate WooCommerce; until then the plugin does nothing.', 'tassyir-for-woocommerce' );
		echo '</p></div>';
	}

	/**
	 * Connection problems and leftover manual webhooks.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->connection->is_connected() ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=tassyir' );
		$problem      = (string) $this->connection->get( 'problem', '' );
		$data         = (string) $this->connection->get( 'problemData', '' );
		$message      = '';

		if ( Connection::PROBLEM_RECONNECT === $problem ) {
			$message = __( 'Tassyir: this site must be reconnected. Orders are kept in the queue until then.', 'tassyir-for-woocommerce' );
		} elseif ( Connection::PROBLEM_OUTDATED === $problem ) {
			/* translators: %s: minimum plugin version, e.g. 1.2.0. */
			$message = sprintf( __( 'Tassyir: please update the Tassyir for WooCommerce plugin to version %s or newer. Orders are kept in the queue until then.', 'tassyir-for-woocommerce' ), $data );
		} elseif ( Connection::PROBLEM_CLOCK === $problem ) {
			/* translators: %s: clock difference, e.g. +600 s. */
			$message = sprintf( __( 'Tassyir: your server clock is wrong (%s), so Tassyir refuses its requests. Ask your host to fix the server time.', 'tassyir-for-woocommerce' ), $data );
		}

		if ( '' !== $message ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( $message ),
				esc_url( $settings_url ),
				esc_html__( 'Open Tassyir settings', 'tassyir-for-woocommerce' )
			);
		}

		$webhooks = (int) $this->connection->get( 'manualWebhooksLeft', 0 );
		if ( $webhooks > 0 && ! self::on_settings_page() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of webhooks. */
						_n( 'Tassyir: %d old manual WooCommerce webhook is no longer needed.', 'Tassyir: %d old manual WooCommerce webhooks are no longer needed.', $webhooks, 'tassyir-for-woocommerce' ),
						$webhooks
					)
				),
				esc_url( $settings_url ),
				esc_html__( 'Review and delete them', 'tassyir-for-woocommerce' )
			);
		}
	}

	/**
	 * Whether the current screen is our settings page (it shows its own webhook box).
	 */
	private static function on_settings_page(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen instanceof \WP_Screen && false !== strpos( $screen->id, 'tassyir' );
	}
}
