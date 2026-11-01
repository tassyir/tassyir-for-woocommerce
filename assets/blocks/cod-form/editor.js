/**
 * Block editor side of the Tassyir COD form block: a server-side rendered preview and a
 * product id setting (empty = the product page the block is placed on). No build step.
 */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'tassyir/cod-form', {
		edit: function ( props ) {
			var productId = props.attributes.productId || 0;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Product', 'tassyir-for-woocommerce' ) },
						el( TextControl, {
							label: __( 'Product ID', 'tassyir-for-woocommerce' ),
							help: __( 'Leave empty on a product page: the form is for that product.', 'tassyir-for-woocommerce' ),
							type: 'number',
							value: productId ? String( productId ) : '',
							onChange: function ( value ) {
								props.setAttributes( { productId: parseInt( value, 10 ) || 0 } );
							}
						} )
					)
				),
				el( ServerSideRender, { block: 'tassyir/cod-form', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
