<?php
/**
 * A held submission's thank-you page on a classic theme: WooCommerce's `checkout/thankyou.php`,
 * `order/order-details.php` and `order/order-details-customer.php` markup, filled from the receipt
 * (Receipt::classic_template()). No order hook runs here.
 *
 * @package Tassyir\WooCommerce
 */

use Tassyir\WooCommerce\Cod\Receipt;

defined( 'ABSPATH' ) || exit;

$tassyir_receipt = Receipt::current();
if ( null === $tassyir_receipt ) {
	return;
}
/** @var WC_Order $tassyir_order In-memory order, never saved. */
$tassyir_order = $tassyir_receipt['order'];
?>
<div class="woocommerce-order">

	<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received"><?php echo wp_kses_post( Receipt::received_text() ); ?></p>

	<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">

		<li class="woocommerce-order-overview__order order">
			<?php echo esc_html( Receipt::wc( 'Order number:' ) ); ?>
			<strong><?php echo esc_html( (string) $tassyir_receipt['number'] ); ?></strong>
		</li>

		<li class="woocommerce-order-overview__date date">
			<?php echo esc_html( Receipt::wc( 'Date:' ) ); ?>
			<strong><?php echo esc_html( wc_format_datetime( $tassyir_order->get_date_created() ) ); ?></strong>
		</li>

		<li class="woocommerce-order-overview__total total">
			<?php echo esc_html( Receipt::wc( 'Total:' ) ); ?>
			<strong><?php echo wp_kses_post( $tassyir_order->get_formatted_order_total() ); ?></strong>
		</li>

		<li class="woocommerce-order-overview__payment-method method">
			<?php echo esc_html( Receipt::wc( 'Payment method:' ) ); ?>
			<strong><?php echo wp_kses_post( $tassyir_order->get_payment_method_title() ); ?></strong>
		</li>

	</ul>

	<?php echo Receipt::cod_instructions(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() inside. ?>

	<section class="woocommerce-order-details">
		<h2 class="woocommerce-order-details__title"><?php echo esc_html( Receipt::wc( 'Order details' ) ); ?></h2>

		<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
			<thead>
				<tr>
					<th class="woocommerce-table__product-name product-name"><?php echo esc_html( Receipt::wc( 'Product' ) ); ?></th>
					<th class="woocommerce-table__product-table product-total"><?php echo esc_html( Receipt::wc( 'Total' ) ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $tassyir_order->get_items() as $tassyir_item ) :
					if ( ! $tassyir_item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$tassyir_product = $tassyir_item->get_product();
					// @phpstan-ignore-next-line argument.type (WooCommerce's template passes the order item itself.)
					$tassyir_link = $tassyir_product instanceof WC_Product_Variation ? $tassyir_product->get_permalink( $tassyir_item ) : ( $tassyir_product ? $tassyir_product->get_permalink() : '' );
					?>
					<tr class="woocommerce-table__line-item order_item">
						<td class="woocommerce-table__product-name product-name">
							<?php if ( $tassyir_product && $tassyir_product->is_visible() ) : ?>
								<a href="<?php echo esc_url( $tassyir_link ); ?>"><?php echo esc_html( $tassyir_item->get_name() ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $tassyir_item->get_name() ); ?>
							<?php endif; ?>
							<strong class="product-quantity">&times;&nbsp;<?php echo esc_html( (string) $tassyir_item->get_quantity() ); ?></strong>
							<?php wc_display_item_meta( $tassyir_item ); ?>
						</td>
						<td class="woocommerce-table__product-total product-total">
							<?php echo wp_kses_post( $tassyir_order->get_formatted_line_subtotal( $tassyir_item ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<?php foreach ( $tassyir_order->get_order_item_totals() as $tassyir_total ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( (string) $tassyir_total['label'] ); ?></th>
						<td><?php echo wp_kses_post( (string) $tassyir_total['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( $tassyir_order->get_customer_note() ) : ?>
					<tr>
						<th><?php echo esc_html( Receipt::wc( 'Note:' ) ); ?></th>
						<td><?php echo wp_kses( nl2br( wc_wptexturize_order_note( $tassyir_order->get_customer_note() ) ), array( 'br' => array() ) ); ?></td>
					</tr>
				<?php endif; ?>
			</tfoot>
		</table>
	</section>

	<section class="woocommerce-customer-details">
		<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
			<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">
				<h2 class="woocommerce-column__title"><?php echo esc_html( Receipt::wc( 'Billing address' ) ); ?></h2>
				<address>
					<?php echo wp_kses_post( $tassyir_order->get_formatted_billing_address() ); ?>
					<?php if ( $tassyir_order->get_billing_phone() ) : ?>
						<p class="woocommerce-customer-details--phone"><?php echo esc_html( $tassyir_order->get_billing_phone() ); ?></p>
					<?php endif; ?>
				</address>
			</div>
			<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
				<h2 class="woocommerce-column__title"><?php echo esc_html( Receipt::wc( 'Shipping address' ) ); ?></h2>
				<address>
					<?php echo wp_kses_post( $tassyir_order->get_formatted_shipping_address() ); ?>
					<?php if ( $tassyir_order->get_shipping_phone() ) : ?>
						<p class="woocommerce-customer-details--phone"><?php echo esc_html( $tassyir_order->get_shipping_phone() ); ?></p>
					<?php endif; ?>
				</address>
			</div>
		</section>
	</section>

</div>
