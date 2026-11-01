<?php
/**
 * The COD form's markup (port of the Shopify block, extensions/cod-form/blocks/cod-form.liquid).
 * Static and safe to cache: everything dynamic comes from the plugin's REST routes.
 *
 * @package Tassyir\WooCommerce
 *
 * @var array<string, mixed>  $settings     Form settings.
 * @var array<string, string> $texts        Texts in the form's language.
 * @var string                $dir          rtl | ltr.
 * @var string                $uid          Unique id of this form on the page.
 * @var string                $product_json Product data for the script.
 * @var string                $i18n_json    Script strings.
 * @var string                $style        Inline CSS custom properties (colours, radius).
 * @var string                $title        Product name.
 * @var string                $image_url    Product thumbnail URL ('' for none).
 * @var bool                  $has_variants More than one variant to choose from.
 * @var string                $variant_name Name of the variant selected first.
 * @var bool                  $preview      Rendered in the block editor (no script runs there).
 */

defined( 'ABSPATH' ) || exit;

$tassyir_icons = ! empty( $settings['show_icons'] );
$tassyir_icon  = static function ( string $paths ) {
	echo '<span class="tsy-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">' . wp_kses(
		$paths,
		array(
			'path'     => array( 'd' => true ),
			'circle'   => array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'polyline' => array( 'points' => true ),
		)
	) . '</svg></span>';
};
$tassyir_req   = '<span class="tsy-req">*</span>';
?>
<div class="tsy-cod-form" id="tsy-cod-<?php echo esc_attr( $uid ); ?>" dir="<?php echo esc_attr( $dir ); ?>" style="<?php echo esc_attr( $style ); ?>"
	data-tsy-rest="<?php echo esc_url( rest_url( Tassyir\WooCommerce\Cod\Rest::NAMESPACE_V1 . '/' ) ); ?>"
	data-block-id="<?php echo esc_attr( $uid ); ?>"
	data-require-address="<?php echo esc_attr( ! empty( $settings['require_address'] ) ? 'true' : 'false' ); ?>"
	data-enable-stopdesk="<?php echo esc_attr( ! empty( $settings['show_stopdesk'] ) ? 'true' : 'false' ); ?>"
	data-fire-meta-pixel="<?php echo esc_attr( ! empty( $settings['pixel_meta'] ) ? 'true' : 'false' ); ?>"
	data-fire-tiktok-pixel="<?php echo esc_attr( ! empty( $settings['pixel_tiktok'] ) ? 'true' : 'false' ); ?>"
	data-show-offers="<?php echo esc_attr( ! empty( $settings['show_offers'] ) ? 'true' : 'false' ); ?>"
	data-shipping-pending-text="<?php echo esc_attr( $texts['shipping_pending_text'] ); ?>"
	data-free-shipping-label="<?php echo esc_attr( $texts['free_shipping_label'] ); ?>"
	data-upload-label="<?php echo esc_attr( $texts['upload_label'] ); ?>"
	data-desk-available-label="<?php echo esc_attr( $texts['desk_available_label'] ); ?>"
	data-optional-label="<?php echo esc_attr( $texts['optional_label'] ); ?>"
	data-province-placeholder="<?php echo esc_attr( $texts['province_placeholder'] ); ?>"
	data-town-placeholder="<?php echo esc_attr( $texts['town_placeholder'] ); ?>"
	data-bundle-label="<?php echo esc_attr( $texts['bundle_label'] ); ?>"
	data-show-bundle-savings="<?php echo esc_attr( ! empty( $settings['show_bundle_savings'] ) ? 'true' : 'false' ); ?>"
	data-bundle-savings-label="<?php echo esc_attr( $texts['bundle_savings_label'] ); ?>"
	data-bundle-item-label="<?php echo esc_attr( $texts['bundle_item_label'] ); ?>"
	data-tsy-product="<?php echo esc_attr( $product_json ); ?>"
	data-tsy-i18n="<?php echo esc_attr( $i18n_json ); ?>"
	<?php echo $preview ? 'data-tsy-preview="1"' : ''; ?>
>
	<h3 class="tsy-cod-heading"><?php echo esc_html( $texts['heading'] ); ?></h3>

	<div class="tsy-cod-fields" data-tsy-form>
		<div class="tsy-bundle" data-tsy-bundle hidden></div>

		<?php if ( $has_variants ) : ?>
		<div class="tsy-field" data-tsy-field="variant">
			<div data-tsy-variant-options class="tsy-variant-options"></div>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $settings['show_quantity'] ) ) : ?>
		<div class="tsy-field" data-tsy-field="quantity">
			<label for="tsy-qty-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['quantity_label'] ); ?></label>
			<div class="tsy-qty-stepper">
				<button type="button" class="tsy-qty-btn" data-tsy-qty-decrement aria-label="-">−</button>
				<input type="number" id="tsy-qty-<?php echo esc_attr( $uid ); ?>" data-tsy-qty-input min="1" max="999" step="1" value="1" inputmode="numeric">
				<button type="button" class="tsy-qty-btn" data-tsy-qty-increment aria-label="+">+</button>
			</div>
		</div>
		<?php endif; ?>

		<div class="tsy-field" data-tsy-field="name">
			<label for="tsy-name-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['name_label'] ); ?> <?php echo wp_kses_post( $tassyir_req ); ?></label>
			<div class="tsy-input-holder">
				<?php
				if ( $tassyir_icons ) {
					$tassyir_icon( '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>' );
				}
				?>
				<input type="text" id="tsy-name-<?php echo esc_attr( $uid ); ?>" data-tsy-name autocomplete="name" placeholder="<?php echo esc_attr( $texts['name_placeholder'] ); ?>"<?php echo $tassyir_icons ? ' class="tsy-has-icon"' : ''; ?>>
			</div>
		</div>

		<div class="tsy-field" data-tsy-field="phone">
			<label for="tsy-phone-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['phone_label'] ); ?> <?php echo wp_kses_post( $tassyir_req ); ?></label>
			<div class="tsy-input-holder">
				<?php
				if ( $tassyir_icons ) {
					$tassyir_icon( '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"></path>' );
				}
				?>
				<input type="tel" id="tsy-phone-<?php echo esc_attr( $uid ); ?>" data-tsy-phone dir="ltr" inputmode="tel" autocomplete="tel" placeholder="<?php echo esc_attr( $texts['phone_placeholder'] ); ?>"<?php echo $tassyir_icons ? ' class="tsy-has-icon"' : ''; ?>>
			</div>
			<?php if ( '' !== $texts['phone_hint'] ) : ?>
			<p class="tsy-phone-hint"><?php echo esc_html( $texts['phone_hint'] ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $settings['show_phone2'] ) ) : ?>
		<div class="tsy-field" data-tsy-field="phone2">
			<label for="tsy-phone2-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['phone2_label'] ); ?> <span class="tsy-optional"><?php echo esc_html( $texts['optional_label'] ); ?></span></label>
			<input type="tel" id="tsy-phone2-<?php echo esc_attr( $uid ); ?>" data-tsy-phone2 dir="ltr" inputmode="tel" autocomplete="tel" placeholder="<?php echo esc_attr( $texts['phone_placeholder'] ); ?>">
		</div>
		<?php endif; ?>

		<div class="tsy-row-2col">
			<div class="tsy-field" data-tsy-field="province">
				<label for="tsy-province-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['province_label'] ); ?> <?php echo wp_kses_post( $tassyir_req ); ?></label>
				<div class="tsy-input-holder">
					<?php
					if ( $tassyir_icons ) {
						$tassyir_icon( '<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"></path><path d="M15 5.764v15"></path><path d="M9 3.236v15"></path>' );
					}
					?>
					<select id="tsy-province-<?php echo esc_attr( $uid ); ?>" data-tsy-province<?php echo $tassyir_icons ? ' class="tsy-has-icon"' : ''; ?>>
						<option value=""><?php echo esc_html( $texts['province_placeholder'] ); ?></option>
					</select>
				</div>
			</div>

			<div class="tsy-field" data-tsy-field="town">
				<label for="tsy-town-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['town_label'] ); ?> <?php echo wp_kses_post( $tassyir_req ); ?></label>
				<div class="tsy-input-holder">
					<?php
					if ( $tassyir_icons ) {
						$tassyir_icon( '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"></path><circle cx="12" cy="10" r="3"></circle>' );
					}
					?>
					<select id="tsy-town-<?php echo esc_attr( $uid ); ?>" data-tsy-town disabled<?php echo $tassyir_icons ? ' class="tsy-has-icon"' : ''; ?>>
						<option value=""><?php echo esc_html( $texts['town_placeholder'] ); ?></option>
					</select>
				</div>
			</div>
		</div>

		<div class="tsy-field tsy-delivery-type" data-tsy-field="delivery-type" data-tsy-delivery-type-group hidden>
			<div class="tsy-segmented" role="radiogroup">
				<label class="tsy-segment">
					<input type="radio" name="tsy-delivery-<?php echo esc_attr( $uid ); ?>" value="home" data-tsy-delivery-home checked>
					<span class="tsy-segment-inner">
						<svg class="tsy-segment-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
						<span class="tsy-segment-label"><?php echo esc_html( $texts['home_label'] ); ?></span>
					</span>
				</label>
				<label class="tsy-segment" data-tsy-stopdesk-option>
					<input type="radio" name="tsy-delivery-<?php echo esc_attr( $uid ); ?>" value="stopdesk" data-tsy-delivery-stopdesk>
					<span class="tsy-segment-inner">
						<svg class="tsy-segment-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
						<span class="tsy-segment-label"><?php echo esc_html( $texts['stopdesk_label'] ); ?></span>
					</span>
				</label>
			</div>
		</div>

		<?php if ( ! empty( $settings['show_address'] ) ) : ?>
		<div class="tsy-field" data-tsy-field="address">
			<label for="tsy-address-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['address_label'] ); ?>
				<?php if ( ! empty( $settings['require_address'] ) ) : ?>
					<?php echo wp_kses_post( $tassyir_req ); ?>
				<?php else : ?>
					<span class="tsy-optional"><?php echo esc_html( $texts['optional_label'] ); ?></span>
				<?php endif; ?>
			</label>
			<input type="text" id="tsy-address-<?php echo esc_attr( $uid ); ?>" data-tsy-address autocomplete="street-address" placeholder="<?php echo esc_attr( $texts['address_placeholder'] ); ?>"<?php echo ! empty( $settings['require_address'] ) ? ' aria-required="true"' : ''; ?>>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $settings['show_note'] ) ) : ?>
		<div class="tsy-field" data-tsy-field="note">
			<label for="tsy-note-<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $texts['note_label'] ); ?> <span class="tsy-optional"><?php echo esc_html( $texts['optional_label'] ); ?></span></label>
			<textarea id="tsy-note-<?php echo esc_attr( $uid ); ?>" data-tsy-note rows="3"></textarea>
		</div>
		<?php endif; ?>

		<div class="tsy-custom-fields" data-tsy-custom-fields></div>

		<input type="text" name="website" class="tsy-hp" data-tsy-honeypot tabindex="-1" autocomplete="off" aria-hidden="true">

		<div class="tsy-code" data-tsy-code hidden>
			<button type="button" class="tsy-code-toggle" data-tsy-code-toggle><?php echo esc_html( $texts['js_code_toggle'] ); ?></button>
			<div class="tsy-code-panel" data-tsy-code-panel hidden>
				<input type="text" class="tsy-code-input" data-tsy-code-input dir="ltr" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="64" aria-label="<?php echo esc_attr( $texts['js_code_toggle'] ); ?>" placeholder="<?php echo esc_attr( $texts['js_code_placeholder'] ); ?>">
				<button type="button" class="tsy-code-apply" data-tsy-code-apply><?php echo esc_html( $texts['js_code_apply'] ); ?></button>
			</div>
			<p class="tsy-code-status" data-tsy-code-status aria-live="polite" hidden></p>
			<button type="button" class="tsy-code-remove" data-tsy-code-remove hidden><?php echo esc_html( $texts['js_code_remove'] ); ?></button>
		</div>

		<div class="tsy-summary" data-tsy-summary>
			<div class="tsy-summary-header">
				<span class="tsy-summary-title"><?php echo esc_html( $texts['summary_title'] ); ?></span>
				<span class="tsy-summary-badge"><?php echo esc_html( $texts['summary_badge'] ); ?></span>
			</div>

			<div class="tsy-summary-item">
				<?php if ( '' !== $image_url ) : ?>
				<img class="tsy-summary-thumb" src="<?php echo esc_url( $image_url ); ?>" width="56" height="56" alt="" loading="lazy">
				<?php endif; ?>
				<div class="tsy-summary-item-info">
					<span class="tsy-summary-item-title"><?php echo esc_html( $title ); ?></span>
					<span class="tsy-summary-item-variant" data-tsy-summary-variant<?php echo $has_variants ? '' : ' hidden'; ?>><?php echo esc_html( $has_variants ? $variant_name : '' ); ?></span>
				</div>
				<span class="tsy-summary-item-price" data-tsy-summary-subtotal>—</span>
			</div>

			<div class="tsy-summary-body">
				<div class="tsy-summary-row">
					<span class="tsy-summary-row-label"><?php echo esc_html( $texts['subtotal_label'] ); ?></span>
					<span class="tsy-summary-row-value" data-tsy-summary-subtotal2>—</span>
				</div>
				<div class="tsy-summary-discounts" data-tsy-summary-discounts hidden></div>
				<div class="tsy-summary-row">
					<span class="tsy-summary-row-label"><?php echo esc_html( $texts['shipping_label'] ); ?></span>
					<span class="tsy-summary-row-value" data-tsy-summary-shipping>—</span>
				</div>
			</div>
			<div class="tsy-summary-total">
				<div class="tsy-summary-total-start">
					<span class="tsy-summary-total-label"><?php echo esc_html( $texts['total_label'] ); ?></span>
					<?php if ( '' !== $texts['cod_sublabel'] ) : ?>
					<span class="tsy-cod-sublabel"><?php echo esc_html( $texts['cod_sublabel'] ); ?></span>
					<?php endif; ?>
				</div>
				<span class="tsy-summary-total-value" data-tsy-summary-total>—</span>
			</div>
			<p class="tsy-summary-savings" data-tsy-summary-savings hidden></p>
			<p class="tsy-summary-nudge" data-tsy-summary-nudge aria-live="polite" hidden></p>
		</div>

		<div class="tsy-error" data-tsy-error aria-live="polite" hidden></div>

		<button type="button" class="tsy-submit-btn" data-tsy-submit<?php echo $preview ? ' disabled' : ''; ?>>
			<span class="tsy-submit-label" data-tsy-submit-label><?php echo esc_html( $texts['button_label'] ); ?></span>
			<span class="tsy-spinner" data-tsy-spinner hidden></span>
		</button>
	</div>

	<div class="tsy-success" data-tsy-success hidden>
		<span class="tsy-success-icon" aria-hidden="true"></span>
		<p><?php echo esc_html( $texts['success_message'] ); ?></p>
		<p class="tsy-order-id" data-tsy-order-id></p>
		<p class="tsy-success-note" data-tsy-success-note hidden></p>
	</div>
</div>
