<?php
/**
 * Skwirrel Sync - Sync History Management.
 *
 * Manages sync history, last sync timestamps, and sync result storage.
 * All methods are static since they operate on WordPress options.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_History {

	/** @var string WordPress option key for the last sync timestamp. */
	public const OPTION_LAST_SYNC = 'skwirrel_wc_sync_last_sync';

	/** @var string WordPress option key for the last sync result array. */
	public const OPTION_LAST_SYNC_RESULT = 'skwirrel_wc_sync_last_result';

	/** @var string WordPress option key for the sync history array. */
	public const OPTION_SYNC_HISTORY = 'skwirrel_wc_sync_history';

	/** @var int Maximum number of history entries to keep. */
	public const MAX_HISTORY_ENTRIES = 20;

	/** @var string Transient key indicating a sync is currently in progress. */
	public const SYNC_IN_PROGRESS = 'skwirrel_wc_sync_in_progress';

	/** @var int Time-to-live in seconds for the sync-in-progress transient. */
	public const HEARTBEAT_TTL = 60;

	/** @var string Option key for the sync phase progress array. */
	public const OPTION_SYNC_PROGRESS = 'skwirrel_wc_sync_progress';

	/** Phase identifiers. */
	public const PHASE_FETCH      = 'fetch';
	public const PHASE_PRODUCTS   = 'products';
	public const PHASE_TAXONOMY   = 'taxonomy';
	public const PHASE_ATTRIBUTES = 'attributes';
	public const PHASE_MEDIA      = 'media';
	public const PHASE_CLEANUP    = 'cleanup';

	/** Trigger types. */
	public const TRIGGER_MANUAL    = 'manual';
	public const TRIGGER_SCHEDULED = 'scheduled';
	public const TRIGGER_PURGE     = 'purge';

	/**
	 * Refresh the sync-in-progress transient so the UI knows the sync is still alive.
	 *
	 * If not refreshed within HEARTBEAT_TTL seconds, the transient expires automatically.
	 *
	 * @return void
	 */
	public static function sync_heartbeat(): void {
		set_transient( self::SYNC_IN_PROGRESS, (string) time(), self::HEARTBEAT_TTL );
	}

	/**
	 * Get the timestamp of the last completed sync.
	 *
	 * @return string|null ISO 8601 timestamp string, or null if no sync has been recorded.
	 */
	public static function get_last_sync(): ?string {
		return get_option( self::OPTION_LAST_SYNC, null );
	}

	/**
	 * Get the result array of the last completed sync.
	 *
	 * @return array|null Array with keys: success, created, updated, failed, trashed,
	 *                    categories_removed, error, with_attributes, without_attributes,
	 *                    trigger, timestamp.
	 *                    Returns null if no result has been recorded.
	 */
	public static function get_last_result(): ?array {
		return get_option( self::OPTION_LAST_SYNC_RESULT, null );
	}

	/**
	 * Get the sync history array (most recent first).
	 *
	 * @return array List of result arrays, each with keys: success, created, updated,
	 *               failed, trashed, categories_removed, error, with_attributes,
	 *               without_attributes, trigger, timestamp.
	 */
	public static function get_sync_history(): array {
		$history = get_option( self::OPTION_SYNC_HISTORY, [] );
		return is_array( $history ) ? $history : [];
	}

	/**
	 * Store the result of a sync run and append it to the history.
	 *
	 * Saves the result to the last-result option, prepends it to the history
	 * (capped at MAX_HISTORY_ENTRIES), and clears the sync-in-progress transient.
	 *
	 * @param bool   $ok                 Whether the sync completed successfully.
	 * @param int    $created            Number of products created.
	 * @param int    $updated            Number of products updated.
	 * @param int    $failed             Number of products that failed to sync.
	 * @param string $error              Error message (empty string if no error).
	 * @param int    $with_attrs         Number of products synced with attributes.
	 * @param int    $without_attrs      Number of products synced without attributes.
	 * @param int    $trashed            Number of stale products trashed.
	 * @param int    $categories_removed Number of stale categories removed.
	 * @param string $trigger            What initiated the sync: 'manual', 'scheduled', or 'purge'.
	 * @param array  $failed_products    List of failed product details (product_id, sku, name, error).
	 *
	 * @return void
	 */
	public static function update_last_result(
		bool $ok,
		int $created,
		int $updated,
		int $failed,
		string $error = '',
		int $with_attrs = 0,
		int $without_attrs = 0,
		int $trashed = 0,
		int $categories_removed = 0,
		string $trigger = self::TRIGGER_MANUAL,
		array $failed_products = []
	): void {
		$result = [
			'success'            => $ok,
			'created'            => $created,
			'updated'            => $updated,
			'failed'             => $failed,
			'trashed'            => $trashed,
			'categories_removed' => $categories_removed,
			'error'              => $error,
			'with_attributes'    => $with_attrs,
			'without_attributes' => $without_attrs,
			'trigger'            => $trigger,
			'timestamp'          => time(),
			'failed_products'    => array_slice( $failed_products, 0, 50 ),
		];

		update_option( self::OPTION_LAST_SYNC_RESULT, $result, false );
		delete_transient( self::SYNC_IN_PROGRESS );
		self::clear_sync_progress();

		// Clear slug resync flag after successful sync.
		if ( $ok ) {
			delete_option( 'skwirrel_wc_sync_slug_resync_needed' );
		}

		self::append_to_history( $result );
	}

	/**
	 * Update just the failed_products list in the last sync result.
	 *
	 * Used after a "resync failed" run to replace the failed list with
	 * only the products that are still failing.
	 *
	 * @param array $failed_products Updated list of failed product details.
	 * @return void
	 */
	public static function update_failed_products( array $failed_products ): void {
		$result = get_option( self::OPTION_LAST_SYNC_RESULT, null );
		if ( ! is_array( $result ) ) {
			return;
		}
		$result['failed_products'] = array_slice( $failed_products, 0, 50 );
		$result['failed']          = count( $failed_products );
		update_option( self::OPTION_LAST_SYNC_RESULT, $result, false );
	}

	/**
	 * Add a history entry without updating the last sync result.
	 *
	 * Used for purge operations and other non-sync events that should appear
	 * in history but not overwrite the last sync status.
	 *
	 * @param array $entry History entry array (must include 'timestamp' and 'trigger').
	 * @return void
	 */
	public static function add_history_entry( array $entry ): void {
		self::append_to_history( $entry );
	}

	/**
	 * Append an entry to the history array.
	 *
	 * @param array $entry History entry.
	 * @return void
	 */
	private static function append_to_history( array $entry ): void {
		$history = get_option( self::OPTION_SYNC_HISTORY, [] );
		if ( ! is_array( $history ) ) {
			$history = [];
		}

		// Prepend newest entry at the beginning
		array_unshift( $history, $entry );

		// Keep only the latest MAX_HISTORY_ENTRIES
		$history = array_slice( $history, 0, self::MAX_HISTORY_ENTRIES );

		update_option( self::OPTION_SYNC_HISTORY, $history, false );
	}

	/**
	 * Update the phase progress for the current sync.
	 *
	 * Stores progress in a non-autoloaded option so the admin UI can poll it.
	 * Also refreshes the heartbeat to keep the sync-in-progress transient alive.
	 *
	 * @param string $phase     Phase identifier (one of the PHASE_* constants).
	 * @param int    $current   Number of items processed in this phase.
	 * @param int    $total     Total items to process in this phase.
	 * @param string $label     Human-readable phase label.
	 * @return void
	 */
	public static function update_phase_progress( string $phase, int $current, int $total, string $label = '' ): void {
		$progress = get_option( self::OPTION_SYNC_PROGRESS, [] );
		if ( ! is_array( $progress ) ) {
			$progress = [];
		}

		$progress['current_phase'] = $phase;
		$progress['updated_at']    = time();

		if ( ! isset( $progress['phases'] ) || ! is_array( $progress['phases'] ) ) {
			$progress['phases'] = [];
		}

		$progress['phases'][ $phase ] = [
			'label'   => $label,
			'current' => $current,
			'total'   => $total,
			'status'  => $current >= $total && $total > 0 ? 'completed' : 'in_progress',
		];

		// Mark earlier phases as completed
		$phase_order = [ self::PHASE_FETCH, self::PHASE_PRODUCTS, self::PHASE_TAXONOMY, self::PHASE_ATTRIBUTES, self::PHASE_MEDIA, self::PHASE_CLEANUP ];
		$reached     = false;
		foreach ( $phase_order as $p ) {
			if ( $p === $phase ) {
				$reached = true;
				continue;
			}
			if ( ! $reached && isset( $progress['phases'][ $p ] ) ) {
				$progress['phases'][ $p ]['status'] = 'completed';
			}
		}

		update_option( self::OPTION_SYNC_PROGRESS, $progress, false );
		self::sync_heartbeat();
	}

	/**
	 * Get the current sync phase progress.
	 *
	 * @return array|null Progress array with 'current_phase' and 'phases', or null if not syncing.
	 */
	public static function get_sync_progress(): ?array {
		$progress = get_option( self::OPTION_SYNC_PROGRESS, null );
		return is_array( $progress ) ? $progress : null;
	}

	/**
	 * Clear the sync progress (called when sync completes or is reset).
	 *
	 * @return void
	 */
	public static function clear_sync_progress(): void {
		delete_option( self::OPTION_SYNC_PROGRESS );
	}

	/**
	 * Get the WordPress option key used to store the last sync timestamp.
	 *
	 * This is needed by the sync service to update the timestamp after a successful sync.
	 *
	 * @return string The option key for the last sync timestamp.
	 */
	public static function get_last_sync_option_key(): string {
		return self::OPTION_LAST_SYNC;
	}
}
