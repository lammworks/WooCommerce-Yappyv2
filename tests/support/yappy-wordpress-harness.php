<?php
/**
 * Harness for the Yappy create-payment endpoints.
 *
 * The plugin classes under test are the real ones. WordPress and WooCommerce
 * are not installed here, so this file supplies the small set of functions
 * those classes call. Each stub follows the WordPress 6.7.1 or WooCommerce
 * 9.4.3 source cited on the function. Nothing in this file rate-limits
 * payment creation: a cap has to come from the plugin.
 *
 * @package WooCommerce_Yappy
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WC_YAPPY_VERSION' ) ) {
	define( 'WC_YAPPY_VERSION', '1.3.0' );
}

if ( ! defined( 'WC_YAPPY_PLUGIN_DIR' ) ) {
	define( 'WC_YAPPY_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'WC_YAPPY_PLUGIN_URL' ) ) {
	define( 'WC_YAPPY_PLUGIN_URL', 'https://store.test/wp-content/plugins/woocommerce-yappy/' );
}

if ( ! defined( 'WC_YAPPY_PLUGIN_FILE' ) ) {
	define( 'WC_YAPPY_PLUGIN_FILE', WC_YAPPY_PLUGIN_DIR . 'woocommerce-yappy.php' );
}

/**
 * JSON response that stands in for wp_send_json()'s print-and-exit.
 */
class Yappy_Ajax_Response extends Exception {

	/**
	 * Decoded JSON body.
	 *
	 * @var array
	 */
	public $payload;

	/**
	 * HTTP status passed to wp_send_json(), or null when the caller omitted one.
	 *
	 * @var int|null
	 */
	public $status;

	/**
	 * @param array    $payload Response body.
	 * @param int|null $status  HTTP status.
	 */
	public function __construct( array $payload, $status = null ) {
		parent::__construct( 'ajax response' );
		$this->payload = $payload;
		$this->status  = null === $status ? null : (int) $status;
	}
}

/**
 * Shared order and option storage.
 *
 * Reads and writes are separate critical sections. A pause between them, when
 * the race window is enabled, is the gap a non-atomic check-then-increment
 * loses. The default object cache is deliberately not stored here: WordPress
 * keeps it in process memory (WP_Object_Cache::$cache).
 */
class Yappy_Test_Store {

	/**
	 * Directory holding state.json.
	 *
	 * @var string
	 */
	public static $dir = '';

	/**
	 * Whether this process has already arrived at the start barrier.
	 *
	 * @var bool
	 */
	protected static $barrier_passed = false;

	/**
	 * Create an empty store and select it.
	 *
	 * @return string Store directory.
	 */
	public static function create() {
		self::$dir = sys_get_temp_dir() . '/yappy-rate-' . bin2hex( random_bytes( 4 ) );
		mkdir( self::$dir, 0700, true );
		file_put_contents(
			self::$dir . '/state.json',
			json_encode(
				array(
					'orders'  => array(),
					'options' => array(),
				)
			)
		);
		self::$barrier_passed = false;
		return self::$dir;
	}

	/**
	 * Attach to a store another process created.
	 *
	 * @param string $dir Store directory.
	 * @return void
	 */
	public static function attach( $dir ) {
		self::$dir            = $dir;
		self::$barrier_passed = false;
	}

	/**
	 * Remove the store directory.
	 *
	 * @return void
	 */
	public static function destroy() {
		if ( '' === self::$dir || ! is_dir( self::$dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( self::$dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( self::$dir );
		self::$dir = '';
	}

	/**
	 * Insert one unpaid order.
	 *
	 * @param int    $id             Order ID.
	 * @param string $key            Order key.
	 * @param string $billing_phone  Billing phone stored on the order.
	 * @return void
	 */
	public static function seed_order( $id, $key, $billing_phone ) {
		self::mutate(
			static function ( $state ) use ( $id, $key, $billing_phone ) {
				$state['orders'][ (string) $id ] = array(
					'id'            => (int) $id,
					'key'           => (string) $key,
					'status'        => 'pending',
					'total'         => 10.0,
					'subtotal'      => 10.0,
					'tax'           => 0.0,
					'discount'      => 0.0,
					'shipping'      => 0.0,
					'fees'          => 0.0,
					'billing_phone' => (string) $billing_phone,
					'meta'          => array(),
					'notes'         => array(),
					'paid'          => false,
					'transaction'   => '',
				);
				return $state;
			}
		);
	}

	/**
	 * Load the raw order row.
	 *
	 * @param int $id Order ID.
	 * @return array|null
	 */
	public static function load_order_row( $id ) {
		$state = self::read_state();
		$key   = (string) (int) $id;
		return isset( $state['orders'][ $key ] ) ? $state['orders'][ $key ] : null;
	}

	/**
	 * Persist an order row.
	 *
	 * @param array $row Order row.
	 * @return void
	 */
	public static function save_order_row( array $row ) {
		// The pause sits outside the file lock, so two callers that both already
		// read the row can both write it back. flock() around the whole
		// read-modify-write in the plugin is what makes the second caller see
		// the first write. The locked mutation only replaces this order's row.
		if ( '1' === getenv( 'YAPPY_TEST_RACE_WINDOW' ) ) {
			usleep( 200000 );
		}
		self::mutate(
			static function ( $state ) use ( $row ) {
				$id      = (string) (int) $row['id'];
				$current = isset( $state['orders'][ $id ] ) && is_array( $state['orders'][ $id ] ) ? $state['orders'][ $id ] : array();
				$meta = isset( $current['meta'] ) && is_array( $current['meta'] ) ? $current['meta'] : array();
				if ( isset( $row['deleted_meta'] ) && is_array( $row['deleted_meta'] ) ) {
					foreach ( array_keys( $row['deleted_meta'] ) as $key ) {
						unset( $meta[ $key ] );
					}
				}
				if ( isset( $row['dirty_meta'] ) && is_array( $row['dirty_meta'] ) ) {
					foreach ( array_keys( $row['dirty_meta'] ) as $key ) {
						if ( isset( $row['meta'] ) && is_array( $row['meta'] ) && array_key_exists( $key, $row['meta'] ) ) {
							$meta[ $key ] = $row['meta'][ $key ];
						}
					}
				}
				unset( $row['deleted_meta'], $row['dirty_meta'] );
				$merged         = array_merge( $current, $row );
				$merged['meta'] = $meta;
				$state['orders'][ $id ] = $merged;
				return $state;
			}
		);
	}

	/**
	 * Read one option.
	 *
	 * @param string $name Option name.
	 * @return mixed False when the option is missing.
	 */
	public static function get_option( $name ) {
		$state = self::read_state();
		return array_key_exists( $name, $state['options'] ) ? $state['options'][ $name ] : false;
	}

	/**
	 * Write one option with the same non-atomic gap as a separate read and write.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 * @return void
	 */
	public static function update_option( $name, $value ) {
		if ( '1' === getenv( 'YAPPY_TEST_RACE_WINDOW' ) ) {
			usleep( 200000 );
		}
		self::mutate(
			static function ( $state ) use ( $name, $value ) {
				$state['options'][ $name ] = $value;
				return $state;
			}
		);
	}

	/**
	 * Add an option only when it is absent. The existence check is not atomic
	 * with the insert, matching WordPress 6.7.1 add_option()'s pre-check
	 * (option.php:1113) ahead of the query.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 * @return bool
	 */
	public static function add_option( $name, $value ) {
		$state  = self::read_state();
		$exists = array_key_exists( $name, $state['options'] );
		if ( '1' === getenv( 'YAPPY_TEST_RACE_WINDOW' ) ) {
			usleep( 200000 );
		}
		if ( $exists ) {
			return false;
		}
		$wrote = false;
		self::mutate(
			static function ( $state ) use ( $name, $value, &$wrote ) {
				// During the race window both callers already decided the option
				// was missing, so both inserts are kept (the last value wins).
				if ( array_key_exists( $name, $state['options'] ) && '1' !== getenv( 'YAPPY_TEST_RACE_WINDOW' ) ) {
					return $state;
				}
				$state['options'][ $name ] = $value;
				$wrote                     = true;
				return $state;
			}
		);
		return $wrote;
	}

	/**
	 * Delete an option.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function delete_option( $name ) {
		self::mutate(
			static function ( $state ) use ( $name ) {
				unset( $state['options'][ $name ] );
				return $state;
			}
		);
	}

	/**
	 * Wait until every parallel worker has reached order load.
	 *
	 * A worker that already holds an exclusive lock and is the only one inside
	 * this function times out and continues, so a lock taken before the load
	 * cannot deadlock the rest. Callers that lock after the load serialize
	 * normally.
	 *
	 * @return void
	 */
	public static function arrive() {
		$participants = (int) getenv( 'YAPPY_TEST_PARTICIPANTS' );
		if ( $participants < 2 || self::$barrier_passed || '' === self::$dir ) {
			self::$barrier_passed = true;
			return;
		}
		self::$barrier_passed = true;
		$token = getenv( 'YAPPY_TEST_BARRIER' );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = 'barrier';
		}
		$dir = self::$dir . '/barrier-' . $token;
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0700, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		file_put_contents( $dir . '/' . getmypid(), '1' );
		$deadline = microtime( true ) + 3;
		do {
			$arrived = glob( $dir . '/*' );
			if ( is_array( $arrived ) && count( $arrived ) >= $participants ) {
				return;
			}
			usleep( 1000 );
		} while ( microtime( true ) < $deadline );
	}

	/**
	 * Read state.json.
	 *
	 * @return array
	 */
	protected static function read_state() {
		$path = self::$dir . '/state.json';
		$fh   = fopen( $path, 'c+' );
		flock( $fh, LOCK_SH );
		$raw = stream_get_contents( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		return self::decode_state( $raw );
	}

	/**
	 * Replace state inside an exclusive lock, re-reading first so unrelated
	 * keys written by another process are preserved.
	 *
	 * @param callable $mutator Receives the current state and returns the next one.
	 * @return void
	 */
	protected static function mutate( $mutator ) {
		$path = self::$dir . '/state.json';
		$fh   = fopen( $path, 'c+' );
		flock( $fh, LOCK_EX );
		$raw   = stream_get_contents( $fh );
		$state = $mutator( self::decode_state( $raw ) );
		ftruncate( $fh, 0 );
		rewind( $fh );
		fwrite( $fh, json_encode( $state ) );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
	}

	/**
	 * @param string $raw JSON.
	 * @return array
	 */
	protected static function decode_state( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'orders'  => array(),
				'options' => array(),
			);
		}
		if ( ! isset( $decoded['orders'] ) || ! is_array( $decoded['orders'] ) ) {
			$decoded['orders'] = array();
		}
		if ( ! isset( $decoded['options'] ) || ! is_array( $decoded['options'] ) ) {
			$decoded['options'] = array();
		}
		return $decoded;
	}
}

/**
 * Order double whose payment methods follow WC_Order in WooCommerce 9.4.3.
 */
class Yappy_Test_Order {

	/**
	 * Persisted row.
	 *
	 * @var array
	 */
	protected $row;

	/**
	 * Meta keys changed on this instance.
	 * WC_Data::save_meta_data() (WooCommerce 9.4.3 abstract-wc-data.php:679)
	 * writes only new, changed, or deleted meta. A later save of an order that
	 * never touched a key must not put the old value back.
	 *
	 * @var array<string,bool>
	 */
	protected $dirty_meta = array();

	/**
	 * Meta keys deleted on this instance.
	 *
	 * @var array<string,bool>
	 */
	protected $deleted_meta = array();

	/**
	 * @param array $row Order row from the store.
	 */
	public function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * @return int
	 */
	public function get_id() {
		return (int) $this->row['id'];
	}

	/**
	 * @return string
	 */
	public function get_order_key() {
		return (string) $this->row['key'];
	}

	/**
	 * @return string
	 */
	public function get_status() {
		return (string) $this->row['status'];
	}

	/**
	 * @return float
	 */
	public function get_total() {
		return (float) $this->row['total'];
	}

	/**
	 * @return float
	 */
	public function get_subtotal() {
		return (float) $this->row['subtotal'];
	}

	/**
	 * @return float
	 */
	public function get_total_tax() {
		return (float) $this->row['tax'];
	}

	/**
	 * @param bool $ex_tax Unused. The stored discount is already ex tax.
	 * @return float
	 */
	public function get_total_discount( $ex_tax = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $ex_tax );
		return (float) $this->row['discount'];
	}

	/**
	 * @return float
	 */
	public function get_shipping_total() {
		return (float) $this->row['shipping'];
	}

	/**
	 * @return float
	 */
	public function get_total_fees() {
		return (float) $this->row['fees'];
	}

	/**
	 * @return string
	 */
	public function get_billing_phone() {
		return (string) $this->row['billing_phone'];
	}

	/**
	 * Pending and failed orders with a positive total still need payment.
	 * WC_Order::needs_payment(), WooCommerce 9.4.3 class-wc-order.php:1723.
	 *
	 * @return bool
	 */
	public function needs_payment() {
		return in_array( $this->row['status'], array( 'pending', 'failed' ), true ) && $this->get_total() > 0;
	}

	/**
	 * Processing and completed orders are paid.
	 * WC_Order::is_paid(), WooCommerce 9.4.3 class-wc-order.php:1613.
	 *
	 * @return bool
	 */
	public function is_paid() {
		return in_array( $this->row['status'], array( 'processing', 'completed' ), true );
	}

	/**
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return array_key_exists( $key, $this->row['meta'] ) ? $this->row['meta'][ $key ] : '';
	}

	/**
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
		$this->row['meta'][ $key ] = $value;
		$this->dirty_meta[ $key ]  = true;
		unset( $this->deleted_meta[ $key ] );
	}

	/**
	 * @param string $key Meta key.
	 * @return void
	 */
	public function delete_meta_data( $key ) {
		unset( $this->row['meta'][ $key ] );
		$this->deleted_meta[ $key ] = true;
		unset( $this->dirty_meta[ $key ] );
	}

	/**
	 * @param string $note Note text.
	 * @return void
	 */
	public function add_order_note( $note ) {
		$this->row['notes'][] = (string) $note;
	}

	/**
	 * @param string $status New status, without the wc- prefix.
	 * @param string $note   Optional note.
	 * @return void
	 */
	public function update_status( $status, $note = '' ) {
		$this->row['status'] = (string) $status;
		if ( '' !== $note ) {
			$this->add_order_note( $note );
		}
		$this->save();
	}

	/**
	 * Mark the order paid the way WC_Order::payment_complete() does when the
	 * status allows it (WooCommerce 9.4.3 class-wc-order.php:131).
	 *
	 * @param string $transaction_id Transaction id.
	 * @return bool
	 */
	public function payment_complete( $transaction_id = '' ) {
		if ( ! in_array( $this->row['status'], array( 'on-hold', 'pending', 'failed', 'cancelled' ), true ) ) {
			return true;
		}
		$this->row['transaction'] = (string) $transaction_id;
		$this->row['paid']        = true;
		$this->row['status']      = 'processing';
		$this->save();
		return true;
	}

	/**
	 * @return void
	 */
	public function save() {
		$row                 = $this->row;
		$row['dirty_meta']   = $this->dirty_meta;
		$row['deleted_meta'] = $this->deleted_meta;
		Yappy_Test_Store::save_order_row( $row );
		$this->dirty_meta   = array();
		$this->deleted_meta = array();
	}
}

/**
 * Record of outbound HTTP calls, shared across processes through a file.
 */
class Yappy_Test_Http {

	/**
	 * Whether the in-flight re-entry has already been launched.
	 *
	 * @var bool
	 */
	public static $reenter_armed = true;

	/**
	 * Append one call.
	 *
	 * @param string $url  Request URL.
	 * @param array  $body Decoded JSON body.
	 * @return void
	 */
	public static function record( $url, array $body ) {
		$path = Yappy_Test_Store::$dir . '/http.jsonl';
		$fh   = fopen( $path, 'a' );
		flock( $fh, LOCK_EX );
		fwrite(
			$fh,
			json_encode(
				array(
					'url'  => $url,
					'body' => $body,
				)
			) . "\n"
		);
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
	}

	/**
	 * Every recorded call.
	 *
	 * @return array<int,array{url:string,body:array}>
	 */
	public static function all() {
		$path = Yappy_Test_Store::$dir . '/http.jsonl';
		if ( ! is_file( $path ) ) {
			return array();
		}
		$rows = array();
		foreach ( file( $path, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$rows[] = $decoded;
			}
		}
		return $rows;
	}

	/**
	 * Calls that create a Yappy payment.
	 *
	 * @return array<int,array{url:string,body:array}>
	 */
	public static function payment_creations() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $row ) {
					return false !== strpos( (string) $row['url'], '/payments/payment-wc' );
				}
			)
		);
	}

	/**
	 * Drop the log.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$reenter_armed = true;
		$path                = Yappy_Test_Store::$dir . '/http.jsonl';
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/load.php:1439.
	 *
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/formatting.php:5778, for scalars and arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/formatting.php:5555 and _sanitize_text_fields()
	 * at formatting.php:5607. UTF-8 cleanup is a no-op for the ASCII fields the
	 * create-payment request carries.
	 *
	 * @param mixed $str Value.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		if ( is_object( $str ) || is_array( $str ) ) {
			return '';
		}
		$filtered = (string) $str;
		if ( false !== strpos( $filtered, '<' ) ) {
			$filtered = wp_strip_all_tags( $filtered );
		}
		$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
		$filtered = trim( (string) $filtered );
		$found    = false;
		while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
			$filtered = str_replace( $match[0], '', $filtered );
			$found    = true;
		}
		if ( $found ) {
			$filtered = trim( (string) preg_replace( '/ +/', ' ', $filtered ) );
		}
		return $filtered;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( wp_strip_all_tags_raw( (string) $text ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags_raw' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags_raw( $text ) {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/formatting.php:2818.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority, ignored.
	 * @return void
	 */
	function add_action( $hook, $callback, $priority = 10 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $priority );
		$GLOBALS['yappy_test_actions'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * @param string $hook Hook.
	 * @return bool
	 */
	function has_action( $hook ) {
		return ! empty( $GLOBALS['yappy_test_actions'][ $hook ] );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook Hook.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	function do_action( $hook, ...$args ) {
		if ( empty( $GLOBALS['yappy_test_actions'][ $hook ] ) ) {
			return;
		}
		foreach ( $GLOBALS['yappy_test_actions'][ $hook ] as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority, ignored.
	 * @return void
	 */
	function add_filter( $hook, $callback, $priority = 10 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $priority );
		$GLOBALS['yappy_test_filters'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @param mixed  ...$args Further arguments.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['yappy_test_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['yappy_test_filters'][ $hook ] as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Guests are logged out. admin-ajax.php then fires the nopriv hook.
	 *
	 * @return bool
	 */
	function is_user_logged_in() {
		return false;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Logged-out user. wp_create_nonce() uses ID 0.
	 *
	 * @return object
	 */
	function wp_get_current_user() {
		return (object) array( 'ID' => 0 );
	}
}

if ( ! function_exists( 'wp_get_session_token' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/user.php:3533. No logged_in cookie is set, and
	 * wp_parse_auth_cookie() (pluggable.php:937) returns false in that case, so
	 * the token is an empty string.
	 *
	 * @return string
	 */
	function wp_get_session_token() {
		return '';
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Fixed test salt. The value is not a site secret.
	 *
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'yappy-test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'wp_hash' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/pluggable.php:2591.
	 *
	 * @param string $data   Data.
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function wp_hash( $data, $scheme = 'auth' ) {
		return hash_hmac( 'md5', $data, wp_salt( $scheme ) );
	}
}

if ( ! function_exists( 'wp_nonce_tick' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/pluggable.php:2324. The default lifetime is one day.
	 *
	 * @param string|int $action Action.
	 * @return float
	 */
	function wp_nonce_tick( $action = -1 ) {
		$nonce_life = apply_filters( 'nonce_life', DAY_IN_SECONDS, $action );
		return ceil( time() / ( $nonce_life / 2 ) );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/pluggable.php:2417. The nonce is not stored
	 * and is not consumed on use.
	 *
	 * @param string|int $action Action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}
		$token = wp_get_session_token();
		$i     = wp_nonce_tick( $action );
		return substr( wp_hash( $i . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/pluggable.php:2354. A matching nonce stays
	 * valid for the current and previous tick; verification does not erase it.
	 *
	 * @param string     $nonce  Nonce.
	 * @param string|int $action Action.
	 * @return int|false
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		$nonce = (string) $nonce;
		$user  = wp_get_current_user();
		$uid   = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}
		if ( '' === $nonce ) {
			return false;
		}
		$token = wp_get_session_token();
		$i     = wp_nonce_tick( $action );
		$expected = substr( wp_hash( $i . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 1;
		}
		$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 2;
		}
		return false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $value Value.
	 * @param int   $flags Flags.
	 * @param int   $depth Depth.
	 * @return string
	 */
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		$encoded = json_encode( $value, $flags, $depth );
		return false === $encoded ? '' : $encoded;
	}
}

if ( ! function_exists( 'wp_send_json' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/functions.php:4513, without writing headers.
	 * The response is thrown so a test can keep going after the handler stops.
	 *
	 * @param mixed    $response    Body.
	 * @param int|null $status_code Status.
	 * @param int      $flags       Flags, ignored.
	 * @return void
	 */
	function wp_send_json( $response, $status_code = null, $flags = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $flags );
		if ( ! is_array( $response ) ) {
			$response = array( 'data' => $response );
		}
		throw new Yappy_Ajax_Response( $response, $status_code );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/functions.php:4560.
	 *
	 * @param mixed    $value       Data.
	 * @param int|null $status_code Status.
	 * @param int      $flags       Flags.
	 * @return void
	 */
	function wp_send_json_success( $value = null, $status_code = null, $flags = 0 ) {
		$response = array( 'success' => true );
		if ( isset( $value ) ) {
			$response['data'] = $value;
		}
		wp_send_json( $response, $status_code, $flags );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	/**
	 * WordPress 6.7.1 wp-includes/functions.php:4587, for the array payloads this plugin sends.
	 *
	 * @param mixed    $value       Data.
	 * @param int|null $status_code Status.
	 * @param int      $flags       Flags.
	 * @return void
	 */
	function wp_send_json_error( $value = null, $status_code = null, $flags = 0 ) {
		$response = array( 'success' => false );
		if ( isset( $value ) ) {
			$response['data'] = $value;
		}
		wp_send_json( $response, $status_code, $flags );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $thing );
		return false;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Stand-in for the HTTP API. Records the call and returns a Yappy success
	 * envelope. When re-entry is armed, further create-payment requests run
	 * before this call returns, which is the overlapping request the cap has
	 * to reject.
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments.
	 * @return array
	 */
	function wp_remote_post( $url, $args = array() ) {
		$decoded = array();
		if ( isset( $args['body'] ) && is_string( $args['body'] ) ) {
			$parsed = json_decode( $args['body'], true );
			if ( is_array( $parsed ) ) {
				$decoded = $parsed;
			}
		}
		Yappy_Test_Http::record( (string) $url, $decoded );

		$is_validate = false !== strpos( (string) $url, '/payments/validate/merchant' );
		if ( $is_validate && Yappy_Test_Http::$reenter_armed && '1' === getenv( 'YAPPY_TEST_REENTER' ) ) {
			Yappy_Test_Http::$reenter_armed = false;
			if ( isset( $GLOBALS['yappy_test_reenter'] ) && is_callable( $GLOBALS['yappy_test_reenter'] ) ) {
				call_user_func( $GLOBALS['yappy_test_reenter'] );
			}
		}

		if ( $is_validate ) {
			$body = array(
				'status' => array(
					'code'        => '00',
					'description' => 'ok',
				),
				'body'   => array(
					'token'     => 'yappy-response-placeholder',
					'epochTime' => time(),
				),
			);
		} else {
			$body = array(
				'status' => array(
					'code'        => '00',
					'description' => 'ok',
				),
				'body'   => array(
					'transactionId' => 'txn-' . bin2hex( random_bytes( 4 ) ),
					'token'         => 'yappy-response-placeholder',
					'documentName'  => 'doc-placeholder',
				),
			);
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( $body ),
		);
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) {
		$stored = Yappy_Test_Store::get_option( $option );
		return false === $stored ? $default_value : $stored;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @param mixed  $autoload Ignored.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $autoload );
		Yappy_Test_Store::update_option( $option, $value );
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param string $option    Option name.
	 * @param mixed  $value     Value.
	 * @param string $deprecated Unused.
	 * @param mixed  $autoload  Unused.
	 * @return bool
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $deprecated, $autoload );
		return Yappy_Test_Store::add_option( $option, $value );
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		Yappy_Test_Store::delete_option( $option );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Transient read through options, including the timeout option WordPress stores.
	 *
	 * @param string $transient Name.
	 * @return mixed
	 */
	function get_transient( $transient ) {
		$timeout = get_option( '_transient_timeout_' . $transient, false );
		if ( false !== $timeout && (int) $timeout < time() ) {
			delete_option( '_transient_' . $transient );
			delete_option( '_transient_timeout_' . $transient );
			return false;
		}
		return get_option( '_transient_' . $transient, false );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $transient  Name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Lifetime in seconds. 0 does not expire.
	 * @return bool
	 */
	function set_transient( $transient, $value, $expiration = 0 ) {
		if ( (int) $expiration > 0 ) {
			update_option( '_transient_timeout_' . $transient, time() + (int) $expiration );
		}
		update_option( '_transient_' . $transient, $value );
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $transient Name.
	 * @return bool
	 */
	function delete_transient( $transient ) {
		delete_option( '_transient_' . $transient );
		delete_option( '_transient_timeout_' . $transient );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	/**
	 * In-process only, as WP_Object_Cache::add() stores into $this->cache
	 * (WordPress 6.7.1 class-wp-object-cache.php:30 and :198). A second PHP
	 * process does not see the entry.
	 *
	 * @param string $key    Key.
	 * @param mixed  $data   Data.
	 * @param string $group  Group.
	 * @param int    $expire Expire, ignored.
	 * @return bool
	 */
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $expire );
		$group = '' === $group ? 'default' : $group;
		if ( isset( $GLOBALS['yappy_test_cache'][ $group ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['yappy_test_cache'][ $group ][ $key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * @param string $key   Key.
	 * @param string $group Group.
	 * @return mixed
	 */
	function wp_cache_get( $key, $group = '' ) {
		$group = '' === $group ? 'default' : $group;
		return isset( $GLOBALS['yappy_test_cache'][ $group ][ $key ] ) ? $GLOBALS['yappy_test_cache'][ $group ][ $key ] : false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	/**
	 * @param string $key    Key.
	 * @param mixed  $data   Data.
	 * @param string $group  Group.
	 * @param int    $expire Expire, ignored.
	 * @return bool
	 */
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $expire );
		$group = '' === $group ? 'default' : $group;
		$GLOBALS['yappy_test_cache'][ $group ][ $key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * @param string $key   Key.
	 * @param string $group Group.
	 * @return bool
	 */
	function wp_cache_delete( $key, $group = '' ) {
		$group = '' === $group ? 'default' : $group;
		unset( $GLOBALS['yappy_test_cache'][ $group ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://store.test' . $path;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'https://store.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_validate_redirect' ) ) {
	/**
	 * Host allow-list from WordPress 6.7.1 wp-includes/pluggable.php:1565.
	 * process_payment() calls this only after create_yappy_order() returns.
	 * The ASCII return URL used here is unchanged by wp_sanitize_redirect().
	 *
	 * @param string $location     Candidate URL.
	 * @param string $fallback_url Fallback.
	 * @return string
	 */
	function wp_validate_redirect( $location, $fallback_url = '' ) {
		$location = str_replace( ' ', '%20', trim( $location, " \t\n\r\0\x08\x0B" ) );
		if ( str_starts_with( $location, '//' ) ) {
			$location = 'http:' . $location;
		}
		$cut  = strpos( $location, '?' );
		$test = $cut ? substr( $location, 0, $cut ) : $location;
		$lp   = parse_url( $test );
		if ( false === $lp ) {
			return $fallback_url;
		}
		if ( isset( $lp['scheme'] ) && ! ( 'http' === $lp['scheme'] || 'https' === $lp['scheme'] ) ) {
			return $fallback_url;
		}
		if ( ! isset( $lp['host'] ) && ( isset( $lp['scheme'] ) || isset( $lp['user'] ) || isset( $lp['pass'] ) || isset( $lp['port'] ) ) ) {
			return $fallback_url;
		}
		$wpp           = parse_url( home_url() );
		$allowed_hosts = (array) apply_filters( 'allowed_redirect_hosts', array( $wpp['host'] ), isset( $lp['host'] ) ? $lp['host'] : '' );
		if ( isset( $lp['host'] ) && ( ! in_array( $lp['host'], $allowed_hosts, true ) && strtolower( $wpp['host'] ) !== $lp['host'] ) ) {
			return $fallback_url;
		}
		return $location;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://store.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $order_id Order ID.
	 * @return Yappy_Test_Order|false
	 */
	function wc_get_order( $order_id ) {
		Yappy_Test_Store::arrive();
		$row = Yappy_Test_Store::load_order_row( $order_id );
		if ( ! is_array( $row ) ) {
			return false;
		}
		return new Yappy_Test_Order( $row );
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	/**
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @return void
	 */
	function wc_add_notice( $message, $type = 'success' ) {
		$GLOBALS['yappy_test_notices'][] = array(
			'message' => $message,
			'type'    => $type,
		);
	}
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * Parent of the real gateway. The test subclass replaces the constructor
	 * and get_option(), which is all create_yappy_order() reaches.
	 */
	class WC_Payment_Gateway {} // phpcs:ignore Generic.Classes.OpeningBraceSameLine,Squiz.Commenting.ClassComment
}

require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-status.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-logger.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-api-exception.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-api-client.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-signature.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-reference.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-amounts.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-phone.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-gateway.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-ipn-handler.php';
require_once WC_YAPPY_PLUGIN_DIR . 'includes/class-wc-yappy-ajax.php';

if ( ! class_exists( 'Yappy_Test_Gateway' ) ) {
	/**
	 * Real gateway with settings supplied by the test, skipping WooCommerce's
	 * settings loader.
	 */
	class Yappy_Test_Gateway extends WC_Yappy_Gateway {

		/**
		 * Plugin settings.
		 *
		 * @var array
		 */
		public $settings = array();

		/**
		 * Build the gateway the store would have after a merchant saved settings.
		 */
		public function __construct() {
			$this->id       = self::GATEWAY_ID;
			$this->enabled  = 'yes';
			$this->settings = array(
				'enabled'         => 'yes',
				// Obvious placeholders. These are not merchant credentials.
				'merchant_id'     => 'merchant-placeholder',
				'secret_key'      => base64_encode( 'placeholder-key.unused' ),
				'merchant_domain' => 'https://store.test',
				'environment'     => 'sandbox',
				'debug'           => 'no',
				'ask_phone'       => 'yes',
			);
			$this->log      = new WC_Yappy_Logger( false );
		}

		/**
		 * @param string $key          Setting name.
		 * @param mixed  $empty_value  Default.
		 * @return mixed
		 */
		public function get_option( $key, $empty_value = null ) {
			return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $empty_value;
		}

		/**
		 * @param WC_Order|Yappy_Test_Order $order Order.
		 * @return string
		 */
		public function get_return_url( $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			unset( $order );
			return 'https://store.test/checkout/order-received/';
		}
	}
}

if ( ! function_exists( 'wc_yappy_get_gateway' ) ) {
	/**
	 * @return Yappy_Test_Gateway
	 */
	function wc_yappy_get_gateway() {
		if ( ! isset( $GLOBALS['yappy_test_gateway'] ) ) {
			$GLOBALS['yappy_test_gateway'] = new Yappy_Test_Gateway();
		}
		return $GLOBALS['yappy_test_gateway'];
	}
}

if ( ! function_exists( 'yappy_test_boot_ajax' ) ) {
	/**
	 * Register the public Ajax hooks once.
	 *
	 * @return void
	 */
	function yappy_test_boot_ajax() {
		if ( ! empty( $GLOBALS['yappy_test_ajax_ready'] ) ) {
			return;
		}
		$GLOBALS['yappy_test_ajax_ready'] = true;
		WC_Yappy_Ajax::init();
	}
}

if ( ! function_exists( 'yappy_test_dispatch_ajax' ) ) {
	/**
	 * Run the logged-out or logged-in admin-ajax branch for one POST body.
	 *
	 * WordPress 6.7.1 wp-admin/admin-ajax.php:178 dispatches wp_ajax_{action}
	 * for a logged-in user and wp_ajax_nopriv_{action} otherwise. Guests take
	 * the nopriv branch. There is no rate limit in that file.
	 *
	 * @param array  $post    POST fields.
	 * @param string $branch  Either nopriv or priv.
	 * @return Yappy_Ajax_Response
	 */
	function yappy_test_dispatch_ajax( array $post, $branch = 'nopriv' ) {
		yappy_test_boot_ajax();
		$_POST    = $post;
		$_REQUEST = $post;
		$action   = isset( $post['action'] ) ? (string) $post['action'] : '';
		$hook     = ( 'priv' === $branch ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . $action;
		if ( ! has_action( $hook ) ) {
			throw new RuntimeException( 'No handler for ' . $hook );
		}
		try {
			do_action( $hook );
		} catch ( Yappy_Ajax_Response $response ) {
			return $response;
		}
		throw new RuntimeException( 'Ajax handler returned without a response.' );
	}
}

if ( ! function_exists( 'yappy_test_client_address' ) ) {
	/**
	 * Address admin-ajax sees. WordPress does not replace REMOTE_ADDR.
	 *
	 * @param string $address Address.
	 * @return void
	 */
	function yappy_test_client_address( $address ) {
		$_SERVER['REMOTE_ADDR'] = $address;
	}
}
