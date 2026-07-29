<?php
/**
 * Tests for the Yappy order reference codec.
 *
 * @package WooCommerce_Yappy
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers WC_Yappy_Reference
 */
class ReferenceTest extends TestCase {

	/**
	 * References must satisfy the constraints Yappy enforces: alphanumeric and
	 * no longer than 15 characters (errors E009 / E100 otherwise).
	 */
	public function test_generated_reference_matches_the_yappy_constraints() {
		foreach ( array( 1, 42, 1234, 999999, 2147483647 ) as $order_id ) {
			$reference = WC_Yappy_Reference::generate( $order_id );

			$this->assertSame( 15, strlen( $reference ), 'Reference must be exactly 15 characters.' );
			$this->assertMatchesRegularExpression( '/^[0-9A-Z]{15}$/', $reference );
			$this->assertTrue( WC_Yappy_Reference::is_valid( $reference ) );
		}
	}

	/**
	 * The IPN resolves the order straight from the reference, so the round trip
	 * has to be exact.
	 */
	public function test_reference_round_trips_to_the_order_id() {
		foreach ( array( 1, 7, 100, 65535, 1000000, 2147483647 ) as $order_id ) {
			$reference = WC_Yappy_Reference::generate( $order_id );

			$this->assertSame( $order_id, WC_Yappy_Reference::to_order_id( $reference ) );
		}
	}

	/**
	 * Yappy rejects a reused orderId with E007, so retries must never collide.
	 */
	public function test_each_attempt_produces_a_distinct_reference() {
		$seen = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$seen[] = WC_Yappy_Reference::generate( 1234 );
		}

		$this->assertCount( 200, array_unique( $seen ), 'References for the same order must not repeat.' );
	}

	/**
	 * Every attempt for one order still resolves back to that order.
	 */
	public function test_distinct_references_resolve_to_the_same_order() {
		$first  = WC_Yappy_Reference::generate( 4321 );
		$second = WC_Yappy_Reference::generate( 4321 );

		$this->assertNotSame( $first, $second );
		$this->assertSame( 4321, WC_Yappy_Reference::to_order_id( $first ) );
		$this->assertSame( 4321, WC_Yappy_Reference::to_order_id( $second ) );
	}

	/**
	 * Anything that is not one of our references must not resolve to an order.
	 */
	public function test_malformed_references_do_not_resolve() {
		$this->assertSame( 0, WC_Yappy_Reference::to_order_id( '' ) );
		$this->assertSame( 0, WC_Yappy_Reference::to_order_id( 'too-short' ) );
		$this->assertSame( 0, WC_Yappy_Reference::to_order_id( 'lowercase12345' ) );
		$this->assertSame( 0, WC_Yappy_Reference::to_order_id( 'WAY-TOO-LONG-TO-BE-VALID' ) );
		$this->assertSame( 0, WC_Yappy_Reference::to_order_id( 'ABC!@#DEF123456' ) );
	}

	/**
	 * Invalid order IDs are a programming error, not something to paper over.
	 */
	public function test_zero_order_id_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		WC_Yappy_Reference::generate( 0 );
	}

	/**
	 * The prefix has a fixed width; an order ID beyond it cannot be encoded.
	 */
	public function test_order_id_beyond_the_prefix_capacity_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		// 36^9 is the first value that no longer fits in nine base 36 digits.
		WC_Yappy_Reference::encode_order_id( 101559956668416 );
	}
}
