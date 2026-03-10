<?php
/**
 * Skwirrel Sync Service.
 *
 * Orchestrates product sync: fetches from API, maps, upserts to WooCommerce.
 * Supports full sync and delta sync (updated_on filter).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Service {

	private Skwirrel_WC_Sync_Logger $logger;
	private Skwirrel_WC_Sync_Product_Mapper $mapper;
	private Skwirrel_WC_Sync_Purge_Handler $purge_handler;
	private Skwirrel_WC_Sync_Category_Sync $category_sync;
	private Skwirrel_WC_Sync_Brand_Sync $brand_sync;
	private Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager;
	private Skwirrel_WC_Sync_Product_Upserter $upserter;

	public function __construct() {
		$this->logger           = new Skwirrel_WC_Sync_Logger();
		$this->mapper           = new Skwirrel_WC_Sync_Product_Mapper();
		$lookup                 = new Skwirrel_WC_Sync_Product_Lookup( $this->mapper );
		$this->purge_handler    = new Skwirrel_WC_Sync_Purge_Handler( $this->logger );
		$this->category_sync    = new Skwirrel_WC_Sync_Category_Sync( $this->logger );
		$this->brand_sync       = new Skwirrel_WC_Sync_Brand_Sync( $this->logger );
		$this->taxonomy_manager = new Skwirrel_WC_Sync_Taxonomy_Manager( $this->logger );
		$this->upserter         = new Skwirrel_WC_Sync_Product_Upserter(
			$this->logger,
			$this->mapper,
			$lookup,
			$this->category_sync,
			$this->brand_sync,
			$this->taxonomy_manager,
			new Skwirrel_WC_Sync_Slug_Resolver()
		);
	}

	/**
	 * Run sync in phases. Returns summary array.
	 *
	 * Phases:
	 * 1. Fetch — paginate through API, collect all product data into memory
	 * 2. Products — create/update WC products (basic fields only)
	 * 3. Taxonomy — assign categories, brands, manufacturers
	 * 4. Attributes — assign ETIM + custom class attributes
	 * 5. Media — download images + documents (slowest phase)
	 * 6. Cleanup — flush deferred attrs, purge stale, persist history
	 *
	 * @param bool   $delta   Use delta sync (updated_on >= last sync) if possible.
	 * @param string $trigger What initiated the sync: 'manual' or 'scheduled'.
	 * @return array{success: bool, created: int, updated: int, failed: int, error?: string}
	 */
	public function run_sync( bool $delta = false, string $trigger = Skwirrel_WC_Sync_History::TRIGGER_MANUAL ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running sync requires no time limit
		}

		$sync_started_at = time();
		$this->category_sync->reset_seen_category_ids();
		Skwirrel_WC_Sync_History::sync_heartbeat();
		Skwirrel_WC_Sync_History::clear_sync_progress();

		$client = $this->get_client();
		if ( ! $client ) {
			$this->logger->error( 'Sync aborted: invalid configuration' );
			return [
				'success' => false,
				'error'   => 'Invalid configuration',
				'created' => 0,
				'updated' => 0,
				'failed'  => 0,
			];
		}

		$options     = $this->get_options();
		$created     = 0;
		$updated     = 0;
		$failed      = 0;
		$delta_since = get_option( Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, '' );

		$collection_ids = $this->get_collection_ids();
		$batch_size     = (int) ( $options['batch_size'] ?? 100 );

		// Build API include flags (used as top-level params for getProducts, nested 'options' for getProductsByFilter).
		$api_includes = [
			'include_product_status'       => true,
			'include_product_translations' => true,
			'include_attachments'          => true,
			'include_trade_items'          => true,
			'include_trade_item_prices'    => true,
			'include_categories'           => ! empty( $options['sync_categories'] ),
			'include_product_groups'       => ! empty( $options['sync_categories'] ) || ! empty( $options['sync_grouped_products'] ),
			'include_grouped_products'     => ! empty( $options['sync_grouped_products'] ),
			'include_etim'                 => true,
			'include_etim_translations'    => true,
			'include_languages'            => $this->get_include_languages(),
			'include_contexts'             => [ 1 ],
		];

		// Custom classes
		$sync_cc    = ! empty( $options['sync_custom_classes'] );
		$sync_ti_cc = ! empty( $options['sync_trade_item_custom_classes'] );
		if ( $sync_cc ) {
			$api_includes['include_custom_classes'] = true;
			$cc_filter_mode                         = $options['custom_class_filter_mode'] ?? '';
			$cc_raw                                 = $options['custom_class_filter_ids'] ?? '';
			$cc_parsed                              = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_raw );
			if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
				$api_includes['include_custom_class_id'] = $cc_parsed['ids'];
			}
		}
		if ( $sync_ti_cc ) {
			$api_includes['include_trade_item_custom_classes'] = true;
			$cc_filter_mode                                    = $cc_filter_mode ?? ( $options['custom_class_filter_mode'] ?? '' );
			$cc_raw    = $cc_raw ?? ( $options['custom_class_filter_ids'] ?? '' );
			$cc_parsed = $cc_parsed ?? Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_raw );
			if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
				$api_includes['include_trade_item_custom_class_id'] = $cc_parsed['ids'];
			}
		}

		// Determine whether to use getProducts (fast, full sync) or getProductsByFilter (filtered).
		$use_filter = false;
		$filter     = [];
		if ( $delta && ! empty( $delta_since ) ) {
			$use_filter           = true;
			$filter['updated_on'] = [
				'datetime' => $delta_since,
				'operator' => '>=',
			];
		}
		if ( ! empty( $collection_ids ) ) {
			$use_filter                     = true;
			$filter['dynamic_selection_id'] = $collection_ids[0];
		}

		$this->logger->verbose(
			'Sync started',
			[
				'delta'          => $delta,
				'delta_since'    => $delta_since,
				'batch_size'     => $batch_size,
				'api_method'     => $use_filter ? 'getProductsByFilter' : 'getProducts',
				'collection_ids' => $collection_ids ? $collection_ids : '(all)',
				'filter'         => $filter ? $filter : '(none)',
			]
		);

		// Pre-sync: category tree, brands, custom classes, grouped products
		if ( ! empty( $options['sync_categories'] ) && ! empty( $options['super_category_id'] ) ) {
			$this->category_sync->sync_category_tree( $client, $options, $this->get_include_languages() );
		}
		$this->brand_sync->sync_all_brands( $client );
		if ( ! empty( $options['sync_custom_classes'] ) || ! empty( $options['sync_trade_item_custom_classes'] ) ) {
			$this->taxonomy_manager->sync_all_custom_classes( $client, $options, $this->get_include_languages() );
		}

		$product_to_group_map = [];
		if ( ! empty( $options['sync_grouped_products'] ) ) {
			$grouped_result       = $this->upserter->sync_grouped_products_first( $client, $options );
			$product_to_group_map = $grouped_result['map'];
			$created             += $grouped_result['created'];
			$updated             += $grouped_result['updated'];
		}

		// =====================================================================
		// Phase: Fetch — paginate through API, collect all product data
		// =====================================================================
		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_FETCH,
			0,
			0,
			__( 'Fetching products from API…', 'skwirrel-pim-sync' )
		);

		$sync_items    = []; // [{product, group_info, wc_id, outcome}]
		$virtual_items = []; // [{product, wc_variable_id}] — images for variable products
		$page          = 1;

		$result = $this->fetch_products_page( $client, $use_filter, $filter, $api_includes, $batch_size, $page );
		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error( 'Sync API error', $err );
			Skwirrel_WC_Sync_History::update_last_result( false, $created, $updated, $failed, $err['message'] ?? '', 0, 0, 0, 0, $trigger );
			return [
				'success' => false,
				'error'   => $err['message'] ?? 'API error',
				'created' => 0,
				'updated' => 0,
				'failed'  => 0,
			];
		}

		$data     = $result['result'] ?? [];
		$products = $data['products'] ?? [];

		if ( $delta && empty( $products ) ) {
			$this->logger->info( 'Delta sync: no products updated since last sync' );
			Skwirrel_WC_Sync_History::update_last_result( true, 0, 0, 0, '', 0, 0, 0, 0, $trigger );
			return [
				'success' => true,
				'created' => 0,
				'updated' => 0,
				'failed'  => 0,
			];
		}

		do {
			$this->logger->verbose(
				'Fetching batch',
				[
					'page'  => $page,
					'count' => count( $products ),
				]
			);

			foreach ( $products as $product ) {
				$skwirrel_product_id = $product['product_id'] ?? $product['id'] ?? null;

				// Virtual products → collect for phase 4 (media on parent)
				$virtual_info = null;
				if ( null !== $skwirrel_product_id ) {
					$virtual_info = $product_to_group_map[ 'virtual:' . (int) $skwirrel_product_id ] ?? null;
				}
				if ( $virtual_info && ! empty( $virtual_info['is_virtual_for_variable'] ) ) {
					$virtual_items[] = [
						'product'        => $product,
						'wc_variable_id' => $virtual_info['wc_variable_id'],
					];
					continue;
				}

				// Skip non-grouped VIRTUAL products
				if ( ( $product['product_type'] ?? '' ) === 'VIRTUAL' ) {
					continue;
				}

				// Resolve group info
				$sku_for_lookup = (string) ( $product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? $this->mapper->get_sku( $product ) );
				$group_info     = null;
				if ( null !== $skwirrel_product_id && '' !== $skwirrel_product_id ) {
					$group_info = $product_to_group_map[ (int) $skwirrel_product_id ] ?? null;
				}
				if ( ! $group_info && '' !== $sku_for_lookup ) {
					$group_info = $product_to_group_map[ 'sku:' . $sku_for_lookup ] ?? null;
				}

				$sync_items[] = [
					'product'    => $product,
					'group_info' => $group_info,
					'wc_id'      => 0,
					'outcome'    => 'skipped',
				];
			}

			Skwirrel_WC_Sync_History::update_phase_progress(
				Skwirrel_WC_Sync_History::PHASE_FETCH,
				count( $sync_items ),
				0,
				/* translators: %d = number of products fetched so far */
				sprintf( __( 'Fetching products from API… (%d found)', 'skwirrel-pim-sync' ), count( $sync_items ) )
			);

			if ( count( $products ) < $batch_size ) {
				break;
			}

			++$page;
			$result = $this->fetch_products_page( $client, $use_filter, $filter, $api_includes, $batch_size, $page );
			if ( ! $result['success'] ) {
				$this->logger->error( 'Pagination failed', $result['error'] ?? [] );
				break;
			}
			$data     = $result['result'] ?? [];
			$products = $data['products'] ?? [];
		} while ( ! empty( $products ) );

		$total = count( $sync_items );
		$this->logger->info( "Fetch complete: {$total} products to process in phases" );

		// =====================================================================
		// Phase 1: Products — create/update with basic fields
		// =====================================================================
		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_PRODUCTS,
			0,
			$total,
			__( 'Creating & updating products…', 'skwirrel-pim-sync' )
		);

		foreach ( $sync_items as $i => &$item ) {
			try {
				$result_item = $item['group_info']
					? $this->upserter->create_or_update_variation(
						apply_filters( 'skwirrel_wc_sync_product_before_variation', $item['product'], $item['group_info'] ),
						$item['group_info']
					)
					: $this->upserter->create_or_update_product( $item['product'] );

				$item['wc_id']   = $result_item['wc_id'];
				$item['outcome'] = $result_item['outcome'];

				if ( 'created' === $result_item['outcome'] ) {
					++$created;
				} elseif ( 'updated' === $result_item['outcome'] ) {
					++$updated;
				} else {
					++$failed;
				}
			} catch ( Throwable $e ) {
				++$failed;
				$this->logger->error(
					'Product create/update failed',
					[
						'product' => $item['product']['internal_product_code'] ?? $item['product']['product_id'] ?? '?',
						'error'   => $e->getMessage(),
					]
				);
			}

			if ( ( $i + 1 ) % 25 === 0 || $i === $total - 1 ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_PRODUCTS,
					$i + 1,
					$total,
					__( 'Creating & updating products…', 'skwirrel-pim-sync' )
				);
			}
		}
		unset( $item );

		// =====================================================================
		// Phase 2: Taxonomy — categories, brands, manufacturers
		// =====================================================================
		$taxonomy_label = ! empty( $options['sync_manufacturers'] )
			? __( 'Assigning categories, brands & manufacturers…', 'skwirrel-pim-sync' )
			: __( 'Assigning categories & brands…', 'skwirrel-pim-sync' );

		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_TAXONOMY,
			0,
			$total,
			$taxonomy_label
		);

		foreach ( $sync_items as $i => $item ) {
			if ( ! $item['wc_id'] || 'skipped' === $item['outcome'] ) {
				continue;
			}
			try {
				// For variations, taxonomy goes to the parent variable product
				$tax_target = $item['group_info']['wc_variable_id'] ?? $item['wc_id'];
				$this->upserter->assign_taxonomy( $tax_target, $item['product'] );
			} catch ( Throwable $e ) {
				$this->logger->warning(
					'Taxonomy assignment failed',
					[
						'wc_id' => $item['wc_id'],
						'error' => $e->getMessage(),
					]
				);
			}

			if ( ( $i + 1 ) % 50 === 0 || $i === $total - 1 ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_TAXONOMY,
					$i + 1,
					$total,
					$taxonomy_label
				);
			}
		}

		// =====================================================================
		// Phase 3: Attributes — ETIM + custom class
		// =====================================================================
		$with_attrs    = 0;
		$without_attrs = 0;

		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_ATTRIBUTES,
			0,
			$total,
			__( 'Connecting attributes…', 'skwirrel-pim-sync' )
		);

		foreach ( $sync_items as $i => $item ) {
			if ( ! $item['wc_id'] || 'skipped' === $item['outcome'] ) {
				continue;
			}
			try {
				$attr_count = $this->upserter->assign_attributes( $item['wc_id'], $item['product'], $item['group_info'] );
				if ( $attr_count > 0 ) {
					++$with_attrs;
				} else {
					++$without_attrs;
				}
			} catch ( Throwable $e ) {
				$this->logger->warning(
					'Attribute assignment failed',
					[
						'wc_id' => $item['wc_id'],
						'error' => $e->getMessage(),
					]
				);
			}

			if ( ( $i + 1 ) % 25 === 0 || $i === $total - 1 ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_ATTRIBUTES,
					$i + 1,
					$total,
					__( 'Connecting attributes…', 'skwirrel-pim-sync' )
				);
			}
		}

		// Flush deferred parent attribute terms
		$this->upserter->flush_parent_attribute_terms();

		// =====================================================================
		// Phase 4: Media — images + documents (slowest phase)
		// =====================================================================
		$media_total = $total + count( $virtual_items );

		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_MEDIA,
			0,
			$media_total,
			__( 'Downloading images & documents…', 'skwirrel-pim-sync' )
		);

		$media_i = 0;
		foreach ( $sync_items as $item ) {
			if ( ! $item['wc_id'] || 'skipped' === $item['outcome'] ) {
				++$media_i;
				continue;
			}
			try {
				$this->upserter->assign_media( $item['wc_id'], $item['product'] );
			} catch ( Throwable $e ) {
				$this->logger->warning(
					'Media assignment failed',
					[
						'wc_id' => $item['wc_id'],
						'error' => $e->getMessage(),
					]
				);
			}
			++$media_i;

			if ( 0 === $media_i % 10 || $media_i === $media_total ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_MEDIA,
					$media_i,
					$media_total,
					__( 'Downloading images & documents…', 'skwirrel-pim-sync' )
				);
			}
		}

		// Virtual products: assign images/documents to parent variable product
		foreach ( $virtual_items as $vi ) {
			try {
				$this->upserter->assign_media( $vi['wc_variable_id'], $vi['product'] );
			} catch ( Throwable $e ) {
				$this->logger->warning(
					'Virtual product media failed',
					[
						'wc_variable_id' => $vi['wc_variable_id'],
						'error'          => $e->getMessage(),
					]
				);
			}
			++$media_i;

			if ( 0 === $media_i % 10 || $media_i === $media_total ) {
				Skwirrel_WC_Sync_History::update_phase_progress(
					Skwirrel_WC_Sync_History::PHASE_MEDIA,
					$media_i,
					$media_total,
					__( 'Downloading images & documents…', 'skwirrel-pim-sync' )
				);
			}
		}

		// Free memory — product data is no longer needed
		unset( $sync_items, $virtual_items );

		// =====================================================================
		// Phase 5: Cleanup — purge stale, persist history
		// =====================================================================
		Skwirrel_WC_Sync_History::update_phase_progress(
			Skwirrel_WC_Sync_History::PHASE_CLEANUP,
			0,
			1,
			__( 'Cleaning up…', 'skwirrel-pim-sync' )
		);

		$trashed            = 0;
		$categories_removed = 0;
		if ( ! empty( $options['purge_stale_products'] ) ) {
			if ( $delta ) {
				$this->logger->verbose( 'Purge skipped: delta sync (only during full sync)' );
			} else {
				$trashed = $this->purge_handler->purge_stale_products( $sync_started_at, $this->mapper );
				if ( ! empty( $options['sync_categories'] ) ) {
					$categories_removed = $this->purge_handler->purge_stale_categories( $this->category_sync->get_seen_category_ids() );
				}
			}
		}

		update_option( Skwirrel_WC_Sync_History::OPTION_LAST_SYNC, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		Skwirrel_WC_Sync_History::update_last_result( true, $created, $updated, $failed, '', $with_attrs, $without_attrs, $trashed, $categories_removed, $trigger );

		$this->logger->info(
			'Sync completed',
			[
				'created'            => $created,
				'updated'            => $updated,
				'failed'             => $failed,
				'trashed'            => $trashed,
				'categories_removed' => $categories_removed,
				'with_attributes'    => $with_attrs,
				'without_attributes' => $without_attrs,
			]
		);

		return [
			'success'            => true,
			'created'            => $created,
			'updated'            => $updated,
			'failed'             => $failed,
			'trashed'            => $trashed,
			'categories_removed' => $categories_removed,
		];
	}

	/**
	 * Upsert single product. Delegates to ProductUpserter.
	 *
	 * @param array $product Skwirrel product data.
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function upsert_product( array $product ): string {
		return $this->upserter->upsert_product( $product );
	}

	/**
	 * Sync a single product by its Skwirrel product_id.
	 *
	 * Fetches the product from the API using getProducts with a product_ids filter,
	 * then upserts it into WooCommerce including categories, brands, and attributes.
	 *
	 * @param int $skwirrel_product_id Skwirrel product_id.
	 * @return array{success: bool, outcome?: string, error?: string}
	 */
	public function sync_single_product( int $skwirrel_product_id ): array {
		$client = $this->get_client();
		if ( ! $client ) {
			return [
				'success' => false,
				'error'   => 'Invalid API configuration',
			];
		}

		$options     = $this->get_options();
		$req_options = [
			'include_product_status'       => true,
			'include_product_translations' => true,
			'include_attachments'          => true,
			'include_trade_items'          => true,
			'include_trade_item_prices'    => true,
			'include_categories'           => ! empty( $options['sync_categories'] ),
			'include_product_groups'       => ! empty( $options['sync_categories'] ) || ! empty( $options['sync_grouped_products'] ),
			'include_grouped_products'     => ! empty( $options['sync_grouped_products'] ),
			'include_etim'                 => true,
			'include_etim_translations'    => true,
			'include_languages'            => $this->get_include_languages(),
			'include_contexts'             => [ 1 ],
		];

		$sync_cc    = ! empty( $options['sync_custom_classes'] );
		$sync_ti_cc = ! empty( $options['sync_trade_item_custom_classes'] );
		if ( $sync_cc ) {
			$req_options['include_custom_classes'] = true;
		}
		if ( $sync_ti_cc ) {
			$req_options['include_trade_item_custom_classes'] = true;
		}

		$this->logger->info(
			'Single product sync: fetching product from API',
			[ 'skwirrel_product_id' => $skwirrel_product_id ]
		);

		$result = $client->call(
			'getProductsByFilter',
			[
				'filter'  => [
					'code' => [
						'type'  => 'product_id',
						'codes' => [ (string) $skwirrel_product_id ],
					],
				],
				'options' => $req_options,
				'page'    => 1,
				'limit'   => 1,
			]
		);

		if ( ! $result['success'] ) {
			$err = $result['error'] ?? [ 'message' => 'Unknown error' ];
			$this->logger->error(
				'Single product sync: API error',
				[
					'skwirrel_product_id' => $skwirrel_product_id,
					'error'               => $err,
				]
			);
			return [
				'success' => false,
				'error'   => $err['message'] ?? 'API error',
			];
		}

		$data     = $result['result'] ?? [];
		$products = $data['products'] ?? [];

		$this->logger->info(
			'Single product sync: API returned products',
			[
				'skwirrel_product_id' => $skwirrel_product_id,
				'products_returned'   => count( $products ),
			]
		);

		$product = $products[0] ?? null;

		if ( null === $product ) {
			return [
				'success' => false,
				'error'   => 'Product not found in Skwirrel API',
			];
		}

		try {
			$outcome = $this->upserter->upsert_product( $product );

			$this->logger->info(
				'Single product sync completed',
				[
					'skwirrel_product_id' => $skwirrel_product_id,
					'outcome'             => $outcome,
				]
			);

			return [
				'success' => true,
				'outcome' => $outcome,
			];
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Single product sync failed',
				[
					'skwirrel_product_id' => $skwirrel_product_id,
					'error'               => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	private function get_client(): ?Skwirrel_WC_Sync_JsonRpc_Client {
		$opts  = $this->get_options();
		$url   = $opts['endpoint_url'] ?? '';
		$auth  = $opts['auth_type'] ?? 'bearer';
		$token = Skwirrel_WC_Sync_Admin_Settings::get_auth_token();
		if ( empty( $url ) || empty( $token ) ) {
			return null;
		}
		return new Skwirrel_WC_Sync_JsonRpc_Client(
			$url,
			$auth,
			$token,
			(int) ( $opts['timeout'] ?? 30 ),
			(int) ( $opts['retries'] ?? 2 )
		);
	}

	/**
	 * Fetch a page of products from the API.
	 *
	 * Uses getProducts (faster) for full sync or getProductsByFilter when a filter is needed.
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client       API client.
	 * @param bool                            $use_filter   Whether to use getProductsByFilter.
	 * @param array                           $filter       Filter params for getProductsByFilter.
	 * @param array                           $api_includes Include flags.
	 * @param int                             $batch_size   Products per page.
	 * @param int                             $page         Page number.
	 * @return array API result array.
	 */
	private function fetch_products_page(
		Skwirrel_WC_Sync_JsonRpc_Client $client,
		bool $use_filter,
		array $filter,
		array $api_includes,
		int $batch_size,
		int $page
	): array {
		if ( $use_filter ) {
			return $client->call(
				'getProductsByFilter',
				[
					'filter'  => $filter,
					'options' => $api_includes,
					'page'    => $page,
					'limit'   => $batch_size,
				]
			);
		}

		// Full sync: use getProducts with include flags as top-level params (faster API endpoint).
		return $client->call(
			'getProducts',
			array_merge(
				$api_includes,
				[
					'page'  => $page,
					'limit' => $batch_size,
				]
			)
		);
	}

	private function get_options(): array {
		$defaults = [
			'endpoint_url'          => '',
			'auth_type'             => 'bearer',
			'auth_token'            => '',
			'timeout'               => 30,
			'retries'               => 2,
			'batch_size'            => 100,
			'sync_categories'       => true,
			'sync_grouped_products' => false,
			'sync_images'           => true,
			'image_language'        => 'nl',
			'include_languages'     => [ 'nl-NL', 'nl' ],
			'verbose_logging'       => false,
		];
		$saved    = get_option( 'skwirrel_wc_sync_settings', [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Get collection IDs from settings. Returns array of int IDs, or empty array for "sync all".
	 */
	private function get_collection_ids(): array {
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		$raw  = $opts['collection_ids'] ?? '';
		if ( '' === $raw || ! is_string( $raw ) ) {
			return [];
		}
		$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_map( 'intval', array_filter( $parts, 'is_numeric' ) ) );
	}

	private function get_include_languages(): array {
		$opts  = get_option( 'skwirrel_wc_sync_settings', [] );
		$langs = $opts['include_languages'] ?? [ 'nl-NL', 'nl' ];
		if ( ! empty( $langs ) && is_array( $langs ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $langs ) ) );
		}
		return [ 'nl-NL', 'nl' ];
	}
}
