<?php
/**
 * Small markup helpers shared by the admin screens: icons, brand mark, status pill, cards, stat tiles, setting rows.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rendering helpers. Nothing here reads settings or touches the connection.
 * Every value passed in is escaped on output; the SVG strings are constants of this class.
 */
final class Admin_Ui {

	/**
	 * Inner markup of each icon (24x24 grid, drawn with the current colour).
	 */
	const ICONS = array(
		'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
		'alert'    => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4"/><path d="M12 17.5h.01"/>',
		'box'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
		'cart'     => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 4h2.5l2.2 11h10.6L21 8H6.2"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'external' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
	);

	/**
	 * An inline SVG icon, decorative only.
	 *
	 * @param string $name Key of self::ICONS.
	 * @return string SVG markup ('' for an unknown name).
	 */
	public static function icon( string $name ): string {
		if ( ! isset( self::ICONS[ $name ] ) ) {
			return '';
		}
		return '<svg class="tassyir-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::ICONS[ $name ] . '</svg>';
	}

	/**
	 * Prints an icon.
	 *
	 * @param string $name Key of self::ICONS.
	 */
	public static function print_icon( string $name ): void {
		echo self::icon( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a constant SVG built from self::ICONS.
	}

	/**
	 * The Tassyir brand mark (teal square, two dark shapes).
	 *
	 * @param int $size Width and height in pixels.
	 * @return string SVG markup.
	 */
	public static function brand_mark( int $size = 32 ): string {
		$size = max( 16, $size );
		return '<svg class="tassyir-mark" viewBox="0 0 400 400" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false"><rect width="400" height="400" rx="88" fill="#7CDCDF"/><path d="M265.624 156.77V200.131C253.039 200.131 240.453 200.162 227.867 200.249C219.981 200.305 212.177 200.167 204.439 198.504C196.722 196.852 189.314 194.374 182.32 190.716C159.647 178.838 144.865 160.547 138.113 135.903C136.107 128.597 134.911 121.116 135.199 113.281H178.636C178.462 119.192 179.883 124.826 182.3 130.182C189.293 145.724 201.361 156.765 218.221 156.765" fill="#091E18"/><path d="M265.625 243.622V286.983C253.039 286.983 240.453 287.014 227.867 287.101C219.981 287.157 212.177 287.019 204.44 285.356C196.723 283.704 189.314 281.226 182.321 277.568C159.648 265.695 144.871 247.404 138.113 222.76C136.107 215.449 134.912 207.968 135.199 200.133H178.637C178.462 206.044 179.884 211.678 182.3 217.034C189.294 232.576 201.361 243.617 218.221 243.617" fill="#091E18"/></svg>';
	}

	/**
	 * Prints the brand mark.
	 *
	 * @param int $size Width and height in pixels.
	 */
	public static function print_brand_mark( int $size = 32 ): void {
		echo self::brand_mark( $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a constant SVG; $size is an int.
	}

	/**
	 * Status pill: a coloured dot and a label.
	 *
	 * @param string $state online | offline | idle.
	 * @param string $label Text.
	 */
	public static function print_pill( string $state, string $label ): void {
		$state = in_array( $state, array( 'online', 'offline' ), true ) ? $state : 'idle';
		echo '<span class="tassyir-pill tassyir-pill--' . esc_attr( $state ) . '"><span class="tassyir-pill__dot" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';
	}

	/**
	 * Opens a card. Close it with card_close().
	 *
	 * Arguments: id, class, role, title, desc, icon (key of self::ICONS), actions (callable printing
	 * into the header, end side), body_class.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function card_open( array $args = array() ): void {
		$a = array_merge(
			array(
				'id'         => '',
				'class'      => '',
				'role'       => '',
				'title'      => '',
				'desc'       => '',
				'icon'       => '',
				'actions'    => null,
				'body_class' => '',
			),
			$args
		);

		echo '<section class="' . esc_attr( trim( 'tassyir-card ' . (string) $a['class'] ) ) . '"';
		if ( '' !== (string) $a['id'] ) {
			echo ' id="' . esc_attr( (string) $a['id'] ) . '"';
		}
		if ( '' !== (string) $a['role'] ) {
			echo ' role="' . esc_attr( (string) $a['role'] ) . '"';
		}
		echo '>';

		if ( '' !== (string) $a['title'] ) {
			echo '<header class="tassyir-card__head"><div class="tassyir-card__heading">';
			if ( '' !== (string) $a['icon'] ) {
				echo '<span class="tassyir-card__icon">';
				self::print_icon( (string) $a['icon'] );
				echo '</span>';
			}
			echo '<div><h2 class="tassyir-card__title">' . esc_html( (string) $a['title'] ) . '</h2>';
			if ( '' !== (string) $a['desc'] ) {
				echo '<p class="tassyir-card__desc">' . esc_html( (string) $a['desc'] ) . '</p>';
			}
			echo '</div></div>';
			if ( is_callable( $a['actions'] ) ) {
				echo '<div class="tassyir-card__actions">';
				call_user_func( $a['actions'] );
				echo '</div>';
			}
			echo '</header>';
		}

		echo '<div class="' . esc_attr( trim( 'tassyir-card__body ' . (string) $a['body_class'] ) ) . '">';
	}

	/**
	 * Closes a card opened by card_open().
	 */
	public static function card_close(): void {
		echo '</div></section>';
	}

	/**
	 * One stat tile (a list item: print it inside <ul class="tassyir-stats">).
	 *
	 * @param string $label Small muted label.
	 * @param string $value Big value.
	 * @param string $sub   Optional muted line under the label.
	 * @param string $tone  '' or danger.
	 * @param string $title Optional tooltip on the value.
	 */
	public static function print_stat( string $label, string $value, string $sub = '', string $tone = '', string $title = '' ): void {
		echo '<li class="tassyir-stat' . ( 'danger' === $tone ? ' tassyir-stat--danger' : '' ) . '">';
		echo '<span class="tassyir-stat__value"';
		if ( '' !== $title ) {
			echo ' title="' . esc_attr( $title ) . '"';
		}
		echo '>' . esc_html( $value ) . '</span>';
		echo '<span class="tassyir-stat__label">' . esc_html( $label ) . '</span>';
		if ( '' !== $sub ) {
			echo '<span class="tassyir-stat__sub">' . esc_html( $sub ) . '</span>';
		}
		echo '</li>';
	}

	/**
	 * Opens a setting row: the label and help on the start side, the control on the end side.
	 * Print the control, then call row_close().
	 *
	 * Arguments: label, help, for (id of the control), key (data-tassyir-row), class, wrap (the whole
	 * text side is the <label>, for switches), attrs (extra data-* attributes, name => value).
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function row_open( array $args ): void {
		$a = array_merge(
			array(
				'label' => '',
				'help'  => '',
				'for'   => '',
				'key'   => '',
				'class' => '',
				'wrap'  => false,
				'attrs' => array(),
			),
			$args
		);

		$for = (string) $a['for'];

		echo '<div class="' . esc_attr( trim( 'tassyir-row ' . (string) $a['class'] ) ) . '"';
		if ( '' !== (string) $a['key'] ) {
			echo ' data-tassyir-row="' . esc_attr( (string) $a['key'] ) . '"';
		}
		foreach ( (array) $a['attrs'] as $name => $value ) {
			echo ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
		echo '>';

		if ( '' !== $for && ! empty( $a['wrap'] ) ) {
			echo '<label class="tassyir-row__text" for="' . esc_attr( $for ) . '">';
			echo '<span class="tassyir-row__label" id="' . esc_attr( $for . '-label' ) . '">' . esc_html( (string) $a['label'] ) . '</span>';
			if ( '' !== (string) $a['help'] ) {
				echo '<span class="tassyir-row__help" id="' . esc_attr( $for . '-help' ) . '">' . esc_html( (string) $a['help'] ) . '</span>';
			}
			echo '</label>';
		} else {
			echo '<div class="tassyir-row__text">';
			if ( '' !== $for ) {
				echo '<label class="tassyir-row__label" for="' . esc_attr( $for ) . '">' . esc_html( (string) $a['label'] ) . '</label>';
			} else {
				echo '<span class="tassyir-row__label">' . esc_html( (string) $a['label'] ) . '</span>';
			}
			if ( '' !== (string) $a['help'] ) {
				echo '<span class="tassyir-row__help"';
				if ( '' !== $for ) {
					echo ' id="' . esc_attr( $for . '-help' ) . '"';
				}
				echo '>' . esc_html( (string) $a['help'] ) . '</span>';
			}
			echo '</div>';
		}

		echo '<div class="tassyir-row__control">';
	}

	/**
	 * Closes a row opened by row_open().
	 */
	public static function row_close(): void {
		echo '</div></div>';
	}
}
