<?php
/**
 * Skwirrel Sync - Action Scheduler / WP-Cron integration.
 *
 * Uses Action Scheduler when available (WooCommerce), otherwise WP-Cron.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Action_Scheduler {

	private const HOOK_SYNC = 'skwirrel_wc_sync_run';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::HOOK_SYNC, [ $this, 'run_scheduled_sync' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
	}

	public function schedule(): void {
		$opts     = get_option( 'skwirrel_wc_sync_settings', [] );
		$interval = $opts['sync_interval'] ?? '';
		if ( empty( $interval ) ) {
			$this->unschedule();
			return;
		}

		$this->unschedule();

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			$timestamp        = time() + 60;
			$interval_seconds = $this->interval_to_seconds( $interval );
			if ( $interval_seconds > 0 ) {
				as_schedule_recurring_action( $timestamp, $interval_seconds, self::HOOK_SYNC, [], 'skwirrel-pim-sync' );
			}
		} else {
			wp_schedule_event( time() + 60, $interval, self::HOOK_SYNC );
		}
	}

	public function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_SYNC, [], 'skwirrel-pim-sync' );
		}
		wp_clear_scheduled_hook( self::HOOK_SYNC );
	}

	/**
	 * Run sync. Called by Action Scheduler or WP-Cron.
	 *
	 * @param array $args Optional. ['delta' => bool] - use delta sync (default true for scheduled).
	 */
	public function run_scheduled_sync( array $args = [] ): void {
		// Als een Skwirrel-product in WC is verwijderd, forceer volledige sync
		$force_full = get_option( 'skwirrel_wc_sync_force_full_sync', false );
		if ( $force_full ) {
			delete_option( 'skwirrel_wc_sync_force_full_sync' );
		}

		$delta   = $force_full ? false : ( $args['delta'] ?? true );
		$service = new Skwirrel_WC_Sync_Service();
		$service->run_sync( $delta, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );
	}

	/**
	 * Enqueue manual sync to run asynchronously (avoids timeout).
	 */
	public function enqueue_manual_sync(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_SYNC, [ 'delta' => false ], 'skwirrel-pim-sync' );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::HOOK_SYNC, [ 'delta' => false ], 'skwirrel-pim-sync' );
		} else {
			wp_schedule_single_event( time(), self::HOOK_SYNC, [ [ 'delta' => false ] ] );
			spawn_cron();
		}
	}

	public function add_cron_schedules( array $schedules ): array {
		$schedules['skwirrel_twice_daily'] = [
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Twice daily', 'skwirrel-pim-sync' ),
		];
		return $schedules;
	}

	private function interval_to_seconds( string $interval ): int {
		return match ( $interval ) {
			'hourly' => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'skwirrel_twice_daily' => 12 * HOUR_IN_SECONDS,
			'daily' => DAY_IN_SECONDS,
			'weekly' => WEEK_IN_SECONDS,
			default => 0,
		};
	}

	public static function get_interval_options(): array {
		return [
			''           => __( 'Disabled', 'skwirrel-pim-sync' ),
			'hourly'     => __( 'Hourly', 'skwirrel-pim-sync' ),
			'twicedaily' => __( 'Twice daily', 'skwirrel-pim-sync' ),
			'daily'      => __( 'Daily', 'skwirrel-pim-sync' ),
			'weekly'     => __( 'Weekly', 'skwirrel-pim-sync' ),
		];
	}
}
