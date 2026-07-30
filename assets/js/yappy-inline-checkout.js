/**
 * Starts a Yappy payment inside the classic WooCommerce checkout.
 *
 * The official Yappy component is the primary checkout action. Its click
 * submits WooCommerce's checkout form, and the successful response contains
 * the short-lived credentials that `eventPayment()` requires. This keeps the
 * customer on checkout instead of navigating through WooCommerce's order-pay
 * receipt page.
 *
 * @package WooCommerce_Yappy
 */
( function ( $ ) {
	'use strict';

	var params = window.wcYappyInlineParams || {};
	var i18n = params.i18n || {};
	var componentPromise;
	var activeButton;
	var payment;
	var busy = false;
	var DEFINE_TIMEOUT = 15000;

	function getRoot() {
		return document.getElementById( 'wc-yappy-inline' );
	}

	function getContainer() {
		return document.getElementById( 'wc-yappy-inline-button' );
	}

	function getRequestMarker() {
		return document.getElementById( 'wc-yappy-inline-request' );
	}

	function isYappySelected() {
		return $( 'input[name="payment_method"]:checked' ).val() === 'yappy';
	}

	function setCheckoutButtonVisible( visible ) {
		$( '#place_order' ).toggle( visible );
	}

	function showError( message ) {
		var box = document.getElementById( 'wc-yappy-inline-error' );
		if ( box ) {
			box.textContent = message || i18n.genericError || 'Error';
			box.hidden = false;
		}
	}

	function clearError() {
		var box = document.getElementById( 'wc-yappy-inline-error' );
		if ( box ) {
			box.hidden = true;
			box.textContent = '';
		}
	}

	function setStatus( message ) {
		var box = document.getElementById( 'wc-yappy-inline-status' );
		if ( ! box ) {
			return;
		}

		box.hidden = ! message;
		box.textContent = message || '';
	}

	function showWaiting() {
		var waiting = document.getElementById( 'wc-yappy-inline-waiting' );
		var title = document.getElementById( 'wc-yappy-inline-waiting-title' );
		var message = document.getElementById( 'wc-yappy-inline-waiting-message' );
		var description = getRoot() && getRoot().querySelector( '.wc-yappy__description, .wc-yappy__hint' );

		if ( title ) {
			title.textContent = i18n.confirming || 'Confirming your payment with Yappy…';
		}

		if ( message ) {
			message.textContent = description ? description.textContent.trim() : '';
		}

		if ( waiting ) {
			waiting.hidden = false;
		}
	}

	function hideWaiting() {
		var waiting = document.getElementById( 'wc-yappy-inline-waiting' );
		if ( waiting ) {
			waiting.hidden = true;
		}
	}

	function normalizePhone( value ) {
		var digits = String( value || '' ).replace( /\D/g, '' );

		if ( digits.length > 8 && digits.indexOf( '507' ) === 0 ) {
			digits = digits.slice( 3 );
		}

		return /^[67]\d{7}$/.test( digits ) ? digits : '';
	}

	function setLoading( loading ) {
		if ( activeButton && typeof activeButton.isButtonLoading !== 'undefined' ) {
			activeButton.isButtonLoading = loading;
		}
	}

	function resetCheckoutAttempt() {
		busy = false;
		setLoading( false );
		hideWaiting();
		var marker = getRequestMarker();
		if ( marker ) {
			marker.value = '0';
		}
	}

	function loadComponent() {
		if ( componentPromise ) {
			return componentPromise;
		}

		componentPromise = new Promise( function ( resolve, reject ) {
			if ( document.querySelector( 'script[data-wc-yappy-cdn]' ) ) {
				resolve();
				return;
			}

			var script = document.createElement( 'script' );
			script.type = 'module';
			script.src = params.cdnUrl;
			script.setAttribute( 'data-wc-yappy-cdn', '1' );
			script.onload = resolve;
			script.onerror = reject;
			document.head.appendChild( script );
		} ).then( function () {
			if ( ! window.customElements ) {
				return Promise.reject( new Error( 'unsupported' ) );
			}

			return Promise.race( [
				window.customElements.whenDefined( 'btn-yappy' ),
				new Promise( function ( _resolve, reject ) {
					window.setTimeout( function () {
						reject( new Error( 'timeout' ) );
					}, DEFINE_TIMEOUT );
				} ),
			] );
		} );

		return componentPromise;
	}

	function postStatus() {
		var body = new URLSearchParams();
		body.append( 'action', 'wc_yappy_order_status' );
		body.append( 'order_id', payment.orderId );
		body.append( 'order_key', payment.orderKey );
		body.append( 'nonce', payment.nonce );

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
			.then( function ( response ) {
				if ( ! response.success ) {
					throw new Error( 'status' );
				}
				return response.data;
			} );
	}

	function pollUntilPaid( attemptsLeft ) {
		setStatus( i18n.confirming );
		showWaiting();

		postStatus()
			.then( function ( result ) {
				if ( result.paid ) {
					window.location.href = result.returnUrl || payment.returnUrl;
					return;
				}

				if ( attemptsLeft <= 0 ) {
					window.location.href = payment.returnUrl;
					return;
				}

				window.setTimeout( function () {
					pollUntilPaid( attemptsLeft - 1 );
				}, 2000 );
			} )
			.catch( function () {
				window.location.href = payment.returnUrl;
			} );
	}

	function submitCheckoutFromYappy() {
		var phoneField = document.getElementById( 'wc-yappy-phone' );
		if ( params.askPhone && phoneField && phoneField.value.trim() !== '' && normalizePhone( phoneField.value ) === '' ) {
			showError( i18n.invalidPhone );
			return;
		}

		var marker = getRequestMarker();
		if ( ! marker || busy ) {
			return;
		}

		clearError();
		busy = true;
		marker.value = '1';
		setLoading( true );
		$( 'form.checkout' ).trigger( 'submit' );
	}

	function mountButton() {
		var root = getRoot();
		var container = getContainer();
		if ( ! root || ! container || container.querySelector( 'btn-yappy' ) ) {
			return;
		}

		loadComponent()
			.then( function () {
				if ( ! getContainer() || getContainer() !== container ) {
					return;
				}

				var button = document.createElement( 'btn-yappy' );
				button.setAttribute( 'theme', params.theme || 'blue' );
				button.setAttribute( 'rounded', params.rounded || 'true' );

				button.addEventListener( 'isYappyOnline', function ( event ) {
					var online = ! event || typeof event.detail === 'undefined' || !! event.detail;
					if ( online && isYappySelected() ) {
						setCheckoutButtonVisible( false );
					} else if ( ! online ) {
						setCheckoutButtonVisible( true );
					}
				} );

				button.addEventListener( 'eventClick', submitCheckoutFromYappy );

				button.addEventListener( 'eventSuccess', function () {
					busy = false;
					setLoading( false );
					clearError();
					pollUntilPaid( 10 );
				} );

				button.addEventListener( 'eventError', function ( event ) {
					resetCheckoutAttempt();
					setStatus( '' );
					showError( event && event.detail && event.detail.message ? event.detail.message : i18n.genericError );
				} );

				activeButton = button;
				container.appendChild( button );
				if ( isYappySelected() ) {
					setCheckoutButtonVisible( false );
				}
			} )
			.catch( function () {
				showError( i18n.loadFailed );
				setCheckoutButtonVisible( true );
			} );
	}

	$( document.body ).on( 'updated_checkout payment_method_selected', function () {
		if ( isYappySelected() ) {
			mountButton();
			if ( activeButton ) {
				setCheckoutButtonVisible( false );
			}
		} else {
			setCheckoutButtonVisible( true );
		}
	} );

	$( document.body ).on( 'checkout_error', resetCheckoutAttempt );

	$( document.body ).on( 'checkout_place_order_success', function ( _event, result ) {
		if ( ! busy || ! result || ! result.yappy || ! activeButton ) {
			return;
		}

		payment = result.yappy;
		// WooCommerce checks this value after emitting the event. Clearing it
		// keeps this checkout view in place while Yappy takes over.
		result.redirect = '';
		activeButton.eventPayment( {
			transactionId: payment.transactionId,
			token: payment.token,
			documentName: payment.documentName,
		} );
		showWaiting();
	} );

	$( function () {
		if ( isYappySelected() ) {
			mountButton();
		}
	} );
} )( jQuery );
