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
	 * Prints the tab: intro, section nav, one card per section, sticky save bar.
	 */
	public function render(): void {
		$s = Settings::get();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tassyir-cod-settings">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		$this->render_intro();

		echo '<div class="tassyir-layout">';
		$this->render_nav();
		echo '<div class="tassyir-sections">';
		$this->render_placement( $s );
		$this->render_fields( $s );
		$this->render_texts( $s );
		$this->render_colours( $s );
		$this->render_after( $s );
		$this->render_spam( $s );
		$this->render_pixels( $s );
		echo '</div></div>';

		echo '<div class="tassyir-savebar"><span class="tassyir-unsaved" role="status" hidden>' . esc_html__( 'Unsaved changes', 'tassyir-for-woocommerce' ) . '</span>';
		echo '<div class="tassyir-savebar__end">';
		submit_button( __( 'Save changes', 'tassyir-for-woocommerce' ), 'primary', 'submit', false );
		echo '</div></div>';
		echo '</form>';
	}

	/**
	 * Sections in display order (anchor => title); the section nav and the card titles share it.
	 *
	 * @return array<string, string>
	 */
	private function sections(): array {
		return array(
			'placement' => __( 'Placement', 'tassyir-for-woocommerce' ),
			'fields'    => __( 'Fields', 'tassyir-for-woocommerce' ),
			'texts'     => __( 'Language and texts', 'tassyir-for-woocommerce' ),
			'colours'   => __( 'Colours', 'tassyir-for-woocommerce' ),
			'after'     => __( 'After the order', 'tassyir-for-woocommerce' ),
			'spam'      => __( 'Spam protection', 'tassyir-for-woocommerce' ),
			'pixels'    => __( 'Pixels', 'tassyir-for-woocommerce' ),
		);
	}

	/**
	 * Card id of a section.
	 *
	 * @param string $section Key of sections().
	 */
	private function section_id( string $section ): string {
		return 'tassyir-sec-' . $section;
	}

	/**
	 * The explanation, the shortcode with its Copy button, and the block name.
	 */
	private function render_intro(): void {
		Admin_Ui::card_open( array( 'class' => 'tassyir-intro' ) );
		echo '<p>' . esc_html__( 'The cash-on-delivery form creates a normal WooCommerce order, which is then sent to Tassyir like any other. Delivery fees, wilayas, communes and desks come from your Tassyir store and carrier, so they are not set here.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<p class="tassyir-chip-line"><code class="tassyir-chip">[tassyir_cod_form]</code>';
		echo '<button type="button" class="button button-small tassyir-copy" data-tassyir-copy="[tassyir_cod_form]" data-copied="' . esc_attr__( 'Copied', 'tassyir-for-woocommerce' ) . '" hidden>' . esc_html__( 'Copy', 'tassyir-for-woocommerce' ) . '</button></p>';
		echo '<p class="tassyir-muted">';
		/* translators: 1: shortcode, 2: block name. */
		echo esc_html( sprintf( __( 'Place it anywhere with the shortcode %1$s (also in Elementor\'s shortcode widget) or the "%2$s" block.', 'tassyir-for-woocommerce' ), '[tassyir_cod_form]', __( 'Tassyir COD form', 'tassyir-for-woocommerce' ) ) );
		echo '</p>';
		Admin_Ui::card_close();
	}

	/**
	 * Anchor links to the sections: a sticky column on wide screens, a chip row on narrow ones.
	 */
	private function render_nav(): void {
		echo '<nav class="tassyir-sidenav" aria-label="' . esc_attr__( 'Sections', 'tassyir-for-woocommerce' ) . '"><ul>';
		foreach ( $this->sections() as $key => $title ) {
			echo '<li><a href="#' . esc_attr( $this->section_id( $key ) ) . '">' . esc_html( $title ) . '</a></li>';
		}
		echo '</ul></nav>';
	}

	/**
	 * Opens the card of a section.
	 *
	 * @param string $section Key of sections().
	 * @param string $desc    Optional muted description.
	 * @param string $body    Body class: rows (default) or '' for free content.
	 */
	private function section_open( string $section, string $desc = '', string $body = 'tassyir-card__body--rows' ): void {
		$sections = $this->sections();
		Admin_Ui::card_open(
			array(
				'id'         => $this->section_id( $section ),
				'title'      => $sections[ $section ],
				'desc'       => $desc,
				'body_class' => $body,
			)
		);
	}

	/**
	 * Product pages and the Add to cart behaviour.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_placement( array $s ): void {
		$this->section_open( 'placement' );
		$this->toggle_row( 'product_pages', __( 'Product pages', 'tassyir-for-woocommerce' ), __( 'Show the form on every product page.', 'tassyir-for-woocommerce' ), $s );
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
		Admin_Ui::card_close();
	}

	/**
	 * Which fields the form shows.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_fields( array $s ): void {
		$this->section_open( 'fields' );
		$toggles = array(
			'show_quantity'       => array( __( 'Quantity selector', 'tassyir-for-woocommerce' ), '' ),
			'show_phone2'         => array( __( 'Second phone', 'tassyir-for-woocommerce' ), __( 'Optional for the customer.', 'tassyir-for-woocommerce' ) ),
			'show_address'        => array( __( 'Address', 'tassyir-for-woocommerce' ), '' ),
			'require_address'     => array( __( 'Address required', 'tassyir-for-woocommerce' ), __( 'Customers must fill in the address.', 'tassyir-for-woocommerce' ) ),
			'show_note'           => array( __( 'Note', 'tassyir-for-woocommerce' ), '' ),
			'show_icons'          => array( __( 'Icons in the fields', 'tassyir-for-woocommerce' ), __( 'Small icons inside the fields.', 'tassyir-for-woocommerce' ) ),
			'show_stopdesk'       => array( __( 'Desk delivery option', 'tassyir-for-woocommerce' ), __( 'Only where your carrier has a desk.', 'tassyir-for-woocommerce' ) ),
			'show_offers'         => array( __( 'Quantity offers', 'tassyir-for-woocommerce' ), __( 'Quantity offers you set up in Tassyir.', 'tassyir-for-woocommerce' ) ),
			'show_bundle_savings' => array( __( 'Show the saving on offers', 'tassyir-for-woocommerce' ), '' ),
		);
		foreach ( $toggles as $key => $texts ) {
			// "Address required" only matters while the address is shown (the script greys it out).
			$attrs = 'require_address' === $key ? array( 'data-tassyir-depends' => 'show_address' ) : array();
			$this->toggle_row( $key, $texts[0], $texts[1], $s, $attrs );
		}
		Admin_Ui::card_close();
	}

	/**
	 * Form language, direction, and the 14 editable texts in three languages.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_texts( array $s ): void {
		$this->section_open( 'texts' );
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

		$names   = array(
			'ar' => 'العربية',
			'fr' => 'Français',
			'en' => 'English',
		);
		$default = in_array( (string) $s['language'], Texts::LANGUAGES, true ) ? (string) $s['language'] : 'ar';

		echo '<div class="tassyir-texts" data-tassyir-langs data-default="' . esc_attr( $default ) . '">';
		echo '<p class="tassyir-muted">' . esc_html__( 'Leave a text empty to keep the default shown in grey.', 'tassyir-for-woocommerce' ) . '</p>';
		echo '<div class="tassyir-seg" role="group" aria-label="' . esc_attr__( 'Language of the texts', 'tassyir-for-woocommerce' ) . '" hidden>';
		foreach ( Texts::LANGUAGES as $lang ) {
			echo '<button type="button" class="tassyir-seg__btn" data-lang="' . esc_attr( $lang ) . '" aria-pressed="false">' . esc_html( $names[ $lang ] ) . '</button>';
		}
		echo '</div>';
		foreach ( Texts::LANGUAGES as $lang ) {
			echo '<div class="tassyir-lang-panel" data-lang="' . esc_attr( $lang ) . '">';
			echo '<h3 class="tassyir-lang-panel__title">' . esc_html( $names[ $lang ] ) . '</h3>';
			foreach ( Texts::EDITABLE as $key ) {
				$this->text_row( $lang, $key, $s );
			}
			echo '</div>';
		}
		echo '</div>';
		Admin_Ui::card_close();
	}

	/**
	 * Colours and corner radius.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_colours( array $s ): void {
		$this->section_open( 'colours', '', '' );
		$colors = array(
			'color_primary'     => __( 'Main colour (button, selection)', 'tassyir-for-woocommerce' ),
			'color_button_text' => __( 'Button text', 'tassyir-for-woocommerce' ),
			'color_bg'          => __( 'Background', 'tassyir-for-woocommerce' ),
			'color_text'        => __( 'Text', 'tassyir-for-woocommerce' ),
			'color_border'      => __( 'Borders', 'tassyir-for-woocommerce' ),
		);
		echo '<div class="tassyir-colors">';
		foreach ( $colors as $key => $label ) {
			$id    = 'tassyir-cod-' . $key;
			$value = (string) $s[ $key ];
			echo '<div class="tassyir-color"><label class="tassyir-color__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			echo '<div class="tassyir-color__ctl"><input type="color" id="' . esc_attr( $id ) . '" name="tassyir_cod[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
			echo '<code class="tassyir-color__hex" data-tassyir-hex="' . esc_attr( $id ) . '">' . esc_html( $value ) . '</code></div></div>';
		}
		echo '<div class="tassyir-color"><label class="tassyir-color__label" for="tassyir-cod-radius">' . esc_html__( 'Corner radius', 'tassyir-for-woocommerce' ) . '</label>';
		echo '<div class="tassyir-color__ctl"><input type="number" id="tassyir-cod-radius" name="tassyir_cod[border_radius]" min="0" max="24" value="' . esc_attr( (string) (int) $s['border_radius'] ) . '"> <span class="tassyir-muted">px</span></div></div>';
		echo '</div>';
		Admin_Ui::card_close();
	}

	/**
	 * What the customer sees after ordering.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_after( array $s ): void {
		$this->section_open( 'after' );
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
		Admin_Ui::row_open(
			array(
				'label' => __( 'Thank-you page URL', 'tassyir-for-woocommerce' ),
				'help'  => __( 'Order number and total are added as ?order=&total=. Orders Tassyir holds as possible spam get no WooCommerce order: with the order received page they see the message on the form instead.', 'tassyir-for-woocommerce' ),
				'for'   => 'tassyir-cod-success-url',
				'key'   => 'success_url',
				'class' => 'tassyir-row--wide',
				'attrs' => array( 'data-tassyir-show-when' => 'success_mode=custom' ),
			)
		);
		echo '<input type="url" id="tassyir-cod-success-url" name="tassyir_cod[success_url]" value="' . esc_attr( (string) $s['success_url'] ) . '" placeholder="https://" aria-describedby="tassyir-cod-success-url-help">';
		Admin_Ui::row_close();
		Admin_Ui::card_close();
	}

	/**
	 * Cloudflare real-address switch.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_spam( array $s ): void {
		$this->section_open( 'spam', __( 'Tassyir checks every order before it exists (honeypot, phone and device limits, your spam rules). Behind Cloudflare, every visitor reaches your server from a Cloudflare address: tick this so Tassyir uses the visitor\'s real address instead (it checks that the request really came through Cloudflare).', 'tassyir-for-woocommerce' ) );
		$this->toggle_row( 'behind_cloudflare', __( 'Cloudflare', 'tassyir-for-woocommerce' ), __( 'My site is behind Cloudflare', 'tassyir-for-woocommerce' ), $s );
		Admin_Ui::card_close();
	}

	/**
	 * Purchase events for the merchant's own pixels.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private function render_pixels( array $s ): void {
		$this->section_open( 'pixels', __( 'Sends Purchase with Tassyir\'s event id, so it deduplicates with Tassyir\'s server-side events. The pixel itself must already be on your site.', 'tassyir-for-woocommerce' ) );
		if ( Settings::pixel_plugin_active() ) {
			echo '<div class="tassyir-note" role="note"><span class="tassyir-note__icon">';
			Admin_Ui::print_icon( 'alert' );
			echo '</span><p>' . esc_html__( 'PixelYourSite is active and already sends Purchase from the order received page: keep these off to avoid counting each order twice.', 'tassyir-for-woocommerce' ) . '</p></div>';
		}
		$this->toggle_row( 'pixel_meta', __( 'Meta Pixel', 'tassyir-for-woocommerce' ), __( 'Send Purchase', 'tassyir-for-woocommerce' ), $s );
		$this->toggle_row( 'pixel_tiktok', __( 'TikTok Pixel', 'tassyir-for-woocommerce' ), __( 'Send CompletePayment', 'tassyir-for-woocommerce' ), $s );
		Admin_Ui::card_close();
	}

	/**
	 * A switch row: the real checkbox (so the posted value is unchanged), styled as a toggle.
	 * The whole text side is its label.
	 *
	 * @param string               $key      Setting key.
	 * @param string               $label    Row label.
	 * @param string               $help     Muted help text ('' = none).
	 * @param array<string, mixed> $settings Settings.
	 * @param array<string,string> $attrs    Extra data-* attributes of the row.
	 */
	private function toggle_row( string $key, string $label, string $help, array $settings, array $attrs = array() ): void {
		$id = 'tassyir-cod-' . $key;
		Admin_Ui::row_open(
			array(
				'label' => $label,
				'help'  => $help,
				'for'   => $id,
				'key'   => $key,
				'wrap'  => true,
				'class' => 'tassyir-row--toggle',
				'attrs' => $attrs,
			)
		);
		echo '<input type="checkbox" role="switch" class="tassyir-switch" id="' . esc_attr( $id ) . '" name="tassyir_cod[' . esc_attr( $key ) . ']" value="1" aria-labelledby="' . esc_attr( $id . '-label' ) . '"';
		if ( '' !== $help ) {
			echo ' aria-describedby="' . esc_attr( $id . '-help' ) . '"';
		}
		echo ' ' . checked( ! empty( $settings[ $key ] ), true, false ) . '>';
		Admin_Ui::row_close();
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
		$id = 'tassyir-cod-' . $key;
		Admin_Ui::row_open(
			array(
				'label' => $label,
				'for'   => $id,
				'key'   => $key,
			)
		);
		echo '<select id="' . esc_attr( $id ) . '" name="tassyir_cod[' . esc_attr( $key ) . ']">';
		foreach ( $options as $value => $text ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) $settings[ $key ], $value, false ), esc_html( $text ) );
		}
		echo '</select>';
		Admin_Ui::row_close();
	}

	/**
	 * One editable text of one language: label on the start side, input on the end side.
	 *
	 * @param string               $lang     ar | fr | en.
	 * @param string               $key      Text key.
	 * @param array<string, mixed> $settings Settings.
	 */
	private function text_row( string $lang, string $key, array $settings ): void {
		$id      = 'tassyir-cod-text-' . $lang . '-' . $key;
		$value   = isset( $settings['texts'][ $lang ][ $key ] ) ? (string) $settings['texts'][ $lang ][ $key ] : '';
		$default = Texts::defaults( $lang )[ $key ];
		Admin_Ui::row_open(
			array(
				'label' => $this->text_label( $key ),
				'for'   => $id,
				'class' => 'tassyir-row--text',
			)
		);
		printf(
			'<input type="text" class="widefat" id="%1$s" dir="%2$s" name="tassyir_cod[texts][%3$s][%4$s]" value="%5$s" placeholder="%6$s" aria-label="%7$s">',
			esc_attr( $id ),
			esc_attr( 'ar' === $lang ? 'rtl' : 'ltr' ),
			esc_attr( $lang ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_attr( $default ),
			esc_attr( $this->text_label( $key ) . ' (' . $lang . ')' )
		);
		Admin_Ui::row_close();
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
