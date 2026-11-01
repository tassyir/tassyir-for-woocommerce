<?php
/**
 * Orders list column, bulk action "Send to Tassyir" and the order-edit meta box.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

use Tassyir\WooCommerce\Connection;
use Tassyir\WooCommerce\Order_Sync;
use Tassyir\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Works with both order storages: HPOS (screen `woocommerce_page_wc-orders`) and the legacy
 * posts table (screen `edit-shop_order`).
 */
final class Order_Admin {

	const COLUMN = 'tassyir';
	const BULK   = 'tassyir_send';
	const RESEND = 'tassyir_resend_order';

	/**
	 * Order sync.
	 *
	 * @var Order_Sync
	 */
	private $orders;

	/**
	 * Stored connection (business id for dashboard links).
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Builds the order screens integration.
	 *
	 * @param Order_Sync $orders     Order sync.
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Order_Sync $orders, Connection $connection ) {
		$this->orders     = $orders;
		$this->connection = $connection;
	}

	/**
	 * Hooks for both list tables, the meta box and the resend link.
	 */
	public function register_hooks(): void {
		// HPOS list table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );
		// Legacy list table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_' . self::RESEND, array( $this, 'handle_resend' ) );
	}

	/**
	 * Adds the Tassyir column after the order status.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( $columns ): array {
		$result = array();
		foreach ( (array) $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$result[ self::COLUMN ] = 'Tassyir';
			}
		}
		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = 'Tassyir';
		}
		return $result;
	}

	/**
	 * Prints the column cell: ✓ #number, queued, or the reason it failed / was skipped.
	 *
	 * @param string        $column       Column key.
	 * @param int|\WC_Order $order_or_id Order (HPOS) or post id (legacy).
	 */
	public function render_column( $column, $order_or_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( $order instanceof \WC_Order ) {
			$this->print_state( $order, false );
		}
	}

	/**
	 * Prints an order's Tassyir state with an icon.
	 *
	 * @param \WC_Order $order    Order.
	 * @param bool      $detailed Also print the attempts and the time it was sent.
	 */
	private function print_state( \WC_Order $order, bool $detailed ): void {
		$state  = $this->orders->state( $order );
		$reason = (string) $order->get_meta( Order_Sync::META_REASON );
		$icons  = array(
			'sent'    => 'dashicons-yes-alt',
			'queued'  => 'dashicons-clock',
			'failed'  => 'dashicons-warning',
			'skipped' => 'dashicons-minus',
			'none'    => 'dashicons-minus',
		);

		echo '<span class="tassyir-state tassyir-state-' . esc_attr( $state ) . '">';
		echo '<span class="dashicons ' . esc_attr( $icons[ $state ] ) . '" aria-hidden="true"></span> ';
		$number = (string) $order->get_meta( Order_Sync::META_NUMBER );
		$url    = $this->tassyir_order_url( $order );
		if ( 'sent' === $state && '' !== $number && '' !== $url ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $number ) . '</a>';
		} elseif ( 'sent' === $state && '' !== $number ) {
			echo esc_html( $number );
		} else {
			echo esc_html( Labels::state( $state ) );
		}
		echo '</span>';

		if ( '' !== $reason && 'sent' !== $state ) {
			echo '<br><small>' . esc_html( Labels::reason( $reason ) ) . '</small>';
		}
		if ( $detailed ) {
			$sent_at = (string) $order->get_meta( Order_Sync::META_SENT_AT );
			if ( '' !== $sent_at && 'sent' === $state ) {
				/* translators: %s: date and time. */
				echo '<br><small>' . esc_html( sprintf( __( 'Sent on %s', 'tassyir-for-woocommerce' ), self::local_time( (int) strtotime( $sent_at ) ) ) ) . '</small>';
			}
			$attempts = (int) $order->get_meta( Order_Sync::META_ATTEMPTS );
			if ( $attempts > 1 ) {
				/* translators: %d: number of attempts. */
				echo '<br><small>' . esc_html( sprintf( _n( '%d attempt', '%d attempts', $attempts, 'tassyir-for-woocommerce' ), $attempts ) ) . '</small>';
			}
		}
	}

	/**
	 * Adds "Send to Tassyir" to the bulk actions.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( $actions ): array {
		$actions               = (array) $actions;
		$actions[ self::BULK ] = __( 'Send to Tassyir', 'tassyir-for-woocommerce' );
		return $actions;
	}

	/**
	 * Queues the selected orders (WordPress already checked the list table's nonce).
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Chosen action.
	 * @param int[]  $ids         Selected order ids.
	 */
	public function handle_bulk_action( $redirect_to, $action, $ids ): string {
		if ( self::BULK !== $action || ! current_user_can( 'manage_woocommerce' ) ) {
			return (string) $redirect_to;
		}
		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( $this->orders->resend( absint( $id ) ) ) {
				++$count;
			}
		}
		return add_query_arg( 'tassyir_sent', $count, (string) $redirect_to );
	}

	/**
	 * "N orders queued" after the bulk action.
	 */
	public function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['tassyir_sent'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$count = absint( $_GET['tassyir_sent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		/* translators: %d: number of orders. */
		$text = sprintf( _n( '%d order queued for Tassyir.', '%d orders queued for Tassyir.', $count, 'tassyir-for-woocommerce' ), $count );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Adds the Tassyir box to the order edit screen (HPOS and legacy).
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'tassyir-order',
			'Tassyir',
			array( $this, 'render_meta_box' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Prints the meta box.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Legacy post or HPOS order.
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		echo '<p>';
		$this->print_state( $order, true );
		echo '</p>';

		if ( current_user_can( 'manage_woocommerce' ) && 'queued' !== $this->orders->state( $order ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::RESEND . '&order_id=' . $order->get_id() ),
				self::RESEND . '_' . $order->get_id()
			);
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Send to Tassyir again', 'tassyir-for-woocommerce' ) . '</a></p>';
		}
	}

	/**
	 * The meta box's "Send to Tassyir again" link.
	 */
	public function handle_resend(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( self::RESEND . '_' . $order_id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'tassyir-for-woocommerce' ), 403 );
		}
		$this->orders->resend( $order_id );

		$order    = wc_get_order( $order_id );
		$fallback = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url();
		wp_safe_redirect( add_query_arg( 'tassyir_sent', 1, wp_get_referer() ? wp_get_referer() : $fallback ) );
		exit;
	}

	/**
	 * Link to the order in the Tassyir dashboard ('' when unknown). The dashboard speaks Arabic
	 * (default) and English, and its URLs always carry the locale.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function tassyir_order_url( \WC_Order $order ): string {
		$order_id = (string) $order->get_meta( Order_Sync::META_ORDER_ID );
		$business = (array) $this->connection->get( 'business', array() );
		$bid      = isset( $business['id'] ) ? (string) $business['id'] : '';
		if ( '' === $order_id || '' === $bid ) {
			return '';
		}
		$locale = 0 === strpos( get_user_locale(), 'en' ) ? 'en' : 'ar';
		return Plugin::app_url() . '/' . $locale . '/dashboard/' . rawurlencode( $bid ) . '/orders/' . rawurlencode( $order_id );
	}

	/**
	 * A Unix time in the site's time zone and date format.
	 *
	 * @param int $timestamp Unix seconds.
	 */
	public static function local_time( int $timestamp ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
