<?php
/**
 * Tests for IPN signature verification.
 *
 * This is the security boundary of the plugin: anything that passes here is
 * treated as a genuine instruction from Yappy to mark an order paid.
 *
 * @package WooCommerce_Yappy
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers WC_Yappy_Signature
 */
class SignatureTest extends TestCase {

	/**
	 * The HMAC key half of the fixture secret.
	 */
	const HMAC_KEY = 'hash-key-part';

	/**
	 * The half of the fixture secret that is not the HMAC key.
	 */
	const API_KEY = 'api-key-part';

	/**
	 * A secret shaped like the one Yappy Comercial issues: base64 of two
	 * dot-separated parts, of which only the first is the HMAC key.
	 *
	 * Assembled at run time rather than stored as a base64 literal — an encoded
	 * blob in a fixture is indistinguishable from a leaked credential to a secret
	 * scanner, and this one is worth nothing.
	 *
	 * @return string
	 */
	private static function secret() {
		return base64_encode( self::HMAC_KEY . '.' . self::API_KEY );
	}

	/**
	 * The HMAC key is the first dot-separated part of the decoded secret.
	 */
	public function test_key_extraction_takes_the_first_part_only() {
		$this->assertSame( self::HMAC_KEY, WC_Yappy_Signature::extract_key( self::secret() ) );
	}

	/**
	 * A secret that is not usable must yield no key rather than a wrong one.
	 */
	public function test_unusable_secrets_yield_no_key() {
		$this->assertSame( '', WC_Yappy_Signature::extract_key( '' ) );
		$this->assertSame( '', WC_Yappy_Signature::extract_key( '   ' ) );
		$this->assertSame( '', WC_Yappy_Signature::extract_key( 'not valid base64 !!!' ) );
	}

	/**
	 * Build a correctly signed notification.
	 *
	 * @param string $order_id Reference.
	 * @param string $status   Status code.
	 * @param string $domain   Registered domain.
	 * @return array
	 */
	private function signed( $order_id, $status, $domain ) {
		return array(
			'orderId' => $order_id,
			'status'  => $status,
			'domain'  => $domain,
			'hash'    => hash_hmac( 'sha256', $order_id . $status . $domain, self::HMAC_KEY ),
		);
	}

	/**
	 * The happy path.
	 */
	public function test_a_correctly_signed_notification_is_accepted() {
		$params = $this->signed( 'A3KX9MZQ1BPRY7W', 'E', 'https://store.com' );

		$this->assertTrue( WC_Yappy_Signature::verify( $params, self::secret() ) );
	}

	/**
	 * Yappy sends the digest in hex; case must not decide authenticity.
	 */
	public function test_uppercase_hash_is_accepted() {
		$params         = $this->signed( 'A3KX9MZQ1BPRY7W', 'E', 'https://store.com' );
		$params['hash'] = strtoupper( $params['hash'] );

		$this->assertTrue( WC_Yappy_Signature::verify( $params, self::secret() ) );
	}

	/**
	 * Every signed field is covered by the HMAC, so tampering with any of them
	 * has to invalidate the notification.
	 */
	public function test_tampering_with_any_signed_field_is_rejected() {
		$base = $this->signed( 'A3KX9MZQ1BPRY7W', 'R', 'https://store.com' );

		$tampered = $base;
		$tampered['status'] = 'E';
		$this->assertFalse( WC_Yappy_Signature::verify( $tampered, self::secret() ), 'A forged status must be rejected.' );

		$tampered = $base;
		$tampered['orderId'] = 'B3KX9MZQ1BPRY7W';
		$this->assertFalse( WC_Yappy_Signature::verify( $tampered, self::secret() ), 'A forged reference must be rejected.' );

		$tampered = $base;
		$tampered['domain'] = 'https://attacker.com';
		$this->assertFalse( WC_Yappy_Signature::verify( $tampered, self::secret() ), 'A forged domain must be rejected.' );

		$tampered = $base;
		$tampered['hash'] = str_repeat( '0', 64 );
		$this->assertFalse( WC_Yappy_Signature::verify( $tampered, self::secret() ), 'A forged hash must be rejected.' );
	}

	/**
	 * A notification signed with a different secret belongs to a different store.
	 */
	public function test_a_notification_signed_with_another_secret_is_rejected() {
		$params = $this->signed( 'A3KX9MZQ1BPRY7W', 'E', 'https://store.com' );

		$this->assertFalse( WC_Yappy_Signature::verify( $params, base64_encode( 'other-key.api-key' ) ) );
	}

	/**
	 * An incomplete payload cannot be authenticated, so it must not be trusted.
	 */
	public function test_incomplete_payloads_are_rejected() {
		$complete = $this->signed( 'A3KX9MZQ1BPRY7W', 'E', 'https://store.com' );

		foreach ( array( 'orderId', 'status', 'domain', 'hash' ) as $missing ) {
			$params = $complete;
			unset( $params[ $missing ] );

			$this->assertFalse(
				WC_Yappy_Signature::verify( $params, self::secret() ),
				sprintf( 'A notification without "%s" must be rejected.', $missing )
			);
		}
	}

	/**
	 * With no usable secret nothing can be verified, so nothing may be accepted.
	 */
	public function test_verification_fails_when_the_secret_is_unusable() {
		$params = $this->signed( 'A3KX9MZQ1BPRY7W', 'E', 'https://store.com' );

		$this->assertFalse( WC_Yappy_Signature::verify( $params, '' ) );
		$this->assertFalse( WC_Yappy_Signature::verify( $params, 'not valid base64 !!!' ) );
	}

	/**
	 * An empty hash must never match, whatever the key.
	 */
	public function test_empty_hash_is_rejected() {
		$params         = $this->signed( 'A3KX9MZQ1BPRY7W', 'E', 'https://store.com' );
		$params['hash'] = '';

		$this->assertFalse( WC_Yappy_Signature::verify( $params, self::secret() ) );
	}
}
