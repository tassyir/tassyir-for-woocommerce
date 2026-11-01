<?php
/**
 * The "order received" page of a submission Tassyir held or dropped (§H6 hold / drop).
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

use Automattic\WooCommerce\Blocks\Utils\StyleAttributesUtils;

defined( 'ABSPATH' ) || exit;

/**
 * A held submission must look like an allowed one to the person (or bot) who sent it, yet no
 * WooCommerce order may exist and no Purchase may be tracked. So it gets a receipt: an order
 * number and key of the same shape as an order's, kept 2 hours in a transient, and a redirect to
 * the same `/checkout/order-received/<number>/?key=wc_order_…` URL. On that URL WooCommerce
 * renders its own page, finds no order, and this class fills the order-confirmation blocks (block
 * themes) or the `checkout/thankyou.php` template (classic themes) from the receipt, with the
 * same markup and WooCommerce's own strings. No order hook runs (`woocommerce_thankyou`,
 * `woocommerce_before_thankyou`, …), and an order lookup by number or key finds nothing, which is
 * what pixel plugins such as PixelYourSite check before they send Purchase.
 */
final class Receipt {

	/**
	 * How long the receipt page works (the quote's lifetime).
	 */
	const TTL = 2 * HOUR_IN_SECONDS;

	const BLOCK_PREFIX = 'woocommerce/order-confirmation-';

	/**
	 * The receipt shown on this request, with its in-memory order under `order`.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $current = null;

	/**
	 * Hooks the order-received page.
	 */
	public static function register_hooks(): void {
		add_action( 'wp', array( self::class, 'detect' ) );
		// Before WooCommerce's own render_block filter (10), which adds its data attributes to ours.
		add_filter( 'render_block', array( self::class, 'render_block' ), 9, 2 );
		add_filter( 'wc_get_template', array( self::class, 'classic_template' ), 10, 2 );
	}

	/**
	 * Stores the receipt of a held / dropped submission.
	 *
	 * @param array<string, mixed> $submission Checked submission (Rest::clean_submission()).
	 * @param array<string, mixed> $answer     Tassyir's submit answer.
	 * @param string               $lang       Form language.
	 * @return array<string, mixed> The receipt (number, key, created, lang, submission, answer).
	 */
	public static function create( array $submission, array $answer, string $lang ): array {
		$keep    = array_flip( array( 'name', 'phone', 'address', 'note', 'provinceCode', 'townOns', 'stopdesk', 'items', 'submissionId' ) );
		$receipt = array(
			'number'     => self::next_number(),
			'key'        => 'wc_order_' . wp_generate_password( 13, false ), // WooCommerce's order key shape.
			'created'    => time(),
			'lang'       => $lang,
			'submission' => array_intersect_key( $submission, $keep ),
			'answer'     => array_intersect_key( $answer, array_flip( array( 'lines', 'shippingPrice', 'freeShipping', 'total', 'destination', 'phone', 'priceChanged', 'rejectedCodes' ) ) ),
		);
		if ( null === self::order( $receipt ) ) {
			unset( $receipt['answer']['lines'] ); // A held duplicate carries no lines: price the form's items.
		}
		set_transient( self::transient( (string) $receipt['key'] ), $receipt, self::TTL );
		set_transient( self::transient( 'submission:' . $submission['submissionId'] ), $receipt['key'], self::TTL );
		return $receipt;
	}

	/**
	 * The receipt already given to a submission id (a double press).
	 *
	 * @param string $submission_id Form submission id.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_submission( string $submission_id ): ?array {
		$key = get_transient( self::transient( 'submission:' . $submission_id ) );
		return is_string( $key ) ? self::find( $key ) : null;
	}

	/**
	 * A stored receipt by its key.
	 *
	 * @param string $key Receipt key.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $key ): ?array {
		$receipt = '' === $key ? false : get_transient( self::transient( $key ) );
		return is_array( $receipt ) && isset( $receipt['number'], $receipt['key'] ) ? $receipt : null;
	}

	/**
	 * The receipt's order-received URL, built like WC_Order::get_checkout_order_received_url().
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 */
	public static function url( array $receipt ): string {
		return add_query_arg( 'key', (string) $receipt['key'], wc_get_endpoint_url( 'order-received', (string) $receipt['number'], wc_get_checkout_url() ) );
	}

	/**
	 * The receipt as an order object, for display only: built in memory, never saved.
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 */
	public static function order( array $receipt ): ?\WC_Order {
		try {
			$order = Order_Creator::build( (array) $receipt['submission'], (array) $receipt['answer'], (string) $receipt['lang'] );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$order->set_date_created( (int) $receipt['created'] );
		return $order;
	}

	/**
	 * `wp`: on the order-received endpoint, the receipt named by the URL's number and key.
	 */
	public static function detect(): void {
		self::$current = null;

		$number = absint( get_query_var( 'order-received' ) );
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the access check, as on WooCommerce's own page.
		if ( 0 === $number || '' === $key ) {
			return;
		}
		$receipt = self::find( $key );
		$order   = null !== $receipt && (int) $receipt['number'] === $number ? self::order( $receipt ) : null;
		if ( null !== $order ) {
			self::$current = $receipt + array( 'order' => $order );
		}
	}

	/**
	 * The receipt shown on this request.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::$current;
	}

	/**
	 * Forgets the receipt of this request (tests).
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * `render_block`, block themes: each order-confirmation block as WooCommerce renders it for a
	 * processing COD order, from the receipt.
	 *
	 * @param string               $content Rendered block.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string
	 */
	public static function render_block( $content, $block ): string {
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		if ( null === self::$current || 0 !== strpos( $name, self::BLOCK_PREFIX ) ) {
			return (string) $content;
		}
		$part  = substr( $name, strlen( self::BLOCK_PREFIX ) );
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$type  = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$attrs = $type instanceof \WP_Block_Type ? $type->prepare_attributes_for_render( $attrs ) : $attrs; // Defaults, e.g. align: wide.
		/** @var \WC_Order $order */
		$order = self::$current['order'];

		switch ( $part ) {
			case 'status':
				$inner = '<h1>' . wp_kses_post( self::received_title() ) . '</h1><p>' . wp_kses_post( self::received_text() ) . '</p>';
				break;
			case 'summary':
				$inner = '<ul class="wc-block-order-confirmation-summary-list">'
					. self::summary_row( self::wc( 'Order #:' ), (string) self::$current['number'] )
					. self::summary_row( self::wc( 'Date:' ), wc_format_datetime( $order->get_date_created() ) )
					. self::summary_row( self::wc( 'Total:' ), $order->get_formatted_order_total() )
					. self::summary_row( self::wc( 'Payment:' ), $order->get_payment_method_title() )
					. '</ul>';
				break;
			case 'totals':
				$inner = self::totals_table( $order, $attrs );
				break;
			case 'totals-wrapper':
			case 'shipping-wrapper':
			case 'billing-wrapper':
				$inner = self::inner_blocks( $block );
				break;
			case 'shipping-address':
				$inner = '<address>' . wp_kses_post( $order->get_formatted_shipping_address() ) . '</address>' . self::phone( $order->get_shipping_phone() );
				break;
			case 'billing-address':
				$inner = '<address>' . wp_kses_post( $order->get_formatted_billing_address() ) . '</address>' . self::phone( $order->get_billing_phone() );
				break;
			case 'additional-information':
				$inner = self::cod_instructions();
				break;
			default: // Downloads, account creation, additional fields: none for a COD form order.
				$inner = '';
		}
		return '' === $inner ? '' : self::wrap( $part, $attrs, $inner );
	}

	/**
	 * `wc_get_template`, classic themes: the receipt's thank-you template in place of
	 * WooCommerce's (which has no order to show).
	 *
	 * @param string $template      Template path.
	 * @param string $template_name Template name.
	 * @return string
	 */
	public static function classic_template( $template, $template_name ) {
		if ( null !== self::$current && 'checkout/thankyou.php' === $template_name ) {
			return __DIR__ . '/views/receipt-classic.php';
		}
		return $template;
	}

	/**
	 * WooCommerce's title of the order-received page, filters included.
	 */
	public static function received_title(): string {
		return (string) apply_filters( 'woocommerce_thankyou_order_received_title', esc_html( self::wc( 'Order received' ) ), false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
	}

	/**
	 * WooCommerce's "Thank you" sentence, filters included.
	 */
	public static function received_text(): string {
		return (string) apply_filters( 'woocommerce_thankyou_order_received_text', esc_html( self::wc( 'Thank you. Your order has been received.' ) ), false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
	}

	/**
	 * The COD gateway's thank-you instructions, as it prints them.
	 */
	public static function cod_instructions(): string {
		$gateways     = WC()->payment_gateways()->payment_gateways();
		$instructions = isset( $gateways['cod'] ) && isset( $gateways['cod']->instructions ) ? (string) $gateways['cod']->instructions : '';
		return '' === $instructions ? '' : wp_kses_post( wpautop( wptexturize( $instructions ) ) );
	}

	/**
	 * A string as WooCommerce translates it (its own text domain, already loaded), so the page
	 * reads exactly like WooCommerce's.
	 *
	 * @param string $text English text from WooCommerce's templates.
	 */
	public static function wc( string $text ): string {
		return get_translations_for_domain( 'woocommerce' )->translate( $text );
	}

	/**
	 * The order lines and totals table of the Totals block.
	 *
	 * @param \WC_Order            $order In-memory order.
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private static function totals_table( \WC_Order $order, array $attrs ): string {
		$rows = '';
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			// @phpstan-ignore-next-line argument.type (WooCommerce's Totals block passes the order item itself.)
			$link  = $product instanceof \WC_Product_Variation ? $product->get_permalink( $item ) : ( $product ? $product->get_permalink() : '' );
			$name  = $product && $product->is_visible() ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $item->get_name() ) ) : esc_html( $item->get_name() );
			$rows .= '<tr class="woocommerce-table__line-item order_item"><td class="wc-block-order-confirmation-totals__product">'
				. $name . '&nbsp; <strong class="product-quantity">&times;&nbsp;' . esc_html( (string) $item->get_quantity() ) . '</strong> '
				. wc_display_item_meta( $item, array( 'echo' => false ) )
				. '</td><td class="wc-block-order-confirmation-totals__total">' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</td></tr>';
		}
		$totals = '';
		add_filter( 'woocommerce_order_shipping_to_display_shipped_via', '__return_empty_string' );
		foreach ( array_diff_key( $order->get_order_item_totals(), array_flip( array( 'cart_subtotal', 'payment_method' ) ) ) as $total ) {
			$totals .= '<tr><th class="wc-block-order-confirmation-totals__label" scope="row">' . esc_html( (string) $total['label'] ) . '</th><td class="wc-block-order-confirmation-totals__total">' . wp_kses_post( (string) $total['value'] ) . '</td></tr>';
		}
		remove_filter( 'woocommerce_order_shipping_to_display_shipped_via', '__return_empty_string' );

		$table = self::classes_and_styles( $attrs, array( 'border_color', 'border_radius', 'border_width', 'border_style', 'background_color', 'text_color' ) );
		return '<table cellspacing="0" class="wc-block-order-confirmation-totals__table ' . esc_attr( $table['classes'] ) . '" style="' . esc_attr( $table['styles'] ) . '"><thead><tr>'
			. '<th class="wc-block-order-confirmation-totals__product">' . esc_html( self::wc( 'Product' ) ) . '</th>'
			. '<th class="wc-block-order-confirmation-totals__total">' . esc_html( self::wc( 'Total' ) ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody><tfoot>' . $totals . '</tfoot></table>';
	}

	/**
	 * One row of the Summary block.
	 *
	 * @param string $name  Label.
	 * @param string $value Value (HTML).
	 */
	private static function summary_row( string $name, string $value ): string {
		return '' === $value ? '' : '<li class="wc-block-order-confirmation-summary-list-item"><span class="wc-block-order-confirmation-summary-list-item__key">' . esc_html( $name ) . '</span> <span class="wc-block-order-confirmation-summary-list-item__value">' . wp_kses_post( $value ) . '</span></li>';
	}

	/**
	 * The phone line under an address.
	 *
	 * @param string $phone Phone.
	 */
	private static function phone( string $phone ): string {
		return '' === $phone ? '' : '<p class="woocommerce-customer-details--phone">' . esc_html( $phone ) . '</p>';
	}

	/**
	 * A wrapper block's inner blocks, rendered again (WooCommerce's wrapper dropped them: no order).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private static function inner_blocks( array $block ): string {
		$html  = '';
		$index = 0;
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		foreach ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array() as $chunk ) {
			if ( is_string( $chunk ) ) {
				$html .= $chunk;
			} elseif ( isset( $inner[ $index ] ) ) {
				$html .= render_block( $inner[ $index++ ] );
			}
		}
		return $html;
	}

	/**
	 * The wrapper WooCommerce puts around every order-confirmation block.
	 *
	 * @param string               $part  Block name after the prefix.
	 * @param array<string, mixed> $attrs Block attributes.
	 * @param string               $inner Content.
	 */
	private static function wrap( string $part, array $attrs, string $inner ): string {
		$found = self::classes_and_styles( $attrs );
		$name  = 'order-confirmation-' . $part;
		return sprintf( '<div class="wp-block-woocommerce-%1$s wc-block-%1$s %2$s" style="%3$s">%4$s</div>', esc_attr( $name ), esc_attr( $found['classes'] ), esc_attr( $found['styles'] ), $inner );
	}

	/**
	 * The classes and inline styles WooCommerce derives from block attributes, with its own helper.
	 *
	 * @param array<string, mixed> $attrs      Block attributes, defaults applied.
	 * @param string[]             $properties Only these properties ([] = all).
	 * @return array{classes: string, styles: string}
	 */
	private static function classes_and_styles( array $attrs, array $properties = array() ): array {
		$found = StyleAttributesUtils::get_classes_and_styles_by_attributes( $attrs, $properties );
		return array(
			'classes' => (string) $found['classes'],
			'styles'  => (string) $found['styles'],
		);
	}

	/**
	 * The receipt number: the id the next order would have taken. Order ids come from the posts
	 * table's auto-increment (HPOS reserves them there too), so a row inserted and deleted at once
	 * takes one, exactly as an order would, and no later order repeats it. Plain SQL: no post is
	 * ever created as far as WordPress and other plugins can tell (no hooks, no cache purge).
	 */
	private static function next_number(): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array(
			'post_type'             => 'tassyir_receipt',
			'post_status'           => 'trash',
			'post_date'             => $now,
			'post_date_gmt'         => current_time( 'mysql', true ),
			'post_modified'         => $now,
			'post_modified_gmt'     => current_time( 'mysql', true ),
			'post_title'            => '',
			'post_content'          => '',
			'post_excerpt'          => '',
			'post_content_filtered' => '',
			'to_ping'               => '',
			'pinged'                => '',
		);
		if ( $wpdb->insert( $wpdb->posts, $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- reserves an id, see above.
			$id = (int) $wpdb->insert_id;
			$wpdb->delete( $wpdb->posts, array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the row lived for one query.
			if ( $id > 0 ) {
				return $id;
			}
		}
		$ids = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'limit'   => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);
		/** @var int[] $ids `return => ids` gives order ids. */
		return ( isset( $ids[0] ) ? (int) $ids[0] : 0 ) + 1;
	}

	/**
	 * Transient name of a key.
	 *
	 * @param string $key Receipt key, or `submission:<id>`.
	 */
	private static function transient( string $key ): string {
		return 'tassyir_receipt_' . md5( $key );
	}
}
