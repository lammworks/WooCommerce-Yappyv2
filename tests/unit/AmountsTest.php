<?php
/**
 * Tests for the amount set sent to Yappy.
 *
 * @package WooCommerce_Yappy
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers WC_Yappy_Amounts
 */
class AmountsTest extends TestCase {

	/**
	 * Assert the identity Yappy's E010 implies, on the transmitted strings.
	 *
	 * @param array $amounts Result of `WC_Yappy_Amounts::build()`.
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
	 * Assert every amount satisfies the documented minimum and format.
	 *
	 * @param array $amounts Result of `WC_Yappy_Amounts::build()`.
	 */
	private function assertDocumentedConstraints( array $amounts ) {
		foreach ( array( 'total', 'subtotal', 'taxes', 'discount' ) as $key ) {
			$this->assertMatchesRegularExpression( '/^\d+\.\d{2}$/', $amounts[ $key ], $key . ' must be formatted "0.00".' );
		}

		$this->assertGreaterThanOrEqual( 0.01, (float) $amounts['total'] );
		$this->assertGreaterThanOrEqual( 0.0, (float) $amounts['subtotal'] );
		$this->assertGreaterThanOrEqual( 0.0, (float) $amounts['taxes'] );
		$this->assertGreaterThanOrEqual( 0.0, (float) $amounts['discount'] );
	}

	/**
	 * The plain case: what Yappy receives is what the cart showed.
	 */
	public function test_amounts_map_straight_from_the_cart() {
		$amounts = WC_Yappy_Amounts::build( 25.00, 25.00 );

		$this->assertSame( '25.00', $amounts['total'] );
		$this->assertSame( '25.00', $amounts['subtotal'] );
		$this->assertSame( '0.00', $amounts['taxes'] );
		$this->assertSame( '0.00', $amounts['discount'] );
		$this->assertIdentityHolds( $amounts );
		$this->assertDocumentedConstraints( $amounts );
	}

	/**
	 * A taxed order sends the cart subtotal, not the subtotal minus tax.
	 */
	public function test_taxed_order_sends_the_cart_subtotal() {
		$amounts = WC_Yappy_Amounts::build( 107.00, 100.00, 7.00 );

		$this->assertSame( '107.00', $amounts['total'] );
		$this->assertSame( '100.00', $amounts['subtotal'] );
		$this->assertSame( '7.00', $amounts['taxes'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * A store that charges no tax sends 0.00, not an empty or negative value.
	 */
	public function test_absent_tax_is_sent_as_zero() {
		$amounts = WC_Yappy_Amounts::build( 40.00, 40.00, 0.0 );

		$this->assertSame( '0.00', $amounts['taxes'] );
		$this->assertSame( '40.00', $amounts['subtotal'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * A coupon shows up as the discount, with the subtotal still pre-discount.
	 */
	public function test_coupon_discount_is_reported_separately() {
		$amounts = WC_Yappy_Amounts::build( 90.00, 100.00, 0.0, 10.00 );

		$this->assertSame( '90.00', $amounts['total'] );
		$this->assertSame( '100.00', $amounts['subtotal'] );
		$this->assertSame( '10.00', $amounts['discount'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * Yappy has no shipping field, so shipping rides in the subtotal rather than
	 * silently disappearing from the amounts.
	 */
	public function test_shipping_is_folded_into_the_subtotal() {
		$amounts = WC_Yappy_Amounts::build( 105.00, 100.00, 0.0, 0.0, 5.00 );

		$this->assertSame( '105.00', $amounts['total'] );
		$this->assertSame( '105.00', $amounts['subtotal'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * A realistic order: items, a coupon, shipping and tax on the discounted sum.
	 */
	public function test_full_order_reconciles() {
		// 100.00 of items, 10.00 coupon, 5.00 shipping, 7% tax on 95.00 = 6.65.
		$amounts = WC_Yappy_Amounts::build( 101.65, 100.00, 6.65, 10.00, 5.00 );

		$this->assertSame( '101.65', $amounts['total'] );
		$this->assertSame( '105.00', $amounts['subtotal'] );
		$this->assertSame( '6.65', $amounts['taxes'] );
		$this->assertSame( '10.00', $amounts['discount'] );
		$this->assertIdentityHolds( $amounts );
		$this->assertDocumentedConstraints( $amounts );
	}

	/**
	 * Surcharges added as WooCommerce fees are treated like shipping.
	 */
	public function test_fees_are_folded_into_the_subtotal() {
		$amounts = WC_Yappy_Amounts::build( 52.50, 50.00, 0.0, 0.0, 2.50 );

		$this->assertSame( '52.50', $amounts['subtotal'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * The total transmitted must stay exactly what WooCommerce charged, even when
	 * the parts each round in a different direction.
	 */
	public function test_rounding_residue_never_moves_the_total() {
		$cases = array(
			array( 19.999, 19.999, 1.005, 0.0, 0.0 ),
			array( 33.333, 30.0, 3.333, 1.111, 1.111 ),
			array( 0.01, 0.01, 0.0, 0.0, 0.0 ),
			array( 12.345, 12.345, 0.005, 0.005, 0.0 ),
			array( 1999.995, 1900.0, 129.995, 49.995, 19.995 ),
		);

		foreach ( $cases as $case ) {
			list( $total, $subtotal, $taxes, $discount, $extras ) = $case;

			$amounts = WC_Yappy_Amounts::build( $total, $subtotal, $taxes, $discount, $extras );

			$this->assertSame( WC_Yappy_Amounts::format( round( $total, 2 ) ), $amounts['total'] );
			$this->assertIdentityHolds( $amounts );
			$this->assertDocumentedConstraints( $amounts );
		}
	}

	/**
	 * Negative inputs are treated as absent rather than shipped as-is: Yappy
	 * documents 0.00 as the minimum for taxes and discount.
	 */
	public function test_negative_taxes_and_discounts_are_floored_at_zero() {
		$amounts = WC_Yappy_Amounts::build( 50.00, 50.00, -5.00, -2.00 );

		$this->assertSame( '0.00', $amounts['taxes'] );
		$this->assertSame( '0.00', $amounts['discount'] );
		$this->assertSame( '50.00', $amounts['subtotal'] );
		$this->assertIdentityHolds( $amounts );
	}

	/**
	 * Inconsistent input must still yield a payload Yappy will accept, with the
	 * total left untouched.
	 */
	public function test_inconsistent_input_still_produces_a_valid_payload() {
		$amounts = WC_Yappy_Amounts::build( 10.00, 0.0, 25.00, 0.0 );

		$this->assertSame( '10.00', $amounts['total'] );
		$this->assertIdentityHolds( $amounts );
		$this->assertDocumentedConstraints( $amounts );
	}

	/**
	 * Yappy will not take an order below one cent.
	 */
	public function test_total_below_the_minimum_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		WC_Yappy_Amounts::build( 0.00, 0.00 );
	}
}
