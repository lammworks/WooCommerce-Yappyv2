<?php
/**
 * Logging helper.
 *
 * Writes to the WooCommerce log under the `yappy` source, and only when the
 * gateway has debug logging enabled. Debug-level entries are dropped otherwise,
 * while errors are always recorded so a broken integration leaves a trace.
 *
 * @package WooCommerce_Yappy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small wrapper around `wc_get_logger()`.
 */
class WC_Yappy_Logger {

	/**
	 * Whether debug level messages are recorded.
	 *
	 * @var bool
	 */
	protected $debug;

	/**
	 * WooCommerce logger instance.
	 *
	 * @var WC_Logger_Interface|null
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param bool $debug Whether debug logging is enabled.
	 */
	public function __construct( $debug = false ) {
		$this->debug = (bool) $debug;
	}

	/**
	 * Record a message.
	 *
	 * @param string $level   One of the WC_Log_Levels constants.
	 * @param string $message Message to record.
	 * @param array  $context Optional structured context appended to the message.
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		if ( 'debug' === $level && ! $this->debug ) {
			return;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( null === $this->logger ) {
			$this->logger = wc_get_logger();
		}

		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		$this->logger->log( $level, $message, array( 'source' => 'yappy' ) );
	}

	/**
	 * Record a debug message.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Record an informational message.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Record an error message.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}
}
