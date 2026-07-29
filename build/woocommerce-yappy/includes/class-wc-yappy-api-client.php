<?php
/**
 * HTTP client for the Yappy Botón de Pago API.
 *
 * Implements the two server-to-server calls of the new integration:
 *
 *   1. POST /payments/validate/merchant — exchanges the merchant credentials for
 *      a short lived token.
 *   2. POST /payments/payment-wc — creates the order and returns the three values
 *      the `<btn-yappy>` web component needs.
 *
 * Both calls must happen from the server. The token is deliberately never cached:
 * Yappy issues it for immediate use by the very next request.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the two Yappy endpoints.
 */
class WC_Yappy_API_Client {

	/**
	 * Production API host.
	 */
	const API_PRODUCTION = 'https://apipagosbg.bgeneral.cloud';

	/**
	 * Sandbox (UAT) API host.
	 */
	const API_SANDBOX = 'https://api-comecom-uat.yappycloud.com';

	/**
	 * Production CDN URL for the button web component.
	 */
	const CDN_PRODUCTION = 'https://bt-cdn.yappy.cloud/v1/cdn/web-component-btn-yappy.js';

	/**
	 * Sandbox (UAT) CDN URL for the button web component.
	 */
	const CDN_SANDBOX = 'https://bt-cdn-uat.yappycloud.com/v1/cdn/web-component-btn-yappy.js';

	/**
	 * Merchant ID issued by Yappy Comercial.
	 *
	 * @var string
	 */
	protected $merchant_id;

	/**
	 * Domain registered with Yappy Comercial.
	 *
	 * @var string
	 */
	protected $domain;

	/**
	 * Whether to talk to the sandbox environment.
	 *
	 * @var bool
	 */
	protected $sandbox;

	/**
	 * Logger, or null when logging is disabled.
	 *
	 * @var WC_Yappy_Logger|null
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param string               $merchant_id Merchant ID.
	 * @param string               $domain      Registered domain, e.g. `https://store.com`.
	 * @param bool                 $sandbox     Use the UAT environment.
	 * @param WC_Yappy_Logger|null $logger      Optional logger.
	 */
	public function __construct( $merchant_id, $domain, $sandbox = false, $logger = null ) {
		$this->merchant_id = (string) $merchant_id;
		$this->domain      = untrailingslashit( (string) $domain );
		$this->sandbox     = (bool) $sandbox;
		$this->logger      = $logger;
	}

	/**
	 * Base URL of the API for the configured environment.
	 *
	 * @return string
	 */
	public function get_api_base() {
		return $this->sandbox ? self::API_SANDBOX : self::API_PRODUCTION;
	}

	/**
	 * CDN URL of the button web component for the configured environment.
	 *
	 * @return string
	 */
	public function get_cdn_url() {
		return $this->sandbox ? self::CDN_SANDBOX : self::CDN_PRODUCTION;
	}

	/**
	 * Domain sent to Yappy as `urlDomain` and `domain`.
	 *
	 * @return string
	 */
	public function get_domain() {
		return $this->domain;
	}

	/**
	 * Step 1: validate the merchant and obtain a short lived token.
	 *
	 * @return array{token:string,epochTime:int}
	 * @throws WC_Yappy_API_Exception When the credentials are rejected or the call fails.
	 */
	public function validate_merchant() {
		$body = $this->request(
			'/payments/validate/merchant',
			array(
				'merchantId' => $this->merchant_id,
				'urlDomain'  => $this->domain,
			)
		);

		$token = isset( $body['token'] ) ? (string) $body['token'] : '';

		if ( '' === $token ) {
			throw new WC_Yappy_API_Exception( 'validate/merchant returned no token.' );
		}

		return array(
			'token'     => $token,
			'epochTime' => isset( $body['epochTime'] ) ? (int) $body['epochTime'] : 0,
		);
	}

	/**
	 * Step 2: create the payment order.
	 *
	 * @param array  $args       {
	 *     Order arguments.
	 *
	 *     @type string $orderId     Unique reference, alphanumeric, max 15 characters.
	 *     @type string $ipnUrl      Publicly reachable URL Yappy notifies.
	 *     @type string $total       Total, two decimals.
	 *     @type string $subtotal    Subtotal, two decimals.
	 *     @type string $taxes       Taxes, two decimals.
	 *     @type string $discount    Discount, two decimals.
	 *     @type int    $paymentDate Unix timestamp.
	 *     @type string $aliasYappy  Optional customer phone; omitted renders a QR code.
	 * }
	 * @param string $auth_token Token returned by `validate_merchant()`.
	 * @return array{transactionId:string,token:string,documentName:string}
	 * @throws WC_Yappy_API_Exception When Yappy rejects the order or the call fails.
	 */
	public function create_order( array $args, $auth_token ) {
		$payload = array(
			'merchantId'  => $this->merchant_id,
			'orderId'     => (string) $args['orderId'],
			'domain'      => $this->domain,
			'paymentDate' => (int) $args['paymentDate'],
			'ipnUrl'      => (string) $args['ipnUrl'],
			'discount'    => (string) $args['discount'],
			'taxes'       => (string) $args['taxes'],
			'subtotal'    => (string) $args['subtotal'],
			'total'       => (string) $args['total'],
		);

		// Yappy renders a QR code when no phone is supplied, so only send the key
		// when we actually have a number.
		if ( ! empty( $args['aliasYappy'] ) ) {
			$payload['aliasYappy'] = (string) $args['aliasYappy'];
		}

		$body = $this->request( '/payments/payment-wc', $payload, array( 'Authorization' => (string) $auth_token ) );

		$transaction_id = isset( $body['transactionId'] ) ? (string) $body['transactionId'] : '';
		$token          = isset( $body['token'] ) ? (string) $body['token'] : '';
		$document_name  = isset( $body['documentName'] ) ? (string) $body['documentName'] : '';

		if ( '' === $transaction_id || '' === $token || '' === $document_name ) {
			throw new WC_Yappy_API_Exception( 'payment-wc returned an incomplete body.' );
		}

		return array(
			'transactionId' => $transaction_id,
			'token'         => $token,
			'documentName'  => $document_name,
		);
	}

	/**
	 * Run both steps in order.
	 *
	 * The token from step 1 is short lived, so the two calls always travel
	 * together. When Yappy reports its own clock in step 1 that value is used as
	 * `paymentDate`, which keeps the payload correct even if the store's clock drifts.
	 *
	 * @param array $args Same arguments as `create_order()`, minus `paymentDate`.
	 * @return array{transactionId:string,token:string,documentName:string}
	 * @throws WC_Yappy_API_Exception When either step fails.
	 */
	public function init_checkout( array $args ) {
		$credentials = $this->validate_merchant();

		if ( empty( $args['paymentDate'] ) ) {
			$args['paymentDate'] = $credentials['epochTime'] > 0 ? $credentials['epochTime'] : time();
		}

		return $this->create_order( $args, $credentials['token'] );
	}

	/**
	 * Perform a POST request against the Yappy API and unwrap its envelope.
	 *
	 * Yappy answers with `{ "status": { "code", "description" }, "body": { ... } }`
	 * and does not always signal failures through the HTTP status code, so the
	 * envelope is inspected regardless of the transport result.
	 *
	 * @param string $path            Endpoint path, starting with a slash.
	 * @param array  $payload         Request body, JSON encoded.
	 * @param array  $extra_headers   Additional headers.
	 * @return array The `body` element of the response.
	 * @throws WC_Yappy_API_Exception When the request fails or Yappy reports an error.
	 */
	protected function request( $path, array $payload, array $extra_headers = array() ) {
		$url = $this->get_api_base() . $path;

		$args = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array_merge(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$extra_headers
			),
			'body'    => wp_json_encode( $payload ),
		);

		/**
		 * Filter the request arguments before they are sent to Yappy.
		 *
		 * @param array  $args    Arguments for `wp_remote_post()`.
		 * @param string $path    Endpoint path.
		 * @param array  $payload Decoded request body.
		 */
		$args = apply_filters( 'wc_yappy_request_args', $args, $path, $payload );

		$this->log( 'debug', sprintf( 'POST %s', $url ), $this->redact( $payload ) );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', sprintf( 'Transport error calling %s: %s', $path, $response->get_error_message() ) );
			throw new WC_Yappy_API_Exception( sprintf( 'Could not reach Yappy (%s): %s', $path, $response->get_error_message() ) );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw       = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			$this->log( 'error', sprintf( 'Unreadable response from %s (HTTP %d).', $path, $http_code ), array( 'body' => substr( (string) $raw, 0, 500 ) ) );
			throw new WC_Yappy_API_Exception( sprintf( 'Unreadable response from Yappy (%s, HTTP %d).', $path, $http_code ) );
		}

		$status_code = isset( $decoded['status']['code'] ) ? (string) $decoded['status']['code'] : '';
		$description = isset( $decoded['status']['description'] ) ? (string) $decoded['status']['description'] : '';
		$body        = isset( $decoded['body'] ) && is_array( $decoded['body'] ) ? $decoded['body'] : array();

		// An error code is anything that starts with `E`; success codes are numeric
		// (`00`). Combined with the HTTP status this covers both failure shapes.
		$is_error = ( $http_code < 200 || $http_code >= 300 )
			|| ( '' !== $status_code && 'E' === strtoupper( substr( $status_code, 0, 1 ) ) );

		if ( $is_error ) {
			$this->log(
				'error',
				sprintf( 'Yappy rejected %s (HTTP %d, code %s): %s', $path, $http_code, $status_code, $description )
			);
			throw new WC_Yappy_API_Exception(
				sprintf( 'Yappy error on %s (HTTP %d, code %s): %s', $path, $http_code, $status_code, $description ),
				$status_code
			);
		}

		$this->log( 'debug', sprintf( 'Yappy accepted %s (code %s).', $path, $status_code ) );

		return $body;
	}

	/**
	 * Remove values that should never reach the log.
	 *
	 * @param array $payload Request body.
	 * @return array
	 */
	protected function redact( array $payload ) {
		if ( isset( $payload['aliasYappy'] ) ) {
			$payload['aliasYappy'] = '***' . substr( (string) $payload['aliasYappy'], -2 );
		}

		return $payload;
	}

	/**
	 * Forward a message to the logger when one is configured.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Optional structured context.
	 * @return void
	 */
	protected function log( $level, $message, array $context = array() ) {
		if ( $this->logger instanceof WC_Yappy_Logger ) {
			$this->logger->log( $level, $message, $context );
		}
	}
}
