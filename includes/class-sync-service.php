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
	 * Run sync. Returns summary array.
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

		$get_params = [
			'page'                         => 1,
			'limit'                        => (int) ( $options['batch_size'] ?? 100 ),
			'include_product_status'       => true,
			'include_product_translations' => true,
			'include_attachments'          => true,
			'include_trade_items'          => true,
			'include_trade_item_prices'    => true,
			'include_categories'           => ! empty( $options['sync_categories'] ),
			// Product groups: needed for _product_groups (eTIM can be nested here)
			'include_product_groups'       => ! empty( $options['sync_categories'] ) || ! empty( $options['sync_grouped_products'] ),
			// Grouped products: include when product is a variation (may affect eTIM structure)
			'include_grouped_products'     => ! empty( $options['sync_grouped_products'] ),
			'include_etim'                 => true,
			// Note: include_etim_features exists only for getGroupedProducts, not getProducts
			'include_etim_translations'    => true,
			'include_languages'            => $this->get_include_languages(),
			'include_contexts'             => [ 1 ],
		];

		// Custom classes: product-level
		$sync_cc    = ! empty( $options['sync_custom_classes'] );
		$sync_ti_cc = ! empty( $options['sync_trade_item_custom_classes'] );
		if ( $sync_cc ) {
			$get_params['include_custom_classes'] = true;
			// Whitelist: pass specific IDs to the API for efficient filtering
			$cc_filter_mode = $options['custom_class_filter_mode'] ?? '';
			$cc_raw         = $options['custom_class_filter_ids'] ?? '';
			$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_raw );
			if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
				$get_params['include_custom_class_id'] = $cc_parsed['ids'];
			}
		}
		if ( $sync_ti_cc ) {
			$get_params['include_trade_item_custom_classes'] = true;
			$cc_filter_mode                                  = $cc_filter_mode ?? ( $options['custom_class_filter_mode'] ?? '' );
			$cc_raw    = $cc_raw ?? ( $options['custom_class_filter_ids'] ?? '' );
			$cc_parsed = $cc_parsed ?? Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_raw );
			if ( 'whitelist' === $cc_filter_mode && ! empty( $cc_parsed['ids'] ) ) {
				$get_params['include_trade_item_custom_class_id'] = $cc_parsed['ids'];
			}
		}

		// Collection ID filter: only sync products from these collections (empty = all)
		// Note: exact API parameter name may need adjustment — logged for debugging
		if ( ! empty( $collection_ids ) ) {
			$get_params['collection_ids'] = $collection_ids;
		}

		$this->logger->verbose(
			'Sync started',
			[
				'delta'          => $delta,
				'delta_since'    => $delta_since,
				'batch_size'     => $get_params['limit'],
				'include_etim'   => true,
				'collection_ids' => $collection_ids ? $collection_ids : '(all)',
			]
		);

		// Sync category tree from super category (before products)
		if ( ! empty( $options['sync_categories'] ) && ! empty( $options['super_category_id'] ) ) {
			$this->category_sync->sync_category_tree( $client, $options, $this->get_include_languages() );
		}

		// Sync all brands (independent of products)
		$this->brand_sync->sync_all_brands( $client );

		// Sync all custom classes as WooCommerce attributes (independent of products)
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

		if ( $delta && ! empty( $delta_since ) ) {
			$req_options = $get_params;
			unset( $req_options['page'], $req_options['limit'] );
			$result = $client->call(
				'getProductsByFilter',
				[
					'filter'  => [
						'updated_on' => [
							'datetime' => $delta_since,
							'operator' => '>=',
						],
					],
					'options' => $req_options,
					'page'    => 1,
					'limit'   => $get_params['limit'],
				]
			);
		} else {
			$result = $client->call( 'getProducts', $get_params );
		}

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

		$this->logger->verbose(
			'API response received',
			[
				'products_count' => count( $products ),
				'page'           => 1,
			]
		);

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

		$page                 = 1;
		$total_processed      = 0;
		$with_attrs           = 0;
		$without_attrs        = 0;
		$logged_first_product = false;

		do {
			$this->logger->verbose(
				'Processing batch',
				[
					'page'  => $page,
					'count' => count( $products ),
				]
			);

			// Log first product's raw structure once per sync for diagnostics
			if ( ! $logged_first_product && ! empty( $products[0] ) ) {
				$first = $products[0];
				$this->logger->info(
					'First product structure sample',
					[
						'product_id'            => $first['product_id'] ?? '?',
						'has__categories'       => isset( $first['_categories'] ),
						'_categories_count'     => is_array( $first['_categories'] ?? null ) ? count( $first['_categories'] ) : 0,
						'_categories_sample'    => is_array( $first['_categories'] ?? null ) ? array_slice( $first['_categories'], 0, 2 ) : null,
						'has__product_groups'   => isset( $first['_product_groups'] ),
						'_product_groups_count' => is_array( $first['_product_groups'] ?? null ) ? count( $first['_product_groups'] ) : 0,
						'_product_groups_names' => is_array( $first['_product_groups'] ?? null )
							? array_column( $first['_product_groups'], 'product_group_name' )
							: null,
						'top_level_keys'        => array_keys( $first ),
					]
				);
				$logged_first_product = true;
			}

			foreach ( $products as $product ) {
				Skwirrel_WC_Sync_History::sync_heartbeat();
				try {
					$product_id          = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
					$skwirrel_product_id = $product['product_id'] ?? $product['id'] ?? null;

					// Check if this is a virtual product for a variable product
					$virtual_info = null;
					if ( null !== $skwirrel_product_id ) {
						$virtual_info = $product_to_group_map[ 'virtual:' . (int) $skwirrel_product_id ] ?? null;
					}

					// If this product is a virtual product for a variable product, assign its images and documents
					if ( $virtual_info && ! empty( $virtual_info['is_virtual_for_variable'] ) ) {
						$wc_variable_id = $virtual_info['wc_variable_id'];
						$this->logger->info(
							'Processing virtual product - assigning images and documents to variable product',
							[
								'virtual_product_id' => $skwirrel_product_id,
								'wc_variable_id'     => $wc_variable_id,
							]
						);

						// Get images from virtual product and assign to variable product
						$img_ids = $this->mapper->get_image_attachment_ids( $product, $wc_variable_id );
						if ( ! empty( $img_ids ) ) {
							$wc_product = wc_get_product( $wc_variable_id );
							if ( $wc_product ) {
								$wc_product->set_image_id( $img_ids[0] );
								$wc_product->set_gallery_image_ids( array_slice( $img_ids, 1 ) );
								$wc_product->save();
								$this->logger->info(
									'Assigned images from virtual product to variable product',
									[
										'wc_variable_id' => $wc_variable_id,
										'image_count'    => count( $img_ids ),
									]
								);
							}
						}

						// Get documents from virtual product and assign to variable product
						$documents = $this->mapper->get_document_attachments( $product, $wc_variable_id );
						if ( ! empty( $documents ) ) {
							update_post_meta( $wc_variable_id, '_skwirrel_document_attachments', $documents );
							$this->logger->info(
								'Assigned documents from virtual product to variable product',
								[
									'wc_variable_id' => $wc_variable_id,
									'document_count' => count( $documents ),
								]
							);
						}

						continue; // Skip creating a product for this virtual product
					}

					// Skip other VIRTUAL type products that aren't virtual products for variable products
					if ( ( $product['product_type'] ?? '' ) === 'VIRTUAL' ) {
						$this->logger->verbose(
							'Skipping virtual product (not used for variable product)',
							[
								'product_id'            => $product['product_id'] ?? '?',
								'internal_product_code' => $product['internal_product_code'] ?? '',
							]
						);
						continue;
					}
					$sku_for_lookup = (string) ( $product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? $this->mapper->get_sku( $product ) );
					$group_info     = null;
					if ( null !== $skwirrel_product_id && '' !== $skwirrel_product_id ) {
						$group_info = $product_to_group_map[ (int) $skwirrel_product_id ] ?? null;
					}
					if ( ! $group_info && '' !== $sku_for_lookup ) {
						$group_info = $product_to_group_map[ 'sku:' . $sku_for_lookup ] ?? null;
					}
					$this->logger->verbose(
						'Product lookup',
						[
							'product_id' => $skwirrel_product_id,
							'sku'        => $sku_for_lookup,
							'in_group'   => (bool) $group_info,
						]
					);
					if ( defined( 'SKWIRREL_WC_SYNC_DEBUG_ETIM' ) && SKWIRREL_WC_SYNC_DEBUG_ETIM && ! $group_info && null !== $skwirrel_product_id ) {
						$upload = wp_upload_dir();
						$dir    = ( $upload['basedir'] ?? '' ) . '/skwirrel-pim-sync';
						wp_mkdir_p( $dir );
						if ( $dir && wp_is_writable( $dir ) ) {
							$line = sprintf(
								"[%s] Product NOT in group: product_id=%s, sku=%s (map has %d product_ids)\n",
								gmdate( 'Y-m-d H:i:s' ),
								$skwirrel_product_id,
								$sku_for_lookup,
								count( array_filter( array_keys( $product_to_group_map ), 'is_int' ) )
							);
							file_put_contents( $dir . '/skwirrel-variation-debug.log', $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- debug-only, writes to uploads/skwirrel-pim-sync/
						}
					}
					$outcome = $group_info
						? $this->upserter->upsert_product_as_variation(
							apply_filters( 'skwirrel_wc_sync_product_before_variation', $product, $group_info ),
							$group_info
						)
						: $this->upserter->upsert_product( $product );
					if ( 'skipped' !== $outcome ) {
						$attrs      = $this->mapper->get_attributes( $product );
						$attr_count = count( $attrs );
						if ( $attr_count > 0 ) {
							++$with_attrs;
							$this->logger->verbose(
								'Product has attributes',
								[
									'product'    => $product_id,
									'outcome'    => $outcome,
									'attr_count' => $attr_count,
									'attrs'      => array_keys( $attrs ),
								]
							);
						} else {
							++$without_attrs;
							$this->logger->verbose(
								'Product has no attributes',
								[
									'product'      => $product_id,
									'outcome'      => $outcome,
									'has__etim'    => isset( $product['_etim'] ),
									'brand'        => $product['brand_name'] ?? null,
									'manufacturer' => $product['manufacturer_name'] ?? null,
								]
							);
						}
					}
					if ( 'created' === $outcome ) {
						++$created;
					} elseif ( 'updated' === $outcome ) {
						++$updated;
					} else {
						++$failed;
					}
				} catch ( Throwable $e ) {
					++$failed;
					$this->logger->error(
						'Product sync failed',
						[
							'product' => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
							'error'   => $e->getMessage(),
						]
					);
				}
			}

			$total_processed += count( $products );
			if ( count( $products ) < $get_params['limit'] ) {
				break;
			}

			++$page;
			$get_params['page'] = $page;
			if ( $delta && ! empty( $delta_since ) ) {
				$req_options = $get_params;
				unset( $req_options['page'], $req_options['limit'] );
				$result = $client->call(
					'getProductsByFilter',
					[
						'filter'  => [
							'updated_on' => [
								'datetime' => $delta_since,
								'operator' => '>=',
							],
						],
						'options' => $req_options,
						'page'    => $page,
						'limit'   => $get_params['limit'],
					]
				);
			} else {
				$result = $client->call( 'getProducts', $get_params );
			}
			if ( ! $result['success'] ) {
				$this->logger->error( 'Pagination failed', $result['error'] ?? [] );
				break;
			}
			$data     = $result['result'] ?? [];
			$products = $data['products'] ?? [];

			$this->logger->verbose(
				'API pagination',
				[
					'page'             => $page,
					'products_in_page' => count( $products ),
				]
			);

		} while ( ! empty( $products ) );

		// Flush deferred parent attribute terms (batch-updates variable product attribute options)
		$this->upserter->flush_parent_attribute_terms();

		$this->logger->verbose( 'Sync finished, persisting last sync timestamp' );

		// Purge stale products/categories (only during full sync without collection filter)
		$trashed            = 0;
		$categories_removed = 0;
		if ( ! empty( $options['purge_stale_products'] ) ) {
			if ( $delta ) {
				$this->logger->verbose( 'Purge overgeslagen: delta sync (alleen bij volledige sync)' );
			} elseif ( ! empty( $collection_ids ) ) {
				$this->logger->warning(
					'Purge overgeslagen: collectie-filter actief. Verwijder het collectie-filter of voer een volledige sync uit zonder filter om verwijderde producten op te ruimen.',
					[
						'collection_ids' => $collection_ids,
					]
				);
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
