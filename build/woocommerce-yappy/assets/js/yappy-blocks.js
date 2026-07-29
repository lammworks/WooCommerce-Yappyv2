/**
 * Registers Yappy with the WooCommerce Checkout block.
 *
 * Yappy is paid on a dedicated page after the order is placed, so the block only
 * needs to render the method's label and description. The redirect returned by
 * `process_payment()` takes the customer to the Yappy button.
 *
 * @package WooCommerce_Yappy
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp || ! window.wp.element ) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings ? window.wc.wcSettings.getSetting : null;
	var createElement = window.wp.element.createElement;
	var decodeEntities = window.wp.htmlEntities
		? window.wp.htmlEntities.decodeEntities
		: function ( value ) {
			return value;
		};

	var settings = getSetting ? getSetting( 'yappy_data', {} ) : {};
	var title = decodeEntities( settings.title || 'Yappy' );
	var description = decodeEntities( settings.description || '' );

	/**
	 * The label shown next to the radio button, with the Yappy mark when available.
	 *
	 * @return {Object} React element.
	 */
	function Label() {
		var children = [ createElement( 'span', { key: 'title' }, title ) ];

		if ( settings.icon ) {
			children.push(
				createElement( 'img', {
					key: 'icon',
					src: settings.icon,
					alt: title,
					className: 'wc-yappy-blocks__icon',
					style: { marginLeft: 'auto', maxHeight: '24px' },
				} )
			);
		}

		return createElement(
			'span',
			{ className: 'wc-yappy-blocks__label', style: { display: 'flex', width: '100%', alignItems: 'center' } },
			children
		);
	}

	/**
	 * The content shown once the method is selected.
	 *
	 * @return {Object|null} React element.
	 */
	function Content() {
		return description ? createElement( 'p', null, description ) : null;
	}

	registerPaymentMethod( {
		name: 'yappy',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
