<?php
/**
 * Handles the Yappy IPN callback.
 *
 * Yappy sends a GET request to the configured `ipnUrl` once the customer has
 * acted on the payment request. This is the only authority on whether an order
 * was paid — the browser never decides that.
 *
 * The request carries no session and no nonce; its authenticity comes entirely
 * from the HMAC in the `hash` parameter, which is verified before anything is
 * read from the payload.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint reachable at `/wc-api/wc_yappy/`.
 */
class WC_Yappy_IPN_Handler {

	/**
	 * Register the endpoint.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_api_wc_yappy', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Process an incoming notification.
	 *
	 * @return void
	 */
	public static function handle() {
		$gateway = wc_yappy_get_gateway();

		if ( ! $gateway ) {
			self::respond( 503, 'Gateway unavailable' );
		}

		$log = $gateway->get_logger();

		// The payload is authenticated by its HMAC, so the raw values are read
		// verbatim: the signature covers the exact bytes Yappy sent, and any
		// rewriting (URL normalisation in particular) would break verification.
		// Nonce verification does not apply to a server-to-server callback.
		// phpcs:disable WordPress.Security.NonceVerification
		$source = ! empty( $_GET ) ? $_GET : $_POST;
		$params = array();
		foreach ( array( 'orderId', 'status', 'domain', 'hash', 'confirmationNumber' ) as $field ) {
			// A caller can send `?orderId[]=x`; anything that is not a scalar is
			// read as absent rather than coerced.
			$params[ $field ] = isset( $source[ $field ] ) && is_scalar( $source[ $field ] )
				? (string) wp_unslash( $source[ $field ] )
				: '';
		}
		// phpcs:enable WordPress.Security.NonceVerification

		$log->debug(
			'IPN received.',
			array(
				'orderId' => sanitize_text_field( $params['orderId'] ),
				'status'  => sanitize_text_field( $params['status'] ),
			)
		);

		if ( ! WC_Yappy_Signature::verify( $params, $gateway->get_secret_key() ) ) {
			$log->error(
				'IPN rejected: signature mismatch.',
				array( 'orderId' => sanitize_text_field( $params['orderId'] ) )
			);
			self::respond( 400, 'Invalid hash' );
		}

		// Past this point the payload is authenticated. Shape checks still run,
		// because a valid signature proves origin, not that the values are usable.
		if ( ! WC_Yappy_Status::is_valid( $params['status'] ) ) {
			$log->error( 'IPN rejected: unknown status.', array( 'status' => sanitize_text_field( $params['status'] ) ) );
			self::respond( 400, 'Unknown status' );
		}

		$params['confirmationNumber'] = sanitize_text_field( $params['confirmationNumber'] );

		$order_id = WC_Yappy_Reference::to_order_id( $params['orderId'] );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			$log->error( 'IPN rejected: no order for reference.', array( 'orderId' => sanitize_text_field( $params['orderId'] ) ) );
			// The signature was valid, so there is nothing for Yappy to retry.
			self::respond( 200, 'Order not found' );
		}

		if ( ! $gateway->reference_belongs_to_order( $order, $params['orderId'] ) ) {
			$log->error(
				'IPN rejected: reference does not belong to the resolved order.',
				array(
					'orderId' => sanitize_text_field( $params['orderId'] ),
					'order'   => $order->get_id(),
				)
			);
			self::respond( 200, 'Reference mismatch' );
		}

		self::apply_status( $gateway, $order, $params );

		self::respond( 200, 'OK' );
	}

	/**
	 * Move the order to the state the notification describes.
	 *
	 * @param WC_Yappy_Gateway $gateway Gateway instance.
	 * @param WC_Order         $order   Order to update.
	 * @param array            $params  Verified notification parameters.
	 * @return void
	 */
	protected static function apply_status( $gateway, $order, array $params ) {
		$log    = $gateway->get_logger();
		$status = $params['status'];

		$order->update_meta_data( WC_Yappy_Gateway::META_LAST_STATUS, $status );

		if ( '' !== $params['confirmationNumber'] ) {
			$order->update_meta_data( WC_Yappy_Gateway::META_CONFIRMATION, $params['confirmationNumber'] );
		}

		// A paid order is never moved backwards by a later notification.
		if ( ! $order->needs_payment() && WC_Yappy_Status::EXECUTED !== $status ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Yappy status label. */
					__( 'Ignored a late Yappy notification (%s) because the order is already paid.', 'woocommerce-yappy' ),
					WC_Yappy_Status::label( $status )
				)
			);
			$order->save();
			$log->info( 'IPN ignored: order already paid.', array( 'order' => $order->get_id() ) );
			return;
		}

		switch ( $status ) {
			case WC_Yappy_Status::EXECUTED:
				if ( ! $order->needs_payment() ) {
					$order->save();
					$log->info( 'IPN duplicate: order already completed.', array( 'order' => $order->get_id() ) );
					return;
				}

				$transaction_id = '' !== $params['confirmationNumber']
					? $params['confirmationNumber']
					: (string) $order->get_meta( WC_Yappy_Gateway::META_TRANSACTION_ID );

				$order->add_order_note(
					sprintf(
						/* translators: 1: Yappy reference, 2: confirmation number. */
						__( 'Yappy payment executed. Reference: %1$s. Confirmation: %2$s.', 'woocommerce-yappy' ),
						sanitize_text_field( $params['orderId'] ),
						'' !== $params['confirmationNumber'] ? $params['confirmationNumber'] : __( 'not provided', 'woocommerce-yappy' )
					)
				);
				$order->payment_complete( $transaction_id );
				$log->info( 'IPN accepted: payment complete.', array( 'order' => $order->get_id() ) );
				break;

			case WC_Yappy_Status::REJECTED:
				$order->update_status( 'failed', __( 'Yappy payment rejected by the customer.', 'woocommerce-yappy' ) );
				$log->info( 'IPN accepted: payment rejected.', array( 'order' => $order->get_id() ) );
				break;

			case WC_Yappy_Status::CANCELLED:
				$order->update_status( 'cancelled', __( 'Yappy payment cancelled by the customer.', 'woocommerce-yappy' ) );
				$log->info( 'IPN accepted: payment cancelled.', array( 'order' => $order->get_id() ) );
				break;

			case WC_Yappy_Status::EXPIRED:
				$order->update_status( 'failed', __( 'The Yappy payment request expired before it was confirmed.', 'woocommerce-yappy' ) );
				$log->info( 'IPN accepted: payment expired.', array( 'order' => $order->get_id() ) );
				break;
		}

		$order->save();
	}

	/**
	 * Send a plain text response and stop.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message Response body.
	 * @return void
	 */
	protected static function respond( $code, $message ) {
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}
}
