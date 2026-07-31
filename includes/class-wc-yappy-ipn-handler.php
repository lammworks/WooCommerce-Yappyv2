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
		// The IPN must never be served from a cache: a cached response means the
		// notification never reaches this handler and the order is never marked
		// paid. These constants ask the common page caches (host reverse-proxy
		// caches and caching plugins alike) to skip this request. Edge caches such
		// as Cloudflare still have to be told to bypass /wc-api/ in their own
		// configuration — no origin header can guarantee that from here.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}

		$gateway = wc_yappy_get_gateway();

		if ( ! $gateway ) {
			// Record the hit even though the gateway is missing: this is always
			// logged, so a reachability test (or a real notification) proves the
			// request got as far as PHP even when nothing else could run.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'IPN endpoint reached but the Yappy gateway is unavailable.',
					array( 'source' => 'yappy' )
				);
			}
			self::respond( 503, 'Gateway unavailable' );
		}

		$log = $gateway->get_logger();

		// The payload is authenticated by its HMAC, so the raw values are read
		// verbatim: the signature covers the exact bytes Yappy sent, and any
		// rewriting (URL normalisation in particular) would break verification.
		// Nonce verification does not apply to a server-to-server callback.
		// phpcs:disable WordPress.Security.NonceVerification
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		// Yappy delivers the result as a GET request. POST and a JSON body are
		// read as well, so a reverse proxy that forwards the callback as a POST,
		// or a JSON-style delivery, cannot silently drop it. The HMAC still covers
		// the same values whichever way they arrive.
		$source = ! empty( $_GET ) ? $_GET : $_POST;

		if ( empty( $source ) ) {
			$raw     = file_get_contents( 'php://input' );
			$decoded = json_decode( (string) $raw, true );
			if ( is_array( $decoded ) ) {
				$source = $decoded;
			}
		}

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
				'method'             => $method,
				'orderId'            => sanitize_text_field( $params['orderId'] ),
				'status'             => sanitize_text_field( $params['status'] ),
				'domain'             => sanitize_text_field( $params['domain'] ),
				'hasHash'            => '' !== $params['hash'],
				'confirmationNumber' => sanitize_text_field( $params['confirmationNumber'] ),
			)
		);

		if ( ! WC_Yappy_Signature::verify( $params, $gateway->get_secret_key() ) ) {
			$secret = $gateway->get_secret_key();
			$log->error(
				'IPN rejected: signature mismatch.',
				array( 'orderId' => sanitize_text_field( $params['orderId'] ) )
			);
			// Debug-only detail to tell the three failure modes apart: an empty
			// payload (nothing was parsed), a missing secret key (keyConfigured is
			// false), or a genuine hash/algorithm mismatch (the signed string and
			// key look right but the digests differ). The signed values and the
			// provided hash already travel in the request, so nothing secret is
			// added to the log here.
			$log->debug(
				'IPN signature detail.',
				array(
					'signed'        => $params['orderId'] . $params['status'] . $params['domain'],
					'provided'      => $params['hash'],
					'expected'      => WC_Yappy_Signature::calculate( $params['orderId'], $params['status'], $params['domain'], $secret ),
					'keyConfigured' => '' !== $secret,
				)
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
		$log                = $gateway->get_logger();
		$status             = $params['status'];
		$current_reference  = (string) $order->get_meta( WC_Yappy_Gateway::META_REFERENCE );
		$is_current_attempt = '' !== $current_reference && hash_equals( $current_reference, (string) $params['orderId'] );

		if ( $is_current_attempt || WC_Yappy_Status::EXECUTED === $status ) {
			$order->update_meta_data( WC_Yappy_Gateway::META_LAST_STATUS, $status );
		}

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

		// An earlier request can resolve after the customer has started a fresh
		// one. A late rejection, cancellation or expiry must not cancel the newer
		// attempt. A late successful payment is still accepted below.
		if ( ! $is_current_attempt && WC_Yappy_Status::EXECUTED !== $status ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Yappy status label. */
					__( 'Ignored a late Yappy notification (%s) because a newer payment attempt is active.', 'woocommerce-yappy' ),
					WC_Yappy_Status::label( $status )
				)
			);
			$order->save();
			$log->info( 'IPN ignored: newer payment attempt is active.', array( 'order' => $order->get_id() ) );
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
				// Cancelling the Yappy request does not cancel the customer's shop
				// order. A failed order remains payable, so the checkout can offer a
				// safe retry with a fresh Yappy reference.
				$order->update_status( 'failed', __( 'Yappy payment cancelled by the customer.', 'woocommerce-yappy' ) );
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
		// Explicit no-store, including the CDN-specific directives Cloudflare and
		// other edge caches honour, so a well-behaved cache does not retain the
		// response. A cache forced to "cache everything" still needs a bypass rule
		// for /wc-api/ in its own configuration.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'CDN-Cache-Control: no-store' );
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}
}
