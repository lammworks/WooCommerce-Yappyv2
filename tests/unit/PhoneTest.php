<?php
/**
 * Tests for phone number normalisation.
 *
 * @package WooCommerce_Yappy
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers WC_Yappy_Phone
 */
class PhoneTest extends TestCase {

	/**
	 * Customers type the same number in many shapes; all of them must reduce to
	 * the bare national number Yappy expects in `aliasYappy`.
	 */
	public function test_common_input_shapes_normalise_to_the_national_number() {
		$cases = array(
			'61234567'      => '61234567',
			'6123-4567'     => '61234567',
			'6123 4567'     => '61234567',
			'+507 6123 4567' => '61234567',
			'(507) 6123-4567' => '61234567',
			'50761234567'   => '61234567',
			'71234567'      => '71234567',
		);

		foreach ( $cases as $input => $expected ) {
			$this->assertSame( $expected, WC_Yappy_Phone::normalize( $input ), sprintf( 'Failed for "%s".', $input ) );
		}
	}

	/**
	 * Anything that is not a Panamanian mobile yields an empty string, which makes
	 * the caller fall back to the QR flow instead of sending a bad number.
	 */
	public function test_non_mobile_numbers_normalise_to_an_empty_string() {
		$cases = array(
			'',
			'   ',
			'abc',
			'2001234',      // Landline, seven digits.
			'21234567',     // Eight digits but not a mobile prefix.
			'612345',       // Too short.
			'6123456789',   // Too long.
			'+1 555 123 4567',
		);

		foreach ( $cases as $input ) {
			$this->assertSame( '', WC_Yappy_Phone::normalize( $input ), sprintf( 'Failed for "%s".', $input ) );
		}
	}

	/**
	 * The validator works on bare digits only.
	 */
	public function test_validity_check() {
		$this->assertTrue( WC_Yappy_Phone::is_valid( '61234567' ) );
		$this->assertTrue( WC_Yappy_Phone::is_valid( '71234567' ) );
		$this->assertFalse( WC_Yappy_Phone::is_valid( '81234567' ) );
		$this->assertFalse( WC_Yappy_Phone::is_valid( '6123456' ) );
		$this->assertFalse( WC_Yappy_Phone::is_valid( '6123-4567' ) );
	}
}
