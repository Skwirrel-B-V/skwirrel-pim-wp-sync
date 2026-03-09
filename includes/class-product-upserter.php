<?php
/**
 * Skwirrel Product Upserter.
 *
 * Handles all product upsert operations: creating/updating simple products,
 * variable products (from grouped products), and variations.
 * Extracted from Skwirrel_WC_Sync_Service for separation of concerns.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Upserter {

	private Skwirrel_WC_Sync_Logger $logger;
	private Skwirrel_WC_Sync_Product_Mapper $mapper;
	private Skwirrel_WC_Sync_Product_Lookup $lookup;
	private Skwirrel_WC_Sync_Category_Sync $category_sync;
	private Skwirrel_WC_Sync_Brand_Sync $brand_sync;
	private Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager;
	private Skwirrel_WC_Sync_Slug_Resolver $slug_resolver;

	/** @var array<int, array<string, int[]>> Deferred parent term updates: parent_id => [taxonomy => [term_id, ...]] */
	private array $deferred_parent_terms = [];

	/** @var array<int, array<string, string[]>> Deferred non-variation attributes: parent_id => [label => [value, ...]] */
	private array $deferred_parent_attrs = [];

	/**
	 *
	 * @param Skwirrel_WC_Sync_Logger          $logger          Logger instance.
	 * @param Skwirrel_WC_Sync_Product_Mapper   $mapper          Product field mapper.
	 * @param Skwirrel_WC_Sync_Product_Lookup   $lookup          Product lookup helper.
	 * @param Skwirrel_WC_Sync_Category_Sync    $category_sync   Category sync handler.
	 * @param Skwirrel_WC_Sync_Brand_Sync       $brand_sync      Brand sync handler.
	 * @param Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager Taxonomy/attribute manager.
	 * @param Skwirrel_WC_Sync_Slug_Resolver    $slug_resolver   Product slug resolver.
	 */
	public function __construct(
		Skwirrel_WC_Sync_Logger $logger,
		Skwirrel_WC_Sync_Product_Mapper $mapper,
		Skwirrel_WC_Sync_Product_Lookup $lookup,
		Skwirrel_WC_Sync_Category_Sync $category_sync,
		Skwirrel_WC_Sync_Brand_Sync $brand_sync,
		Skwirrel_WC_Sync_Taxonomy_Manager $taxonomy_manager,
		Skwirrel_WC_Sync_Slug_Resolver $slug_resolver
	) {
		$this->logger           = $logger;
		$this->mapper           = $mapper;
		$this->lookup           = $lookup;
		$this->category_sync    = $category_sync;
		$this->brand_sync       = $brand_sync;
		$this->taxonomy_manager = $taxonomy_manager;
		$this->slug_resolver    = $slug_resolver;
	}

	/**
	 * Upsert single product. Returns 'created'|'updated'|'skipped'.
	 *
	 * Lookup chain (eerste match wint):
	 * 1. SKU -> wc_get_product_id_by_sku() (snelste, WC index)
	 * 2. _skwirrel_external_id meta -> find_by_external_id() (betrouwbare API key)
	 * 3. _skwirrel_product_id meta -> find_by_skwirrel_product_id() (stabiele Skwirrel ID)
	 *
	 * @param array $product Skwirrel product data.
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function upsert_product( array $product ): string {
		$key = $this->mapper->get_unique_key( $product );
		if ( ! $key ) {
			$this->logger->warning( 'Product has no unique key, skipping', [ 'product_id' => $product['product_id'] ?? '?' ] );
			return 'skipped';
		}

		$sku                 = $this->mapper->get_sku( $product );
		$skwirrel_product_id = $product['product_id'] ?? null;

		// Stap 1: Zoek op SKU (snelste via WC index)
		$wc_id = wc_get_product_id_by_sku( $sku );

		// Als SKU matcht met een variable product, sla over — dit simple product
		// mag niet het variable product overschrijven
		if ( $wc_id ) {
			$existing = wc_get_product( $wc_id );
			if ( $existing && $existing->is_type( 'variable' ) ) {
				$this->logger->verbose(
					'SKU matcht met variable product, zoek verder',
					[
						'sku'            => $sku,
						'wc_variable_id' => $wc_id,
					]
				);
				$wc_id = 0; // Niet matchen, maar SKU NIET veranderen — we zoeken verder
			}
		}

		// Stap 2: Zoek op _skwirrel_external_id meta
		if ( ! $wc_id ) {
			$wc_id = $this->lookup->find_by_external_id( $key );
		}

		// Stap 3: Zoek op _skwirrel_product_id meta (meest stabiele identifier)
		if ( ! $wc_id && $skwirrel_product_id !== null && $skwirrel_product_id !== '' && $skwirrel_product_id !== 0 ) {
			$wc_id = $this->lookup->find_by_skwirrel_product_id( (int) $skwirrel_product_id );
			if ( $wc_id ) {
				$this->logger->info(
					'Product gevonden via _skwirrel_product_id fallback',
					[
						'skwirrel_product_id' => $skwirrel_product_id,
						'wc_id'               => $wc_id,
					]
				);
			}
		}

		// Voorkom dubbele SKU bij nieuw product: als een ander product al deze SKU heeft,
		// genereer een unieke SKU met suffix
		$is_new = ! $wc_id;
		if ( $is_new ) {
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id ) {
				$original_sku = $sku;
				$sku          = $sku . '-' . ( $skwirrel_product_id ?? uniqid() );
				$this->logger->warning(
					'Dubbele SKU voorkomen bij nieuw product',
					[
						'original_sku'        => $original_sku,
						'new_sku'             => $sku,
						'existing_wc_id'      => $existing_sku_id,
						'skwirrel_product_id' => $skwirrel_product_id,
					]
				);
			}
		} else {
			// Bestaand product: controleer of SKU is veranderd en geen conflict veroorzaakt
			$existing_sku_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_sku_id && (int) $existing_sku_id !== (int) $wc_id ) {
				$original_sku = $sku;
				$sku          = $sku . '-' . ( $skwirrel_product_id ?? uniqid() );
				$this->logger->warning(
					'SKU conflict bij update, unieke SKU gegenereerd',
					[
						'original_sku'      => $original_sku,
						'new_sku'           => $sku,
						'wc_id'             => $wc_id,
						'conflicting_wc_id' => $existing_sku_id,
					]
				);
			}
		}

		$this->logger->verbose(
			'Upsert product',
			[
				'product'      => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
				'sku'          => $sku,
				'key'          => $key,
				'wc_id'        => $wc_id,
				'is_new'       => $is_new,
				'lookup_chain' => $is_new ? 'geen match (nieuw)' : 'gevonden',
			]
		);

		if ( $is_new ) {
			$wc_product = new WC_Product_Simple();
		} else {
			$wc_product = wc_get_product( $wc_id );
			if ( ! $wc_product ) {
				$this->logger->warning( 'WC product not found', [ 'wc_id' => $wc_id ] );
				return 'skipped';
			}
			// Bestaand product dat variable is mag niet overschreven worden als simple
			if ( $wc_product->is_type( 'variable' ) ) {
				$this->logger->warning(
					'Bestaand product is variable, kan niet overschrijven als simple',
					[
						'wc_id'               => $wc_id,
						'sku'                 => $sku,
						'skwirrel_product_id' => $skwirrel_product_id,
					]
				);
				return 'skipped';
			}
		}

		$wc_product->set_sku( $sku );
		$wc_product->set_name( $this->mapper->get_name( $product ) );

		// Set slug for new products; optionally update existing if enabled in permalink settings.
		if ( $is_new || $this->slug_resolver->should_update_on_resync() ) {
			$exclude_id = $is_new ? null : $wc_product->get_id();
			$slug       = $this->slug_resolver->resolve( $product, $exclude_id );
			if ( $slug !== null ) {
				$wc_product->set_slug( $slug );
			}
		}

		$wc_product->set_short_description( $this->mapper->get_short_description( $product ) );
		$wc_product->set_description( $this->mapper->get_long_description( $product ) );
		$wc_product->set_status( $this->mapper->get_status( $product ) );

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$wc_product->set_regular_price( '' );
			$wc_product->set_price( '' );
			$wc_product->set_sold_individually( false );
		} elseif ( $price !== null ) {
			$wc_product->set_regular_price( (string) $price );
			$wc_product->set_price( (string) $price );
		}

		$attrs = $this->mapper->get_attributes( $product );

		// Merge custom class attributes (if enabled)
		$cc_options   = $this->get_options();
		$cc_text_meta = [];
		if ( ! empty( $cc_options['sync_custom_classes'] ) || ! empty( $cc_options['sync_trade_item_custom_classes'] ) ) {
			$cc_filter_mode = $cc_options['custom_class_filter_mode'] ?? '';
			$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_options['custom_class_filter_ids'] ?? '' );
			$include_ti     = ! empty( $cc_options['sync_trade_item_custom_classes'] );

			$cc_attrs = $this->mapper->get_custom_class_attributes(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);
			// Merge: custom class attrs after ETIM attrs (ETIM takes precedence on name conflict)
			foreach ( $cc_attrs as $name => $value ) {
				if ( ! isset( $attrs[ $name ] ) ) {
					$attrs[ $name ] = $value;
				}
			}

			$cc_text_meta = $this->mapper->get_custom_class_text_meta(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);
		}

		$wc_product->save();

		$id = $wc_product->get_id();
		update_post_meta( $id, $this->mapper->get_external_id_meta_key(), $key );
		update_post_meta( $id, $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		update_post_meta( $id, $this->mapper->get_synced_at_meta_key(), time() );

		$img_ids = $this->mapper->get_image_attachment_ids( $product, $id );
		if ( ! empty( $img_ids ) ) {
			$wc_product->set_image_id( $img_ids[0] );           // First image = featured
			$wc_product->set_gallery_image_ids( array_slice( $img_ids, 1 ) ); // All others = gallery
			$wc_product->save();
		}

		$downloads = $this->mapper->get_downloadable_files( $product, $id );
		if ( ! empty( $downloads ) ) {
			$wc_product->set_downloadable( true );
			$wc_product->set_downloads( $this->format_downloads( $downloads ) );
			$wc_product->save();
		}

		$documents = $this->mapper->get_document_attachments( $product, $id );
		update_post_meta( $id, '_skwirrel_document_attachments', $documents );

		// Save custom class text meta (T/B types)
		if ( ! empty( $cc_text_meta ) ) {
			foreach ( $cc_text_meta as $meta_key => $meta_value ) {
				update_post_meta( $id, $meta_key, $meta_value );
			}
			$this->logger->verbose(
				'Custom class text meta saved',
				[
					'wc_id'     => $id,
					'meta_keys' => array_keys( $cc_text_meta ),
				]
			);
		}

		$this->category_sync->assign_categories( $id, $product, $this->mapper );
		$this->brand_sync->assign_brand( $id, $product );

		// Save attributes as global WooCommerce taxonomy-based attributes
		// so they appear in layered navigation and product filters.
		if ( ! empty( $attrs ) ) {
			$wc_attrs = [];
			$position = 0;
			foreach ( $attrs as $name => $value ) {
				$term_data = $this->taxonomy_manager->ensure_attribute_term( $name, (string) $value );
				if ( ! $term_data ) {
					continue;
				}
				$tax  = $term_data['taxonomy'];
				$slug = $this->taxonomy_manager->get_attribute_slug( $name );

				wp_set_object_terms( $id, [ $term_data['term_id'] ], $tax, false );

				$attr = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
				$attr->set_name( $tax );
				$attr->set_options( [ $term_data['term_id'] ] );
				$attr->set_position( $position++ );
				$attr->set_visible( true );
				$attr->set_variation( false );
				$wc_attrs[ $tax ] = $attr;
			}
			if ( ! empty( $wc_attrs ) ) {
				$wc_product->set_attributes( $wc_attrs );
				$wc_product->save();
				clean_post_cache( $id );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $id );
				}
			}
			$this->logger->verbose(
				'Attributes saved as global taxonomies',
				[
					'wc_id'      => $id,
					'attr_count' => count( $wc_attrs ),
					'names'      => array_keys( $attrs ),
				]
			);
		}

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * Voeg product uit getProducts toe als variation aan variable product.
	 *
	 * @param array $product    Skwirrel product data.
	 * @param array $group_info Group mapping info (wc_variable_id, etim_variation_codes, etc.).
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function upsert_product_as_variation( array $product, array $group_info ): string {
		$wc_variable_id = $group_info['wc_variable_id'] ?? 0;
		$sku            = $group_info['sku'] ?? $this->mapper->get_sku( $product );
		if ( ! $wc_variable_id ) {
			return $this->upsert_product( $product );
		}

		$variation_id = $this->lookup->find_variation_by_sku( $wc_variable_id, $sku );
		if ( ! $variation_id ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $wc_variable_id );
		} else {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				return 'skipped';
			}
		}

		$variation->set_sku( $sku );
		$variation->set_status( 'publish' ); // Ensure variation is enabled
		$variation->set_catalog_visibility( 'visible' ); // Make visible in catalog

		$price = $this->mapper->get_regular_price( $product );
		if ( $this->mapper->is_price_on_request( $product ) ) {
			$variation->set_regular_price( '' );
			$variation->set_price( '' );
			$variation->set_stock_status( 'outofstock' ); // Price on request = out of stock
		} elseif ( $price !== null && $price > 0 ) {
			$variation->set_regular_price( (string) $price );
			$variation->set_price( (string) $price );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( false ); // Don't manage stock, always available
		} else {
			// No price available - set to 0 and log warning
			$this->logger->warning(
				'Variation has no price, setting to 0',
				[
					'sku'             => $sku,
					'product_id'      => $product['product_id'] ?? '?',
					'has_trade_items' => ! empty( $product['_trade_items'] ?? [] ),
				]
			);
			$variation->set_regular_price( '0' );
			$variation->set_price( '0' );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( false );
		}

		$variation_attrs = [];
		$etim_codes      = $group_info['etim_variation_codes'] ?? [];
		$etim_values     = [];
		if ( ! empty( $etim_codes ) ) {
			$lang        = $this->get_include_languages();
			$lang        = ! empty( $lang ) ? $lang[0] : ( get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl' );
			$etim_values = $this->mapper->get_etim_feature_values_for_codes( $product, $etim_codes, $lang );
			$this->logger->verbose(
				'Variation eTIM lookup',
				[
					'sku'                => $sku,
					'etim_codes'         => array_column( $etim_codes, 'code' ),
					'etim_values_found'  => array_keys( $etim_values ),
					'has_product_etim'   => isset( $product['_etim'] ),
					'has_product_groups' => ! empty( $product['_product_groups'] ?? [] ),
				]
			);
			foreach ( $etim_codes as $ef ) {
				$code = strtoupper( (string) ( $ef['code'] ?? '' ) );
				$data = $etim_values[ $code ] ?? null;
				if ( ! $data ) {
					continue;
				}
				$slug  = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$tax   = wc_attribute_taxonomy_name( $slug );
				$label = ! empty( $data['label'] ) ? $data['label'] : $code;
				if ( ! taxonomy_exists( $tax ) ) {
					$this->taxonomy_manager->ensure_product_attribute_exists( $slug, $label );
				} else {
					$this->taxonomy_manager->maybe_update_attribute_label( $slug, $label );
				}
				$term = get_term_by( 'slug', $data['slug'], $tax ) ?: get_term_by( 'name', $data['value'], $tax );
				if ( ! $term || is_wp_error( $term ) ) {
					$insert = wp_insert_term( $data['value'], $tax, [ 'slug' => $data['slug'] ] );
					$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], $tax ) : null;
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$variation_attrs[ $tax ] = $term->slug;
					// Track term for deferred parent update (applied after all variations are processed)
					$this->deferred_parent_terms[ $wc_variable_id ][ $tax ][] = $term->term_id;
				}
			}
		}
		if ( empty( $variation_attrs ) ) {
			// Fallback to pa_skwirrel_variant only when parent uses pa_skwirrel_variant (no eTIM attributes)
			$parent_uses_etim = ! empty( $etim_codes );
			if ( $parent_uses_etim ) {
				$this->logger->warning(
					'Variation has no eTIM values; parent expects eTIM attributes',
					[
						'sku'            => $sku,
						'wc_variable_id' => $wc_variable_id,
						'etim_codes'     => array_column( $etim_codes ?? [], 'code' ),
					]
				);
				if ( defined( 'SKWIRREL_WC_SYNC_DEBUG_ETIM' ) && SKWIRREL_WC_SYNC_DEBUG_ETIM ) {
					$dump    = wp_upload_dir();
					$sub_dir = ( $dump['basedir'] ?? '' ) . '/skwirrel-pim-sync';
					wp_mkdir_p( $sub_dir );
					$file = $sub_dir . '/skwirrel-etim-debug-' . $sku . '.json';
					if ( $file && wp_is_writable( $sub_dir ) ) {
						file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- debug-only, writes to uploads/skwirrel-pim-sync/
							$file,
							wp_json_encode(
								[
									'sku'             => $sku,
									'product_keys'    => array_keys( $product ),
									'_etim'           => $product['_etim'] ?? null,
									'_etim_features'  => $product['_etim_features'] ?? null,
									'_product_groups' => array_map(
										function ( $g ) {
											return array_intersect_key( $g, array_flip( [ 'product_group_name', '_etim', '_etim_features' ] ) );
										},
										$product['_product_groups'] ?? []
									),
								],
								JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
							)
						);
					}
				}
			}
			$term = get_term_by( 'name', $sku, 'pa_skwirrel_variant' ) ?: get_term_by( 'slug', sanitize_title( $sku ), 'pa_skwirrel_variant' );
			if ( ! $term ) {
				$insert = wp_insert_term( $sku, 'pa_skwirrel_variant' );
				$term   = ! is_wp_error( $insert ) ? get_term( $insert['term_id'], 'pa_skwirrel_variant' ) : null;
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$variation_attrs['pa_skwirrel_variant'] = $term->slug;
			}
		}
		if ( defined( 'SKWIRREL_WC_SYNC_DEBUG_ETIM' ) && SKWIRREL_WC_SYNC_DEBUG_ETIM ) {
			$this->write_variation_debug( $sku, $etim_codes ?? [], $etim_values ?? [], $product, $variation_attrs );
		}

		// Set variation attributes BEFORE saving
		if ( ! empty( $variation_attrs ) ) {
			$variation->set_attributes( $variation_attrs );
		}

		$img_ids = $this->mapper->get_image_attachment_ids( $product, $wc_variable_id );
		if ( ! empty( $img_ids ) ) {
			$variation->set_image_id( $img_ids[0] );
		}

		$variation->update_meta_data( $this->mapper->get_product_id_meta_key(), $product['product_id'] ?? 0 );
		$variation->update_meta_data( $this->mapper->get_external_id_meta_key(), $this->mapper->get_unique_key( $product ) ?? '' );
		$variation->update_meta_data( $this->mapper->get_synced_at_meta_key(), time() );

		// Save variation first to get ID
		$variation->save();
		$vid = $variation->get_id();

		// Explicitly persist variation attributes in post meta
		// WooCommerce variations use ONLY post meta (not term relationships)
		if ( $vid && ! empty( $variation_attrs ) ) {
			foreach ( $variation_attrs as $tax => $term_slug ) {
				// Update post meta with the term slug
				update_post_meta( $vid, 'attribute_' . $tax, wp_slash( $term_slug ) );
			}

			// Log what we're saving
			$this->logger->verbose(
				'Variation attributes saved to meta',
				[
					'sku'        => $sku,
					'vid'        => $vid,
					'attributes' => $variation_attrs,
				]
			);

			// Verify immediately
			$verified = [];
			foreach ( $variation_attrs as $tax => $expected ) {
				$verified[ $tax ] = get_post_meta( $vid, 'attribute_' . $tax, true );
			}

			if ( $verified !== $variation_attrs ) {
				$this->logger->error(
					'Variation attribute meta verification failed',
					[
						'sku'      => $sku,
						'vid'      => $vid,
						'expected' => $variation_attrs,
						'verified' => $verified,
					]
				);
			} else {
				$this->logger->verbose(
					'Variation attribute meta verification SUCCESS',
					[
						'sku'      => $sku,
						'vid'      => $vid,
						'verified' => $verified,
					]
				);
			}

			clean_post_cache( $vid );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $vid );
			}
		}

		// Sync parent product to update available variations
		$wc_product = wc_get_product( $wc_variable_id );
		if ( $wc_product && $wc_product->is_type( 'variable' ) ) {
			try {
				WC_Product_Variable::sync( $wc_variable_id );
				WC_Product_Variable::sync_stock_status( $wc_variable_id );
				clean_post_cache( $wc_variable_id );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $wc_variable_id );
				}
			} catch ( Throwable $e ) {
				$this->logger->warning(
					'Parent sync failed, continuing',
					[
						'wc_variable_id' => $wc_variable_id,
						'error'          => $e->getMessage(),
					]
				);
			}
		}

		// Collect non-variation ETIM + custom class attributes for parent product
		$non_var_attrs = $this->mapper->get_attributes( $product );
		$cc_options    = $this->get_options();
		if ( ! empty( $cc_options['sync_custom_classes'] ) || ! empty( $cc_options['sync_trade_item_custom_classes'] ) ) {
			$cc_filter_mode = $cc_options['custom_class_filter_mode'] ?? '';
			$cc_parsed      = Skwirrel_WC_Sync_Product_Mapper::parse_custom_class_filter( $cc_options['custom_class_filter_ids'] ?? '' );
			$include_ti     = ! empty( $cc_options['sync_trade_item_custom_classes'] );
			$cc_attrs       = $this->mapper->get_custom_class_attributes(
				$product,
				$include_ti,
				$cc_filter_mode,
				$cc_parsed['ids'],
				$cc_parsed['codes']
			);
			foreach ( $cc_attrs as $name => $value ) {
				if ( ! isset( $non_var_attrs[ $name ] ) ) {
					$non_var_attrs[ $name ] = $value;
				}
			}
		}

		// Remove attributes already used as variation axes
		$variation_tax_slugs = array_keys( $variation_attrs );
		foreach ( $variation_tax_slugs as $tax ) {
			// Strip 'pa_' prefix and match against ETIM slug patterns
			$slug = str_replace( 'pa_', '', $tax );
			foreach ( $non_var_attrs as $label => $val ) {
				if ( sanitize_title( $label ) === $slug ) {
					unset( $non_var_attrs[ $label ] );
				}
			}
		}

		// Defer non-variation attributes for parent (merged in flush_parent_attribute_terms)
		if ( ! empty( $non_var_attrs ) ) {
			foreach ( $non_var_attrs as $label => $value ) {
				$this->deferred_parent_attrs[ $wc_variable_id ][ $label ][] = (string) $value;
			}
		}

		do_action( 'skwirrel_wc_sync_after_variation_save', $variation->get_id(), $variation_attrs, $product );

		return $variation_id ? 'updated' : 'created';
	}

	/**
	 * Stap 1: Haal grouped products op, maak variable producten aan (zonder variations).
	 * Retourneert map: product_id => [grouped_product_id, order, sku, wc_variable_id].
	 *
	 * @param Skwirrel_WC_Sync_JsonRpc_Client $client  JSON-RPC client instance.
	 * @param array                           $options Plugin settings array.
	 * @return array{created: int, updated: int, map: array}
	 */
	public function sync_grouped_products_first( Skwirrel_WC_Sync_JsonRpc_Client $client, array $options ): array {
		$created              = 0;
		$updated              = 0;
		$product_to_group_map = [];
		$batch_size           = (int) ( $options['batch_size'] ?? 100 );
		$params               = [
			'page'                      => 1,
			'limit'                     => $batch_size,
			'include_products'          => true,
			'include_etim_features'     => true,
			'include_etim_translations' => true,
			'include_languages'         => $this->get_include_languages(),
		];

		// Pass collection_ids filter to grouped products API call
		$collection_ids = $this->get_collection_ids();
		if ( ! empty( $collection_ids ) ) {
			$params['collection_ids'] = $collection_ids;
		}

		$page = 1;
		do {
			$params['page']  = $page;
			$params['limit'] = $batch_size;
			$result          = $client->call( 'getGroupedProducts', $params );

			if ( ! $result['success'] ) {
				$this->logger->warning( 'getGroupedProducts failed', $result['error'] ?? [] );
				break;
			}

			$data   = $result['result'] ?? [];
			$groups = $data['grouped_products'] ?? $data['groups'] ?? $data['products'] ?? [];
			if ( ! is_array( $groups ) ) {
				$groups = [];
			}

			$page_info    = $data['page'] ?? [];
			$current_page = (int) ( $page_info['current_page'] ?? $page );
			$total_pages  = (int) ( $page_info['number_of_pages'] ?? 1 );

			foreach ( $groups as $group ) {
				try {
					$outcome = $this->create_variable_product_from_group( $group, $product_to_group_map );
					if ( $outcome === 'created' ) {
						++$created;
					} elseif ( $outcome === 'updated' ) {
						++$updated;
					}
				} catch ( Throwable $e ) {
					$this->logger->error(
						'Grouped product sync failed',
						[
							'grouped_product_id' => $group['grouped_product_id'] ?? $group['id'] ?? '?',
							'error'              => $e->getMessage(),
						]
					);
				}
			}

			if ( empty( $groups ) || $current_page >= $total_pages ) {
				break;
			}
			++$page;
		} while ( true );

		$product_ids_in_groups = array_filter( array_keys( $product_to_group_map ), 'is_int' );
		$this->logger->info(
			'Grouped products loaded',
			[
				'variable_products'     => $created + $updated,
				'product_ids_in_groups' => count( $product_ids_in_groups ),
			]
		);
		return [
			'created' => $created,
			'updated' => $updated,
			'map'     => $product_to_group_map,
		];
	}

	/**
	 * Maak variable product aan (zonder variations). Vul product_to_group_map voor later.
	 *
	 * @param array $group              Grouped product data from API.
	 * @param array &$product_to_group_map Reference to the product-to-group mapping array.
	 * @return string 'created'|'updated'|'skipped'
	 */
	public function create_variable_product_from_group( array $group, array &$product_to_group_map ): string {
		$grouped_id = $group['grouped_product_id'] ?? $group['id'] ?? null;
		if ( $grouped_id === null || $grouped_id === '' ) {
			return 'skipped';
		}

		$products     = $group['_products'] ?? $group['products'] ?? [];
		$variant_skus = [];

		foreach ( $products as $item ) {
			$product_id = null;
			$sku        = null;
			$order      = 999;
			if ( is_array( $item ) ) {
				$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : null;
				$sku        = (string) ( $item['internal_product_code'] ?? '' );
				$order      = isset( $item['order'] ) ? (int) $item['order'] : 999;
			} else {
				$product_id = (int) $item;
				$sku        = '';
			}
			if ( $product_id && $sku !== '' ) {
				$variant_skus[] = $sku;
			}
		}

		$wc_id  = $this->lookup->find_by_grouped_product_id( (int) $grouped_id );
		$is_new = ! $wc_id;

		if ( $is_new ) {
			$wc_product = new WC_Product_Variable();
		} else {
			$wc_product = wc_get_product( $wc_id );
			if ( ! $wc_product || ! $wc_product->is_type( 'variable' ) ) {
				$wc_product = new WC_Product_Variable();
				if ( $wc_id ) {
					wp_delete_post( $wc_id, true );
				}
				$is_new = true;
			}
		}

		$name = (string) ( $group['grouped_product_name'] ?? $group['grouped_product_code'] ?? $group['name'] ?? '' );
		if ( $name === '' ) {
			/* translators: %s = grouped product ID */
			$name = sprintf( __( 'Product %s', 'skwirrel-pim-sync' ), $grouped_id );
		}

		$group_sku = (string) ( $group['grouped_product_code'] ?? $group['internal_product_code'] ?? '' );
		if ( $group_sku !== '' ) {
			$wc_product->set_sku( $group_sku );
		}
		$wc_product->set_name( $name );

		// Set slug for new variable products; optionally update existing if enabled in permalink settings.
		if ( $is_new || $this->slug_resolver->should_update_on_resync() ) {
			$exclude_id = $is_new ? null : $wc_product->get_id();
			$slug       = $this->slug_resolver->resolve_for_group( $group, $exclude_id );
			if ( $slug !== null ) {
				$wc_product->set_slug( $slug );
			}
		}

		$wc_product->set_status( ! empty( $group['product_trashed_on'] ) ? 'trash' : 'publish' );
		$wc_product->set_catalog_visibility( 'visible' );
		$wc_product->set_stock_status( 'instock' ); // Parent must be in stock
		$wc_product->set_manage_stock( false ); // Don't manage stock at parent level

		$etim_features        = $group['_etim_features'] ?? [];
		$etim_variation_codes = [];
		if ( is_array( $etim_features ) ) {
			$raw = isset( $etim_features[0] ) ? $etim_features : array_values( $etim_features );
			foreach ( $raw as $f ) {
				if ( is_array( $f ) && ! empty( $f['etim_feature_code'] ) ) {
					$etim_variation_codes[] = [
						'code'  => $f['etim_feature_code'],
						'order' => (int) ( $f['order'] ?? 999 ),
						'label' => $this->mapper->resolve_etim_feature_label( $f ),
					];
				}
			}
			usort( $etim_variation_codes, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		}

		$attrs = [];
		if ( ! empty( $etim_variation_codes ) ) {
			foreach ( $etim_variation_codes as $pos => $ef ) {
				$code      = $ef['code'];
				$etim_slug = $this->taxonomy_manager->get_etim_attribute_slug( $code );
				$label     = ! empty( $ef['label'] ) ? $ef['label'] : $code;
				$tax       = $this->taxonomy_manager->ensure_product_attribute_exists( $etim_slug, $label );
				$attr      = new WC_Product_Attribute();
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $etim_slug ) );
				$attr->set_name( $tax );
				$attr->set_options( [] );
				$attr->set_position( $pos );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attrs[ $tax ] = $attr;
			}
		}
		if ( empty( $attrs ) ) {
			$this->taxonomy_manager->ensure_variant_taxonomy_exists();
			$attr = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( 'skwirrel_variant' ) );
			$attr->set_name( 'pa_skwirrel_variant' );
			$attr->set_options( array_values( array_unique( $variant_skus ) ) );
			$attr->set_position( 0 );
			$attr->set_visible( true );
			$attr->set_variation( true );
			$attrs['pa_skwirrel_variant'] = $attr;
		}
		$wc_product->set_attributes( $attrs );
		$wc_product->save();

		$id = $wc_product->get_id();
		update_post_meta( $id, Skwirrel_WC_Sync_Product_Lookup::GROUPED_PRODUCT_ID_META, (int) $grouped_id );
		update_post_meta( $id, $this->mapper->get_synced_at_meta_key(), time() );

		// Store virtual_product_id if present (this product has images for the variable product)
		$virtual_product_id = $group['virtual_product_id'] ?? null;
		if ( $virtual_product_id ) {
			update_post_meta( $id, '_skwirrel_virtual_product_id', (int) $virtual_product_id );
		}

		foreach ( $products as $item ) {
			$product_id = null;
			$sku        = null;
			$order      = 999;
			if ( is_array( $item ) ) {
				$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : null;
				$sku        = (string) ( $item['internal_product_code'] ?? '' );
				$order      = isset( $item['order'] ) ? (int) $item['order'] : 999;
			}
			if ( $product_id && $sku !== '' ) {
				$info                                      = [
					'grouped_product_id'   => (int) $grouped_id,
					'order'                => $order,
					'sku'                  => $sku,
					'wc_variable_id'       => $id,
					'etim_variation_codes' => $etim_variation_codes,
					'virtual_product_id'   => $virtual_product_id, // Include virtual product ID in map
				];
				$product_to_group_map[ (int) $product_id ] = $info;
				$product_to_group_map[ 'sku:' . $sku ]     = $info;
			}
		}

		// If this group has a virtual product, track it for image assignment
		if ( $virtual_product_id ) {
			$product_to_group_map[ 'virtual:' . (int) $virtual_product_id ] = [
				'wc_variable_id'          => $id,
				'is_virtual_for_variable' => true,
			];
		}

		$this->category_sync->assign_categories( $id, $group, $this->mapper );
		$this->brand_sync->assign_brand( $id, $group );

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * Write variation debug information to log file.
	 *
	 * @param string $sku             Product SKU.
	 * @param array  $etim_codes      ETIM variation codes from group.
	 * @param array  $etim_values     Resolved ETIM feature values.
	 * @param array  $product         Skwirrel product data.
	 * @param array  $variation_attrs Resolved variation attributes (taxonomy => slug).
	 * @return void
	 */
	private function write_variation_debug( string $sku, array $etim_codes, array $etim_values, array $product, array $variation_attrs ): void {
		$upload = wp_upload_dir();
		$dir    = ( $upload['basedir'] ?? '' ) . '/skwirrel-pim-sync';
		wp_mkdir_p( $dir );
		if ( ! $dir || ! wp_is_writable( $dir ) ) {
			return;
		}
		$file = $dir . '/skwirrel-variation-debug.log';
		$line = sprintf(
			"[%s] SKU=%s | etim_codes=%s | etim_values_found=%s | has__etim=%s | variation_attrs=%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$sku,
			wp_json_encode( array_column( $etim_codes, 'code' ) ),
			wp_json_encode( array_keys( $etim_values ) ),
			isset( $product['_etim'] ) ? 'yes' : 'no',
			wp_json_encode( $variation_attrs )
		);
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- debug-only, writes to uploads/skwirrel-pim-sync/
	}

	/**
	 * Flush all deferred parent attribute term updates.
	 *
	 * Called once after all variations have been processed, to update each parent
	 * variable product's attribute options and term relationships in a single pass.
	 * This avoids WC object cache staleness from rapid incremental updates.
	 *
	 * @return void
	 */
	public function flush_parent_attribute_terms(): void {
		if ( empty( $this->deferred_parent_terms ) && empty( $this->deferred_parent_attrs ) ) {
			return;
		}

		foreach ( $this->deferred_parent_terms as $parent_id => $taxonomies ) {
			// Clear all caches to ensure we get the freshest product data
			clean_post_cache( $parent_id );
			wc_delete_product_transients( $parent_id );

			$wc_product = wc_get_product( $parent_id );
			if ( ! $wc_product || ! $wc_product->is_type( 'variable' ) ) {
				$this->logger->warning(
					'Deferred parent term flush: product not found or not variable',
					[
						'parent_id' => $parent_id,
					]
				);
				continue;
			}

			$attrs = $wc_product->get_attributes();
			if ( empty( $attrs ) ) {
				$this->logger->warning(
					'Deferred parent term flush: no attributes on parent',
					[
						'parent_id' => $parent_id,
					]
				);
				continue;
			}

			$changed = false;
			foreach ( $taxonomies as $taxonomy => $term_ids ) {
				if ( ! isset( $attrs[ $taxonomy ] ) ) {
					$this->logger->warning(
						'Deferred parent term flush: attribute not found on parent',
						[
							'parent_id'       => $parent_id,
							'taxonomy'        => $taxonomy,
							'available_attrs' => array_keys( $attrs ),
						]
					);
					continue;
				}

				$attr = $attrs[ $taxonomy ];
				if ( ! $attr->is_taxonomy() ) {
					continue;
				}

				// Merge existing options with all new term IDs, deduplicated
				$existing = $attr->get_options();
				$existing = is_array( $existing ) ? array_map( 'intval', $existing ) : [];
				$merged   = array_unique( array_merge( $existing, array_map( 'intval', $term_ids ) ) );
				$attr->set_options( $merged );
				$attrs[ $taxonomy ] = $attr;

				// Set term relationships directly in DB
				wp_set_object_terms( $parent_id, $merged, $taxonomy, false );
				$changed = true;

				$this->logger->verbose(
					'Parent attribute terms set',
					[
						'parent_id' => $parent_id,
						'taxonomy'  => $taxonomy,
						'term_ids'  => $merged,
					]
				);
			}

			if ( $changed ) {
				$wc_product->set_attributes( $attrs );
				$wc_product->save();
				$this->logger->verbose(
					'Parent product saved with attribute terms',
					[
						'parent_id'       => $parent_id,
						'attribute_count' => count( $attrs ),
					]
				);
			}
		}

		// Merge non-variation attributes onto parent variable products
		foreach ( $this->deferred_parent_attrs as $parent_id => $attr_map ) {
			$wc_product = wc_get_product( $parent_id );
			if ( ! $wc_product || ! $wc_product->is_type( 'variable' ) ) {
				continue;
			}

			$attrs    = $wc_product->get_attributes();
			$position = count( $attrs );

			foreach ( $attr_map as $label => $values ) {
				$slug = sanitize_title( $label );
				// Skip if this attribute already exists (e.g. a variation axis)
				if ( isset( $attrs[ $slug ] ) || isset( $attrs[ 'pa_' . $slug ] ) ) {
					continue;
				}
				$unique_values = array_values( array_unique( array_filter( $values ) ) );
				if ( empty( $unique_values ) ) {
					continue;
				}
				$attr = new WC_Product_Attribute();
				$attr->set_id( 0 );
				$attr->set_name( $label );
				$attr->set_options( $unique_values );
				$attr->set_position( $position++ );
				$attr->set_visible( true );
				$attr->set_variation( false );
				$attrs[ $slug ] = $attr;
			}

			$wc_product->set_attributes( $attrs );
			$wc_product->save();

			// Also persist as _product_attributes meta for WC compatibility
			$product_attrs_meta = get_post_meta( $parent_id, '_product_attributes', true );
			if ( ! is_array( $product_attrs_meta ) ) {
				$product_attrs_meta = [];
			}
			$meta_position = count( $product_attrs_meta );
			foreach ( $attr_map as $label => $values ) {
				$slug = sanitize_title( $label );
				if ( isset( $product_attrs_meta[ $slug ] ) ) {
					// Merge values into existing meta entry
					$existing                             = $product_attrs_meta[ $slug ]['value'] ?? '';
					$existing_arr                         = '' !== $existing ? array_map( 'trim', explode( ' | ', $existing ) ) : [];
					$merged                               = array_values( array_unique( array_merge( $existing_arr, array_filter( $values ) ) ) );
					$product_attrs_meta[ $slug ]['value'] = implode( ' | ', $merged );
					continue;
				}
				$unique_values = array_values( array_unique( array_filter( $values ) ) );
				if ( empty( $unique_values ) ) {
					continue;
				}
				$product_attrs_meta[ $slug ] = [
					'name'         => $label,
					'value'        => implode( ' | ', $unique_values ),
					'position'     => (string) $meta_position++,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 0,
				];
			}
			update_post_meta( $parent_id, '_product_attributes', $product_attrs_meta );
			clean_post_cache( $parent_id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $parent_id );
			}

			$this->logger->verbose(
				'Non-variation attributes merged onto parent',
				[
					'parent_id'  => $parent_id,
					'attr_count' => count( $attr_map ),
					'labels'     => array_keys( $attr_map ),
				]
			);
		}

		$parent_count                = count( $this->deferred_parent_terms ) + count( $this->deferred_parent_attrs );
		$this->deferred_parent_terms = [];
		$this->deferred_parent_attrs = [];
		$this->logger->info( 'Flushed parent attribute terms', [ 'parents_updated' => $parent_count ] );
	}

	/**
	 * Format downloadable files for WooCommerce.
	 *
	 * @param array $files Array of file data with 'name' and 'file' keys.
	 * @return array Formatted downloads array keyed by string index.
	 */
	private function format_downloads( array $files ): array {
		$downloads = [];
		foreach ( $files as $i => $f ) {
			$downloads[ (string) $i ] = [
				'name' => $f['name'],
				'file' => $f['file'],
			];
		}
		return $downloads;
	}

	/**
	 * Get plugin options with defaults.
	 *
	 * @return array Plugin settings merged with defaults.
	 */
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
	 *
	 * @return array<int> Numeric collection IDs.
	 */
	private function get_collection_ids(): array {
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		$raw  = $opts['collection_ids'] ?? '';
		if ( $raw === '' || ! is_string( $raw ) ) {
			return [];
		}
		$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_map( 'intval', array_filter( $parts, 'is_numeric' ) ) );
	}

	/**
	 * Get include_languages from settings. Returns array of language codes for API calls.
	 *
	 * @return array<string> Language codes (e.g. ['nl-NL', 'nl']).
	 */
	private function get_include_languages(): array {
		$opts  = get_option( 'skwirrel_wc_sync_settings', [] );
		$langs = $opts['include_languages'] ?? [ 'nl-NL', 'nl' ];
		if ( ! empty( $langs ) && is_array( $langs ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $langs ) ) );
		}
		return [ 'nl-NL', 'nl' ];
	}
}
