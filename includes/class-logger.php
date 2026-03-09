<?php
/**
 * Skwirrel Sync Logger.
 *
 * Uses WC_Logger when available, otherwise WP debug log.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Logger {

	private const LOG_SOURCE      = 'skwirrel-pim-sync';
	private ?WC_Logger $wc_logger = null;

	public function __construct() {
		if ( function_exists( 'wc_get_logger' ) ) {
			$this->wc_logger = wc_get_logger();
		}
	}

	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	public function debug( string $message, array $context = [] ): void {
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || $this->is_verbose() ) {
			$this->log( 'debug', $message, $context );
		}
	}

	/**
	 * Verbose log: always logged when SKWIRREL_VERBOSE_SYNC or plugin setting is on.
	 */
	public function verbose( string $message, array $context = [] ): void {
		if ( $this->is_verbose() ) {
			$this->log( 'info', $message, $context );
		}
	}

	private function is_verbose(): bool {
		if ( defined( 'SKWIRREL_VERBOSE_SYNC' ) && SKWIRREL_VERBOSE_SYNC ) {
			return true;
		}
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		return ! empty( $opts['verbose_logging'] );
	}

	private function log( string $level, string $message, array $context ): void {
		$context_string = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
		$full_message   = $message . $context_string;

		if ( $this->wc_logger ) {
			$this->wc_logger->log( $level, $full_message, [ 'source' => self::LOG_SOURCE ] );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when WC_Logger unavailable
			error_log( sprintf( '[Skwirrel Sync][%s] %s', strtoupper( $level ), $full_message ) );
		}
	}

	/**
	 * Returns URL to WooCommerce logs page. User can select skwirrel-wc-sync from dropdown.
	 */
	public function get_log_file_url(): ?string {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return null;
		}
		return admin_url( 'admin.php?page=wc-status&tab=logs' );
	}
}
