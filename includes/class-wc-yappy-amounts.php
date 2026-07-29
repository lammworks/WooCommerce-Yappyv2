<?php
/**
 * Amount formatting for the Yappy create-order call.
 *
 * Yappy validates the arithmetic of the amounts it receives and answers with
 * error E010 when they do not add up. Every value travels as a string with
 * exactly two decimals, and the plugin guarantees the identity
 *
 *     total = subtotal + taxes - discount
 *
 * holds *after* rounding, by deriving the subtotal from the other three values
 * and absorbing any rounding residue there. That keeps the total Yappy charges
 * identical to the total WooCommerce recorded, which is the number that matters.
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
	 * Split a total into the four amounts of the create-order payload.
	 *
	 * @param float $total    Order grand total.
	 * @param float $taxes    Total tax included in the grand total.
	 * @param float $discount Total discount already applied to the grand total.
	 * @return array{total:string,subtotal:string,taxes:string,discount:string}
	 * @throws InvalidArgumentException When the total is below the Yappy minimum.
	 */
	public static function split( $total, $taxes = 0.0, $discount = 0.0 ) {
		$total    = round( (float) $total, 2 );
		$taxes    = round( max( 0.0, (float) $taxes ), 2 );
		$discount = round( max( 0.0, (float) $discount ), 2 );

		if ( $total < self::MINIMUM_TOTAL ) {
			throw new InvalidArgumentException( 'Yappy requires a total of at least 0.01.' );
		}

		// Taxes can never exceed the total; a larger value would force a negative
		// subtotal, which Yappy rejects.
		if ( $taxes > $total ) {
			$taxes = $total;
		}

		$subtotal = round( $total + $discount - $taxes, 2 );

		// A discount larger than the net amount would also push the subtotal
		// negative. Clamp the discount instead of shipping an invalid payload.
		if ( $subtotal < 0 ) {
			$discount = round( max( 0.0, $taxes - $total ), 2 );
			$subtotal = round( $total + $discount - $taxes, 2 );
		}

		// Absorb any residue left by rounding the three inputs independently, so
		// the identity holds exactly on the values actually transmitted.
		$residue = round( $total - ( $subtotal + $taxes - $discount ), 2 );
		if ( abs( $residue ) >= 0.005 ) {
			$subtotal = round( $subtotal + $residue, 2 );
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
