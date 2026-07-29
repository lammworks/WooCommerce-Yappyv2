<?php
/**
 * Exception raised when the Yappy API rejects or fails a request.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Carries the Yappy `status.code` alongside the failure message.
 */
class WC_Yappy_API_Exception extends Exception {

	/**
	 * Yappy error code such as `E007`, when the API returned one.
	 *
	 * @var string
	 */
	protected $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $message    Technical message, written to the log.
	 * @param string $error_code Yappy error code, when available.
	 */
	public function __construct( $message, $error_code = '' ) {
		parent::__construct( $message );
		$this->error_code = (string) $error_code;
	}

	/**
	 * Yappy error code reported by the API.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Message safe to display to the customer.
	 *
	 * @return string
	 */
	public function get_customer_message() {
		return WC_Yappy_Status::error_message( $this->error_code, '' );
	}
}
