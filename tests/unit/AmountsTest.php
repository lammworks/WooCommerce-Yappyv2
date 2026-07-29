<?php
/**
 * Tests for the amount split sent to Yappy.
 *
 * @package WooCommerce_Yappy
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers WC_Yappy_Amounts
 */
class AmountsTest extends TestCase {

	/**
	 * Assert the identity Yappy validates (error E010 otherwise).
	 *
	 * @param array $amounts Result of `WC_Yappy_Amounts::split()`.
	 */
	private function assertIdentityHolds( array $amounts ) {
		$computed = (float) $amounts['subtotal'] + (float) $amounts['taxes'] - (float) $amounts['discount'];

		$this->assertSame(
			$amounts['total'],
			WC_Yappy_Amounts::format( $computed ),
			'total must equal subtotal + taxes - discount on the transmitted values.'
		);
	}

	/**
	 * Every amount travels as a string with exactly two decimals.
	 */
	public function test_amounts_are_formatted_with_two_decimals() {
		$amounts = WC_Yappy_Amounts::split( 25, 0, 0 );

		$this->assertSame( '25.00', $amounts['total'] );
		$this->assertSame( '25.00', $amounts['subtotal'] );
		$this->assertSame( '0.00', $amounts['taxes'] );
		$this->assertSame( '0.00', $amounts['discount'] );
	}

	/**
	 * A plain taxed order.
	 */
	public function test_subtotal_is_derived_from_total_and_taxes() {
		$amounts = WC_Yappy_Amounts::split( 107.00, 7.00, 0.00 );

		$this->assertSame( '107.00', $amounts['total'] );
		$this->assertSame( '100.00', $amounts['subtotal'] );
		$this->assertSame( '7.00', $amounts['taxes'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * A discounted order.
	 */
	public function test_discount_is_accounted_for() {
		$amounts = WC_Yappy_Amounts::split( 90.00, 0.00, 10.00 );

		$this->assertSame( '90.00', $amounts['total'] );
		$this->assertSame( '100.00', $amounts['subtotal'] );
		$this->assertSame( '10.00', $amounts['discount'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * The transmitted total must stay exactly what WooCommerce charged, even when
	 * the inputs each round in a different direction.
	 */
	public function test_rounding_residue_never_moves_the_total() {
		$cases = array(
			array( 19.999, 1.005, 0.0 ),
			array( 33.333, 3.333, 1.111 ),
			array( 0.01, 0.0, 0.0 ),
			array( 12.345, 0.005, 0.005 ),
			array( 1999.995, 129.995, 49.995 ),
		);

		foreach ( $cases as $case ) {
			list( $total, $taxes, $discount ) = $case;

			$amounts = WC_Yappy_Amounts::split( $total, $taxes, $discount );

			$this->assertSame( WC_Yappy_Amounts::format( round( $total, 2 ) ), $amounts['total'] );
			$this->assertIdentityHolds( $amounts );
		}
	}

	/**
	 * A tax figure larger than the total would force a negative subtotal, which
	 * Yappy rejects. It is clamped instead.
	 */
	public function test_taxes_above_the_total_are_clamped() {
		$amounts = WC_Yappy_Amounts::split( 10.00, 25.00, 0.00 );

		$this->assertSame( '10.00', $amounts['total'] );
		$this->assertGreaterThanOrEqual( 0.0, (float) $amounts['subtotal'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * Negative inputs are treated as absent rather than shipped as-is.
	 */
	public function test_negative_taxes_and_discounts_are_floored_at_zero() {
		$amounts = WC_Yappy_Amounts::split( 50.00, -5.00, -2.00 );

		$this->assertSame( '0.00', $amounts['taxes'] );
		$this->assertSame( '0.00', $amounts['discount'] );
		$this->assertSame( '50.00', $amounts['subtotal'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * Yappy will not take an order below one cent.
	 */
	public function test_total_below_the_minimum_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		WC_Yappy_Amounts::split( 0.00, 0.00, 0.00 );
	}
}
