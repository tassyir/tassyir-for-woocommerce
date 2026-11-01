<?php
/**
 * WooCommerce → Tassyir → Checkout form: the COD form's settings tab.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

use Tassyir\WooCommerce\Cod\Settings;
use Tassyir\WooCommerce\Cod\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * Placement, language and texts, colours, fields, offers, the page after an order, pixels.
 * Delivery fees and desks are not here: they come from the merchant's Tassyir fee group and carrier.
 */
final class Cod_Settings_Page {

	const ACTION = 'tassyir_save_cod_form';
	const TAB    = 'checkout-form';

	/**
	 * Hooks the save action.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Saves the form (nonce + capability), then back to the tab.
	 */
	public function handle_save(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( Settings_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'tassyir-for-woocommerce' ), 403 );
		}
		$input = isset( $_POST['tassyir_cod'] ) && is_array( $_POST['tassyir_cod'] ) ? wp_unslash( $_POST['tassyir_cod'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is checked by Settings::sanitize().
		Settings::save( Settings::sanitize( (array) $input ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'            => self::TAB,
					'tassyir_notice' => 'cod_saved',
				),
				admin_url( 'admin.php?page=' . Settings_Page::SLUG )
			)
		);
		exit;
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$s = Settings::get();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tassyir-cod-settings">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		echo '<p>' . esc_html__( 'The cash-on-delivery form creates a normal WooCommerce order, which is then sent to Tassyir like any other. Delivery fees, wilayas, communes and desks come from your Tassyir store and carrier, so they are not set here.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<p class="description">';
		/* translators: 1: shortcode, 2: block name. */
		echo esc_html( sprintf( __( 'Place it anywhere with the shortcode %1$s (also in Elementor\'s shortcode widget) or the "%2$s" block.', 'tassyir-for-woocommerce' ), '[tassyir_cod_form]', __( 'Tassyir COD form', 'tassyir-for-woocommerce' ) ) );
		echo '</p>';

		echo '<h2>' . esc_html__( 'Placement', 'tassyir-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		$this->checkbox_row( 'product_pages', __( 'Product pages', 'tassyir-for-woocommerce' ), __( 'Show on every product page', 'tassyir-for-woocommerce' ), $s );
		$this->select_row(
			'placement_mode',
			__( 'Add to cart', 'tassyir-for-woocommerce' ),
			array(
				'replace'   => __( 'Replace Add to cart with the form', 'tassyir-for-woocommerce' ),
				'hide_cart' => __( 'Replace Add to cart, and hide the cart & checkout', 'tassyir-for-woocommerce' ),
				'keep'      => __( 'Keep Add to cart under the form', 'tassyir-for-woocommerce' ),
			),
			$s
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Fields', 'tassyir-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		$toggles = array(
			'show_quantity'       => __( 'Quantity selector', 'tassyir-for-woocommerce' ),
			'show_phone2'         => __( 'Second phone (optional for the customer)', 'tassyir-for-woocommerce' ),
			'show_address'        => __( 'Address', 'tassyir-for-woocommerce' ),
			'require_address'     => __( 'Address is required', 'tassyir-for-woocommerce' ),
			'show_note'           => __( 'Note', 'tassyir-for-woocommerce' ),
			'show_icons'          => __( 'Icons in the fields', 'tassyir-for-woocommerce' ),
			'show_stopdesk'       => __( 'Desk delivery option (only where your carrier has a desk)', 'tassyir-for-woocommerce' ),
			'show_offers'         => __( 'Quantity offers (from Tassyir)', 'tassyir-for-woocommerce' ),
			'show_bundle_savings' => __( 'Show the saving on offers', 'tassyir-for-woocommerce' ),
		);
		foreach ( $toggles as $key => $label ) {
			$this->checkbox_row( $key, $label, __( 'Show', 'tassyir-for-woocommerce' ), $s );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Language and texts', 'tassyir-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		$this->select_row(
			'language',
			__( 'Form language', 'tassyir-for-woocommerce' ),
			array(
				'auto' => __( 'The site language (Arabic otherwise)', 'tassyir-for-woocommerce' ),
				'ar'   => 'العربية',
				'fr'   => 'Français',
				'en'   => 'English',
			),
			$s
		);
		$this->select_row(
			'direction',
			__( 'Text direction', 'tassyir-for-woocommerce' ),
			array(
				'auto' => __( 'From the language', 'tassyir-for-woocommerce' ),
				'rtl'  => __( 'Right to left', 'tassyir-for-woocommerce' ),
				'ltr'  => __( 'Left to right', 'tassyir-for-woocommerce' ),
			),
			$s
		);
		echo '</table>';
		echo '<p class="description">' . esc_html__( 'Leave a text empty to keep the default shown in grey.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped tassyir-texts"><thead><tr><th></th>';
		foreach ( array( 'العربية', 'Français', 'English' ) as $language ) {
			echo '<th>' . esc_html( $language ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Texts::EDITABLE as $key ) {
			echo '<tr><th scope="row">' . esc_html( $this->text_label( $key ) ) . '</th>';
			foreach ( Texts::LANGUAGES as $lang ) {
				$value   = isset( $s['texts'][ $lang ][ $key ] ) ? (string) $s['texts'][ $lang ][ $key ] : '';
				$default = Texts::defaults( $lang )[ $key ];
				printf(
					'<td><input type="text" class="widefat" dir="%1$s" name="tassyir_cod[texts][%2$s][%3$s]" value="%4$s" placeholder="%5$s" aria-label="%6$s"></td>',
					esc_attr( 'ar' === $lang ? 'rtl' : 'ltr' ),
					esc_attr( $lang ),
					esc_attr( $key ),
					esc_attr( $value ),
					esc_attr( $default ),
					esc_attr( $this->text_label( $key ) . ' (' . $lang . ')' )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Colours', 'tassyir-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		$colors = array(
			'color_primary'     => __( 'Main colour (button, selection)', 'tassyir-for-woocommerce' ),
			'color_button_text' => __( 'Button text', 'tassyir-for-woocommerce' ),
			'color_bg'          => __( 'Background', 'tassyir-for-woocommerce' ),
			'color_text'        => __( 'Text', 'tassyir-for-woocommerce' ),
			'color_border'      => __( 'Borders', 'tassyir-for-woocommerce' ),
		);
		foreach ( $colors as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="tassyir-cod-%1$s">%2$s</label></th><td><input type="color" id="tassyir-cod-%1$s" name="tassyir_cod[%1$s]" value="%3$s"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) $s[ $key ] )
			);
		}
		printf(
			'<tr><th scope="row"><label for="tassyir-cod-radius">%1$s</label></th><td><input type="number" id="tassyir-cod-radius" name="tassyir_cod[border_radius]" min="0" max="24" value="%2$d"> px</td></tr>',
			esc_html__( 'Corner radius', 'tassyir-for-woocommerce' ),
			(int) $s['border_radius']
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'After the order', 'tassyir-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		$this->select_row(
			'success_mode',
			__( 'The customer sees', 'tassyir-for-woocommerce' ),
			array(
				'order_received' => __( 'WooCommerce\'s order received page', 'tassyir-for-woocommerce' ),
				'custom'         => __( 'My own page (URL below)', 'tassyir-for-woocommerce' ),
				'inline'         => __( 'A message on the form', 'tassyir-for-woocommerce' ),
			),
			$s
		);
		printf(
			'<tr><th scope="row"><label for="tassyir-cod-success-url">%1$s</label></th><td><input type="url" class="regular-text" id="tassyir-cod-success-url" name="tassyir_cod[success_url]" value="%2$s" placeholder="https://"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Thank-you page URL', 'tassyir-for-woocommerce' ),
			esc_attr( (string) $s['success_url'] ),
			esc_html__( 'Order number and total are added as ?order=&total=. Orders Tassyir holds as possible spam get no WooCommerce order: with the order received page they see the message on the form instead.', 'tassyir-for-woocommerce' )
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Spam protection', 'tassyir-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Tassyir checks every order before it exists (honeypot, phone and device limits, your spam rules). Behind Cloudflare, every visitor reaches your server from a Cloudflare address: tick this so Tassyir uses the visitor\'s real address instead (it checks that the request really came through Cloudflare).', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'behind_cloudflare', __( 'Cloudflare', 'tassyir-for-woocommerce' ), __( 'My site is behind Cloudflare', 'tassyir-for-woocommerce' ), $s );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Pixels', 'tassyir-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Sends Purchase with Tassyir\'s event id, so it deduplicates with Tassyir\'s server-side events. The pixel itself must already be on your site.', 'tassyir-for-woocommerce' ) . '</p>';
		if ( Settings::pixel_plugin_active() ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'PixelYourSite is active and already sends Purchase from the order received page: keep these off to avoid counting each order twice.', 'tassyir-for-woocommerce' ) . '</p>';
		}
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'pixel_meta', __( 'Meta Pixel', 'tassyir-for-woocommerce' ), __( 'Send Purchase', 'tassyir-for-woocommerce' ), $s );
		$this->checkbox_row( 'pixel_tiktok', __( 'TikTok Pixel', 'tassyir-for-woocommerce' ), __( 'Send CompletePayment', 'tassyir-for-woocommerce' ), $s );
		echo '</table>';

		submit_button( __( 'Save changes', 'tassyir-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * A checkbox row.
	 *
	 * @param string               $key      Setting key.
	 * @param string               $label    Row label.
	 * @param string               $text     Checkbox text.
	 * @param array<string, mixed> $settings Settings.
	 */
	private function checkbox_row( string $key, string $label, string $text, array $settings ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="tassyir_cod[%2$s]" value="1" %3$s> %4$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( ! empty( $settings[ $key ] ), true, false ),
			esc_html( $text )
		);
	}

	/**
	 * A select row.
	 *
	 * @param string                $key      Setting key.
	 * @param string                $label    Row label.
	 * @param array<string, string> $options  Value => text.
	 * @param array<string, mixed>  $settings Settings.
	 */
	private function select_row( string $key, string $label, array $options, array $settings ): void {
		printf( '<tr><th scope="row"><label for="tassyir-cod-%1$s">%2$s</label></th><td><select id="tassyir-cod-%1$s" name="tassyir_cod[%1$s]">', esc_attr( $key ), esc_html( $label ) );
		foreach ( $options as $value => $text ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) $settings[ $key ], $value, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}

	/**
	 * Admin label of an editable text.
	 *
	 * @param string $key Text key.
	 */
	private function text_label( string $key ): string {
		$labels = array(
			'heading'         => __( 'Heading', 'tassyir-for-woocommerce' ),
			'button_label'    => __( 'Button', 'tassyir-for-woocommerce' ),
			'success_message' => __( 'Success message', 'tassyir-for-woocommerce' ),
			'name_label'      => __( 'Name', 'tassyir-for-woocommerce' ),
			'phone_label'     => __( 'Phone', 'tassyir-for-woocommerce' ),
			'phone2_label'    => __( 'Second phone', 'tassyir-for-woocommerce' ),
			'province_label'  => __( 'Wilaya', 'tassyir-for-woocommerce' ),
			'town_label'      => __( 'Commune', 'tassyir-for-woocommerce' ),
			'address_label'   => __( 'Address', 'tassyir-for-woocommerce' ),
			'note_label'      => __( 'Note', 'tassyir-for-woocommerce' ),
			'home_label'      => __( 'Home delivery', 'tassyir-for-woocommerce' ),
			'stopdesk_label'  => __( 'Desk delivery', 'tassyir-for-woocommerce' ),
			'bundle_label'    => __( 'Offers heading', 'tassyir-for-woocommerce' ),
			'cod_sublabel'    => __( 'Under the total', 'tassyir-for-woocommerce' ),
		);
		return $labels[ $key ] ?? $key;
	}
}
