/**
 * Tassyir settings page.
 *
 * - Asks for confirmation before forms marked with data-tassyir-confirm.
 * - Checkout form tab (progressive enhancement; every form works without this script):
 *   copy button, dependent rows, language switcher, colour hex labels, unsaved-changes notice.
 */
( function () {
	'use strict';

	var NS = 'tassyir_cod';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var question = form && form.getAttribute && form.getAttribute( 'data-tassyir-confirm' );
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );

	/**
	 * The form control of a setting key.
	 *
	 * @param {HTMLFormElement} form Settings form.
	 * @param {string}          key  Setting key.
	 * @return {HTMLElement|null}
	 */
	function field( form, key ) {
		return form.elements[ NS + '[' + key + ']' ] || null;
	}

	/**
	 * Copy buttons: shown only when the clipboard API exists; the label flips to "Copied" for 2 s.
	 *
	 * @param {HTMLElement} root Container.
	 */
	function initCopy( root ) {
		var buttons = root.querySelectorAll( '[data-tassyir-copy]' );
		var i;

		function bind( button ) {
			var label = button.textContent;
			var timer = null;
			button.hidden = false;
			button.addEventListener( 'click', function () {
				navigator.clipboard.writeText( button.getAttribute( 'data-tassyir-copy' ) ).then( function () {
					button.textContent = button.getAttribute( 'data-copied' ) || label;
					window.clearTimeout( timer );
					timer = window.setTimeout( function () {
						button.textContent = label;
					}, 2000 );
				} );
			} );
		}

		if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
			return;
		}
		for ( i = 0; i < buttons.length; i++ ) {
			bind( buttons[ i ] );
		}
	}

	/**
	 * "Address required" is greyed out and disabled while the address is hidden. A disabled box is not
	 * posted, so its value is kept in a hidden input meanwhile: nothing changes for what gets saved.
	 *
	 * @param {HTMLFormElement} form Settings form.
	 */
	function initDepends( form ) {
		var rows = form.querySelectorAll( '[data-tassyir-depends]' );
		var i;

		function bind( row ) {
			var master = field( form, row.getAttribute( 'data-tassyir-depends' ) );
			var input = row.querySelector( 'input[type="checkbox"]' );
			var keeper = null;

			if ( ! master || ! input ) {
				return;
			}

			function sync() {
				var off = ! master.checked;
				row.classList.toggle( 'is-disabled', off );
				input.disabled = off;
				if ( off && input.checked && ! keeper ) {
					keeper = document.createElement( 'input' );
					keeper.type = 'hidden';
					keeper.name = input.name;
					keeper.value = input.value;
					row.appendChild( keeper );
				} else if ( ( ! off || ! input.checked ) && keeper ) {
					keeper.parentNode.removeChild( keeper );
					keeper = null;
				}
			}

			master.addEventListener( 'change', sync );
			sync();
		}

		for ( i = 0; i < rows.length; i++ ) {
			bind( rows[ i ] );
		}
	}

	/**
	 * "Thank-you page URL" is shown only when the customer is sent to their own page.
	 *
	 * @param {HTMLFormElement} form Settings form.
	 */
	function initShowWhen( form ) {
		var rows = form.querySelectorAll( '[data-tassyir-show-when]' );
		var i;

		function bind( row ) {
			var rule = row.getAttribute( 'data-tassyir-show-when' ).split( '=' );
			var master = field( form, rule[ 0 ] );

			if ( ! master ) {
				return;
			}
			function sync() {
				row.hidden = master.value !== rule[ 1 ];
			}
			master.addEventListener( 'change', sync );
			sync();
		}

		for ( i = 0; i < rows.length; i++ ) {
			bind( rows[ i ] );
		}
	}

	/**
	 * One language panel at a time, chosen with a segmented control. Without this script all three
	 * panels stay visible, each under its own language heading.
	 *
	 * @param {HTMLElement} root Container.
	 */
	function initLanguages( root ) {
		var box = root.querySelector( '[data-tassyir-langs]' );
		var switcher;
		var buttons;
		var panels;
		var i;

		function show( lang ) {
			var j;
			for ( j = 0; j < panels.length; j++ ) {
				panels[ j ].hidden = panels[ j ].getAttribute( 'data-lang' ) !== lang;
			}
			for ( j = 0; j < buttons.length; j++ ) {
				buttons[ j ].setAttribute( 'aria-pressed', buttons[ j ].getAttribute( 'data-lang' ) === lang ? 'true' : 'false' );
			}
		}

		if ( ! box ) {
			return;
		}
		switcher = box.querySelector( '.tassyir-seg' );
		buttons = box.querySelectorAll( '.tassyir-seg__btn' );
		panels = box.querySelectorAll( '.tassyir-lang-panel' );
		if ( ! switcher || ! buttons.length || ! panels.length ) {
			return;
		}
		box.classList.add( 'is-enhanced' );
		switcher.hidden = false;
		for ( i = 0; i < buttons.length; i++ ) {
			buttons[ i ].addEventListener( 'click', function ( event ) {
				show( event.currentTarget.getAttribute( 'data-lang' ) );
			} );
		}
		show( box.getAttribute( 'data-default' ) || panels[ 0 ].getAttribute( 'data-lang' ) );
	}

	/**
	 * The hex value next to each colour swatch follows the picker.
	 *
	 * @param {HTMLElement} root Container.
	 */
	function initColours( root ) {
		var labels = root.querySelectorAll( '[data-tassyir-hex]' );
		var i;

		function bind( label ) {
			var input = document.getElementById( label.getAttribute( 'data-tassyir-hex' ) );
			if ( ! input ) {
				return;
			}
			input.addEventListener( 'input', function () {
				label.textContent = input.value;
			} );
		}

		for ( i = 0; i < labels.length; i++ ) {
			bind( labels[ i ] );
		}
	}

	/**
	 * "Unsaved changes" in the save bar, and a leave-the-page warning only while there are some.
	 *
	 * @param {HTMLFormElement} form Settings form.
	 */
	function initUnsaved( form ) {
		var notice = form.querySelector( '.tassyir-unsaved' );
		var dirty = false;

		function mark() {
			dirty = true;
			if ( notice ) {
				notice.hidden = false;
			}
		}

		form.addEventListener( 'input', mark );
		form.addEventListener( 'change', mark );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	var form = document.querySelector( 'form.tassyir-cod-settings' );
	if ( form ) {
		initCopy( form );
		initDepends( form );
		initShowWhen( form );
		initLanguages( form );
		initColours( form );
		initUnsaved( form );
	}
}() );
