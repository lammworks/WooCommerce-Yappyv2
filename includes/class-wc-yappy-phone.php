<?php
/**
 * Panamanian mobile number normalisation.
 *
 * Yappy expects `aliasYappy` as the bare 8 digit national number. Customers type
 * it every possible way — with the +507 country code, with dashes, with spaces —
 * so the value is normalised before it reaches the API. When a number cannot be
 * normalised the plugin sends nothing at all, and the Yappy button falls back to
 * its QR code flow rather than failing the payment.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalises and validates Yappy phone numbers.
 */
class WC_Yappy_Phone {

	/**
	 * Panamanian country calling code.
	 */
	const COUNTRY_CODE = '507';

	/**
	 * Length of a Panamanian mobile number.
	 */
	const LENGTH = 8;

	/**
	 * Normalise a phone number to the 8 digit form Yappy expects.
	 *
	 * @param string $phone Raw input.
	 * @return string The normalised number, or an empty string when it is not a valid Panamanian mobile.
	 */
	public static function normalize( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );

		if ( null === $digits || '' === $digits ) {
			return '';
		}

		// Drop the country code when the customer included it, with or without a
		// leading zero or plus sign (both are already stripped above).
		if ( strlen( $digits ) > self::LENGTH && 0 === strpos( $digits, self::COUNTRY_CODE ) ) {
			$digits = substr( $digits, strlen( self::COUNTRY_CODE ) );
		}

		if ( ! self::is_valid( $digits ) ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Whether a string is a bare Panamanian mobile number.
	 *
	 * Mobile lines in Panama are 8 digits and begin with 6; the 7 range is also
	 * in use, so both are accepted.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	public static function is_valid( $digits ) {
		return 1 === preg_match( '/^[67]\d{7}$/', (string) $digits );
	}
}
