/**
 * Tassyir settings page: asks for confirmation before buttons marked with data-tassyir-confirm.
 */
( function () {
	'use strict';
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var question = form && form.getAttribute && form.getAttribute( 'data-tassyir-confirm' );
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );
}() );
