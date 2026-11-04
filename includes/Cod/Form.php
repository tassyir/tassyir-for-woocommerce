<?php
/**
 * Renders the COD form for a product.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Cod;

use Tassyir\WooCommerce\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * The form is static HTML (cacheable) plus one script and one stylesheet; it renders only for a
 * connected site and a purchasable simple or variable product. Disconnected, nothing renders and
 * the store keeps its normal Add to cart.
 */
final class Form {

	const HANDLE = 'tassyir-cod-form';

	/**
	 * Campaign capture script: loaded on every front-end page of a connected site, and a
	 * dependency of the form's script so a form page always has it.
	 */
	const ATTRIBUTION_HANDLE = 'tassyir-attribution';

	/**
	 * Variants listed at most (a variation with "Any" attributes expands to one per value).
	 */
	const MAX_VARIANTS = 200;

	/**
	 * Forms rendered on this page (unique ids).
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Registers the script and stylesheet (enqueued when a form renders).
	 */
	public static function register_assets(): void {
		wp_register_style( self::HANDLE, plugins_url( 'assets/css/cod-form.css', TASSYIR_WC_FILE ), array(), TASSYIR_WC_VERSION );
		wp_register_script( self::ATTRIBUTION_HANDLE, plugins_url( 'assets/js/attribution.js', TASSYIR_WC_FILE ), array(), TASSYIR_WC_VERSION, true );
		wp_register_script( self::HANDLE, plugins_url( 'assets/js/cod-form.js', TASSYIR_WC_FILE ), array( self::ATTRIBUTION_HANDLE ), TASSYIR_WC_VERSION, true );
	}

	/**
	 * Whether the form can be shown for this product.
	 *
	 * @param mixed $product Product.
	 */
	public static function supports( $product ): bool {
		return $product instanceof \WC_Product
			&& ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) )
			&& 'publish' === $product->get_status()
			&& ( new Connection() )->is_connected();
	}

	/**
	 * The form's HTML for a product ('' when it cannot be shown).
	 *
	 * @param mixed $product Product.
	 * @param bool  $preview Rendered for the block editor (the script does not run there).
	 */
	public static function render( $product, bool $preview = false ): string {
		if ( ! self::supports( $product ) ) {
			return '';
		}
		/** @var \WC_Product $product */
		$data = self::product_data( $product );
		if ( array() === $data['variants'] ) {
			return '';
		}

		if ( ! $preview ) {
			if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
				self::register_assets();
			}
			wp_enqueue_style( self::HANDLE );
			wp_enqueue_script( self::HANDLE );
		}

		$settings = Settings::get();
		$lang     = Settings::language( $settings );
		$texts    = Settings::texts( $settings, $lang );
		$selected = $data['variants'][0];
		foreach ( $data['variants'] as $variant ) {
			if ( (string) $variant['id'] === (string) $data['selectedVariantId'] ) {
				$selected = $variant;
			}
		}
		$image_id = (int) $product->get_image_id();
		$image    = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : false;

		++self::$count;
		$vars = array(
			'settings'     => $settings,
			'texts'        => $texts,
			'dir'          => Settings::direction( $settings, $lang ),
			'uid'          => $product->get_id() . '-' . self::$count,
			'product_json' => (string) wp_json_encode( $data ),
			'i18n_json'    => (string) wp_json_encode( Texts::script( $lang ) ),
			'style'        => self::style( $settings ),
			'title'        => $product->get_name(),
			'image_url'    => is_string( $image ) ? $image : '',
			'has_variants' => count( $data['variants'] ) > 1,
			'variant_name' => (string) $selected['title'],
			'preview'      => $preview,
		);
		return self::view( $vars );
	}

	/**
	 * The product as the script reads it (the Shopify block's shape): prices in cents, one
	 * variant per purchasable combination, options aligned to optionNames.
	 *
	 * @param \WC_Product $product Simple or variable product.
	 * @return array{productId: int, productTitle: string, selectedVariantId: string, optionNames: string[], variants: array<int, array<string, mixed>>}
	 */
	public static function product_data( \WC_Product $product ): array {
		$data = array(
			'productId'         => $product->get_id(),
			'productTitle'      => $product->get_name(),
			'selectedVariantId' => '',
			'optionNames'       => array(),
			'variants'          => array(),
		);

		if ( ! $product instanceof \WC_Product_Variable ) {
			if ( '' === (string) $product->get_price() ) {
				return $data;
			}
			$data['variants'][]        = array(
				'id'        => (string) $product->get_id(),
				'title'     => $product->get_name(),
				'sku'       => (string) $product->get_sku(),
				'price'     => Format::cents( $product->get_price() ),
				'available' => $product->is_purchasable() && $product->is_in_stock(),
				'options'   => array(),
			);
			$data['selectedVariantId'] = (string) $product->get_id();
			return $data;
		}

		// Attribute key (attribute_pa_size) => [ value slug => display name ].
		$axes = array();
		foreach ( $product->get_variation_attributes() as $attribute => $values ) {
			$key                   = 'attribute_' . sanitize_title( (string) $attribute );
			$axes[ $key ]          = array();
			$data['optionNames'][] = wc_attribute_label( (string) $attribute, $product );
			foreach ( (array) $values as $value ) {
				$axes[ $key ][ (string) $value ] = self::value_label( (string) $attribute, (string) $value );
			}
		}
		$keys     = array_keys( $axes );
		$defaults = $product->get_default_attributes();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() || '' === (string) $variation->get_price() ) {
				continue;
			}
			// Expand attributes the variation leaves open ("Any …") into one entry per value.
			$combos = array( array() );
			$own    = $variation->get_variation_attributes();
			foreach ( $keys as $key ) {
				$value  = isset( $own[ $key ] ) ? (string) $own[ $key ] : '';
				$values = '' === $value ? array_keys( $axes[ $key ] ) : array( $value );
				$next   = array();
				foreach ( $combos as $combo ) {
					foreach ( $values as $one ) {
						$next[] = $combo + array( $key => array( (string) $one, '' === $value ) );
					}
				}
				$combos = $next;
			}
			foreach ( $combos as $index => $combo ) {
				if ( count( $data['variants'] ) >= self::MAX_VARIANTS ) {
					break 2;
				}
				$options = array();
				$picked  = array();
				$match   = true;
				foreach ( $keys as $key ) {
					list( $value, $open ) = $combo[ $key ];
					$options[]            = $axes[ $key ][ $value ] ?? $value;
					if ( $open ) {
						$picked[ $key ] = $value;
					}
					$default = $defaults[ str_replace( 'attribute_', '', $key ) ] ?? null;
					if ( null === $default || (string) $default !== $value ) {
						$match = false;
					}
				}
				$id      = count( $combos ) > 1 ? $variation->get_id() . '-' . $index : (string) $variation->get_id();
				$variant = array(
					'id'             => (string) $id,
					'wooVariationId' => $variation->get_id(),
					'title'          => implode( ' / ', $options ),
					'sku'            => (string) $variation->get_sku(),
					'price'          => Format::cents( $variation->get_price() ),
					'available'      => $variation->is_purchasable() && $variation->is_in_stock(),
					'options'        => $options,
				);
				if ( array() !== $picked ) {
					$variant['attributes'] = $picked;
				}
				$data['variants'][] = $variant;
				if ( $match && '' === $data['selectedVariantId'] ) {
					$data['selectedVariantId'] = (string) $id;
				}
			}
		}
		if ( '' === $data['selectedVariantId'] ) {
			foreach ( $data['variants'] as $variant ) {
				if ( $variant['available'] ) {
					$data['selectedVariantId'] = (string) $variant['id'];
					break;
				}
			}
		}
		return $data;
	}

	/**
	 * The display name of an attribute value (a term's name for taxonomy attributes).
	 *
	 * @param string $attribute Attribute name (pa_size or a custom one).
	 * @param string $value     Value (term slug, or the custom value).
	 */
	private static function value_label( string $attribute, string $value ): string {
		if ( taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $value, $attribute );
			if ( $term instanceof \WP_Term ) {
				return $term->name;
			}
		}
		return $value;
	}

	/**
	 * The merchant's colours and radius as the stylesheet's custom properties.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function style( array $settings ): string {
		$vars = array(
			'--tsy-primary'  => $settings['color_primary'],
			'--tsy-btn-text' => $settings['color_button_text'],
			'--tsy-bg'       => $settings['color_bg'],
			'--tsy-text'     => $settings['color_text'],
			'--tsy-border'   => $settings['color_border'],
		);
		$css  = '';
		foreach ( $vars as $name => $value ) {
			$color = sanitize_hex_color( (string) $value );
			if ( $color ) {
				$css .= $name . ': ' . $color . '; ';
			}
		}
		return $css . '--tsy-radius: ' . (int) $settings['border_radius'] . 'px;';
	}

	/**
	 * Renders the view with its variables.
	 *
	 * @param array<string, mixed> $vars View variables.
	 */
	private static function view( array $vars ): string {
		$settings     = $vars['settings'];
		$texts        = $vars['texts'];
		$dir          = $vars['dir'];
		$uid          = $vars['uid'];
		$product_json = $vars['product_json'];
		$i18n_json    = $vars['i18n_json'];
		$style        = $vars['style'];
		$title        = $vars['title'];
		$image_url    = $vars['image_url'];
		$has_variants = $vars['has_variants'];
		$variant_name = $vars['variant_name'];
		$preview      = $vars['preview'];
		ob_start();
		include __DIR__ . '/views/form.php';
		return (string) ob_get_clean();
	}
}
