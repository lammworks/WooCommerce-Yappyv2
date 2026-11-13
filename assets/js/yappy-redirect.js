/**
 * Validates a checkout return URL before it is assigned to window.location.
 *
 * Paid orders are sent to the WooCommerce order-received URL. The server builds
 * that URL (`WC_Order::get_checkout_order_received_url()` and
 * `WC_Payment_Gateway::get_return_url()`); the browser does not read it from
 * the query string. It still arrives in a JSON response, and those WooCommerce
 * filters can rewrite it, so a `javascript:` value must never be assigned.
 *
 * Allowed destinations are http(s) URLs on this store (the page, admin-ajax,
 * or the checkout URL printed with the page) and the Yappy API and CDN
 * endpoints this plugin already calls. The assigned value is rebuilt from the
 * parsed URL with a literal scheme.
 *
 * @package WooCommerce_Yappy
 */
( function ( root ) {
	'use strict';

	/**
	 * Yappy hosts, with the path prefix that belongs to each one.
	 * Kept in step with WC_Yappy_API_Client.
	 */
	var YAPPY_ENDPOINTS = {
		'apipagosbg.bgeneral.cloud': '/payments/',
		'api-comecom-uat.yappycloud.com': '/payments/',
		'bt-cdn.yappy.cloud': '/v1/cdn/',
		'bt-cdn-uat.yappycloud.com': '/v1/cdn/'
	};

	/**
	 * Port the URL actually uses, filling in the scheme default.
	 *
	 * @param {URL} parsed Parsed URL.
	 * @return {string}
	 */
	function portOf( parsed ) {
		if ( parsed.port ) {
			return parsed.port;
		}

		return parsed.protocol === 'https:' ? '443' : '80';
	}

	/**
	 * Record a server-printed URL's host as a store the browser may return to.
	 *
	 * http and https are both recorded so WooCommerce's SSL upgrade of the
	 * thank-you URL still works when the payment page itself was opened on http.
	 *
	 * @param {Object} allowed Map of `hostname:port` keys.
	 * @param {string} href    Absolute or relative URL.
	 */
	function remember( allowed, href ) {
		if ( typeof href !== 'string' || href === '' ) {
			return;
		}

		var parsed;

		try {
			parsed = new URL( href, root.location.href );
		} catch ( error ) {
			return;
		}

		if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
			return;
		}

		if ( parsed.username || parsed.password ) {
			return;
		}

		var name = parsed.hostname.toLowerCase();

		if ( ! name ) {
			return;
		}

		allowed[ name + ':' + portOf( parsed ) ] = true;
		allowed[ name + ':80' ] = true;
		allowed[ name + ':443' ] = true;
	}

	/**
	 * Hosts printed by the server with this page, plus the page the customer is on.
	 *
	 * @return {Object} Map of `hostname:port` keys.
	 */
	function trustedKeys() {
		var allowed = {};

		if ( root.location && root.location.href ) {
			remember( allowed, root.location.href );
		}

		[ root.wcYappyParams, root.wcYappyInlineParams ].forEach( function ( params ) {
			if ( ! params ) {
				return;
			}

			// Printed with the page by wp_json_encode(). The status poll's returnUrl
			// is not a trust anchor: that is the value being checked.
			remember( allowed, params.ajaxUrl );
			remember( allowed, params.checkoutUrl );
		} );

		return allowed;
	}

	/**
	 * Rebuild a URL when it is safe to navigate to, otherwise return an empty string.
	 *
	 * @param {string} value Candidate return URL.
	 * @return {string} Absolute http(s) URL, or ''.
	 */
	function safeUrl( value ) {
		if ( typeof value !== 'string' || value === '' ) {
			return '';
		}

		// Reject characters browsers have historically ignored inside a scheme,
		// including `java\nscript:` and backslash-based protocol-relative URLs.
		if ( /[\\\u0000-\u001F\u007F]/.test( value ) ) {
			return '';
		}

		var parsed;

		try {
			parsed = new URL( value, root.location.href );
		} catch ( error ) {
			return '';
		}

		if ( parsed.username || parsed.password ) {
			return '';
		}

		var protocol = parsed.protocol;

		if ( protocol !== 'http:' && protocol !== 'https:' ) {
			return '';
		}

		var name = parsed.hostname.toLowerCase();
		var yappyPrefix = Object.prototype.hasOwnProperty.call( YAPPY_ENDPOINTS, name )
			? YAPPY_ENDPOINTS[ name ]
			: '';
		var onStore = !! trustedKeys()[ name + ':' + portOf( parsed ) ];

		if ( ! onStore ) {
			if ( protocol !== 'https:' || ! yappyPrefix || parsed.pathname.indexOf( yappyPrefix ) !== 0 ) {
				return '';
			}
		}

		// Literal scheme. The rest comes from the parsed URL, so a `javascript:`
		// input cannot survive into the string that is assigned.
		return ( protocol === 'https:' ? 'https://' : 'http://' ) +
			parsed.host + parsed.pathname + parsed.search + parsed.hash;
	}

	/**
	 * Navigate to the first safe candidate.
	 *
	 * @param {string|Array<string>} candidates Preferred URL, then fallbacks.
	 * @return {boolean} Whether a navigation was started.
	 */
	function assign( candidates ) {
		var list = Array.isArray( candidates ) ? candidates : [ candidates ];
		var safe = '';
		var i;

		for ( i = 0; i < list.length; i++ ) {
			safe = safeUrl( list[ i ] );
			if ( safe ) {
				break;
			}
		}

		if ( ! safe || ! root.location ) {
			return false;
		}

		root.location.href = safe;
		return true;
	}

	root.wcYappyRedirect = {
		safeUrl: safeUrl,
		assign: assign
	};
} )( typeof window !== 'undefined' ? window : globalThis );
