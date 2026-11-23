<?php
/**
 * Limits how often a Yappy payment can be created.
 *
 * Every created payment is a real request at Banco General and, when a phone
 * number is supplied, a push to that phone. The order key and nonce that
 * authorise the request stay valid across repeats, so the limit has to come
 * from here: a cap per WooCommerce order and a cap per client address.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Raised when a payment attempt is refused by the rate limit.
 */
class WC_Yappy_Rate_Limit_Exception extends Exception {

	/**
	 * Message safe to display to the customer.
	 *
	 * @return string
	 */
	public function get_customer_message() {
		return __( 'Too many Yappy payment attempts. Please wait a few minutes before trying again, or contact the store.', 'woocommerce-yappy' );
	}
}

/**
 * Reserves payment attempts against persisted counters.
 *
 * The check and the increment run inside one lock that every PHP process
 * shares, and the increment is stored before the caller contacts Yappy, so
 * concurrent or overlapping requests cannot all pass the same check.
 */
class WC_Yappy_Rate_Limiter {

	/**
	 * Payment creations allowed for one WooCommerce order within the window.
	 */
	const PER_ORDER = 3;

	/**
	 * Window for the per-order cap, in seconds.
	 */
	const PER_ORDER_WINDOW = 86400;

	/**
	 * Payment creations allowed for one client address within the window.
	 */
	const PER_ADDRESS = 5;

	/**
	 * Window for the per-address cap, in seconds.
	 */
	const PER_ADDRESS_WINDOW = 3600;

	/**
	 * Seconds to wait for the lock before refusing the attempt.
	 */
	const LOCK_TIMEOUT = 10;

	/**
	 * Reserve one payment attempt for the order and the current client.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 * @throws WC_Yappy_Rate_Limit_Exception When a cap is reached or the lock is unavailable.
	 */
	public static function reserve( $order_id ) {
		$limits  = self::get_limits();
		$now     = time();
		$address = self::get_client_bucket();

		$counters = array(
			array(
				'key'    => 'wc_yappy_rl_order_' . absint( $order_id ),
				'limit'  => $limits['per_order'],
				'window' => $limits['per_order_window'],
				'scope'  => 'order',
			),
			array(
				'key'    => 'wc_yappy_rl_addr_' . md5( $address ),
				'limit'  => $limits['per_address'],
				'window' => $limits['per_address_window'],
				'scope'  => 'address',
			),
		);

		$lock = self::acquire_lock();
		if ( false === $lock ) {
			throw new WC_Yappy_Rate_Limit_Exception( 'The Yappy rate-limit lock could not be acquired.' );
		}

		try {
			$values = array();
			foreach ( $counters as $index => $counter ) {
				$values[ $index ] = self::read_counter( $counter['key'], $counter['window'], $now );
				if ( $values[ $index ]['count'] >= $counter['limit'] ) {
					throw new WC_Yappy_Rate_Limit_Exception(
						sprintf( 'Yappy payment attempts exceeded the %s limit.', $counter['scope'] )
					);
				}
			}

			foreach ( $counters as $index => $counter ) {
				$values[ $index ]['count']++;
				set_transient( $counter['key'], $values[ $index ], max( 1, $values[ $index ]['expires'] - $now ) );
			}
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Caps and windows, after the `wc_yappy_rate_limits` filter.
	 *
	 * @return array{per_order:int,per_order_window:int,per_address:int,per_address_window:int}
	 */
	protected static function get_limits() {
		$defaults = array(
			'per_order'          => self::PER_ORDER,
			'per_order_window'   => self::PER_ORDER_WINDOW,
			'per_address'        => self::PER_ADDRESS,
			'per_address_window' => self::PER_ADDRESS_WINDOW,
		);

		/**
		 * Filter the Yappy payment-creation limits.
		 *
		 * @param array $limits Keys per_order, per_order_window, per_address and
		 *                      per_address_window. Windows are in seconds.
		 */
		$limits = apply_filters( 'wc_yappy_rate_limits', $defaults );
		$limits = is_array( $limits ) ? $limits : array();

		$result = array();
		foreach ( $defaults as $name => $default ) {
			$value           = isset( $limits[ $name ] ) && is_numeric( $limits[ $name ] ) ? (int) $limits[ $name ] : $default;
			$result[ $name ] = max( 1, $value );
		}

		return $result;
	}

	/**
	 * The bucket the current client is counted in.
	 *
	 * REMOTE_ADDR is used because forwarding headers are set by the client. A
	 * store behind a reverse proxy can supply the real address through the
	 * `wc_yappy_rate_limit_client_address` filter. IPv6 clients are counted per
	 * /64, the smallest block an end user is normally assigned.
	 *
	 * @return string
	 */
	protected static function get_client_bucket() {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		/**
		 * Filter the client address used by the Yappy rate limit.
		 *
		 * @param string $address Address from REMOTE_ADDR.
		 */
		$address = (string) apply_filters( 'wc_yappy_rate_limit_client_address', $address );

		if ( false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $address );
			if ( false !== $packed ) {
				return 'ipv6:' . bin2hex( substr( $packed, 0, 8 ) );
			}
		}

		if ( false !== filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return 'ipv4:' . $address;
		}

		return 'unknown';
	}

	/**
	 * Read a fixed-window counter.
	 *
	 * @param string $key    Transient name.
	 * @param int    $window Window length in seconds.
	 * @param int    $now    Current time.
	 * @return array{count:int,expires:int}
	 */
	protected static function read_counter( $key, $window, $now ) {
		$value = get_transient( $key );

		if ( is_array( $value ) && isset( $value['count'], $value['expires'] ) && (int) $value['expires'] > $now ) {
			return array(
				'count'   => max( 0, (int) $value['count'] ),
				'expires' => (int) $value['expires'],
			);
		}

		return array(
			'count'   => 0,
			'expires' => $now + $window,
		);
	}

	/**
	 * Take the lock that serialises every reservation.
	 *
	 * A file lock covers processes on this host. When WordPress has a database
	 * connection, a MySQL named lock is also taken so web servers that share the
	 * database are serialised too. At least one of the two must be held.
	 *
	 * @return array{file:resource|null,db:string|null}|false False when the lock could not be taken.
	 */
	protected static function acquire_lock() {
		$lock = array(
			'file' => null,
			'db'   => null,
		);

		$path   = self::get_lock_directory() . 'wc-yappy-rate-limit.lock';
		$handle = @fopen( $path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false !== $handle ) {
			$deadline = microtime( true ) + self::LOCK_TIMEOUT;
			while ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
				if ( microtime( true ) >= $deadline ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return false;
				}
				usleep( 20000 );
			}
			$lock['file'] = $handle;
		}

		$wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		if ( $wpdb instanceof wpdb ) {
			$name = 'wc_yappy_rl_' . md5( (string) $wpdb->prefix );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );
			if ( '1' !== (string) $acquired ) {
				self::release_lock( $lock );
				return false;
			}
			$lock['db'] = $name;
		}

		if ( null === $lock['file'] && null === $lock['db'] ) {
			return false;
		}

		return $lock;
	}

	/**
	 * Release a lock taken by `acquire_lock()`.
	 *
	 * @param array{file:resource|null,db:string|null} $lock Lock handles.
	 * @return void
	 */
	protected static function release_lock( array $lock ) {
		if ( null !== $lock['db'] && isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
			$wpdb = $GLOBALS['wpdb'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock['db'] ) );
		}

		if ( null !== $lock['file'] ) {
			flock( $lock['file'], LOCK_UN );
			fclose( $lock['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Directory for the lock file, with a trailing slash.
	 *
	 * @return string
	 */
	protected static function get_lock_directory() {
		$directory = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();

		return rtrim( (string) $directory, '/\\' ) . '/';
	}
}
