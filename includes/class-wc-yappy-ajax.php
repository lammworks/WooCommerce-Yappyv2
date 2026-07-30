<?php
/**
 * AJAX endpoints used by the Yappy payment page.
 *
 * The browser never talks to Yappy directly — it asks this plugin to create the
 * order server side and only receives the three opaque values the web component
 * needs. Requests are authenticated by the order key (a per-order secret) plus a
 * nonce, so a guest checkout works without a login.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `wc_yappy_create_order` and `wc_yappy_order_status` actions.
 */
class WC_Yappy_Ajax {

	/**
	 * Hook the endpoints.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_wc_yappy_create_order', array( __CLASS__, 'create_order' ) );
		add_action( 'wp_ajax_nopriv_wc_yappy_create_order', array( __CLASS__, 'create_order' ) );
		add_action( 'wp_ajax_wc_yappy_order_status', array( __CLASS__, 'order_status' ) );
		add_action( 'wp_ajax_nopriv_wc_yappy_order_status', array( __CLASS__, 'order_status' ) );
	}

	/**
	 * Read a scalar field from the request body.
	 *
	 * The nonce is checked by the caller, in `get_authorised_order()`; anything
	 * that is not a scalar — `field[]=x` — is read as absent rather than coerced.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected static function read_post_field( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $field ] ) || ! is_scalar( $_POST[ $field ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
	}

	/**
	 * Resolve and authorise the order referenced by the request.
	 *
	 * @return WC_Order Never returns on failure; sends a JSON error and exits.
	 */
	protected static function get_authorised_order() {
		$order_id = absint( self::read_post_field( 'order_id' ) );
		$nonce    = self::read_post_field( 'nonce' );
		$key      = self::read_post_field( 'order_key' );

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'wc_yappy_pay_' . $order_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session expired. Please refresh the page and try again.', 'woocommerce-yappy' ) ),
				403
			);
		}

		$order = wc_get_order( $order_id );

		// The order key proves the requester owns this order, which is what makes
		// the endpoint safe for logged-out customers.
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			wp_send_json_error(
				array( 'message' => __( 'The order could not be found.', 'woocommerce-yappy' ) ),
				404
			);
		}

		return $order;
	}

	/**
	 * Create the Yappy order and return the values the web component needs.
	 *
	 * @return void
	 */
	public static function create_order() {
		$order   = self::get_authorised_order();
		$gateway = wc_yappy_get_gateway();

		if ( ! $gateway || ! $gateway->is_configured() ) {
			wp_send_json_error(
				array( 'message' => __( 'Yappy is not configured for this store.', 'woocommerce-yappy' ) ),
				503
			);
		}

		if ( ! $order->needs_payment() ) {
			wp_send_json_error(
				array(
					'message' => __( 'This order has already been paid.', 'woocommerce-yappy' ),
					'paid'    => true,
				),
				409
			);
		}

		$phone = self::read_post_field( 'phone' );

		// An unusable number is rejected here rather than silently downgraded to
		// the QR flow, so the customer finds out about the typo.
		if ( '' !== $phone && '' === WC_Yappy_Phone::normalize( $phone ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Enter a valid Panamanian mobile number, for example 61234567. Leave it empty to pay by QR code.', 'woocommerce-yappy' ) ),
				400
			);
		}

		try {
			$result = $gateway->create_yappy_order( $order, $phone );
		} catch ( WC_Yappy_API_Exception $e ) {
			$gateway->get_logger()->error(
				'Could not create the Yappy order.',
				array(
					'order' => $order->get_id(),
					'error' => $e->getMessage(),
				)
			);
			wp_send_json_error( array( 'message' => $e->get_customer_message() ), 502 );
		} catch ( Exception $e ) {
			$gateway->get_logger()->error(
				'Could not prepare the Yappy order.',
				array(
					'order' => $order->get_id(),
					'error' => $e->getMessage(),
				)
			);
			wp_send_json_error(
				array( 'message' => __( 'Something went wrong with Yappy. Please try again.', 'woocommerce-yappy' ) ),
				500
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Report whether the IPN has confirmed the payment yet.
	 *
	 * @return void
	 */
	public static function order_status() {
		$order = self::get_authorised_order();

		wp_send_json_success(
			array(
				// A cancelled or failed order still needs payment, but neither state
				// means that Yappy collected money. Do not send the customer to a
				// receipt until WooCommerce considers the order paid.
				'paid'        => $order->is_paid(),
				'status'      => $order->get_status(),
				'yappyStatus' => (string) $order->get_meta( WC_Yappy_Gateway::META_LAST_STATUS ),
				'returnUrl'   => $order->get_checkout_order_received_url(),
			)
		);
	}
}
