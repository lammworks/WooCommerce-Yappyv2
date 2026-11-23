<?php
/**
 * Rate limit on Yappy payment creation.
 *
 * A guest who has just checked out holds the order id, the order key, and a
 * WordPress nonce. The nonce stays valid across repeats (WordPress 6.7.1
 * wp_verify_nonce() does not consume it), and admin-ajax.php will keep
 * dispatching wc_yappy_create_order. These tests are the cap that has to stop
 * that loop: they fail while every repeat still creates a Yappy payment.
 *
 * Limits exercised here:
 * - At most 3 Yappy payment creations for one WooCommerce order.
 * - At most 5 Yappy payment creations for one client address (REMOTE_ADDR)
 *   across orders, counting a burst of concurrent requests.
 * The reservation has to be visible to other requests before the outbound
 * Yappy call. An in-process cache does not count: each admin-ajax.php request
 * is a new process, and the default object cache is not shared.
 *
 * @package WooCommerce_Yappy
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers WC_Yappy_Ajax
 * @covers WC_Yappy_Gateway
 */
class CreatePaymentRateLimitTest extends TestCase {

	const MAX_CREATES_PER_ORDER   = 3;
	const MAX_CREATES_PER_ADDRESS = 5;
	const BURST                   = 8;
	const BILLING_PHONE           = '60000000';
	const CLIENT_ADDRESS          = '203.0.113.10';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/support/yappy-wordpress-harness.php';
		Yappy_Test_Store::create();
		Yappy_Test_Http::reset();
		$GLOBALS['yappy_test_cache']    = array();
		$GLOBALS['yappy_test_reenter']  = null;
		putenv( 'YAPPY_TEST_REENTER' );
		putenv( 'YAPPY_TEST_RACE_WINDOW' );
		putenv( 'YAPPY_TEST_PARTICIPANTS' );
		yappy_test_client_address( self::CLIENT_ADDRESS );
		yappy_test_boot_ajax();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['yappy_test_reenter'] = null;
		putenv( 'YAPPY_TEST_REENTER' );
		putenv( 'YAPPY_TEST_RACE_WINDOW' );
		putenv( 'YAPPY_TEST_PARTICIPANTS' );
		Yappy_Test_Store::destroy();
	}

	/**
	 * The same nonce and order key that checkout handed the buyer open a new
	 * Yappy payment on every post, each pushed to a phone the buyer types.
	 */
	public function test_repeated_guest_create_order_stops_at_the_per_order_cap() {
		$order = $this->seed( 1001 );
		$responses = array();
		for ( $i = 0; $i < self::BURST; $i++ ) {
			$responses[] = $this->guest_create( $order, $this->phone( $i ) );
		}

		$creations = Yappy_Test_Http::payment_creations();
		$this->assertLessThanOrEqual(
			self::MAX_CREATES_PER_ORDER,
			count( $creations ),
			'Each repeat posted wc_yappy_create_order and Yappy was asked to open another payment.'
		);
		$this->assertGreaterThanOrEqual( 1, count( $creations ), 'The first create must still reach Yappy.' );
		$this->assertTrue( $responses[0]->payload['success'] );
		$this->assertNotContains( self::BILLING_PHONE, $this->pushed_phones( $creations ) );
		$this->assertContains( $this->phone( 0 ), $this->pushed_phones( $creations ) );
		$this->assertSame(
			$GLOBALS['yappy_test_actions']['wp_ajax_wc_yappy_create_order'],
			$GLOBALS['yappy_test_actions']['wp_ajax_nopriv_wc_yappy_create_order'],
			'The logged-in and guest hooks have to share the capped handler.'
		);
	}

	/**
	 * Requests that are already inside the Yappy call must not each be allowed
	 * to create a payment. The counter has to be stored before that call.
	 */
	public function test_creates_that_overlap_the_outbound_call_stop_at_the_cap() {
		$order = $this->seed( 1002 );
		putenv( 'YAPPY_TEST_REENTER=1' );
		$GLOBALS['yappy_test_reenter'] = function () use ( $order ) {
			for ( $i = 1; $i < self::BURST; $i++ ) {
				$this->guest_create( $order, $this->phone( $i ) );
			}
		};

		$this->guest_create( $order, $this->phone( 0 ) );

		$this->assertLessThanOrEqual(
			self::MAX_CREATES_PER_ORDER,
			count( Yappy_Test_Http::payment_creations() ),
			'Overlapping create-order requests all reached Yappy.'
		);
		$this->assertGreaterThanOrEqual( 1, count( Yappy_Test_Http::payment_creations() ) );
	}

	/**
	 * Separate PHP requests for one order, started together.
	 */
	public function test_parallel_guest_requests_for_one_order_stop_at_the_cap() {
		$order = $this->seed( 1003 );
		$jobs  = array();
		for ( $i = 0; $i < self::BURST; $i++ ) {
			$jobs[] = $this->job( 'ajax', $order, $this->phone( $i ) );
		}
		$this->run_workers( $jobs );

		$creations = Yappy_Test_Http::payment_creations();
		$this->assertLessThanOrEqual( self::MAX_CREATES_PER_ORDER, count( $creations ) );
		$this->assertGreaterThanOrEqual( 1, count( $creations ) );
		foreach ( $this->pushed_phones( $creations ) as $phone ) {
			$this->assertNotSame( self::BILLING_PHONE, $phone );
		}
	}

	/**
	 * Classic inline checkout calls create_yappy_order() from process_payment()
	 * on every submit, including a repeat pay-for-order post.
	 */
	public function test_parallel_inline_process_payment_stops_at_the_cap() {
		$order = $this->seed( 1004 );
		$jobs  = array();
		for ( $i = 0; $i < self::BURST; $i++ ) {
			$jobs[] = $this->job( 'process_payment', $order, $this->phone( $i ) );
		}
		$this->run_workers( $jobs );

		$this->assertLessThanOrEqual(
			self::MAX_CREATES_PER_ORDER,
			count( Yappy_Test_Http::payment_creations() )
		);
		$this->assertGreaterThanOrEqual( 1, count( Yappy_Test_Http::payment_creations() ) );
	}

	/**
	 * A new WooCommerce order must not reset the per-address limit.
	 */
	public function test_one_address_cannot_create_payments_for_unlimited_orders() {
		$jobs = array();
		for ( $i = 0; $i < self::BURST; $i++ ) {
			$order  = $this->seed( 2000 + $i );
			$jobs[] = $this->job( 'ajax', $order, $this->phone( $i ), self::CLIENT_ADDRESS );
		}
		$this->run_workers( $jobs );

		$this->assertLessThanOrEqual(
			self::MAX_CREATES_PER_ADDRESS,
			count( Yappy_Test_Http::payment_creations() ),
			'One client address opened a Yappy payment for every order.'
		);
		$this->assertGreaterThanOrEqual( 1, count( Yappy_Test_Http::payment_creations() ) );
	}

	/**
	 * The order key is what authorises the guest. A different key must not
	 * create a payment.
	 */
	public function test_wrong_order_key_does_not_create_a_payment() {
		$order          = $this->seed( 1005 );
		$order['key']   = 'wc_order_wrong';
		$response       = $this->guest_create( $order, $this->phone( 0 ) );

		$this->assertFalse( $response->payload['success'] );
		$this->assertSame( 404, $response->status );
		$this->assertCount( 0, Yappy_Test_Http::payment_creations() );
	}

	/**
	 * A creation that is still allowed pushes the phone from the request, and
	 * an executed IPN for that reference marks the WooCommerce order paid.
	 */
	public function test_allowed_create_pushes_the_request_phone_and_ipn_marks_the_order_paid() {
		$order    = $this->seed( 1006 );
		$phone    = $this->phone( 0 );
		$response = $this->guest_create( $order, $phone );

		$this->assertTrue( $response->payload['success'] );
		$creations = Yappy_Test_Http::payment_creations();
		$this->assertCount( 1, $creations );
		$this->assertSame( $phone, $creations[0]['body']['aliasYappy'] );

		$stored    = wc_get_order( $order['id'] );
		$reference = (string) $stored->get_meta( WC_Yappy_Gateway::META_REFERENCE );
		$this->assertSame( $reference, $creations[0]['body']['orderId'] );

		$gateway = wc_yappy_get_gateway();
		$domain  = 'https://store.test';
		$status  = WC_Yappy_Status::EXECUTED;
		$params  = array(
			'orderId'            => $reference,
			'status'             => $status,
			'domain'             => $domain,
			'hash'               => WC_Yappy_Signature::calculate( $reference, $status, $domain, $gateway->get_secret_key() ),
			'confirmationNumber' => 'confirmation-placeholder',
		);
		$method  = new ReflectionMethod( WC_Yappy_IPN_Handler::class, 'process' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $params, 'GET' );

		$this->assertSame( 200, $result['code'] );
		$this->assertTrue( wc_get_order( $order['id'] )->is_paid() );
	}

	/**
	 * @param int $id Order id.
	 * @return array{id:int,key:string,nonce:string}
	 */
	private function seed( $id ) {
		$key   = 'wc_order_' . $id;
		$nonce = wp_create_nonce( 'wc_yappy_pay_' . $id );
		Yappy_Test_Store::seed_order( $id, $key, self::BILLING_PHONE );
		return array(
			'id'    => $id,
			'key'   => $key,
			'nonce' => $nonce,
		);
	}

	/**
	 * @param int $index Index.
	 * @return string
	 */
	private function phone( $index ) {
		return '61' . str_pad( (string) ( $index + 1 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * @param array  $order   Seeded order.
	 * @param string $phone   Attacker-supplied phone.
	 * @param string $branch  nopriv or priv.
	 * @return Yappy_Ajax_Response
	 */
	private function guest_create( array $order, $phone, $branch = 'nopriv' ) {
		$cache = isset( $GLOBALS['yappy_test_cache'] ) ? $GLOBALS['yappy_test_cache'] : array();
		$GLOBALS['yappy_test_cache'] = array();
		try {
			return yappy_test_dispatch_ajax(
				array(
					'action'    => 'wc_yappy_create_order',
					'order_id'  => (string) $order['id'],
					'order_key' => $order['key'],
					'nonce'     => $order['nonce'],
					'phone'     => $phone,
				),
				$branch
			);
		} finally {
			$GLOBALS['yappy_test_cache'] = $cache;
		}
	}

	/**
	 * @param string $mode    ajax or process_payment.
	 * @param array  $order   Seeded order.
	 * @param string $phone   Phone.
	 * @param string $address Client address.
	 * @return array
	 */
	private function job( $mode, array $order, $phone, $address = self::CLIENT_ADDRESS ) {
		return array(
			'mode'      => $mode,
			'order_id'  => $order['id'],
			'order_key' => $order['key'],
			'nonce'     => $order['nonce'],
			'phone'     => $phone,
			'address'   => $address,
			'branch'    => 'nopriv',
		);
	}

	/**
	 * @param array $jobs Worker jobs.
	 * @return void
	 */
	private function run_workers( array $jobs ) {
		$barrier = bin2hex( random_bytes( 3 ) );
		$procs   = array();
		foreach ( $jobs as $job ) {
			$pipes   = array();
			$command = array(
				PHP_BINARY,
				dirname( __DIR__ ) . '/support/yappy-rate-limit-worker.php',
				json_encode( $job ),
			);
			$env = array(
				'PATH'                    => (string) getenv( 'PATH' ),
				'HOME'                    => false !== getenv( 'HOME' ) ? (string) getenv( 'HOME' ) : '/tmp',
				'YAPPY_TEST_STORE'        => Yappy_Test_Store::$dir,
				'YAPPY_TEST_PARTICIPANTS' => (string) count( $jobs ),
				'YAPPY_TEST_BARRIER'      => $barrier,
				'YAPPY_TEST_RACE_WINDOW'  => '1',
			);
			$proc = proc_open(
				$command,
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes,
				dirname( __DIR__, 2 ),
				$env
			);
			$this->assertIsResource( $proc );
			$procs[] = array(
				'proc'   => $proc,
				'stdout' => $pipes[1],
				'stderr' => $pipes[2],
			);
		}

		foreach ( $procs as $proc ) {
			$stdout = stream_get_contents( $proc['stdout'] );
			$stderr = stream_get_contents( $proc['stderr'] );
			fclose( $proc['stdout'] );
			fclose( $proc['stderr'] );
			$code = proc_close( $proc['proc'] );
			$this->assertSame( 0, $code, $stderr . $stdout );
		}
	}

	/**
	 * @param array $creations Payment-creation rows.
	 * @return string[]
	 */
	private function pushed_phones( array $creations ) {
		$phones = array();
		foreach ( $creations as $creation ) {
			$phones[] = isset( $creation['body']['aliasYappy'] ) ? (string) $creation['body']['aliasYappy'] : '';
		}
		return $phones;
	}
}
