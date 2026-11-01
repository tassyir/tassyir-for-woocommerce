<?php
/**
 * Where the COD form appears: the block, the shortcode and (optionally) every product page.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

use Tassyir\WooCommerce\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * - Gutenberg block `tassyir/cod-form` (server-rendered) and shortcode `[tassyir_cod_form]`
 *   (also what Elementor's shortcode widget uses); both default to the current product.
 * - "Show on every product page": after the price (woocommerce_single_product_summary, 25),
 *   or in place of the Add to cart block on block themes; "replace" removes Add to cart,
 *   "hide_cart" also removes the shop's Add to cart buttons and sends cart and checkout to the shop.
 * Disconnected, none of this happens: the store keeps its normal Add to cart.
 */
final class Placement {

	const SHORTCODE = 'tassyir_cod_form';
	const BLOCK     = 'tassyir/cod-form';

	/**
	 * Stored connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Whether the product page's form was already printed on this page.
	 *
	 * @var bool
	 */
	private $printed = false;

	/**
	 * Builds the placement.
	 *
	 * @param Connection $connection Stored connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Hooks everything.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( Form::class, 'register_assets' ) );
		add_action( 'wp', array( $this, 'product_page_hooks' ) );
		add_action( 'template_redirect', array( $this, 'redirect_cart_and_checkout' ) );
	}

	/**
	 * Shortcode and block.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		if ( function_exists( 'register_block_type' ) ) {
			register_block_type(
				TASSYIR_WC_DIR . 'assets/blocks/cod-form',
				array( 'render_callback' => array( $this, 'render_block' ) )
			);
		}
	}

	/**
	 * [tassyir_cod_form product="123"]: the form of a product (default: the current one).
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'product' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return Form::render( self::product( (int) $atts['product'] ) );
	}

	/**
	 * The block's server render. In the editor (REST "block-renderer" request) the markup is a
	 * preview: no script runs there and the submit button is disabled.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_block( $attributes ): string {
		$id      = isset( $attributes['productId'] ) ? (int) $attributes['productId'] : 0;
		$preview = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$html    = Form::render( self::product( $id ), $preview );
		if ( '' === $html && $preview && current_user_can( 'edit_posts' ) ) {
			return '<p>' . esc_html__( 'Tassyir COD form: choose a product (or place the block on a product page). The site must be connected to Tassyir.', 'tassyir-for-woocommerce' ) . '</p>';
		}
		return $html;
	}

	/**
	 * On a product page with "Show on every product page" on: the form, and Add to cart removed
	 * when the mode says so.
	 */
	public function product_page_hooks(): void {
		$settings = Settings::get();
		if ( empty( $settings['product_pages'] ) || ! $this->connection->is_connected() || ! function_exists( 'is_product' ) ) {
			return;
		}
		if ( 'hide_cart' === $settings['placement_mode'] ) {
			remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
			add_filter( 'render_block_woocommerce/product-button', '__return_empty_string' );
		}
		if ( ! is_product() ) {
			return;
		}
		$product = self::product( 0 );
		if ( ! Form::supports( $product ) ) {
			return;
		}
		add_action( 'woocommerce_single_product_summary', array( $this, 'print_form' ), 25 );
		if ( 'keep' !== $settings['placement_mode'] ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		}
		// Block themes: the product template has an Add to cart block instead of the hook.
		add_filter( 'render_block_woocommerce/add-to-cart-form', array( $this, 'filter_add_to_cart_block' ), 10, 1 );
		add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( $this, 'filter_add_to_cart_block' ), 10, 1 );
	}

	/**
	 * Prints the current product's form (classic product template).
	 */
	public function print_form(): void {
		echo $this->form_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes every value.
	}

	/**
	 * Block themes: the form in place of (or before) the Add to cart block.
	 *
	 * @param string $html The block's HTML.
	 */
	public function filter_add_to_cart_block( $html ): string {
		$keep = 'keep' === Settings::get()['placement_mode'];
		$form = $this->form_once();
		if ( '' === $form ) {
			// Already shown on this page (or not showable): Add to cart stays only in "keep" mode.
			return $keep || ! $this->printed ? (string) $html : '';
		}
		return $keep ? $form . $html : $form;
	}

	/**
	 * The current product's form the first time it is asked for on this page, then '' (block
	 * themes may run both the summary hook and the Add to cart block filter).
	 */
	private function form_once(): string {
		if ( $this->printed ) {
			return '';
		}
		$form          = Form::render( self::product( 0 ) );
		$this->printed = '' !== $form;
		return $form;
	}

	/**
	 * "Hide cart & checkout": the cart and checkout pages lead to the shop. The order-received
	 * and order-pay pages (checkout endpoints) stay reachable.
	 */
	public function redirect_cart_and_checkout(): void {
		$settings = Settings::get();
		if ( empty( $settings['product_pages'] ) || 'hide_cart' !== $settings['placement_mode'] || ! $this->connection->is_connected() ) {
			return;
		}
		if ( ! function_exists( 'is_cart' ) || is_admin() ) {
			return;
		}
		$on_checkout = is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
		if ( is_cart() || $on_checkout ) {
			$shop = wc_get_page_permalink( 'shop' );
			wp_safe_redirect( $shop ? $shop : home_url( '/' ) );
			exit;
		}
	}

	/**
	 * The product a form is for: the given id, else the page's product.
	 *
	 * @param int $id Product id (0 = current).
	 * @return \WC_Product|null
	 */
	private static function product( int $id ): ?\WC_Product {
		if ( $id > 0 ) {
			$product = wc_get_product( $id );
			return $product instanceof \WC_Product ? $product : null;
		}
		global $product;
		if ( $product instanceof \WC_Product ) {
			return $product;
		}
		$current = wc_get_product( (int) get_the_ID() );
		return $current instanceof \WC_Product ? $current : null;
	}
}
