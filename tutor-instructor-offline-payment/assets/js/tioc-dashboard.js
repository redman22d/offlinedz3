/**
 * Tutor Instructor Offline Payment — dashboard.
 *
 * The three dashboard pages are plain HTML forms. This file upgrades them to
 * AJAX so an instructor can confirm a payment without losing their place in a
 * long list, and toggles the inline edit panels.
 *
 * Every form still carries all of its data in named inputs, so if this file
 * fails to load the pages remain readable — only the interactivity is lost.
 */
( function () {
	'use strict';

	var config = window.TIOC_Dashboard || {};
	var i18n = config.i18n || {};

	/**
	 * Confirmation text for a given `data-tioc-confirm` value.
	 *
	 * @param {string} kind Confirmation kind.
	 *
	 * @return {string} Message, or an empty string when none applies.
	 */
	function confirmMessage( kind ) {
		if ( 'delete' === kind ) {
			return i18n.confirmDelete || '';
		}

		if ( 'approve' === kind ) {
			return i18n.confirmApprove || '';
		}

		return '';
	}

	/**
	 * The notice area closest to a form.
	 *
	 * @param {HTMLFormElement} form Form.
	 *
	 * @return {HTMLElement|null} Notice slot.
	 */
	function noticeSlot( form ) {
		var page = form.closest ? form.closest( '.tioc-dash' ) : null;

		return ( page || document ).querySelector( '.tioc-notice-slot' );
	}

	/**
	 * Show a message above the page content.
	 *
	 * @param {HTMLFormElement} form    Form the message belongs to.
	 * @param {string}          message Message text.
	 * @param {string}          type    success|error.
	 *
	 * @return {void}
	 */
	function notify( form, message, type ) {
		var slot = noticeSlot( form );

		if ( ! slot ) {
			if ( 'error' === type ) {
				window.alert( message );
			}

			return;
		}

		slot.innerHTML = '';

		var alert = document.createElement( 'div' );
		alert.className = 'tioc-alert tioc-alert-' + ( 'error' === type ? 'error' : 'success' );
		alert.textContent = message;
		slot.appendChild( alert );

		if ( slot.scrollIntoView ) {
			slot.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		}
	}

	/**
	 * Disable a form's buttons while it is in flight.
	 *
	 * @param {HTMLFormElement} form    Form.
	 * @param {boolean}         busy    Whether the request is running.
	 *
	 * @return {void}
	 */
	function setBusy( form, busy ) {
		var buttons = form.querySelectorAll( 'button, input[type="submit"]' );
		var i;

		for ( i = 0; i < buttons.length; i++ ) {
			buttons[ i ].disabled = busy;
		}

		if ( busy ) {
			form.setAttribute( 'aria-busy', 'true' );
		} else {
			form.removeAttribute( 'aria-busy' );
		}
	}

	/**
	 * Send one form to admin-ajax.
	 *
	 * @param {HTMLFormElement} form Form with a data-tioc-action attribute.
	 *
	 * @return {void}
	 */
	function submitForm( form ) {
		var action = form.getAttribute( 'data-tioc-action' );
		var data = new FormData( form );

		data.append( 'action', action );
		data.append( 'nonce', config.nonce || '' );

		setBusy( form, true );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: { message: i18n.error } };
			} );
		} ).then( function ( payload ) {
			var body = payload && payload.data ? payload.data : {};
			var message = body.message || ( payload && payload.success ? '' : i18n.error );

			if ( ! payload || ! payload.success ) {
				setBusy( form, false );
				notify( form, message || i18n.error, 'error' );

				return;
			}

			if ( body.reload ) {
				// The list, the counters and the nav badge all change together, so a
				// reload is both simpler and more honest than patching the DOM.
				window.location.reload();

				return;
			}

			setBusy( form, false );
			notify( form, message, 'success' );
		} ).catch( function () {
			setBusy( form, false );
			notify( form, i18n.error, 'error' );
		} );
	}

	/**
	 * Handle a submission, including any confirmation step.
	 *
	 * @param {Event} event Submit event.
	 *
	 * @return {void}
	 */
	function onSubmit( event ) {
		var form = event.target;

		if ( ! form || ! form.getAttribute || ! form.getAttribute( 'data-tioc-action' ) ) {
			return;
		}

		event.preventDefault();

		if ( form.getAttribute( 'aria-busy' ) ) {
			return;
		}

		var kind = form.getAttribute( 'data-tioc-confirm' );

		if ( 'reject' === kind ) {
			var reason = window.prompt( i18n.rejectPrompt || '', '' );

			if ( null === reason ) {
				return;
			}

			var field = form.querySelector( '[name="reason"]' );

			if ( field ) {
				field.value = reason;
			}
		} else if ( kind ) {
			var message = confirmMessage( kind );

			if ( message && ! window.confirm( message ) ) {
				return;
			}
		}

		submitForm( form );
	}

	/**
	 * Show or hide an inline panel.
	 *
	 * @param {Event} event Click event.
	 *
	 * @return {void}
	 */
	function onClick( event ) {
		var trigger = event.target.closest ? event.target.closest( '[data-tioc-toggle]' ) : null;

		if ( ! trigger ) {
			return;
		}

		var panel = document.getElementById( trigger.getAttribute( 'data-tioc-toggle' ) );

		if ( ! panel ) {
			return;
		}

		event.preventDefault();

		var hidden = panel.hasAttribute( 'hidden' );

		if ( hidden ) {
			panel.removeAttribute( 'hidden' );
		} else {
			panel.setAttribute( 'hidden', 'hidden' );
		}

		trigger.setAttribute( 'aria-expanded', hidden ? 'true' : 'false' );
	}

	/**
	 * Bind the two delegated listeners.
	 *
	 * @return {void}
	 */
	function init() {
		if ( ! window.fetch || ! window.FormData || ! config.ajaxUrl ) {
			return;
		}

		document.addEventListener( 'submit', onSubmit );
		document.addEventListener( 'click', onClick );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
