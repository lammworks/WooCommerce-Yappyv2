<?php
/**
 * IPN signature verification.
 *
 * Yappy signs every IPN notification with an HMAC-SHA256 of
 * `orderId . status . domain`. The key is not the secret key as stored: the
 * secret issued by Yappy Comercial is base64, and once decoded it holds two
 * dot-separated parts. Only the first part is the HMAC key.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the authenticity of Yappy IPN callbacks.
 */
class WC_Yappy_Signature {

	/**
	 * Hash algorithm used by Yappy.
	 */
	const ALGORITHM = 'sha256';

	/**
	 * Extract the HMAC key from the merchant secret key.
	 *
	 * @param string $secret_key Secret key exactly as issued by Yappy Comercial (base64).
	 * @return string HMAC key, or an empty string when the secret cannot be decoded.
	 */
	public static function extract_key( $secret_key ) {
		$secret_key = trim( (string) $secret_key );

		if ( '' === $secret_key ) {
			return '';
		}

		$decoded = base64_decode( $secret_key, true );

		if ( false === $decoded || '' === $decoded ) {
			return '';
		}

		$parts = explode( '.', $decoded );

		return isset( $parts[0] ) ? $parts[0] : '';
	}

	/**
	 * Compute the expected signature for an IPN payload.
	 *
	 * @param string $order_id   The `orderId` Yappy sent back.
	 * @param string $status     The `status` Yappy sent back.
	 * @param string $domain     The `domain` Yappy sent back.
	 * @param string $secret_key Merchant secret key (base64, as issued).
	 * @return string Lowercase hex digest, or an empty string when the key is unusable.
	 */
	public static function calculate( $order_id, $status, $domain, $secret_key ) {
		$key = self::extract_key( $secret_key );

		if ( '' === $key ) {
			return '';
		}

		return hash_hmac( self::ALGORITHM, (string) $order_id . (string) $status . (string) $domain, $key );
	}

	/**
	 * Verify the `hash` query parameter of an IPN request.
	 *
	 * The comparison is constant time, and every field is required: a request
	 * missing any of them cannot be authenticated and is rejected.
	 *
	 * @param array  $params     IPN parameters, expected keys: orderId, status, domain, hash.
	 * @param string $secret_key Merchant secret key (base64, as issued).
	 * @return bool
	 */
	public static function verify( array $params, $secret_key ) {
		$order_id = isset( $params['orderId'] ) ? (string) $params['orderId'] : '';
		$status   = isset( $params['status'] ) ? (string) $params['status'] : '';
		$domain   = isset( $params['domain'] ) ? (string) $params['domain'] : '';
		$provided = isset( $params['hash'] ) ? strtolower( trim( (string) $params['hash'] ) ) : '';

		if ( '' === $order_id || '' === $status || '' === $domain || '' === $provided ) {
			return false;
		}

		$expected = self::calculate( $order_id, $status, $domain, $secret_key );

		if ( '' === $expected ) {
			return false;
		}

		return hash_equals( $expected, $provided );
	}
}
