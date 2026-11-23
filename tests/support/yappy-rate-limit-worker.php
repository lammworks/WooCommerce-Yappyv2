<?php
/**
 * One create-payment request in its own process.
 *
 * admin-ajax.php boots WordPress per request, so counters that live only in
 * process memory do not carry to the next call. The parent test starts several
 * of these at once.
 *
 * @package WooCommerce_Yappy
 */

require __DIR__ . '/yappy-wordpress-harness.php';

if ( $argc < 2 ) {
	fwrite( STDERR, "missing job\n" );
	exit( 2 );
}

$job = json_decode( $argv[1], true );
if ( ! is_array( $job ) ) {
	fwrite( STDERR, "bad job\n" );
	exit( 2 );
}

$store = getenv( 'YAPPY_TEST_STORE' );
if ( ! is_string( $store ) || '' === $store ) {
	fwrite( STDERR, "missing store\n" );
	exit( 2 );
}

Yappy_Test_Store::attach( $store );
yappy_test_client_address( isset( $job['address'] ) ? (string) $job['address'] : '203.0.113.10' );

try {
	if ( 'process_payment' === $job['mode'] ) {
		$_POST = array(
			'wc_yappy_inline' => '1',
			'wc-yappy-phone'  => (string) $job['phone'],
		);
		$result  = wc_yappy_get_gateway()->process_payment( (int) $job['order_id'] );
		$success = is_array( $result ) && isset( $result['result'] ) && 'success' === $result['result'];
	} else {
		$response = yappy_test_dispatch_ajax(
			array(
				'action'    => 'wc_yappy_create_order',
				'order_id'  => (string) $job['order_id'],
				'order_key' => (string) $job['order_key'],
				'nonce'     => (string) $job['nonce'],
				'phone'     => (string) $job['phone'],
			),
			isset( $job['branch'] ) ? (string) $job['branch'] : 'nopriv'
		);
		$success  = ! empty( $response->payload['success'] );
	}
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}

echo $success ? "ok\n" : "refused\n";
exit( 0 );
