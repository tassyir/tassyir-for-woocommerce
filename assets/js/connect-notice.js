/**
 * Tassyir connect notice: the × button only hides the notice on this page, so also tell
 * WordPress to stop showing it to this user (the notice carries the nonce'd AJAX URL).
 */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest && event.target.closest( '.tassyir-connect-notice .notice-dismiss' );
		if ( ! button ) {
			return;
		}
		var url = button.closest( '.tassyir-connect-notice' ).getAttribute( 'data-dismiss-url' );
		if ( url && window.fetch ) {
			window.fetch( url, { credentials: 'same-origin' } );
		}
	} );
}() );
