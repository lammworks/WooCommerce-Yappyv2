<?php
/**
 * Uninstall routine.
 *
 * Settings are only removed when the shop manager explicitly asked for it, so
 * deactivating and reinstalling the plugin never costs them their credentials.
 * Order data is always kept: it is part of the store's payment records.
 *
 * @package WooCommerce_Yappy
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wc_yappy_settings = get_option( 'woocommerce_yappy_settings' );

if ( is_array( $wc_yappy_settings ) && isset( $wc_yappy_settings['remove_data'] ) && 'yes' === $wc_yappy_settings['remove_data'] ) {
	delete_option( 'woocommerce_yappy_settings' );
}
