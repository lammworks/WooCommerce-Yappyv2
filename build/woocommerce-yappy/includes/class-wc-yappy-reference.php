<?php
/**
 * Yappy order reference codec.
 *
 * Yappy requires `orderId` to be alphanumeric, at most 15 characters and unique
 * for every transaction (a reused value is rejected with error E007). WooCommerce
 * order IDs are neither unique per payment attempt — a customer may retry — nor
 * guaranteed to stay short forever, so the plugin sends a derived reference.
 *
 * Layout (exactly 15 characters, `[0-9A-Z]`):
 *
 *     AAAAAAAAA BBBBBB
 *     |         `- 6 random characters, fresh on every payment attempt
 *     `- 9 characters: the WooCommerce order ID in base 36, zero padded
 *
 * Encoding the order ID in the reference lets the IPN handler resolve the order
 * without a meta query, which keeps the webhook fast and storage agnostic (it
 * works identically on HPOS and on legacy post storage).
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and parses the 15 character reference sent to Yappy as `orderId`.
 */
class WC_Yappy_Reference {

	/**
	 * Total length of the reference. Yappy rejects anything longer with E009.
	 */
	const LENGTH = 15;

	/**
	 * Characters used for the base 36 order ID prefix.
	 */
	const PREFIX_LENGTH = 9;

	/**
	 * Characters of per-attempt entropy appended to the prefix.
	 */
	const SUFFIX_LENGTH = 6;

	/**
	 * Alphabet used for the random suffix.
	 */
	const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	/**
	 * Build a fresh reference for a WooCommerce order.
	 *
	 * Two calls for the same order return different values on purpose: each
	 * payment attempt needs its own `orderId` or Yappy answers with E007.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string 15 character alphanumeric reference.
	 * @throws InvalidArgumentException When the order ID cannot be encoded in the prefix.
	 */
	public static function generate( $order_id ) {
		$prefix = self::encode_order_id( $order_id );

		$suffix   = '';
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		for ( $i = 0; $i < self::SUFFIX_LENGTH; $i++ ) {
			$suffix .= $alphabet[ random_int( 0, $max ) ];
		}

		return $prefix . $suffix;
	}

	/**
	 * Encode a WooCommerce order ID into the fixed width base 36 prefix.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 * @throws InvalidArgumentException When the order ID is not a positive integer that fits the prefix.
	 */
	public static function encode_order_id( $order_id ) {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 ) {
			throw new InvalidArgumentException( 'Order ID must be a positive integer.' );
		}

		$encoded = strtoupper( base_convert( (string) $order_id, 10, 36 ) );

		if ( strlen( $encoded ) > self::PREFIX_LENGTH ) {
			throw new InvalidArgumentException( 'Order ID is too large to encode into a Yappy reference.' );
		}

		return str_pad( $encoded, self::PREFIX_LENGTH, '0', STR_PAD_LEFT );
	}

	/**
	 * Recover the WooCommerce order ID from a reference.
	 *
	 * @param string $reference Reference previously produced by `generate()`.
	 * @return int WooCommerce order ID, or 0 when the reference is malformed.
	 */
	public static function to_order_id( $reference ) {
		if ( ! self::is_valid( $reference ) ) {
			return 0;
		}

		$prefix = substr( (string) $reference, 0, self::PREFIX_LENGTH );

		return (int) base_convert( strtolower( $prefix ), 36, 10 );
	}

	/**
	 * Whether a string has the shape of a reference produced by this plugin.
	 *
	 * @param string $reference Candidate reference.
	 * @return bool
	 */
	public static function is_valid( $reference ) {
		if ( ! is_string( $reference ) ) {
			return false;
		}

		return 1 === preg_match( '/^[0-9A-Z]{' . self::LENGTH . '}$/', $reference );
	}
}
