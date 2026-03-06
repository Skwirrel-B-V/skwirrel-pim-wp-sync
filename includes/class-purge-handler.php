<?php
/**
 * Skwirrel Sync Purge Handler.
 *
 * Centralises all purge logic: full purge (danger zone), stale product cleanup,
 * and stale category cleanup. Extracted from Admin_Settings and Sync_Service.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Purge_Handler {

	private Skwirrel_WC_Sync_Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Skwirrel_WC_Sync_Logger $logger Logger instance for recording purge activity.
	 */
	public function __construct( Skwirrel_WC_Sync_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Purge all Skwirrel-managed data from WooCommerce.
	 *
	 * This is the "danger zone" full purge. It removes all Skwirrel media attachments,
	 * products (and their variations), categories, brands, attribute taxonomies, and
	 * resets sync state options. The purge result is stored for display in the admin UI.
	 *
	 * Steps performed:
	 * 1. Delete Skwirrel media attachments (posts with _skwirrel_source_url meta).
	 * 2. Find products with _skwirrel_external_id or _skwirrel_grouped_product_id meta
	 *    and collect their category term IDs.
	 * 3. Also collect categories with _skwirrel_category_id term meta.
	 * 4. Delete or trash products (permanent = wp_delete_post with force, else set status to trash).
	 * 5. Delete product_brand terms (if taxonomy exists).
	 * 6. Delete Skwirrel attribute taxonomies (etim_% and variant).
	 * 7. Reset sync state options (last_sync, last_result, force_full_sync — but NOT history).
	 * 8. Store purge result in skwirrel_wc_sync_last_purge option.
	 *
	 * @param bool $permanent Whether to permanently delete products (true) or move them to trash (false).
	 * @return void
	 */
	public function purge_all( bool $permanent ): void {
		$mode_label = $permanent ? 'permanent delete' : 'trash';
		$this->logger->info( "Purge all Skwirrel products started (mode: {$mode_label})" );

		global $wpdb;

		// --- Step 1: Delete Skwirrel media attachments ---
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
		$attachment_ids = $wpdb->get_col(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND pm.meta_key = '_skwirrel_source_url'"
		);

		$attachments_deleted = 0;
		if ( ! empty( $attachment_ids ) ) {
			$this->logger->info( 'Purge: ' . count( $attachment_ids ) . ' Skwirrel media files found, deleting...' );
			foreach ( $attachment_ids as $attachment_id ) {
				wp_delete_attachment( (int) $attachment_id, true );
				++$attachments_deleted;
			}
			$this->logger->info( "Purge: {$attachments_deleted} media files deleted." );
		}

		// --- Step 2: Find products and collect their category term IDs ---
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
		if ( $permanent ) {
			$product_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type IN ('product', 'product_variation')
                    AND p.post_status IN (%s, %s, %s, %s, %s)
                    AND pm.meta_key IN ('_skwirrel_external_id', '_skwirrel_grouped_product_id')",
					'publish',
					'draft',
					'pending',
					'private',
					'trash'
				)
			);
		} else {
			$product_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type IN ('product', 'product_variation')
                    AND p.post_status IN (%s, %s, %s, %s)
                    AND pm.meta_key IN ('_skwirrel_external_id', '_skwirrel_grouped_product_id')",
					'publish',
					'draft',
					'pending',
					'private'
				)
			);
		}

		// Collect category term IDs assigned to Skwirrel products BEFORE deleting them.
		// This catches categories without _skwirrel_category_id meta (e.g. from _product_groups fallback).
		$skwirrel_cat_term_ids = [];
		if ( ! empty( $product_ids ) ) {
			$int_ids = array_map( 'absint', $product_ids );
			$id_list = implode( ',', $int_ids );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint-sanitized database results
			$skwirrel_cat_term_ids = $wpdb->get_col(
				"SELECT DISTINCT tt.term_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
                WHERE tr.object_id IN ({$id_list})"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Also collect categories with _skwirrel_category_id meta (may include categories not assigned to any current product)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
		$meta_cat_term_ids = $wpdb->get_col(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm
            INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id AND tt.taxonomy = 'product_cat'
            WHERE tm.meta_key = '_skwirrel_category_id' AND tm.meta_value != ''"
		);

		$all_cat_term_ids = array_unique( array_map( 'intval', array_merge( $skwirrel_cat_term_ids, $meta_cat_term_ids ) ) );

		// --- Step 3: Delete products ---
		$deleted = 0;
		if ( ! empty( $product_ids ) ) {
			$count = count( $product_ids );
			$this->logger->info( "Purge: {$count} Skwirrel products found, processing..." );

			foreach ( $product_ids as $product_id ) {
				$product_id = (int) $product_id;

				if ( $permanent ) {
					// Delete variations first for variable products
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
					$children = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
							$product_id
						)
					);
					foreach ( $children as $child_id ) {
						wp_delete_post( (int) $child_id, true );
					}
					wp_delete_post( $product_id, true );
				} else {
					$product = wc_get_product( $product_id );
					if ( $product ) {
						$product->set_status( 'trash' );
						$product->save();
					}
				}
				++$deleted;
			}
		}

		$this->logger->info( "Purge: {$deleted} products processed (mode: {$mode_label})" );

		// --- Step 4: Delete Skwirrel-related categories ---
		// Skip the default WooCommerce "Uncategorized" category.
		$default_cat_id     = (int) get_option( 'default_product_cat', 0 );
		$categories_deleted = 0;
		if ( ! empty( $all_cat_term_ids ) ) {
			$this->logger->info( 'Purge: ' . count( $all_cat_term_ids ) . ' Skwirrel categories found, deleting...' );
			foreach ( $all_cat_term_ids as $term_id ) {
				if ( $term_id === $default_cat_id ) {
					continue;
				}
				$result = wp_delete_term( $term_id, 'product_cat' );
				if ( true === $result ) {
					++$categories_deleted;
				}
			}
			$this->logger->info( "Purge: {$categories_deleted} categories deleted." );
		}

		// --- Step 5: Delete product_brand terms that were assigned to Skwirrel products ---
		$brands_deleted = 0;
		if ( taxonomy_exists( 'product_brand' ) ) {
			$brand_terms = get_terms(
				[
					'taxonomy'   => 'product_brand',
					'hide_empty' => false,
					'fields'     => 'ids',
				]
			);
			if ( ! is_wp_error( $brand_terms ) && ! empty( $brand_terms ) ) {
				$this->logger->info( 'Purge: ' . count( $brand_terms ) . ' product brands found, deleting...' );
				foreach ( $brand_terms as $brand_term_id ) {
					$result = wp_delete_term( (int) $brand_term_id, 'product_brand' );
					if ( true === $result ) {
						++$brands_deleted;
					}
				}
				$this->logger->info( "Purge: {$brands_deleted} brands deleted." );
			}
		}

		// --- Step 6: Delete Skwirrel-created attribute taxonomies ---
		// Matches: etim_* (all ETIM-based attributes) and variant (fallback attribute)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk purge operation
		$attribute_rows = $wpdb->get_results(
			"SELECT attribute_id, attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
            WHERE attribute_name LIKE 'etim\\_%'
            OR attribute_name = 'skwirrel_variant'"
		);

		$attributes_deleted = 0;
		if ( ! empty( $attribute_rows ) ) {
			$this->logger->info( 'Purge: ' . count( $attribute_rows ) . ' Skwirrel attributes found, deleting...' );
			foreach ( $attribute_rows as $attr ) {
				if ( function_exists( 'wc_delete_attribute' ) ) {
					wc_delete_attribute( (int) $attr->attribute_id );
				}
				++$attributes_deleted;
			}
			delete_transient( 'wc_attribute_taxonomies' );
			$this->logger->info( "Purge: {$attributes_deleted} attributes deleted." );
		}

		// --- Step 7: Reset sync state options ---
		// Keep last_result visible so users can still see the last sync status.
		delete_option( 'skwirrel_wc_sync_last_sync' );
		delete_option( 'skwirrel_wc_sync_force_full_sync' );
		$this->logger->info( 'Purge: sync state options reset (last sync result preserved).' );

		// --- Step 8: Store purge result + add history entry ---
		$this->logger->info( "Purge completed: {$deleted} products, {$attachments_deleted} media, {$categories_deleted} categories, {$brands_deleted} brands, {$attributes_deleted} attributes (mode: {$mode_label})" );

		$purge_result = [
			'timestamp'   => time(),
			'mode'        => $mode_label,
			'products'    => $deleted,
			'attachments' => $attachments_deleted,
			'categories'  => $categories_deleted,
			'brands'      => $brands_deleted,
			'attributes'  => $attributes_deleted,
		];

		update_option( 'skwirrel_wc_sync_last_purge', $purge_result, false );

		// Add purge to sync history so it's visible in the history table.
		Skwirrel_WC_Sync_History::add_history_entry(
			[
				'success'            => true,
				'trigger'            => Skwirrel_WC_Sync_History::TRIGGER_PURGE,
				'created'            => 0,
				'updated'            => 0,
				'failed'             => 0,
				'trashed'            => $deleted,
				'categories_removed' => $categories_deleted,
				'with_attributes'    => 0,
				'without_attributes' => 0,
				'error'              => '',
				'purge_mode'         => $mode_label,
				'purge_attachments'  => $attachments_deleted,
				'purge_brands'       => $brands_deleted,
				'purge_attributes'   => $attributes_deleted,
				'timestamp'          => time(),
			]
		);
	}

	/**
	 * Purge stale products that were not updated during the current sync run.
	 *
	 * Finds products with _skwirrel_external_id or _skwirrel_grouped_product_id meta
	 * where _skwirrel_synced_at is either missing or older than the sync start timestamp.
	 * Stale products (and their variations) are moved to the trash.
	 *
	 * Safety: the synced_at meta value is validated as numeric before comparison to
	 * prevent corrupt meta values from causing incorrect trashing.
	 *
	 * @param int                              $sync_started_at Unix timestamp when the sync run started.
	 * @param Skwirrel_WC_Sync_Product_Mapper  $mapper          Product mapper instance (for meta key accessors).
	 * @return int Number of products and variations moved to trash.
	 */
	public function purge_stale_products( int $sync_started_at, Skwirrel_WC_Sync_Product_Mapper $mapper ): int {
		global $wpdb;
		$external_id_meta = $mapper->get_external_id_meta_key();
		$synced_at_meta   = $mapper->get_synced_at_meta_key();

		// Find products with _skwirrel_external_id that were NOT updated during this sync.
		// Safety check: meta_value must be numeric (prevent corrupt data from causing incorrect trashing).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stale_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm_ext.post_id
             FROM {$wpdb->postmeta} pm_ext
             INNER JOIN {$wpdb->posts} p ON pm_ext.post_id = p.ID
                 AND p.post_status NOT IN ('trash', 'auto-draft')
                 AND p.post_type IN ('product', 'product_variation')
             LEFT JOIN {$wpdb->postmeta} pm_sync ON pm_ext.post_id = pm_sync.post_id
                 AND pm_sync.meta_key = %s
             WHERE pm_ext.meta_key = %s
                 AND pm_ext.meta_value != ''
                 AND (
                     pm_sync.meta_value IS NULL
                     OR (pm_sync.meta_value REGEXP '^[0-9]+$' AND CAST(pm_sync.meta_value AS UNSIGNED) < %d)
                 )",
				$synced_at_meta,
				$external_id_meta,
				$sync_started_at
			)
		);

		// Find variable products (grouped products) that were not updated during this sync.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stale_variable_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm_grp.post_id
             FROM {$wpdb->postmeta} pm_grp
             INNER JOIN {$wpdb->posts} p ON pm_grp.post_id = p.ID
                 AND p.post_status NOT IN ('trash', 'auto-draft')
                 AND p.post_type = 'product'
             LEFT JOIN {$wpdb->postmeta} pm_sync ON pm_grp.post_id = pm_sync.post_id
                 AND pm_sync.meta_key = %s
             WHERE pm_grp.meta_key = %s
                 AND pm_grp.meta_value != ''
                 AND (
                     pm_sync.meta_value IS NULL
                     OR (pm_sync.meta_value REGEXP '^[0-9]+$' AND CAST(pm_sync.meta_value AS UNSIGNED) < %d)
                 )",
				$synced_at_meta,
				Skwirrel_WC_Sync_Product_Lookup::GROUPED_PRODUCT_ID_META,
				$sync_started_at
			)
		);

		$all_stale = array_unique(
			array_merge(
				array_map( 'intval', $stale_ids ),
				array_map( 'intval', $stale_variable_ids )
			)
		);

		if ( empty( $all_stale ) ) {
			$this->logger->verbose( 'No stale products found' );
			return 0;
		}

		// Log pre-purge summary
		$this->logger->info(
			'Stale products detected',
			[
				'count'           => count( $all_stale ),
				'product_ids'     => array_slice( $all_stale, 0, 20 ),
				'sync_started_at' => gmdate( 'Y-m-d H:i:s', $sync_started_at ),
			]
		);

		$trashed = 0;
		foreach ( $all_stale as $post_id ) {
			$product = wc_get_product( $post_id );
			if ( ! $product ) {
				$this->logger->verbose( 'Stale product not found, skipped', [ 'wc_id' => $post_id ] );
				continue;
			}

			$this->logger->info(
				'Product removed from Skwirrel, moved to trash',
				[
					'wc_id' => $post_id,
					'sku'   => $product->get_sku(),
					'name'  => $product->get_name(),
					'type'  => $product->get_type(),
				]
			);

			$product->set_status( 'trash' );
			$product->save();
			++$trashed;

			// Variable product: also move variations to trash
			if ( $product->is_type( 'variable' ) ) {
				$variation_ids = $product->get_children();
				foreach ( $variation_ids as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation && $variation->get_status() !== 'trash' ) {
						$variation->set_status( 'trash' );
						$variation->save();
						++$trashed;
					}
				}
			}
		}

		if ( $trashed > 0 ) {
			$this->logger->info( 'Stale products cleaned up', [ 'count' => $trashed ] );
		}

		return $trashed;
	}

	/**
	 * Purge stale categories that are no longer present in Skwirrel.
	 *
	 * Compares all WooCommerce product_cat terms that have _skwirrel_category_id term meta
	 * against the list of category IDs seen during the current sync run. Categories not
	 * in the seen list are deleted, unless they still have non-trashed products assigned
	 * (safety check to prevent data loss).
	 *
	 * @param string[] $seen_category_ids Skwirrel category IDs that were encountered during sync.
	 * @return int Number of categories deleted.
	 */
	public function purge_stale_categories( array $seen_category_ids ): int {
		global $wpdb;
		$cat_meta_key = Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_skwirrel_terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.term_id, tm.meta_value as skwirrel_id, t.name as term_name
             FROM {$wpdb->termmeta} tm
             INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id AND tt.taxonomy = 'product_cat'
             INNER JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
             WHERE tm.meta_key = %s AND tm.meta_value != ''",
				$cat_meta_key
			)
		);

		$seen   = array_unique( $seen_category_ids );
		$purged = 0;

		foreach ( $all_skwirrel_terms as $term ) {
			if ( in_array( $term->skwirrel_id, $seen, true ) ) {
				continue;
			}

			// Safety check: do not delete if products are still assigned to this category
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$product_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                     AND p.post_type IN ('product', 'product_variation')
                     AND p.post_status NOT IN ('trash', 'auto-draft')
                 WHERE tr.term_taxonomy_id = (
                     SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
                     WHERE term_id = %d AND taxonomy = 'product_cat'
                 )",
					$term->term_id
				)
			);

			if ( $product_count > 0 ) {
				$this->logger->warning(
					'Category not deleted: products still assigned',
					[
						'term_id'       => $term->term_id,
						'name'          => $term->term_name,
						'skwirrel_id'   => $term->skwirrel_id,
						'product_count' => $product_count,
					]
				);
				continue;
			}

			$this->logger->info(
				'Category removed from Skwirrel, cleaned up',
				[
					'term_id'     => $term->term_id,
					'name'        => $term->term_name,
					'skwirrel_id' => $term->skwirrel_id,
				]
			);
			wp_delete_term( (int) $term->term_id, 'product_cat' );
			++$purged;
		}

		if ( $purged > 0 ) {
			$this->logger->info( 'Stale categories cleaned up', [ 'count' => $purged ] );
		}

		return $purged;
	}
}
