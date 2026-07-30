<?php
/**
 * The Yappy payment gateway.
 *
 * Flow: WooCommerce places the order as usual, then sends the customer to the
 * order-pay page, where the Yappy web component is rendered. Clicking it asks
 * this plugin's backend to create the Yappy order and hands the resulting
 * credentials to the component, which opens the Yappy app (or shows a QR code).
 * Payment is confirmed server side by the IPN, never by the browser.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce payment gateway backed by the Yappy Botón de Pago API.
 */
class WC_Yappy_Gateway extends WC_Payment_Gateway {

	/**
	 * Gateway identifier.
	 */
	const GATEWAY_ID = 'yappy';

	/**
	 * Order meta holding the reference sent to Yappy for the current attempt.
	 */
	const META_REFERENCE = '_yappy_reference';

	/**
	 * Order meta holding every reference used across attempts.
	 */
	const META_REFERENCE_HISTORY = '_yappy_reference_history';

	/**
	 * Order meta holding the Yappy transaction ID.
	 */
	const META_TRANSACTION_ID = '_yappy_transaction_id';

	/**
	 * Order meta holding the Yappy confirmation number.
	 */
	const META_CONFIRMATION = '_yappy_confirmation_number';

	/**
	 * Order meta holding the last status received through the IPN.
	 */
	const META_LAST_STATUS = '_yappy_last_status';

	/**
	 * Currencies Yappy can settle. Panama uses the US dollar alongside the balboa.
	 *
	 * @var string[]
	 */
	protected $supported_currencies = array( 'USD', 'PAB' );

	/**
	 * Logger.
	 *
	 * @var WC_Yappy_Logger
	 */
	protected $log;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		// The Yappy component is rendered in the classic checkout. The customer
		// presses that component once: WooCommerce creates the order and the
		// component immediately opens the Yappy app (or its QR flow) in place.
		$this->has_fields         = true;
		$this->method_title       = __( 'Yappy', 'woocommerce-yappy' );
		$this->method_description = __( 'Accept payments with Yappy, the mobile payment service from Banco General (Panamá). Uses the new Botón de Pago Yappy integration.', 'woocommerce-yappy' );
		$this->icon               = apply_filters( 'wc_yappy_icon', WC_YAPPY_PLUGIN_URL . 'assets/images/yappy.svg' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title             = $this->get_option( 'title', __( 'Yappy', 'woocommerce-yappy' ) );
		$this->description       = $this->get_option( 'description' );
		$this->order_button_text = __( 'Continue to Yappy', 'woocommerce-yappy' );

		$this->log = new WC_Yappy_Logger( 'yes' === $this->get_option( 'debug', 'no' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Settings exposed in WooCommerce → Settings → Payments → Yappy.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-yappy' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Yappy', 'woocommerce-yappy' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'woocommerce-yappy' ),
				'type'        => 'text',
				'description' => __( 'The name the customer sees at checkout.', 'woocommerce-yappy' ),
				'default'     => __( 'Yappy', 'woocommerce-yappy' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'woocommerce-yappy' ),
				'type'        => 'textarea',
				'description' => __( 'The text shown under the payment method at checkout.', 'woocommerce-yappy' ),
				'default'     => __( 'Pay with your Yappy app from Banco General. You will confirm the payment on your phone.', 'woocommerce-yappy' ),
			),
			'credentials'     => array(
				'title'       => __( 'Credentials', 'woocommerce-yappy' ),
				'type'        => 'title',
				'description' => $this->get_credentials_description(),
			),
			'merchant_id'     => array(
				'title'       => __( 'Merchant ID', 'woocommerce-yappy' ),
				'type'        => 'text',
				'description' => __( 'The merchant ID issued in Yappy Comercial.', 'woocommerce-yappy' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'secret_key'      => array(
				'title'       => __( 'Secret key', 'woocommerce-yappy' ),
				'type'        => 'password',
				'description' => __( 'The secret key issued in Yappy Comercial. Used to verify that payment notifications really come from Yappy.', 'woocommerce-yappy' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'merchant_domain' => array(
				'title'       => __( 'Registered domain', 'woocommerce-yappy' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: the site URL detected by WordPress. */
					__( 'Must match the domain registered in Yappy Comercial, exactly. Leave empty to use %s.', 'woocommerce-yappy' ),
					'<code>' . esc_html( $this->get_default_domain() ) . '</code>'
				),
				'default'     => '',
			),
			'environment'     => array(
				'title'       => __( 'Environment', 'woocommerce-yappy' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Use Sandbox to test against the Yappy UAT environment. Sandbox credentials do not work in production and vice versa.', 'woocommerce-yappy' ),
				'default'     => 'production',
				'desc_tip'    => true,
				'options'     => array(
					'production' => __( 'Production', 'woocommerce-yappy' ),
					'sandbox'    => __( 'Sandbox (UAT)', 'woocommerce-yappy' ),
				),
			),
			'appearance'      => array(
				'title' => __( 'Button', 'woocommerce-yappy' ),
				'type'  => 'title',
			),
			'button_theme'    => array(
				'title'   => __( 'Button theme', 'woocommerce-yappy' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'blue',
				'options' => array(
					'blue'     => __( 'Blue', 'woocommerce-yappy' ),
					'darkBlue' => __( 'Dark blue', 'woocommerce-yappy' ),
					'orange'   => __( 'Orange', 'woocommerce-yappy' ),
					'dark'     => __( 'Dark', 'woocommerce-yappy' ),
					'sky'      => __( 'Sky', 'woocommerce-yappy' ),
					'light'    => __( 'Light', 'woocommerce-yappy' ),
				),
			),
			'button_rounded'  => array(
				'title'   => __( 'Rounded corners', 'woocommerce-yappy' ),
				'type'    => 'checkbox',
				'label'   => __( 'Render the Yappy button with rounded corners', 'woocommerce-yappy' ),
				'default' => 'yes',
			),
			'ask_phone'       => array(
				'title'       => __( 'Ask for the phone number', 'woocommerce-yappy' ),
				'type'        => 'checkbox',
				'label'       => __( 'Ask the customer for their Yappy phone number', 'woocommerce-yappy' ),
				'description' => __( 'When enabled the payment request is pushed straight to that number. When disabled — or when the customer leaves it empty — Yappy shows a QR code instead.', 'woocommerce-yappy' ),
				'default'     => 'yes',
			),
			'advanced'        => array(
				'title' => __( 'Advanced', 'woocommerce-yappy' ),
				'type'  => 'title',
			),
			'debug'           => array(
				'title'       => __( 'Debug log', 'woocommerce-yappy' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log Yappy requests and notifications', 'woocommerce-yappy' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: log file location. */
					__( 'Saved under %s. Errors are always logged; this adds the request-level detail.', 'woocommerce-yappy' ),
					'<code>WooCommerce &rarr; Status &rarr; Logs</code>'
				),
			),
			'remove_data'     => array(
				'title'   => __( 'Remove data on uninstall', 'woocommerce-yappy' ),
				'type'    => 'checkbox',
				'label'   => __( 'Delete the settings of this plugin when it is uninstalled', 'woocommerce-yappy' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Blurb shown above the credential fields, including the IPN URL to register.
	 *
	 * @return string
	 */
	protected function get_credentials_description() {
		return '<p>' . esc_html__( 'Yappy notifies this store of every payment result at the following URL. It must be reachable from the internet over HTTPS:', 'woocommerce-yappy' ) . '</p>' .
			'<p><code>' . esc_html( $this->get_ipn_url() ) . '</code></p>';
	}

	/**
	 * Default registered domain, derived from the site URL.
	 *
	 * @return string
	 */
	protected function get_default_domain() {
		$home  = home_url();
		$parts = wp_parse_url( $home );

		if ( empty( $parts['host'] ) ) {
			return untrailingslashit( $home );
		}

		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$domain = $scheme . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$domain .= ':' . $parts['port'];
		}

		return $domain;
	}

	/**
	 * The domain sent to Yappy as `urlDomain` and `domain`.
	 *
	 * @return string
	 */
	public function get_merchant_domain() {
		$configured = trim( (string) $this->get_option( 'merchant_domain', '' ) );
		$domain     = '' !== $configured ? $configured : $this->get_default_domain();

		/**
		 * Filter the domain sent to Yappy.
		 *
		 * @param string $domain Registered domain.
		 */
		return untrailingslashit( apply_filters( 'wc_yappy_merchant_domain', $domain ) );
	}

	/**
	 * The URL Yappy calls with the payment result.
	 *
	 * @return string
	 */
	public function get_ipn_url() {
		/**
		 * Filter the IPN URL sent to Yappy.
		 *
		 * @param string $url IPN URL.
		 */
		return apply_filters( 'wc_yappy_ipn_url', WC()->api_request_url( 'wc_yappy' ) );
	}

	/**
	 * Whether the gateway is talking to the sandbox environment.
	 *
	 * @return bool
	 */
	public function is_sandbox() {
		return 'sandbox' === $this->get_option( 'environment', 'production' );
	}

	/**
	 * Merchant secret key.
	 *
	 * @return string
	 */
	public function get_secret_key() {
		return trim( (string) $this->get_option( 'secret_key', '' ) );
	}

	/**
	 * Logger used by this gateway.
	 *
	 * @return WC_Yappy_Logger
	 */
	public function get_logger() {
		return $this->log;
	}

	/**
	 * Build an API client from the current settings.
	 *
	 * @return WC_Yappy_API_Client
	 */
	public function get_api_client() {
		return new WC_Yappy_API_Client(
			trim( (string) $this->get_option( 'merchant_id', '' ) ),
			$this->get_merchant_domain(),
			$this->is_sandbox(),
			$this->log
		);
	}

	/**
	 * Whether the gateway has everything it needs to run.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->get_option( 'merchant_id', '' ) )
			&& '' !== $this->get_secret_key();
	}

	/**
	 * Whether the gateway may be offered to the customer.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! $this->is_configured() ) {
			return false;
		}

		if ( ! in_array( get_woocommerce_currency(), $this->supported_currencies, true ) ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Render the official Yappy button in a classic WooCommerce checkout.
	 *
	 * The Checkout block retains the receipt-page fallback because its Store API
	 * response cannot safely hand the short-lived Yappy credentials to this
	 * classic JavaScript integration.
	 *
	 * @return void
	 */
	public function payment_fields() {
		$this->enqueue_inline_checkout_assets();

		$phone = '';
		if ( 'yes' === $this->get_option( 'ask_phone', 'yes' ) && function_exists( 'WC' ) && WC()->checkout() ) {
			$phone = WC_Yappy_Phone::normalize( WC()->checkout()->get_value( 'billing_phone' ) );
		}

		wc_get_template(
			'yappy/inline-checkout.php',
			array(
				'description' => $this->get_description(),
				'ask_phone'   => 'yes' === $this->get_option( 'ask_phone', 'yes' ),
				'phone'       => $phone,
			),
			'',
			WC_YAPPY_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * Warn the shop manager about anything that would break the integration.
	 *
	 * @return void
	 */
	public function admin_options() {
		if ( 'yes' === $this->enabled && ! in_array( get_woocommerce_currency(), $this->supported_currencies, true ) ) {
			echo '<div class="inline error"><p><strong>' . esc_html__( 'Yappy is disabled', 'woocommerce-yappy' ) . '</strong>: ' .
				esc_html(
					sprintf(
						/* translators: %s: list of supported currencies. */
						__( 'Yappy only settles in %s. Change the store currency to offer it.', 'woocommerce-yappy' ),
						implode( ', ', $this->supported_currencies )
					)
				) . '</p></div>';
		}

		if ( 'yes' === $this->enabled && ! $this->is_sandbox() && ! wc_site_is_https() ) {
			echo '<div class="inline error"><p><strong>' . esc_html__( 'Yappy requires HTTPS', 'woocommerce-yappy' ) . '</strong>: ' .
				esc_html__( 'Yappy will not deliver payment notifications to a store that is not served over HTTPS.', 'woocommerce-yappy' ) . '</p></div>';
		}

		parent::admin_options();
	}

	/**
	 * Place the order and start Yappy when the classic checkout supplied its
	 * inline request marker. Receipt pages remain the fallback for checkout
	 * blocks, direct payment links and JavaScript-disabled browsers.
	 *
	 * No call to Yappy happens here: the Yappy order is only created once the
	 * customer actually presses the button, because Yappy orders expire quickly.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'The order could not be found.', 'woocommerce-yappy' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'pending', __( 'Awaiting Yappy payment.', 'woocommerce-yappy' ) );

		$inline = '1' === $this->get_checkout_post_field( 'wc_yappy_inline' );
		if ( $inline ) {
			$phone = $this->get_checkout_post_field( 'wc-yappy-phone' );

			if ( '' !== $phone && '' === WC_Yappy_Phone::normalize( $phone ) ) {
				wc_add_notice( __( 'Enter a valid Panamanian mobile number, for example 61234567. Leave it empty to pay by QR code.', 'woocommerce-yappy' ), 'error' );
				return array( 'result' => 'failure' );
			}

			try {
				$result = $this->create_yappy_order( $order, $phone );
			} catch ( WC_Yappy_API_Exception $e ) {
				$this->log->error(
					'Could not create the inline Yappy order.',
					array(
						'order' => $order->get_id(),
						'error' => $e->getMessage(),
					)
				);
				wc_add_notice( $e->get_customer_message(), 'error' );
				return array( 'result' => 'failure' );
			} catch ( Exception $e ) {
				$this->log->error(
					'Could not prepare the inline Yappy order.',
					array(
						'order' => $order->get_id(),
						'error' => $e->getMessage(),
					)
				);
				wc_add_notice( __( 'Something went wrong with Yappy. Please try again.', 'woocommerce-yappy' ), 'error' );
				return array( 'result' => 'failure' );
			}

			// WooCommerce returns any extra keys to its checkout JavaScript. The
			// opaque credentials are required by the official Yappy component; no
			// merchant secret is ever exposed to the browser.
			return array(
				'result'   => 'success',
				// WooCommerce always follows a successful redirect. A same-document
				// fragment prevents an empty redirect from reloading checkout while
				// keeping its response contract intact for the inline JavaScript.
				'redirect' => '#wc-yappy-inline',
				'yappy'    => array_merge(
					$result,
					array(
						'orderId'   => $order->get_id(),
						'orderKey'  => $order->get_order_key(),
						'nonce'     => wp_create_nonce( 'wc_yappy_pay_' . $order->get_id() ),
						'returnUrl' => $this->get_return_url( $order ),
					)
				),
			);
		}

		// The cart is deliberately left alone. WooCommerce clears it once the
		// order leaves the pending state, so a customer who abandons the Yappy
		// page still has their cart.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Return one scalar checkout POST value without accepting nested input.
	 * WooCommerce already verifies the checkout nonce before it reaches this
	 * gateway; this method only normalises the optional inline fields.
	 *
	 * @param string $field Request field name.
	 * @return string
	 */
	protected function get_checkout_post_field( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $field ] ) || ! is_scalar( $_POST[ $field ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
	}

	/**
	 * Render the Yappy button on the order-pay page.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$this->enqueue_payment_assets( $order );

		$phone = '';
		if ( 'yes' === $this->get_option( 'ask_phone', 'yes' ) ) {
			$phone = WC_Yappy_Phone::normalize( $order->get_billing_phone() );
		}

		wc_get_template(
			'yappy/payment-page.php',
			array(
				'order'     => $order,
				'gateway'   => $this,
				'ask_phone' => 'yes' === $this->get_option( 'ask_phone', 'yes' ),
				'phone'     => $phone,
			),
			'',
			WC_YAPPY_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * Register and enqueue the assets used by the payment page.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return void
	 */
	protected function enqueue_payment_assets( $order ) {
		wp_enqueue_style(
			'wc-yappy',
			WC_YAPPY_PLUGIN_URL . 'assets/css/yappy.css',
			array(),
			WC_YAPPY_VERSION
		);

		wp_enqueue_script(
			'wc-yappy-checkout',
			WC_YAPPY_PLUGIN_URL . 'assets/js/yappy-checkout.js',
			array(),
			WC_YAPPY_VERSION,
			true
		);

		$params = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'cdnUrl'       => $this->get_api_client()->get_cdn_url(),
			'orderId'      => $order->get_id(),
			'orderKey'     => $order->get_order_key(),
			'nonce'        => wp_create_nonce( 'wc_yappy_pay_' . $order->get_id() ),
			'theme'        => $this->get_option( 'button_theme', 'blue' ),
			'rounded'      => 'yes' === $this->get_option( 'button_rounded', 'yes' ) ? 'true' : 'false',
			'returnUrl'    => $this->get_return_url( $order ),
			'askPhone'     => 'yes' === $this->get_option( 'ask_phone', 'yes' ),
			'pollAttempts' => (int) apply_filters( 'wc_yappy_status_poll_attempts', 10 ),
			'pollInterval' => (int) apply_filters( 'wc_yappy_status_poll_interval', 2000 ),
			'i18n'         => array(
				'loadFailed'   => __( 'The Yappy button could not be loaded. Please refresh the page and try again.', 'woocommerce-yappy' ),
				'genericError' => __( 'Something went wrong with Yappy. Please try again.', 'woocommerce-yappy' ),
				'invalidPhone' => __( 'Enter a valid Panamanian mobile number, for example 61234567. Leave it empty to pay by QR code.', 'woocommerce-yappy' ),
				'confirming'   => __( 'Confirming your payment with Yappy…', 'woocommerce-yappy' ),
			),
		);

		// Passed as JSON rather than through wp_localize_script(), which casts every
		// scalar to a string — that would turn the poll settings and the askPhone
		// flag into strings on the way to the browser.
		wp_add_inline_script(
			'wc-yappy-checkout',
			'window.wcYappyParams = ' . wp_json_encode( $params ) . ';',
			'before'
		);
	}

	/**
	 * Register the assets used to start Yappy from the classic checkout.
	 *
	 * @return void
	 */
	protected function enqueue_inline_checkout_assets() {
		wp_enqueue_style(
			'wc-yappy',
			WC_YAPPY_PLUGIN_URL . 'assets/css/yappy.css',
			array(),
			WC_YAPPY_VERSION
		);

		wp_enqueue_script(
			'wc-yappy-inline-checkout',
			WC_YAPPY_PLUGIN_URL . 'assets/js/yappy-inline-checkout.js',
			array( 'jquery', 'wc-checkout' ),
			WC_YAPPY_VERSION,
			true
		);

		$params = array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'cdnUrl'   => $this->get_api_client()->get_cdn_url(),
			'theme'     => $this->get_option( 'button_theme', 'blue' ),
			'rounded'   => 'yes' === $this->get_option( 'button_rounded', 'yes' ) ? 'true' : 'false',
			'askPhone'  => 'yes' === $this->get_option( 'ask_phone', 'yes' ),
			'i18n'      => array(
				'loadFailed'   => __( 'The Yappy button could not be loaded. Please refresh the page and try again.', 'woocommerce-yappy' ),
				'genericError' => __( 'Something went wrong with Yappy. Please try again.', 'woocommerce-yappy' ),
				'invalidPhone' => __( 'Enter a valid Panamanian mobile number, for example 61234567. Leave it empty to pay by QR code.', 'woocommerce-yappy' ),
				'confirming'   => __( 'Confirming your payment with Yappy…', 'woocommerce-yappy' ),
			),
		);

		wp_add_inline_script(
			'wc-yappy-inline-checkout',
			'window.wcYappyInlineParams = ' . wp_json_encode( $params ) . ';',
			'before'
		);
	}

	/**
	 * Explain on the thank-you page that confirmation may still be in flight.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->needs_payment() ) {
			return;
		}

		echo '<p class="wc-yappy-pending">' . esc_html__( 'We have not received the confirmation from Yappy yet. This page will reflect the payment as soon as Yappy notifies us.', 'woocommerce-yappy' ) . '</p>';
	}

	/**
	 * Create the Yappy order for a WooCommerce order.
	 *
	 * A fresh reference is generated on every call: Yappy rejects a reused
	 * `orderId` with E007, and a customer who retries must get a new one.
	 *
	 * @param WC_Order $order Order being paid.
	 * @param string   $phone Raw phone number typed by the customer, may be empty.
	 * @return array{transactionId:string,token:string,documentName:string}
	 * @throws WC_Yappy_API_Exception When Yappy rejects the order.
	 * @throws InvalidArgumentException When the order amounts are not payable.
	 */
	public function create_yappy_order( $order, $phone = '' ) {
		$reference = WC_Yappy_Reference::generate( $order->get_id() );

		// These reconcile exactly against WC_Abstract_Order::calculate_totals(),
		// which sets total = cart_total + fees + shipping + tax and
		// discount_total = cart_subtotal - cart_total, with get_subtotal()
		// returning that same cart_subtotal.
		//
		// The `true` on get_total_discount() is deliberate, not the default
		// showing through. On a store with "Prices entered with tax" enabled part
		// of a coupon's face value is tax, and WooCommerce splits it into
		// discount_total (ex tax) and discount_tax. get_subtotal() is always ex
		// tax and the tax travels in its own field, so the ex-tax half is the
		// only one that keeps the amounts consistent; passing false would
		// overstate the discount by exactly discount_tax.
		//
		// Shipping and fees ride in the subtotal because Yappy's payload has no
		// field for either. On an order with neither, the subtotal sent is
		// exactly the cart subtotal.
		$amounts = WC_Yappy_Amounts::build(
			$order->get_total(),
			$order->get_subtotal(),
			$order->get_total_tax(),
			$order->get_total_discount( true ),
			(float) $order->get_shipping_total() + (float) $order->get_total_fees()
		);

		$args = array(
			'orderId'     => $reference,
			'ipnUrl'      => $this->get_ipn_url(),
			'total'       => $amounts['total'],
			'subtotal'    => $amounts['subtotal'],
			'taxes'       => $amounts['taxes'],
			'discount'    => $amounts['discount'],
			'paymentDate' => 0,
			'aliasYappy'  => WC_Yappy_Phone::normalize( $phone ),
		);

		/**
		 * Filter the create-order arguments before they are sent to Yappy.
		 *
		 * @param array    $args  Arguments for `WC_Yappy_API_Client::init_checkout()`.
		 * @param WC_Order $order Order being paid.
		 */
		$args = apply_filters( 'wc_yappy_create_order_args', $args, $order );

		$result = $this->get_api_client()->init_checkout( $args );

		$this->remember_reference( $order, $reference );
		$order->update_meta_data( self::META_TRANSACTION_ID, $result['transactionId'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: Yappy reference, 2: Yappy transaction ID. */
				__( 'Yappy order created. Reference: %1$s. Transaction: %2$s.', 'woocommerce-yappy' ),
				$reference,
				$result['transactionId']
			)
		);
		$order->save();

		$this->log->info(
			'Yappy order created.',
			array(
				'order'         => $order->get_id(),
				'reference'     => $reference,
				'transactionId' => $result['transactionId'],
			)
		);

		return $result;
	}

	/**
	 * Store the reference of the current attempt, keeping the previous ones.
	 *
	 * Keeping the history lets a late notification for an earlier attempt still
	 * be matched to the order instead of being discarded.
	 *
	 * @param WC_Order $order     Order being paid.
	 * @param string   $reference Reference just sent to Yappy.
	 * @return void
	 */
	protected function remember_reference( $order, $reference ) {
		$history = $order->get_meta( self::META_REFERENCE_HISTORY );
		$history = is_array( $history ) ? $history : array();

		if ( ! in_array( $reference, $history, true ) ) {
			$history[] = $reference;
		}

		// Retries are rare; a short window is enough to match late notifications.
		if ( count( $history ) > 10 ) {
			$history = array_slice( $history, -10 );
		}

		$order->update_meta_data( self::META_REFERENCE, $reference );
		$order->update_meta_data( self::META_REFERENCE_HISTORY, $history );
	}

	/**
	 * Whether a reference belongs to this order.
	 *
	 * @param WC_Order $order     Order to check.
	 * @param string   $reference Reference received through the IPN.
	 * @return bool
	 */
	public function reference_belongs_to_order( $order, $reference ) {
		if ( (string) $order->get_meta( self::META_REFERENCE ) === (string) $reference ) {
			return true;
		}

		$history = $order->get_meta( self::META_REFERENCE_HISTORY );

		return is_array( $history ) && in_array( (string) $reference, $history, true );
	}
}
