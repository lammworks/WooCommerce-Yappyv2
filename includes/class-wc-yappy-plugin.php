<?php
/**
 * Plugin bootstrap.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
class WC_Yappy_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WC_Yappy_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the plugin instance.
	 *
	 * @return WC_Yappy_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( ! $this->woocommerce_is_active() ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			return;
		}

		$this->includes();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WC_YAPPY_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
		// Hooked directly rather than from inside `woocommerce_blocks_loaded`,
		// which may already have fired by the time this plugin loads.
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks_support' ) );

		WC_Yappy_IPN_Handler::init();
		WC_Yappy_Ajax::init();
	}

	/**
	 * Whether WooCommerce is present and loaded.
	 *
	 * @return bool
	 */
	protected function woocommerce_is_active() {
		return class_exists( 'WooCommerce' ) && class_exists( 'WC_Payment_Gateway' );
	}

	/**
	 * Load the plugin's class files.
	 *
	 * @return void
	 */
	protected function includes() {
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-status.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-logger.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-api-exception.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-api-client.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-signature.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-reference.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-amounts.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-phone.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-gateway.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-ipn-handler.php';
		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-ajax.php';
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'woocommerce-yappy',
			false,
			dirname( plugin_basename( WC_YAPPY_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Add the gateway to WooCommerce.
	 *
	 * @param array $gateways Registered gateway class names.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'WC_Yappy_Gateway';

		return $gateways;
	}

	/**
	 * Register the Checkout block integration.
	 *
	 * @param Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Block payment method registry.
	 * @return void
	 */
	public function register_blocks_support( $registry ) {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-blocks-support.php';

		$registry->register( new WC_Yappy_Blocks_Support() );
	}

	/**
	 * Add a settings shortcut on the plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . WC_Yappy_Gateway::GATEWAY_ID );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'woocommerce-yappy' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Tell the administrator that WooCommerce is required.
	 *
	 * @return void
	 */
	public function render_missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Yappy Payment Button for WooCommerce requires WooCommerce to be installed and active.', 'woocommerce-yappy' ) .
			'</p></div>';
	}
}

/**
 * Retrieve the registered Yappy gateway instance.
 *
 * @return WC_Yappy_Gateway|null
 */
function wc_yappy_get_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();

	return isset( $gateways[ WC_Yappy_Gateway::GATEWAY_ID ] ) && $gateways[ WC_Yappy_Gateway::GATEWAY_ID ] instanceof WC_Yappy_Gateway
		? $gateways[ WC_Yappy_Gateway::GATEWAY_ID ]
		: null;
}
