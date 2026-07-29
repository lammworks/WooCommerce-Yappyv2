<?php
/**
 * Tests for the Yappy status vocabulary.
 *
 * @package WooCommerce_Yappy
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers WC_Yappy_Status
 */
class StatusTest extends TestCase {

	/**
	 * Only the four documented codes may reach the order state machine.
	 */
	public function test_only_documented_statuses_are_valid() {
		foreach ( array( 'E', 'R', 'C', 'X' ) as $code ) {
			$this->assertTrue( WC_Yappy_Status::is_valid( $code ), sprintf( '"%s" should be valid.', $code ) );
		}

		foreach ( array( '', 'e', 'Z', 'EE', '1', 'executed' ) as $code ) {
			$this->assertFalse( WC_Yappy_Status::is_valid( $code ), sprintf( '"%s" should not be valid.', $code ) );
		}
	}

	/**
	 * Every documented status has a label of its own.
	 */
	public function test_each_status_has_a_distinct_label() {
		$labels = array_map( array( WC_Yappy_Status::class, 'label' ), WC_Yappy_Status::all() );

		$this->assertCount( 4, array_unique( $labels ) );
		$this->assertNotContains( 'Unknown', $labels );
	}

	/**
	 * The codes a shop manager is most likely to hit get a specific explanation
	 * rather than the generic fallback.
	 */
	public function test_known_error_codes_get_a_specific_message() {
		$generic = WC_Yappy_Status::error_message( 'E002' );

		foreach ( array( 'E005', 'E007', 'E009', 'E010', 'E011' ) as $code ) {
			$this->assertNotSame(
				$generic,
				WC_Yappy_Status::error_message( $code ),
				sprintf( '"%s" should have its own message.', $code )
			);
		}
	}

	/**
	 * An unrecognised code falls back to Yappy's own description when there is
	 * one, and to a safe generic message otherwise.
	 */
	public function test_unknown_codes_fall_back_sensibly() {
		$this->assertSame( 'Algo pasó', WC_Yappy_Status::error_message( 'E999', 'Algo pasó' ) );
		$this->assertNotSame( '', WC_Yappy_Status::error_message( 'E999', '' ) );
	}
}
