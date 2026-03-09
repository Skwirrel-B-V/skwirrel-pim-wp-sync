<?php
/**
 * Skwirrel Brand & Manufacturer Sync.
 *
 * Handles WooCommerce product_brand and product_manufacturer taxonomy operations:
 * - Registers taxonomies if they don't already exist
 * - Full brand sync from Skwirrel API (getBrands)
 * - Per-product brand and manufacturer assignment
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Brand_Sync {

	public const BRAND_TAXONOMY        = 'product_brand';
	public const MANUFACTURER_TAXONOMY = 'product_manufacturer';

	private Skwirrel_WC_Sync_Logger $logger;

	/**
	 * @param Skwirrel_WC_Sync_Logger $logger Logger instance.
	 */
	public function __construct( Skwirrel_WC_Sync_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register product_brand taxonomy if no other plugin has done so.
	 */
	public function maybe_register_brand_taxonomy(): void {
		if ( taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return;
		}

		register_taxonomy(
			self::BRAND_TAXONOMY,
			[ 'product' ],
			[
				'labels'            => [
					'name'          => __( 'Brands', 'skwirrel-pim-sync' ),
					'singular_name' => __( 'Brand', 'skwirrel-pim-sync' ),
					'search_items'  => __( 'Search brands', 'skwirrel-pim-sync' ),
					'all_items'     => __( 'All brands', 'skwirrel-pim-sync' ),
					'edit_item'     => __( 'Edit brand', 'skwirrel-pim-sync' ),
					'update_item'   => __( 'Update brand', 'skwirrel-pim-sync' ),
					'add_new_item'  => __( 'Add new brand', 'skwirrel-pim-sync' ),
					'new_item_name' => __( 'New brand name', 'skwirrel-pim-sync' ),
					'menu_name'     => __( 'Brands', 'skwirrel-pim-sync' ),
				],
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => [ 'slug' => 'brand' ],
			]
		);
	}

	/**
	 * Register product_manufacturer taxonomy if no other plugin has done so.
	 */
	public function maybe_register_manufacturer_taxonomy(): void {
		if ( taxonomy_exists( self::MANUFACTURER_TAXONOMY ) ) {
			return;
		}

		$permalink_opts    = Skwirrel_WC_Sync_Permalink_Settings::get_options();
		$manufacturer_slug = $permalink_opts['manufacturer_base'] ?? 'manufacturer';

		register_taxonomy(
			self::MANUFACTURER_TAXONOMY,
			[ 'product' ],
			[
				'labels'            => [
					'name'          => __( 'Manufacturers', 'skwirrel-pim-sync' ),
					'singular_name' => __( 'Manufacturer', 'skwirrel-pim-sync' ),
					'search_items'  => __( 'Search manufacturers', 'skwirrel-pim-sync' ),
					'all_items'     => __( 'All manufacturers', 'skwirrel-pim-sync' ),
					'edit_item'     => __( 'Edit manufacturer', 'skwirrel-pim-sync' ),
					'update_item'   => __( 'Update manufacturer', 'skwirrel-pim-sync' ),
					'add_new_item'  => __( 'Add new manufacturer', 'skwirrel-pim-sync' ),
					'new_item_name' => __( 'New manufacturer name', 'skwirrel-pim-sync' ),
					'menu_name'     => __( 'Manufacturers', 'skwirrel-pim-sync' ),
				],
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => [ 'slug' => $manufacturer_slug ],
			]
		);
	}

	/**
	 * Sync all brands from the API, independent of products.
	 *
	 * Creates product_brand terms for every brand returned by getBrands.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client API client.
	 */
	public function sync_all_brands( Skwirrel_WC_Sync_JsonRpc_Client $client ): void {
		if ( ! taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return;
		}

		Skwirrel_WC_Sync_History::sync_heartbeat();
		$this->logger->info( 'Syncing all brands via getBrands' );

		$result = $client->call( 'getBrands', [] );

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error( 'getBrands API error', $err );
			return;
		}

		$data   = $result['result'] ?? [];
		$brands = $data['brands'] ?? $data;
		if ( ! is_array( $brands ) ) {
			$this->logger->warning( 'getBrands returned unexpected format', [ 'type' => gettype( $brands ) ] );
			return;
		}

		$created = 0;
		foreach ( $brands as $brand ) {
			$brand_name = trim( $brand['brand_name'] ?? $brand['name'] ?? '' );
			if ( $brand_name === '' ) {
				continue;
			}

			$term = term_exists( $brand_name, self::BRAND_TAXONOMY );
			if ( $term && ! is_wp_error( $term ) ) {
				continue; // Already exists
			}

			$inserted = wp_insert_term( $brand_name, self::BRAND_TAXONOMY );
			if ( is_wp_error( $inserted ) ) {
				if ( $inserted->get_error_code() !== 'term_exists' ) {
					$this->logger->warning(
						'Failed to create brand term',
						[
							'brand' => $brand_name,
							'error' => $inserted->get_error_message(),
						]
					);
				}
			} else {
				++$created;
			}
		}

		$this->logger->info(
			'Brands synced',
			[
				'total'   => count( $brands ),
				'created' => $created,
			]
		);
	}

	/**
	 * Assign product_brand taxonomy term from Skwirrel brand_name.
	 *
	 * Finds or creates the brand term and assigns it to the product.
	 *
	 * @param int   $wc_product_id WooCommerce product ID.
	 * @param array $product       Skwirrel product data.
	 */
	public function assign_brand( int $wc_product_id, array $product ): void {
		if ( ! taxonomy_exists( self::BRAND_TAXONOMY ) ) {
			return;
		}

		$brand_name = trim( $product['brand_name'] ?? '' );
		if ( $brand_name === '' ) {
			return;
		}

		$term_id = $this->find_or_create_term( $brand_name, self::BRAND_TAXONOMY );
		if ( $term_id === null ) {
			return;
		}

		wp_set_object_terms( $wc_product_id, [ $term_id ], self::BRAND_TAXONOMY );
		$this->logger->verbose(
			'Brand assigned',
			[
				'wc_product_id' => $wc_product_id,
				'brand'         => $brand_name,
				'term_id'       => $term_id,
			]
		);
	}

	/**
	 * Assign product_manufacturer taxonomy term from Skwirrel manufacturer_name.
	 *
	 * Finds or creates the manufacturer term and assigns it to the product.
	 *
	 * @param int   $wc_product_id WooCommerce product ID.
	 * @param array $product       Skwirrel product data.
	 */
	public function assign_manufacturer( int $wc_product_id, array $product ): void {
		if ( ! taxonomy_exists( self::MANUFACTURER_TAXONOMY ) ) {
			return;
		}

		$manufacturer_name = trim( $product['manufacturer_name'] ?? '' );
		if ( $manufacturer_name === '' ) {
			return;
		}

		$term_id = $this->find_or_create_term( $manufacturer_name, self::MANUFACTURER_TAXONOMY );
		if ( $term_id === null ) {
			return;
		}

		wp_set_object_terms( $wc_product_id, [ $term_id ], self::MANUFACTURER_TAXONOMY );
		$this->logger->verbose(
			'Manufacturer assigned',
			[
				'wc_product_id' => $wc_product_id,
				'manufacturer'  => $manufacturer_name,
				'term_id'       => $term_id,
			]
		);
	}

	/**
	 * Find or create a taxonomy term by name.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int|null Term ID, or null on failure.
	 */
	private function find_or_create_term( string $name, string $taxonomy ): ?int {
		$term = term_exists( $name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
		}

		$inserted = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $inserted ) ) {
			if ( $inserted->get_error_code() === 'term_exists' ) {
				return (int) $inserted->get_error_data( 'term_exists' );
			}
			$this->logger->warning(
				'Failed to create term',
				[
					'name'     => $name,
					'taxonomy' => $taxonomy,
					'error'    => $inserted->get_error_message(),
				]
			);
			return null;
		}

		$this->logger->verbose(
			'Term created',
			[
				'term_id'  => (int) $inserted['term_id'],
				'name'     => $name,
				'taxonomy' => $taxonomy,
			]
		);
		return (int) $inserted['term_id'];
	}
}
