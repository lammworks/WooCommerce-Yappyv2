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
	var PAYMENT_TIMEOUT_SECONDS = 300;
	var STATUS_POLL_INTERVAL = 2000;
	var countdownTimer;
	var statusPollTimer;
	var expiresAt = 0;
	var waitingForPayment = false;
	var retryReady = false;

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
		var time = document.getElementById( 'wc-yappy-inline-waiting-time' );
		var description = getRoot() && getRoot().querySelector( '.wc-yappy__description, .wc-yappy__hint' );

		if ( title ) {
			title.textContent = i18n.confirming || 'Confirming your payment with Yappy…';
		}

		if ( message ) {
			message.textContent = description ? description.textContent.trim() : '';
		}

		if ( time ) {
			time.hidden = false;
		}

		if ( waiting ) {
			waiting.classList.remove( 'is-preparing' );
			waiting.hidden = false;
		}
	}

	function showPreparing() {
		var waiting = document.getElementById( 'wc-yappy-inline-waiting' );
		var title = document.getElementById( 'wc-yappy-inline-waiting-title' );
		var message = document.getElementById( 'wc-yappy-inline-waiting-message' );
		var time = document.getElementById( 'wc-yappy-inline-waiting-time' );
		var description = getRoot() && getRoot().querySelector( '.wc-yappy__description, .wc-yappy__hint' );

		if ( title ) {
			title.textContent = i18n.confirming || 'Confirming your payment with Yappy…';
		}

		if ( message ) {
			message.textContent = description ? description.textContent.trim() : '';
		}

		if ( time ) {
			time.hidden = true;
		}

		if ( waiting ) {
			waiting.classList.add( 'is-preparing' );
			waiting.hidden = false;
		}
	}

	function hideWaiting() {
		var waiting = document.getElementById( 'wc-yappy-inline-waiting' );
		if ( waiting ) {
			waiting.hidden = true;
		}
	}

	function updateCountdown() {
		var time = document.getElementById( 'wc-yappy-inline-waiting-time' );
		var seconds = Math.max( 0, Math.ceil( ( expiresAt - Date.now() ) / 1000 ) );
		var minutes = Math.floor( seconds / 60 );
		var remaining = String( seconds % 60 ).padStart( 2, '0' );

		if ( time ) {
			time.textContent = String( minutes ) + ':' + remaining;
			time.dateTime = 'PT' + seconds + 'S';
		}

		if ( seconds === 0 && countdownTimer ) {
			window.clearInterval( countdownTimer );
			countdownTimer = null;
			var title = document.getElementById( 'wc-yappy-inline-waiting-title' );
			if ( title ) {
				title.textContent = i18n.expired || i18n.confirming;
			}
		}
	}

	function stopPaymentTimers() {
		if ( countdownTimer ) {
			window.clearInterval( countdownTimer );
			countdownTimer = null;
		}

		if ( statusPollTimer ) {
			window.clearTimeout( statusPollTimer );
			statusPollTimer = null;
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
		if ( activeButton ) {
			activeButton.isButtonLoading = loading;
		}
	}

	// Release WooCommerce's blockUI overlay from the checkout form. WooCommerce
	// leaves the form blocked after a successful submit because it normally
	// navigates away; the inline flow answers with a same-document fragment, so
	// nothing unblocks it unless we do. From the moment this plugin's own waiting
	// card takes over as the modal, the underlying form must stay interactive so
	// no terminal, error or expiry path can leave the customer stuck behind it.
	function unblockCheckoutForm() {
		var form = $( 'form.checkout' );
		form.removeClass( 'processing' );
		if ( typeof $.fn.unblock === 'function' ) {
			form.unblock();
		}
	}

	function resetCheckoutAttempt() {
		busy = false;
		setLoading( false );
		waitingForPayment = false;
		stopPaymentTimers();
		hideWaiting();
		unblockCheckoutForm();
		var marker = getRequestMarker();
		if ( marker ) {
			marker.value = '0';
		}
		// WooCommerce rejected or errored the submit (for example, terms of service
		// left unchecked). The Yappy button that triggered it is now stuck disabled,
		// so replace it with a fresh one the customer can press again.
		remountYappyButton();
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

	function postPayment( action, extra ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'order_id', payment.orderId );
		body.append( 'order_key', payment.orderKey );
		body.append( 'nonce', payment.nonce );

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
			.then( function ( response ) {
				if ( ! response.success ) {
					var error = new Error( response.data && response.data.message ? response.data.message : 'request' );
					error.data = response.data || {};
					throw error;
				}
				return response.data;
			} );
	}

	function terminalMessage( status ) {
		if ( status === 'C' ) {
			return i18n.cancelled || i18n.genericError;
		}
		if ( status === 'R' ) {
			return i18n.rejected || i18n.genericError;
		}
		return i18n.expired || i18n.genericError;
	}

	// End the current attempt and return the checkout to a state the customer can
	// act on: stop the timers, take down the waiting card, release WooCommerce's
	// overlay, and mount a fresh Yappy button (the old one cannot be re-enabled
	// once pressed). `message`, when given, is shown as the reason it ended.
	function concludeAttempt( message ) {
		waitingForPayment = false;
		busy = false;
		stopPaymentTimers();
		setStatus( '' );
		hideWaiting();
		unblockCheckoutForm();
		remountYappyButton();
		if ( message ) {
			showError( message );
		}
	}

	function finishPaymentAttempt( status ) {
		concludeAttempt( terminalMessage( status ) );
	}

	// The official <btn-yappy> component disables its own button once it has been
	// clicked, and it offers no reliable way to re-enable it. Whenever an attempt
	// ends without navigating away — the customer dismisses the waiting card, or
	// WooCommerce rejects the submit (unchecked terms, a validation error) — the
	// old button is therefore stuck. Replace it with a fresh, enabled one instead
	// of trying to revive it. This keeps the customer's form input (the phone
	// number they may be correcting) intact, unlike a full page reload.
	function remountYappyButton() {
		var container = getContainer();
		if ( container ) {
			container.innerHTML = '';
		}
		activeButton = null;
		payment = undefined;
		retryReady = false;
		mountButton();
	}

	// The customer closed the waiting card. There is no browser-side cancel in the
	// Yappy API, but the intent is clear — abandon this attempt, most often to
	// correct a mistyped phone number. End the wait and mount a fresh button so a
	// new request can be started. Any payment still completed in the Yappy app is
	// recorded server-side through the IPN regardless.
	function dismissPaymentAttempt() {
		clearError();
		concludeAttempt();
	}

	function pollPaymentStatus() {
		if ( ! waitingForPayment || ! payment ) {
			return;
		}

		postPayment( 'wc_yappy_order_status' )
			.then( function ( result ) {
				if ( result.paid ) {
					stopPaymentTimers();
					window.location.href = result.returnUrl || payment.returnUrl;
					return;
				}

				if ( result.yappyStatus === 'C' || result.yappyStatus === 'R' || result.yappyStatus === 'X' ) {
					finishPaymentAttempt( result.yappyStatus );
					return;
				}

				statusPollTimer = window.setTimeout( pollPaymentStatus, STATUS_POLL_INTERVAL );
			} )
			.catch( function () {
				// Keep the customer on checkout. A short temporary network error must
				// never be mistaken for a payment result.
				statusPollTimer = window.setTimeout( pollPaymentStatus, STATUS_POLL_INTERVAL );
			} );
	}

	function beginPaymentWait() {
		waitingForPayment = true;
		retryReady = false;
		expiresAt = Date.now() + ( PAYMENT_TIMEOUT_SECONDS * 1000 );
		stopPaymentTimers();
		// This plugin's waiting card is now the modal, so hand the form back to
		// the customer underneath it. WooCommerce is done with its submit and will
		// not touch the form again until the next one.
		unblockCheckoutForm();
		showWaiting();
		updateCountdown();
		countdownTimer = window.setInterval( updateCountdown, 250 );
		pollPaymentStatus();
	}

	function retryPayment() {
		// Guard against a second press while the fresh request is still in flight.
		if ( busy ) {
			return;
		}

		var phoneField = document.getElementById( 'wc-yappy-phone' );
		var phone = phoneField ? phoneField.value.trim() : '';

		if ( params.askPhone && phone !== '' && normalizePhone( phone ) === '' ) {
			showError( i18n.invalidPhone );
			return;
		}

		busy = true;
		clearError();
		setLoading( true );

		postPayment( 'wc_yappy_create_order', { phone: phone } )
			.then( function ( result ) {
				payment.transactionId = result.transactionId;
				payment.token = result.token;
				payment.documentName = result.documentName;
				activeButton.eventPayment( result );
				beginPaymentWait();
			} )
			.catch( function ( error ) {
				busy = false;
				setLoading( false );
				showError( error.message );
			} );
	}

	function submitCheckoutFromYappy() {
		if ( retryReady ) {
			retryPayment();
			return;
		}

		if ( payment ) {
			return;
		}

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
		// Show feedback before WordPress starts its checkout request. This keeps
		// slower hosts from leaving the customer with only a spinning button.
		showPreparing();
		$( 'form.checkout' ).trigger( 'submit' );
	}

	function handleCheckoutSuccess( _event, result ) {
		if ( ! busy || ! result || ! result.yappy || ! activeButton ) {
			return;
		}

		payment = result.yappy;
		// WooCommerce's checkout.js fires this event with triggerHandler() on
		// form.checkout, so it does not bubble to document.body. Start the
		// customer-facing state before handing credentials to the Yappy component:
		// this keeps the checkout responsive even if the component takes a moment
		// to switch from its own spinner to the mobile-app request.
		beginPaymentWait();
		activeButton.eventPayment( {
			transactionId: payment.transactionId,
			token: payment.token,
			documentName: payment.documentName,
		} );
	}

	function bindCheckoutSuccess() {
		$( 'form.checkout' )
			.off( 'checkout_place_order_success.wcYappyInline' )
			.on( 'checkout_place_order_success.wcYappyInline', handleCheckoutSuccess );
	}

	function mountButton() {
		var root = getRoot();
		var container = getContainer();
		if ( ! root || ! container || container.querySelector( 'btn-yappy' ) ) {
			return;
		}

		loadComponent()
			.then( function () {
				// The guard at the top of mountButton ran before the component
				// finished loading; re-check here so two overlapping mounts (the
				// handler fires on both updated_checkout and payment_method_selected)
				// cannot append a second button with its own live listeners.
				if ( ! getContainer() || getContainer() !== container || container.querySelector( 'btn-yappy' ) ) {
					return;
				}

				var button = document.createElement( 'btn-yappy' );
				button.setAttribute( 'theme', params.theme || 'blue' );
				button.setAttribute( 'rounded', params.rounded || 'true' );
				// The official component's built-in dialog renders an opaque backdrop and
				// an independent countdown. Keep it off so this plugin owns one accessible
				// waiting card, timer and close control across all WordPress themes.
				button.triggerModal = false;

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
					// Only react while an attempt is actually live. Once the customer
					// has dismissed the card or a terminal result has arrived, a late
					// success signal from the component must not reopen the dialog or
					// restart polling on its own.
					if ( ! busy && ! waitingForPayment ) {
						return;
					}
					setStatus( i18n.confirming );
					if ( ! waitingForPayment ) {
						beginPaymentWait();
					}
				} );

				button.addEventListener( 'eventError', function ( event ) {
					var detail = event && event.detail && event.detail.message ? event.detail.message : '';

					if ( waitingForPayment ) {
						// The Yappy app reported the request ended — most often the
						// customer cancelled it there. The component learns this before
						// the store's IPN does, so end the attempt now rather than
						// leaving the waiting card counting down over a live button.
						concludeAttempt( detail || i18n.cancelled || i18n.genericError );
						return;
					}

					resetCheckoutAttempt();
					setStatus( '' );
					showError( detail || i18n.genericError );
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
		bindCheckoutSuccess();

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

	$( document.body ).on( 'click', '#wc-yappy-inline-waiting-close', dismissPaymentAttempt );

	$( function () {
		bindCheckoutSuccess();

		if ( isYappySelected() ) {
			mountButton();
		}
	} );
} )( jQuery );
