<?php
/**
 * Plugin Name:       Yappy Payment Button for WooCommerce
 * Plugin URI:        https://github.com/lammworks/woocommerce-yappyv2
 * Description:       Accept payments with Yappy (Banco General, Panamá) in WooCommerce using the new Botón de Pago Yappy integration.
 * Version:           1.2.7
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lammworks
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-yappy
 * Domain Path:       /languages
 * WC requires at least: 7.6
 * WC tested up to:   9.4
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_YAPPY_VERSION', '1.2.7' );
define( 'WC_YAPPY_PLUGIN_FILE', __FILE__ );
define( 'WC_YAPPY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_YAPPY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load the plugin once all other plugins (WooCommerce included) are available.
 */
function wc_yappy_bootstrap() {
	require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-plugin.php';
	WC_Yappy_Plugin::instance();
}
add_action( 'plugins_loaded', 'wc_yappy_bootstrap', 11 );

/**
 * Declare compatibility with WooCommerce features that require an opt-in.
 *
 * Runs on `before_woocommerce_init`, which is the only point where WooCommerce
 * accepts these declarations.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
);
