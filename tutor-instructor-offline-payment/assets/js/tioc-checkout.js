/**
 * Tutor Instructor Offline Payment — checkout.
 *
 * Three small jobs, no dependencies:
 *
 * 1. reveal the coupon field (the field itself posts to a sibling GET form via
 *    the HTML `form=` attribute, so no AJAX is involved),
 * 2. validate receipt uploads before the browser sends several megabytes,
 * 3. ask once before submitting, because the student is telling us they have
 *    already sent money.
 *
 * Everything degrades: with JS off, the coupon field is simply always visible
 * once the page is rendered without the `tutor-d-none` class, uploads are
 * validated again server-side, and the confirmation is skipped.
 */
( function () {
	'use strict';

	var config = window.TIOC_Checkout || {};
	var i18n = config.i18n || {};

	/**
	 * Fill a single %s placeholder.
	 *
	 * @param {string} template Message with one %s.
	 * @param {string} value    Replacement.
	 *
	 * @return {string} Formatted message.
	 */
	function format( template, value ) {
		return String( template || '' ).replace( '%s', value );
	}

	/**
	 * Lowercase extension of a filename, without the dot.
	 *
	 * @param {string} name Filename.
	 *
	 * @return {string} Extension.
	 */
	function extensionOf( name ) {
		var parts = String( name || '' ).split( '.' );

		return parts.length > 1 ? parts.pop().toLowerCase() : '';
	}

	/**
	 * Show or clear the error message that belongs to one field.
	 *
	 * @param {HTMLInputElement} input   File input.
	 * @param {string}           message Empty string clears.
	 *
	 * @return {void}
	 */
	function setError( input, message ) {
		var slot = input.parentNode.querySelector( '.tioc-field-error' );

		if ( message ) {
			if ( ! slot ) {
				slot = document.createElement( 'p' );
				slot.className = 'tioc-field-error';
				slot.setAttribute( 'role', 'alert' );
				input.parentNode.insertBefore( slot, input.nextSibling );
			}

			slot.textContent = message;
			input.setAttribute( 'aria-invalid', 'true' );
		} else {
			if ( slot ) {
				slot.parentNode.removeChild( slot );
			}

			input.removeAttribute( 'aria-invalid' );
		}

		// Keeps the browser's own validation in step, so a bad file also blocks
		// submission on its own.
		if ( input.setCustomValidity ) {
			input.setCustomValidity( message );
		}
	}

	/**
	 * Check one receipt input against the configured limits.
	 *
	 * @param {HTMLInputElement} input File input.
	 *
	 * @return {boolean} Whether the file is acceptable.
	 */
	function validateInput( input ) {
		var file = input.files && input.files[ 0 ];
		var extensions = config.extensions || [];
		var maxBytes = parseInt( config.maxUploadBytes, 10 ) || 0;
		var extension;

		if ( ! file ) {
			setError( input, '' );

			return true;
		}

		if ( maxBytes && file.size > maxBytes ) {
			setError( input, format( i18n.tooLarge, config.maxUploadLabel || '' ) );

			return false;
		}

		extension = extensionOf( file.name );

		if ( extensions.length && extensions.indexOf( extension ) === -1 ) {
			setError( input, format( i18n.badType, extensions.join( ', ' ) ) );

			return false;
		}

		setError( input, '' );

		return true;
	}

	/**
	 * Wire everything up.
	 *
	 * @return {void}
	 */
	function init() {
		var form = document.getElementById( 'tioc-checkout-form' );
		var toggle = document.getElementById( 'tioc-toggle-coupon' );
		var inputs = document.querySelectorAll( '.tioc-proof-input' );
		var i;

		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var box = document.querySelector( '.tioc-apply-coupon' );

				if ( ! box ) {
					return;
				}

				box.classList.remove( 'tutor-d-none' );
				toggle.parentNode.classList.add( 'tutor-d-none' );

				var field = box.querySelector( 'input[name="coupon_code"]' );

				if ( field ) {
					field.focus();
				}
			} );
		}

		for ( i = 0; i < inputs.length; i++ ) {
			inputs[ i ].addEventListener( 'change', function ( event ) {
				validateInput( event.target );
			} );
		}

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			var valid = true;
			var first = null;
			var index;

			for ( index = 0; index < inputs.length; index++ ) {
				if ( ! validateInput( inputs[ index ] ) ) {
					valid = false;

					if ( ! first ) {
						first = inputs[ index ];
					}
				}
			}

			if ( ! valid ) {
				event.preventDefault();

				if ( first ) {
					first.focus();
				}

				return;
			}

			if ( i18n.confirm && ! window.confirm( i18n.confirm ) ) {
				event.preventDefault();

				return;
			}

			// Stop a double submission creating two orders per instructor.
			var submit = document.getElementById( 'tioc-submit-order' );

			if ( submit ) {
				submit.disabled = true;
				submit.setAttribute( 'aria-busy', 'true' );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
