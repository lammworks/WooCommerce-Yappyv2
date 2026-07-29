/**
 * Drives the Yappy payment page.
 *
 * Loads the official <btn-yappy> web component from the Yappy CDN, asks this
 * plugin's backend to create the Yappy order when the customer presses it, and
 * hands the resulting credentials back to the component. The component then
 * opens the Yappy app or renders a QR code.
 *
 * The browser never decides whether the order was paid: after the component
 * reports success this script polls the store, which only answers "paid" once
 * the server-to-server IPN has arrived.
 *
 * @package WooCommerce_Yappy
 */
( function () {
	'use strict';

	var params = window.wcYappyParams || {};
	var i18n = params.i18n || {};

	var root = document.getElementById( 'wc-yappy' );
	var container = document.getElementById( 'wc-yappy-button' );
	var errorBox = document.getElementById( 'wc-yappy-error' );
	var statusBox = document.getElementById( 'wc-yappy-status' );
	var unavailableBox = document.getElementById( 'wc-yappy-unavailable' );
	var phoneField = document.getElementById( 'wc-yappy-phone' );

	if ( ! root || ! container ) {
		return;
	}

	/**
	 * How long to wait for the CDN component to define itself, in milliseconds.
	 */
	var DEFINE_TIMEOUT = 15000;

	var busy = false;

	/**
	 * Show an error message to the customer.
	 *
	 * @param {string} message Message to display.
	 */
	function showError( message ) {
		if ( ! errorBox ) {
			return;
		}
		errorBox.textContent = message || i18n.genericError || 'Error';
		errorBox.hidden = false;
	}

	/**
	 * Hide any previously shown error.
	 */
	function clearError() {
		if ( errorBox ) {
			errorBox.hidden = true;
			errorBox.textContent = '';
		}
	}

	/**
	 * Show or hide the transient status line.
	 *
	 * @param {string} message Message to display; empty hides the line.
	 */
	function setStatus( message ) {
		if ( ! statusBox ) {
			return;
		}
		if ( message ) {
			statusBox.textContent = message;
			statusBox.hidden = false;
		} else {
			statusBox.hidden = true;
			statusBox.textContent = '';
		}
	}

	/**
	 * Normalise a phone number the way the server does.
	 *
	 * @param {string} value Raw input.
	 * @return {string} Eight digit national number, or an empty string.
	 */
	function normalizePhone( value ) {
		var digits = String( value || '' ).replace( /\D/g, '' );

		if ( digits.length > 8 && digits.indexOf( '507' ) === 0 ) {
			digits = digits.slice( 3 );
		}

		return /^[67]\d{7}$/.test( digits ) ? digits : '';
	}

	/**
	 * POST to admin-ajax.
	 *
	 * @param {string} action Action name.
	 * @param {Object} extra  Additional fields.
	 * @return {Promise<Object>} Resolves with the `data` payload of a successful response.
	 */
	function post( action, extra ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'order_id', params.orderId );
		body.append( 'order_key', params.orderKey );
		body.append( 'nonce', params.nonce );

		Object.keys( extra || {} ).forEach( function ( key ) {
			body.append( key, extra[ key ] );
		} );

		return fetch( params.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return {};
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: i18n.genericError;
					var error = new Error( message );
					error.data = payload && payload.data ? payload.data : {};
					throw error;
				}

				return payload.data;
			} );
	}

	/**
	 * Load the Yappy web component from the CDN, once.
	 *
	 * @return {Promise<void>}
	 */
	function loadComponent() {
		return new Promise( function ( resolve, reject ) {
			if ( document.querySelector( 'script[data-wc-yappy-cdn]' ) ) {
				resolve();
				return;
			}

			var script = document.createElement( 'script' );
			script.type = 'module';
			script.src = params.cdnUrl;
			script.setAttribute( 'data-wc-yappy-cdn', '1' );
			script.onload = function () {
				resolve();
			};
			script.onerror = function () {
				reject( new Error( 'cdn' ) );
			};
			document.head.appendChild( script );
		} ).then( function () {
			if ( ! window.customElements ) {
				return Promise.reject( new Error( 'unsupported' ) );
			}

			// `whenDefined` never rejects on its own, so race it against a timeout
			// to avoid leaving the customer on a page that shows nothing.
			return Promise.race( [
				window.customElements.whenDefined( 'btn-yappy' ),
				new Promise( function ( _resolve, reject ) {
					window.setTimeout( function () {
						reject( new Error( 'timeout' ) );
					}, DEFINE_TIMEOUT );
				} ),
			] );
		} );
	}

	/**
	 * Ask the store whether the IPN has confirmed the payment yet.
	 *
	 * @param {number} attemptsLeft Remaining polls.
	 */
	function pollUntilPaid( attemptsLeft ) {
		setStatus( i18n.confirming );

		post( 'wc_yappy_order_status', {} )
			.then( function ( data ) {
				if ( data.paid ) {
					window.location.href = data.returnUrl || params.returnUrl;
					return;
				}

				if ( attemptsLeft <= 0 ) {
					// The order page explains that confirmation is still pending.
					window.location.href = params.returnUrl;
					return;
				}

				window.setTimeout( function () {
					pollUntilPaid( attemptsLeft - 1 );
				}, params.pollInterval || 2000 );
			} )
			.catch( function () {
				window.location.href = params.returnUrl;
			} );
	}

	/**
	 * Wire the component up and put it on the page.
	 */
	function render() {
		var button = document.createElement( 'btn-yappy' );
		button.setAttribute( 'theme', params.theme || 'blue' );
		button.setAttribute( 'rounded', params.rounded || 'true' );

		/**
		 * Toggle the button's own loading state, when the component exposes it.
		 *
		 * @param {boolean} loading Whether the button should look busy.
		 */
		function setLoading( loading ) {
			if ( typeof button.isButtonLoading !== 'undefined' ) {
				button.isButtonLoading = loading;
			}
		}

		button.addEventListener( 'isYappyOnline', function ( event ) {
			var online = event && typeof event.detail !== 'undefined' ? !! event.detail : true;

			container.hidden = ! online;

			if ( unavailableBox ) {
				unavailableBox.hidden = online;
			}
		} );

		button.addEventListener( 'eventClick', function () {
			if ( busy ) {
				return;
			}

			clearError();

			var phone = '';

			if ( params.askPhone && phoneField ) {
				var raw = phoneField.value.trim();

				if ( raw !== '' ) {
					phone = normalizePhone( raw );

					if ( phone === '' ) {
						showError( i18n.invalidPhone );
						return;
					}
				}
			}

			busy = true;
			setLoading( true );

			post( 'wc_yappy_create_order', { phone: phone } )
				.then( function ( data ) {
					button.eventPayment( {
						transactionId: data.transactionId,
						token: data.token,
						documentName: data.documentName,
					} );
				} )
				.catch( function ( error ) {
					busy = false;
					setLoading( false );

					// The order was already paid while the page was open.
					if ( error.data && error.data.paid ) {
						window.location.href = params.returnUrl;
						return;
					}

					showError( error.message );
				} );
		} );

		button.addEventListener( 'eventSuccess', function () {
			busy = false;
			setLoading( false );
			clearError();
			pollUntilPaid( params.pollAttempts || 10 );
		} );

		button.addEventListener( 'eventError', function ( event ) {
			busy = false;
			setLoading( false );
			setStatus( '' );

			var detail = event ? event.detail : null;
			var message = i18n.genericError;

			if ( typeof detail === 'string' && detail ) {
				message = detail;
			} else if ( detail && detail.message ) {
				message = detail.message;
			}

			showError( message );
		} );

		container.appendChild( button );
	}

	loadComponent().then( render ).catch( function () {
		showError( i18n.loadFailed );
	} );
} )();
