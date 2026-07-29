<?php
/**
 * Test bootstrap.
 *
 * The classes exercised here are deliberately free of WordPress and WooCommerce
 * dependencies, so the suite runs without a WordPress installation. Only the two
 * globals every plugin file guards on are stubbed.
 *
 * @package WooCommerce_Yappy
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain, ignored.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		unset( $domain );
		return $text;
	}
}

require_once __DIR__ . '/../includes/class-wc-yappy-status.php';
require_once __DIR__ . '/../includes/class-wc-yappy-reference.php';
require_once __DIR__ . '/../includes/class-wc-yappy-amounts.php';
require_once __DIR__ . '/../includes/class-wc-yappy-signature.php';
require_once __DIR__ . '/../includes/class-wc-yappy-phone.php';
