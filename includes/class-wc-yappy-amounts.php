<?php
/**
 * Amount formatting for the Yappy create-order call.
 *
 * The four amounts Yappy accepts map onto WooCommerce's own figures:
 *
 *     subtotal  <- the cart subtotal (line items, before tax and before coupons)
 *     taxes     <- the order tax, or 0.00 when the store charges none
 *     discount  <- the coupon discount, or 0.00 when none applies
 *     total     <- the order total, exactly as WooCommerce recorded it
 *
 * Yappy's payload has no field for shipping or fees, so those ride along in the
 * subtotal — otherwise the amounts would not add up to the total being charged.
 * On a store that does not charge either, the subtotal transmitted is precisely
 * the cart subtotal.
 *
 * Every value travels as a string with exactly two decimals, and the identity
 *
 *     total = subtotal + taxes - discount
 *
 * is guaranteed to hold *after* rounding: any residue left by rounding the parts
 * independently is absorbed into the subtotal, never into the total. The total
 * Yappy charges therefore always equals the total WooCommerce recorded.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns WooCommerce order totals into the amount set Yappy expects.
 */
class WC_Yappy_Amounts {

	/**
	 * Smallest total Yappy accepts.
	 */
	const MINIMUM_TOTAL = 0.01;

	/**
	 * Build the four amounts of the create-order payload.
	 *
	 * @param float $total    Order grand total — the amount actually charged.
	 * @param float $subtotal Cart subtotal: line items before tax and before coupons.
	 * @param float $taxes    Order tax. Pass 0 when the store charges no tax.
	 * @param float $discount Coupon discount. Pass 0 when no coupon applies.
	 * @param float $extras   Amounts Yappy has no field for — shipping and fees.
	 * @return array{total:string,subtotal:string,taxes:string,discount:string}
	 * @throws InvalidArgumentException When the total is below the Yappy minimum.
	 */
	public static function build( $total, $subtotal, $taxes = 0.0, $discount = 0.0, $extras = 0.0 ) {
		$total    = round( (float) $total, 2 );
		$subtotal = round( (float) $subtotal, 2 );
		$taxes    = round( max( 0.0, (float) $taxes ), 2 );
		$discount = round( max( 0.0, (float) $discount ), 2 );
		$extras   = round( (float) $extras, 2 );

		if ( $total < self::MINIMUM_TOTAL ) {
			throw new InvalidArgumentException( 'Yappy requires a total of at least 0.01.' );
		}

		// Tax can never exceed the total; a larger figure would only be possible
		// from inconsistent input, and it would force a negative subtotal.
		if ( $taxes > $total ) {
			$taxes = $total;
		}

		// Shipping and surcharges have nowhere else to go in the Yappy payload.
		$subtotal = round( $subtotal + $extras, 2 );

		// Absorb whatever the rounded parts still fail to account for, so the
		// identity holds exactly on the strings that are transmitted.
		$residue = round( $total - ( $subtotal + $taxes - $discount ), 2 );
		if ( abs( $residue ) >= 0.005 ) {
			$subtotal = round( $subtotal + $residue, 2 );
		}

		// Only reachable from internally inconsistent input. Keep the total and
		// the identity intact by letting the discount take the difference.
		if ( $subtotal < 0 ) {
			$discount = round( $discount - $subtotal, 2 );
			$subtotal = 0.0;
		}

		return array(
			'total'    => self::format( $total ),
			'subtotal' => self::format( $subtotal ),
			'taxes'    => self::format( $taxes ),
			'discount' => self::format( $discount ),
		);
	}

	/**
	 * Format a single amount the way Yappy expects it.
	 *
	 * @param float $amount Amount to format.
	 * @return string Amount with exactly two decimals and no thousands separator.
	 */
	public static function format( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
