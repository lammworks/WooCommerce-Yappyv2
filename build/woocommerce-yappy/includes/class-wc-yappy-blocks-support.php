<?php
/**
 * Registers Yappy with the WooCommerce Checkout block.
 *
 * The block-based checkout does not read the classic gateway definition, so the
 * method has to be declared again for the React front end. Because Yappy is paid
 * on a page of its own after the order is placed, the block integration only has
 * to render the method's label and description — the redirect returned by
 * `process_payment()` does the rest.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks integration for the Yappy gateway.
 */
final class WC_Yappy_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method slug, matching the classic gateway ID.
	 *
	 * @var string
	 */
	protected $name = WC_Yappy_Gateway::GATEWAY_ID;

	/**
	 * Load the gateway settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Whether the method should appear in the block checkout.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = wc_yappy_get_gateway();

		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Register the front-end script and return its handle.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'wc-yappy-blocks',
			WC_YAPPY_PLUGIN_URL . 'assets/js/yappy-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			WC_YAPPY_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-yappy-blocks', 'woocommerce-yappy', WC_YAPPY_PLUGIN_DIR . 'languages' );
		}

		return array( 'wc-yappy-blocks' );
	}

	/**
	 * Data handed to the front-end script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = wc_yappy_get_gateway();

		return array(
			'title'       => $gateway ? $gateway->get_title() : __( 'Yappy', 'woocommerce-yappy' ),
			'description' => $gateway ? $gateway->get_description() : '',
			'icon'        => WC_YAPPY_PLUGIN_URL . 'assets/images/yappy.svg',
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products' ),
		);
	}
}
