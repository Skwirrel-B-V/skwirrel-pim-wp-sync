<?php
/**
 * Skwirrel Product Lookup.
 *
 * Provides database lookup methods for finding WooCommerce products
 * by various Skwirrel meta keys (external ID, product ID, grouped product ID, SKU).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Lookup {

	public const GROUPED_PRODUCT_ID_META = '_skwirrel_grouped_product_id';

	private Skwirrel_WC_Sync_Product_Mapper $mapper;

	/**
	 * Constructor.
	 *
	 * @param Skwirrel_WC_Sync_Product_Mapper $mapper Product mapper instance (needed for meta key accessors).
	 */
	public function __construct( Skwirrel_WC_Sync_Product_Mapper $mapper ) {
		$this->mapper = $mapper;
	}

	/**
	 * Find a WooCommerce product or variation by its Skwirrel external ID meta value.
	 *
	 * Searches postmeta for the _skwirrel_external_id key, filtering on
	 * post_type IN ('product', 'product_variation') and post_status NOT IN ('trash', 'auto-draft').
	 *
	 * @param string $key The external ID value to search for.
	 * @return int The WC post ID, or 0 if not found.
	 */
	public function find_by_external_id( string $key ): int {
		global $wpdb;
		$meta_key = $this->mapper->get_external_id_meta_key();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- meta value lookup with post_type filter not supported by WP API
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 AND p.post_type IN ('product', 'product_variation')
                 AND p.post_status NOT IN ('trash', 'auto-draft')
             WHERE pm.meta_key = %s AND pm.meta_value = %s
             LIMIT 1",
				$meta_key,
				$key
			)
		);
		return $id ? (int) $id : 0;
	}

	/**
	 * Find a WooCommerce product or variation by its Skwirrel product ID meta value.
	 *
	 * Searches postmeta for the _skwirrel_product_id key, filtering on
	 * post_type IN ('product', 'product_variation') and post_status NOT IN ('trash', 'auto-draft').
	 *
	 * @param int $product_id The Skwirrel product ID to search for.
	 * @return int The WC post ID, or 0 if not found.
	 */
	public function find_by_skwirrel_product_id( int $product_id ): int {
		global $wpdb;
		$meta_key = $this->mapper->get_product_id_meta_key();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- meta value lookup with post_type filter not supported by WP API
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 AND p.post_type IN ('product', 'product_variation')
                 AND p.post_status NOT IN ('trash', 'auto-draft')
             WHERE pm.meta_key = %s AND pm.meta_value = %s
             LIMIT 1",
				$meta_key,
				(string) $product_id
			)
		);
		return $id ? (int) $id : 0;
	}

	/**
	 * Find a WooCommerce variable product by its Skwirrel grouped product ID meta value.
	 *
	 * Searches postmeta for the _skwirrel_grouped_product_id key.
	 *
	 * @param int $grouped_product_id The Skwirrel grouped product ID to search for.
	 * @return int The WC post ID, or 0 if not found.
	 */
	public function find_by_grouped_product_id( int $grouped_product_id ): int {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- meta value lookup not supported by WP API
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::GROUPED_PRODUCT_ID_META,
				(string) $grouped_product_id
			)
		);
		return $id ? (int) $id : 0;
	}

	/**
	 * Find a WooCommerce product variation by its parent product ID and SKU.
	 *
	 * Joins posts and postmeta on _sku, filtering on post_parent and post_type = 'product_variation'.
	 *
	 * @param int    $parent_id The WC parent (variable) product ID.
	 * @param string $sku       The SKU value to match.
	 * @return int The WC variation post ID, or 0 if not found.
	 */
	public function find_variation_by_sku( int $parent_id, string $sku ): int {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- variation lookup by parent+SKU not supported by WP API
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_parent = %d AND pm.meta_value = %s AND p.post_type = 'product_variation'",
				$parent_id,
				$sku
			)
		);
		return $id ? (int) $id : 0;
	}
}
