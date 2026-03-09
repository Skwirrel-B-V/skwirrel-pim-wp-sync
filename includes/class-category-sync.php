<?php
/**
 * Skwirrel Category Sync.
 *
 * Handles WooCommerce product_cat taxonomy operations:
 * - Full category tree sync from Skwirrel API (getCategories)
 * - Per-product category assignment
 * - Category term find/create with parent hierarchy
 * - Tracks seen category IDs for stale-category purge detection
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Category_Sync {

	private Skwirrel_WC_Sync_Logger $logger;

	/** @var string[] Skwirrel category IDs seen during current sync run. */
	private array $seen_category_ids = [];

	/**
	 * @param Skwirrel_WC_Sync_Logger $logger Logger instance.
	 */
	public function __construct( Skwirrel_WC_Sync_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Get the Skwirrel category IDs encountered during this sync run.
	 *
	 * Used by the purge handler to detect stale categories.
	 *
	 * @return string[]
	 */
	public function get_seen_category_ids(): array {
		return $this->seen_category_ids;
	}

	/**
	 * Reset seen category IDs at the start of a new sync run.
	 */
	public function reset_seen_category_ids(): void {
		$this->seen_category_ids = [];
	}

	/**
	 * Sync the full category tree from a Skwirrel super category via getCategories API.
	 *
	 * Creates/updates WooCommerce product_cat terms for the entire tree.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client  API client.
	 * @param array                           $options Plugin settings.
	 * @param array                           $languages Include languages for API call.
	 */
	public function sync_category_tree( Skwirrel_WC_Sync_JsonRpc_Client $client, array $options, array $languages ): void {
		$super_id = (int) ( $options['super_category_id'] ?? 0 );
		if ( $super_id <= 0 ) {
			return;
		}

		$this->logger->info( 'Syncing category tree', [ 'super_category_id' => $super_id ] );

		$params = [
			'category_id'                   => $super_id,
			'include_children'              => true,
			'include_category_translations' => true,
		];

		if ( ! empty( $languages ) ) {
			$params['include_languages'] = $languages;
		}

		$result = $client->call( 'getCategories', $params );

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error( 'getCategories API error', $err );
			return;
		}

		$data       = $result['result'] ?? [];
		$categories = $data['categories'] ?? $data;
		if ( ! is_array( $categories ) ) {
			$this->logger->warning( 'getCategories returned unexpected format', [ 'type' => gettype( $categories ) ] );
			return;
		}

		$this->logger->info( 'Category tree received', [ 'count' => count( $categories ) ] );

		$tax         = 'product_cat';
		$cat_id_meta = Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META;
		$lang        = $options['image_language'] ?? 'nl';

		// Flatten the tree: build a list of all categories with parent references.
		$flat = [];
		$this->flatten_category_tree( $categories, $flat, $lang );

		if ( empty( $flat ) ) {
			$this->logger->info( 'No categories found in tree' );
			return;
		}

		// Resolve in order: parents before children.
		// Build lookup by Skwirrel category ID.
		$by_id = [];
		foreach ( $flat as $cat ) {
			if ( $cat['id'] !== null ) {
				$by_id[ $cat['id'] ] = $cat;
			}
		}

		$resolved      = []; // skwirrel_id => wc_term_id
		$created_count = 0;

		foreach ( $flat as $cat ) {
			$cat_id = $cat['id'] ?? null;
			if ( $cat_id !== null && isset( $resolved[ $cat_id ] ) ) {
				continue;
			}

			$parent_id = $cat['parent_id'] ?? null;
			$wc_parent = 0;

			// Resolve parent first
			if ( $parent_id !== null && isset( $resolved[ $parent_id ] ) ) {
				$wc_parent = $resolved[ $parent_id ];
			} elseif ( $parent_id !== null && $parent_id !== $super_id ) {
				// Parent not yet resolved but exists in our set — find/create it
				if ( isset( $by_id[ $parent_id ] ) ) {
					$wc_parent = $this->find_or_create_category_term(
						$parent_id,
						$by_id[ $parent_id ]['name'],
						$tax,
						$cat_id_meta,
						0
					);
					if ( $wc_parent ) {
						$resolved[ $parent_id ] = $wc_parent;
					}
				}
			}

			$wc_term_id = $this->find_or_create_category_term(
				$cat_id,
				$cat['name'],
				$tax,
				$cat_id_meta,
				$wc_parent
			);

			if ( $wc_term_id && $cat_id !== null ) {
				$resolved[ $cat_id ] = $wc_term_id;
				++$created_count;
			}
		}

		$this->logger->info(
			'Category tree synced',
			[
				'super_category_id' => $super_id,
				'total_categories'  => count( $flat ),
				'resolved'          => $created_count,
			]
		);
	}

	/**
	 * Assign product categories to a WooCommerce product.
	 *
	 * Matches by Skwirrel category ID first (term meta), then by name.
	 * Supports parent/child hierarchy from _categories data.
	 *
	 * @param int                              $wc_product_id WooCommerce product ID.
	 * @param array                            $product       Skwirrel product data.
	 * @param Skwirrel_WC_Sync_Product_Mapper  $mapper        Product mapper instance.
	 */
	public function assign_categories( int $wc_product_id, array $product, Skwirrel_WC_Sync_Product_Mapper $mapper ): void {
		$categories = $mapper->get_categories( $product );
		if ( empty( $categories ) ) {
			return;
		}

		$tax         = 'product_cat';
		$term_ids    = [];
		$cat_id_meta = Skwirrel_WC_Sync_Product_Mapper::CATEGORY_ID_META;

		// Build lookup: skwirrel_id → category entry (for parent resolution)
		$by_skwirrel_id = [];
		foreach ( $categories as $cat ) {
			if ( $cat['id'] !== null ) {
				$by_skwirrel_id[ $cat['id'] ] = $cat;
			}
		}

		// Resolve the full tree in topological order (roots first).
		$resolved = []; // skwirrel_id => wc_term_id

		// Recursive resolver — resolves parent chain before the category itself.
		$resolve = function ( array $cat ) use (
			&$resolve,
			&$resolved,
			&$term_ids,
			$by_skwirrel_id,
			$tax,
			$cat_id_meta
		): int {
			$cat_id = $cat['id'] ?? null;

			// Already resolved?
			if ( $cat_id !== null && isset( $resolved[ $cat_id ] ) ) {
				return $resolved[ $cat_id ];
			}

			$parent_id         = $cat['parent_id'] ?? null;
			$wc_parent_term_id = 0;

			// Resolve parent first (if it exists in our tree)
			if ( $parent_id !== null && isset( $by_skwirrel_id[ $parent_id ] ) ) {
				$wc_parent_term_id = $resolve( $by_skwirrel_id[ $parent_id ] );
			} elseif ( $parent_id !== null || ( $cat['parent_name'] ?? '' ) !== '' ) {
				// Parent not in our tree — look up / create by ID+name
				$wc_parent_term_id = $this->find_or_create_category_term(
					$parent_id,
					$cat['parent_name'] ?? '',
					$tax,
					$cat_id_meta,
					0
				);
				if ( $wc_parent_term_id && $parent_id !== null ) {
					$resolved[ $parent_id ] = $wc_parent_term_id;
				}
			}

			// Resolve the category itself
			$wc_term_id = $this->find_or_create_category_term(
				$cat_id,
				$cat['name'],
				$tax,
				$cat_id_meta,
				$wc_parent_term_id
			);

			if ( $wc_term_id ) {
				$term_ids[] = $wc_term_id;
				if ( $cat_id !== null ) {
					$resolved[ $cat_id ] = $wc_term_id;
				}
				// Include all ancestors in the product's terms
				if ( $wc_parent_term_id ) {
					$term_ids[] = $wc_parent_term_id;
				}
			}

			return $wc_term_id;
		};

		foreach ( $categories as $cat ) {
			$resolve( $cat );
		}

		$term_ids = array_unique( array_map( 'intval', $term_ids ) );
		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $wc_product_id, $term_ids, $tax );
			$this->logger->verbose(
				'Categories assigned',
				[
					'wc_product_id' => $wc_product_id,
					'term_ids'      => $term_ids,
					'names'         => array_column( $categories, 'name' ),
				]
			);
		}
	}

	/**
	 * Find existing term by Skwirrel category ID (term meta) or name, or create new.
	 *
	 * @param int|null $skwirrel_id    Skwirrel category ID (null if unknown).
	 * @param string   $name           Category name.
	 * @param string   $taxonomy       Taxonomy slug (product_cat).
	 * @param string   $meta_key       Term meta key for Skwirrel ID.
	 * @param int      $parent_term_id WC parent term ID (0 for root).
	 * @return int WC term_id or 0 on failure.
	 */
	public function find_or_create_category_term(
		?int $skwirrel_id,
		string $name,
		string $taxonomy,
		string $meta_key,
		int $parent_term_id
	): int {
		if ( $name === '' && $skwirrel_id === null ) {
			return 0;
		}

		// Track seen category IDs for purge logic
		if ( $skwirrel_id !== null ) {
			$this->seen_category_ids[] = (string) $skwirrel_id;
		}

		// 1. Match by Skwirrel category ID in term meta (reliable)
		if ( $skwirrel_id !== null ) {
			global $wpdb;
			$existing_term_id = $wpdb->get_var(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- term meta lookup by value not supported by WP API
					"SELECT tm.term_id FROM {$wpdb->termmeta} tm
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id AND tt.taxonomy = %s
                 WHERE tm.meta_key = %s AND tm.meta_value = %s
                 LIMIT 1",
					$taxonomy,
					$meta_key,
					(string) $skwirrel_id
				)
			);
			if ( $existing_term_id ) {
				return (int) $existing_term_id;
			}
		}

		// 2. Fall back to name matching
		if ( $name !== '' ) {
			$term = term_exists( $name, $taxonomy, $parent_term_id ?: 0 );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
				// Store Skwirrel ID for next sync
				if ( $skwirrel_id !== null ) {
					update_term_meta( $term_id, $meta_key, (string) $skwirrel_id );
				}
				return $term_id;
			}
		}

		// 3. Create new term
		if ( $name === '' ) {
			return 0;
		}
		$args = [];
		if ( $parent_term_id ) {
			$args['parent'] = $parent_term_id;
		}
		$inserted = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $inserted ) ) {
			// Handle "term already exists" race condition
			if ( $inserted->get_error_code() === 'term_exists' ) {
				$term_id = (int) $inserted->get_error_data( 'term_exists' );
				if ( $skwirrel_id !== null && $term_id ) {
					update_term_meta( $term_id, $meta_key, (string) $skwirrel_id );
				}
				return $term_id;
			}
			$this->logger->warning(
				'Failed to create category term',
				[
					'name'  => $name,
					'error' => $inserted->get_error_message(),
				]
			);
			return 0;
		}

		$term_id = (int) $inserted['term_id'];
		if ( $skwirrel_id !== null ) {
			update_term_meta( $term_id, $meta_key, (string) $skwirrel_id );
		}
		$this->logger->verbose(
			'Category term created',
			[
				'term_id'     => $term_id,
				'name'        => $name,
				'skwirrel_id' => $skwirrel_id,
				'parent'      => $parent_term_id,
			]
		);
		return $term_id;
	}

	/**
	 * Recursively flatten a nested category tree into a flat list.
	 *
	 * @param array  $categories Nested category array from API.
	 * @param array  $flat       Output: flat list of ['id', 'name', 'parent_id'].
	 * @param string $lang       Preferred language for category name.
	 */
	private function flatten_category_tree( array $categories, array &$flat, string $lang ): void {
		foreach ( $categories as $cat ) {
			$cat_id = $cat['category_id'] ?? $cat['product_category_id'] ?? $cat['id'] ?? null;
			if ( $cat_id !== null ) {
				$cat_id = (int) $cat_id;
			}

			$name = $this->pick_category_name( $cat, $lang );
			if ( $name === '' && isset( $cat['category_name'] ) ) {
				$name = $cat['category_name'];
			}

			$parent_id = $cat['parent_category_id'] ?? null;
			if ( $parent_id !== null ) {
				$parent_id = (int) $parent_id;
			}

			if ( $name !== '' ) {
				$flat[] = [
					'id'        => $cat_id,
					'name'      => $name,
					'parent_id' => $parent_id,
				];
			}

			// Recurse into children
			$children = $cat['_children'] ?? $cat['_categories'] ?? $cat['children'] ?? [];
			if ( ! empty( $children ) && is_array( $children ) ) {
				$this->flatten_category_tree( $children, $flat, $lang );
			}
		}
	}

	/**
	 * Pick the best category name based on language preference.
	 *
	 * @param array  $cat  Category data from API.
	 * @param string $lang Preferred language code.
	 * @return string Category name, or empty string if none found.
	 */
	private function pick_category_name( array $cat, string $lang ): string {
		$translations = $cat['_category_translations'] ?? [];
		if ( ! empty( $translations ) && is_array( $translations ) ) {
			foreach ( $translations as $t ) {
				$t_lang = $t['language'] ?? '';
				if ( stripos( $t_lang, $lang ) === 0 || stripos( $lang, $t_lang ) === 0 ) {
					$name = $t['category_name'] ?? $t['product_category_name'] ?? $t['name'] ?? '';
					if ( $name !== '' ) {
						return $name;
					}
				}
			}
			// Fallback: first translation with a name
			foreach ( $translations as $t ) {
				$name = $t['category_name'] ?? $t['product_category_name'] ?? $t['name'] ?? '';
				if ( $name !== '' ) {
					return $name;
				}
			}
		}
		return $cat['category_name'] ?? $cat['product_category_name'] ?? $cat['name'] ?? '';
	}
}
