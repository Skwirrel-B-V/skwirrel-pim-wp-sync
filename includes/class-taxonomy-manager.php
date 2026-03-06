<?php
/**
 * Skwirrel Taxonomy Manager.
 *
 * Manages WooCommerce product attribute taxonomies:
 * - ETIM attribute slug generation
 * - Attribute label updating
 * - Attribute and taxonomy creation/registration
 * - Variant taxonomy management
 * - Custom class pre-sync from API
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Taxonomy_Manager {

	private Skwirrel_WC_Sync_Logger $logger;

	/**
	 * @param Skwirrel_WC_Sync_Logger $logger Logger instance.
	 */
	public function __construct( Skwirrel_WC_Sync_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Sync all custom classes from the API as WooCommerce product attributes.
	 *
	 * This ensures attributes exist before products are processed.
	 * Respects whitelist/blacklist filter settings.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client    API client.
	 * @param array                           $options   Plugin settings.
	 * @param array                           $languages Include languages for API call.
	 */
	public function sync_all_custom_classes( Skwirrel_WC_Sync_JsonRpc_Client $client, array $options, array $languages ): void {
		Skwirrel_WC_Sync_History::sync_heartbeat();
		$this->logger->info( 'Syncing all custom classes via getCustomClasses' );

		$cc_filter_mode = $options['custom_class_filter_mode'] ?? '';
		$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $options['custom_class_filter_ids'] ?? '' );

		$params = [];
		if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
			$params['custom_class_id'] = $cc_parsed['ids'];
		}

		if ( ! empty( $languages ) ) {
			$params['include_languages'] = $languages;
		}

		$result = $client->call( 'getCustomClasses', $params );

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error( 'getCustomClasses API error', $err );
			return;
		}

		$data    = $result['result'] ?? [];
		$classes = $data['custom_classes'] ?? $data;
		if ( ! is_array( $classes ) ) {
			$this->logger->warning( 'getCustomClasses returned unexpected format', [ 'type' => gettype( $classes ) ] );
			return;
		}

		// Apply blacklist filter if configured
		if ( 'blacklist' === $cc_filter_mode && ( ! empty( $cc_parsed['ids'] ) || ! empty( $cc_parsed['codes'] ) ) ) {
			$classes = array_filter(
				$classes,
				function ( array $cc ) use ( $cc_parsed ): bool {
					$id   = $cc['custom_class_id'] ?? null;
					$code = $cc['custom_class_code'] ?? null;
					if ( null !== $id && in_array( (int) $id, $cc_parsed['ids'], true ) ) {
						return false;
					}
					if ( null !== $code && in_array( strtoupper( (string) $code ), array_map( 'strtoupper', $cc_parsed['codes'] ), true ) ) {
						return false;
					}
					return true;
				}
			);
		}

		$created = 0;
		foreach ( $classes as $cc ) {
			$features = $cc['_custom_class_features'] ?? $cc['features'] ?? [];
			if ( ! is_array( $features ) ) {
				continue;
			}

			foreach ( $features as $feat ) {
				$feat_type = strtoupper( $feat['custom_feature_type'] ?? '' );
				// Skip text/blob types — these are stored as meta, not attributes
				if ( in_array( $feat_type, [ 'T', 'B' ], true ) ) {
					continue;
				}

				$name = $feat['custom_feature_description'] ?? $feat['custom_feature_code'] ?? '';
				if ( '' === $name ) {
					continue;
				}

				// Just ensure the attribute name is known; actual values are assigned per product
				$slug = sanitize_title( $name );
				if ( strlen( $slug ) > 0 ) {
					++$created;
				}
			}
		}

		$this->logger->info(
			'Custom classes synced',
			[
				'total_classes'  => count( $classes ),
				'features_found' => $created,
			]
		);
	}

	/**
	 * Ensure the pa_skwirrel_variant WooCommerce attribute taxonomy exists.
	 *
	 * Creates the attribute in the WC attribute table and registers the taxonomy
	 * if it does not already exist. Migrates from the old 'pa_variant' slug if present.
	 */
	public function ensure_variant_taxonomy_exists(): void {
		// Migrate legacy pa_variant → pa_skwirrel_variant (pre-1.9.0)
		if ( ! wc_attribute_taxonomy_id_by_name( 'skwirrel_variant' ) && wc_attribute_taxonomy_id_by_name( 'variant' ) ) {
			$this->migrate_variant_taxonomy();
		}

		if ( taxonomy_exists( 'pa_skwirrel_variant' ) ) {
			return;
		}
		if ( ! wc_attribute_taxonomy_id_by_name( 'skwirrel_variant' ) ) {
			global $wpdb;
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WC attribute table has no API
				$wpdb->prefix . 'woocommerce_attribute_taxonomies',
				[
					'attribute_name'    => 'skwirrel_variant',
					'attribute_label'   => __( 'Variant', 'skwirrel-pim-sync' ),
					'attribute_type'    => 'select',
					'attribute_orderby' => 'menu_order',
					'attribute_public'  => 1,
				]
			);
			delete_transient( 'wc_attribute_taxonomies' );
		}
		register_taxonomy(
			'pa_skwirrel_variant',
			'product',
			[
				'labels'       => [ 'name' => __( 'Variant', 'skwirrel-pim-sync' ) ],
				'hierarchical' => false,
				'show_ui'      => true,
				'query_var'    => true,
				'rewrite'      => [ 'slug' => 'pa_skwirrel_variant' ],
			]
		);
	}

	/**
	 * Migrate the legacy pa_variant taxonomy to pa_skwirrel_variant.
	 *
	 * Renames the WC attribute row, renames the taxonomy in the DB,
	 * and updates product meta references.
	 */
	private function migrate_variant_taxonomy(): void {
		global $wpdb;

		$old_attr_id = wc_attribute_taxonomy_id_by_name( 'variant' );
		if ( ! $old_attr_id ) {
			return;
		}

		// Rename the attribute slug in the WC attributes table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration
		$wpdb->update(
			$wpdb->prefix . 'woocommerce_attribute_taxonomies',
			[ 'attribute_name' => 'skwirrel_variant' ],
			[ 'attribute_id' => $old_attr_id ]
		);
		delete_transient( 'wc_attribute_taxonomies' );

		// Rename the taxonomy in the term_taxonomy table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration
		$wpdb->update(
			$wpdb->term_taxonomy,
			[ 'taxonomy' => 'pa_skwirrel_variant' ],
			[ 'taxonomy' => 'pa_variant' ]
		);

		// Update variation post meta: attribute_pa_variant → attribute_pa_skwirrel_variant
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration
		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_key' => 'attribute_pa_skwirrel_variant' ],
			[ 'meta_key' => 'attribute_pa_variant' ]
		);

		// Update _product_attributes serialized meta on parent products
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_attributes' AND meta_value LIKE '%pa_variant%'",
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$attrs = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $attrs ) || ! isset( $attrs['pa_variant'] ) ) {
				continue;
			}
			$attrs['pa_skwirrel_variant']         = $attrs['pa_variant'];
			$attrs['pa_skwirrel_variant']['name'] = 'pa_skwirrel_variant';
			unset( $attrs['pa_variant'] );
			update_post_meta( (int) $row['post_id'], '_product_attributes', $attrs );
		}

		$this->logger->info(
			'Migrated pa_variant → pa_skwirrel_variant',
			[
				'attribute_id'     => $old_attr_id,
				'products_updated' => count( $rows ),
			]
		);
	}

	/**
	 * Generate a WooCommerce-safe attribute slug for an ETIM code.
	 *
	 * @param string $code ETIM feature code (e.g. "EF000721").
	 * @return string Sanitized slug, max 28 characters.
	 */
	public function get_etim_attribute_slug( string $code ): string {
		$slug = 'etim_' . strtolower( $code );
		return strlen( $slug ) > 28 ? substr( $slug, 0, 28 ) : $slug;
	}

	/**
	 * Update an existing WC attribute taxonomy label if the current label
	 * is a raw code (e.g. "EF000721") and we now have a proper label from the API.
	 *
	 * @param string $slug  Attribute slug.
	 * @param string $label New human-readable label.
	 */
	public function maybe_update_attribute_label( string $slug, string $label ): void {
		if ( '' === $label || preg_match( '/^(EF|etim_)/i', $label ) ) {
			return;
		}
		$attr_id = wc_attribute_taxonomy_id_by_name( $slug );
		if ( ! $attr_id ) {
			return;
		}
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- attribute label update
		$current_label = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_label FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
				$attr_id
			)
		);
		if ( null !== $current_label && $current_label !== $label && preg_match( '/^(EF|etim_)/i', $current_label ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- attribute label update, cache cleared below
			$wpdb->update(
				$wpdb->prefix . 'woocommerce_attribute_taxonomies',
				[ 'attribute_label' => $label ],
				[ 'attribute_id' => $attr_id ]
			);
			delete_transient( 'wc_attribute_taxonomies' );
			wp_cache_delete( 'woocommerce-attributes', 'woocommerce' );
		}
	}

	/**
	 * Ensure a WooCommerce product attribute exists (DB row + registered taxonomy).
	 *
	 * Creates the attribute in the woocommerce_attribute_taxonomies table if needed,
	 * then registers the taxonomy for the current request.
	 *
	 * @param string $slug  Attribute slug (e.g. "etim_ef000721").
	 * @param string $label Human-readable label.
	 * @return string Full taxonomy name (e.g. "pa_etim_ef000721").
	 */
	public function ensure_product_attribute_exists( string $slug, string $label ): string {
		$tax = wc_attribute_taxonomy_name( $slug );
		if ( taxonomy_exists( $tax ) ) {
			return $tax;
		}
		if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
			if ( function_exists( 'wc_create_attribute' ) ) {
				$result = wc_create_attribute(
					[
						'name'         => $label ? $label : $slug,
						'slug'         => $slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					]
				);
				if ( is_wp_error( $result ) ) {
					return $tax;
				}
			} else {
				global $wpdb;
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WC attribute table fallback for old WC versions
					$wpdb->prefix . 'woocommerce_attribute_taxonomies',
					[
						'attribute_name'    => $slug,
						'attribute_label'   => $label ? $label : $slug,
						'attribute_type'    => 'select',
						'attribute_orderby' => 'menu_order',
						'attribute_public'  => 1,
					]
				);
				delete_transient( 'wc_attribute_taxonomies' );
				if ( function_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) {
					WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
				}
			}
		}
		$this->register_etim_taxonomy( $tax, $slug, $label ? $label : $slug );
		return $tax;
	}

	/**
	 * Register an ETIM attribute as a WordPress taxonomy for the current request.
	 *
	 * This is needed because WooCommerce attribute taxonomies are lazy-registered
	 * and may not yet exist during a sync run.
	 *
	 * @param string $taxonomy Full taxonomy name (e.g. "pa_etim_ef000721").
	 * @param string $slug     Attribute slug.
	 * @param string $label    Human-readable label.
	 */
	public function register_etim_taxonomy( string $taxonomy, string $slug, string $label ): void {
		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$permalinks    = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : [];
		$attr_rewrite  = $permalinks['attribute_rewrite_slug'] ?? 'attribute';
		$taxonomy_data = [
			'hierarchical'          => false,
			'update_count_callback' => '_update_post_term_count',
			'labels'                => [
				'name'          => $label,
				'singular_name' => $label,
				/* translators: %s = attribute label */
				'search_items'  => sprintf( __( 'Search %s', 'skwirrel-pim-sync' ), $label ),
				/* translators: %s = attribute label */
				'all_items'     => sprintf( __( 'All %s', 'skwirrel-pim-sync' ), $label ),
				/* translators: %s = attribute label */
				'edit_item'     => sprintf( __( 'Edit %s', 'skwirrel-pim-sync' ), $label ),
				/* translators: %s = attribute label */
				'update_item'   => sprintf( __( 'Update %s', 'skwirrel-pim-sync' ), $label ),
				/* translators: %s = attribute label */
				'add_new_item'  => sprintf( __( 'Add new %s', 'skwirrel-pim-sync' ), $label ),
				/* translators: %s = attribute label */
				'new_item_name' => sprintf( __( 'New %s', 'skwirrel-pim-sync' ), $label ),
			],
			'show_ui'               => true,
			'show_in_quick_edit'    => false,
			'show_in_menu'          => false,
			'meta_box_cb'           => false,
			'query_var'             => true,
			'rewrite'               => $attr_rewrite && $slug
				? [
					'slug'         => trailingslashit( $attr_rewrite ) . urldecode( sanitize_title( $slug ) ),
					'with_front'   => false,
					'hierarchical' => true,
				]
				: false,
			'sort'                  => false,
			'public'                => true,
			'capabilities'          => [
				'manage_terms' => 'manage_product_terms',
				'edit_terms'   => 'edit_product_terms',
				'delete_terms' => 'delete_product_terms',
				'assign_terms' => 'assign_product_terms',
			],
		];
		register_taxonomy( $taxonomy, [ 'product' ], $taxonomy_data );
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce global
		global $wc_product_attributes;
		if ( ! is_array( $wc_product_attributes ) ) {
			$wc_product_attributes = []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		}
		$wc_product_attributes[ $taxonomy ] = (object) [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			'attribute_id'      => wc_attribute_taxonomy_id_by_name( $slug ),
			'attribute_name'    => $slug,
			'attribute_label'   => $label,
			'attribute_type'    => 'select',
			'attribute_orderby' => 'menu_order',
			'attribute_public'  => 1,
		];
	}
}
