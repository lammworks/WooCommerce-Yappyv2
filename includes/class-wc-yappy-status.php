<?php
/**
 * Yappy transaction statuses and API error codes.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Value object holding the status/error vocabulary used by the Yappy API.
 */
class WC_Yappy_Status {

	/**
	 * Payment confirmed by the customer in the Yappy app.
	 */
	const EXECUTED = 'E';

	/**
	 * Payment rejected by the customer.
	 */
	const REJECTED = 'R';

	/**
	 * Payment cancelled by the customer.
	 */
	const CANCELLED = 'C';

	/**
	 * Order lifetime elapsed without confirmation.
	 */
	const EXPIRED = 'X';

	/**
	 * Every status code Yappy can send through the IPN.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::EXECUTED, self::REJECTED, self::CANCELLED, self::EXPIRED );
	}

	/**
	 * Whether the given code is a status Yappy is documented to send.
	 *
	 * @param string $code Raw status code.
	 * @return bool
	 */
	public static function is_valid( $code ) {
		return in_array( (string) $code, self::all(), true );
	}

	/**
	 * Human readable label for a status code.
	 *
	 * @param string $code Raw status code.
	 * @return string
	 */
	public static function label( $code ) {
		switch ( (string) $code ) {
			case self::EXECUTED:
				return __( 'Executed', 'woocommerce-yappy' );
			case self::REJECTED:
				return __( 'Rejected', 'woocommerce-yappy' );
			case self::CANCELLED:
				return __( 'Cancelled', 'woocommerce-yappy' );
			case self::EXPIRED:
				return __( 'Expired', 'woocommerce-yappy' );
			default:
				return __( 'Unknown', 'woocommerce-yappy' );
		}
	}

	/**
	 * Customer facing message for an error code returned in `status.code`.
	 *
	 * @param string $code        Yappy error code, e.g. `E005`.
	 * @param string $description Raw description returned by the API, used as a fallback.
	 * @return string
	 */
	public static function error_message( $code, $description = '' ) {
		switch ( (string) $code ) {
			case 'E005':
				return __( 'This phone number is not registered with Yappy.', 'woocommerce-yappy' );
			case 'E007':
				return __( 'This order has already been registered with Yappy. Please refresh the page and try again.', 'woocommerce-yappy' );
			case 'E009':
				return __( 'The order reference is too long for Yappy (maximum 15 characters).', 'woocommerce-yappy' );
			case 'E010':
				return __( 'The order amounts are not valid for Yappy.', 'woocommerce-yappy' );
			case 'E011':
				return __( 'The store URL configured for Yappy is not valid.', 'woocommerce-yappy' );
			case 'E002':
			case 'E006':
			case 'E008':
			case 'E012':
			case 'E100':
				return __( 'Something went wrong with Yappy. Please try again.', 'woocommerce-yappy' );
			default:
				return '' !== (string) $description
					? (string) $description
					: __( 'Something went wrong with Yappy. Please try again.', 'woocommerce-yappy' );
		}
	}
}
