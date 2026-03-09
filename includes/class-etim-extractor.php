<?php
/**
 * Skwirrel → WooCommerce ETIM Extractor.
 *
 * Extracts and normalizes ETIM features from Skwirrel product data.
 * Methods extracted from Skwirrel_WC_Sync_Product_Mapper.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Etim_Extractor {

	private string $image_language;
	private Skwirrel_WC_Sync_Logger $logger;

	public function __construct( string $image_language ) {
		$this->image_language = $image_language;
		$this->logger         = new Skwirrel_WC_Sync_Logger();
	}

	/**
	 * Get ETIM features as attributes from product._etim or _product_groups[]._etim.
	 * Uses content language for labels/values.
	 * Feature types: A=alphanumeric, L=logical, N=numeric, R=range, C=class, M=modelling.
	 */
	public function get_etim_attributes( array $product ): array {
		$lang       = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		$attrs      = [];
		$seen       = [];
		$etimItems  = $this->collect_etim_items( $product );
		$product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
		$this->logger->verbose(
			'ETIM extraction',
			[
				'product'            => $product_id,
				'lang'               => $lang,
				'etim_items_count'   => count( $etimItems ),
				'has__etim'          => isset( $product['_etim'] ),
				'has_product_groups' => ! empty( $product['_product_groups'] ?? [] ),
			]
		);
		foreach ( $etimItems as $etim ) {
			$features = $etim['_etim_features'] ?? [];
			$features = $this->normalize_etim_features( $features );
			foreach ( $features as $feat ) {
				$trans = $this->normalize_etim_translations( $feat['_etim_feature_translations'] ?? [] );
				$label = $this->pick_etim_translation( $trans, $lang, 'etim_feature_description' );
				if ( $label === '' ) {
					$label = $feat['etim_feature_code'] ?? '';
				}
				$value = $this->format_etim_feature_value( $feat, $lang );
				if ( $value === null || $value === '' ) {
					$this->logger->verbose(
						'ETIM feature skipped (no value)',
						[
							'product'        => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
							'code'           => $feat['etim_feature_code'] ?? '?',
							'label'          => $label,
							'type'           => $feat['etim_feature_type'] ?? '',
							'not_applicable' => $feat['not_applicable'] ?? null,
							'logical_value'  => $feat['logical_value'] ?? null,
							'numeric_value'  => $feat['numeric_value'] ?? null,
						]
					);
					continue;
				}
				$key = $feat['etim_feature_code'] ?? ( 'etim_' . ( $feat['order_number'] ?? 0 ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ]    = true;
				$attrs[ $label ] = $value;
				$this->logger->verbose(
					'ETIM attribute added',
					[
						'product' => $product_id,
						'label'   => $label,
						'value'   => $value,
						'code'    => $key,
					]
				);
			}
		}
		if ( empty( $attrs ) && ! empty( $etimItems ) ) {
			$this->logger->debug(
				'ETIM items found but no attributes extracted',
				[
					'product'        => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
					'etim_count'     => count( $etimItems ),
					'sample_feature' => $etimItems[0]['_etim_features'] ?? null,
				]
			);
		}
		return $attrs;
	}

	/**
	 * Collect ETIM items from product._etim, product._etim_features, and product._product_groups[]._etim.
	 * Also recursively searches for _etim_features anywhere in the product structure.
	 */
	public function collect_etim_items( array $product ): array {
		$items     = [];
		$raw       = $product['_etim'] ?? null;
		$etimItems = ( is_array( $raw ) && isset( $raw[0] ) ) ? $raw : ( $raw ? [ $raw ] : [] );
		foreach ( $etimItems as $etim ) {
			if ( ! empty( $etim['_etim_features'] ) ) {
				$items[] = $etim;
			}
		}
		// Fallback: some APIs return _etim_features directly on product
		if ( empty( $items ) && ! empty( $product['_etim_features'] ) ) {
			$items[] = [ '_etim_features' => $product['_etim_features'] ];
		}
		$groups = $product['_product_groups'] ?? [];
		foreach ( $groups as $g ) {
			$raw       = $g['_etim'] ?? null;
			$groupEtim = ( is_array( $raw ) && isset( $raw[0] ) ) ? $raw : ( $raw ? [ $raw ] : [] );
			foreach ( $groupEtim as $etim ) {
				if ( ! empty( $etim['_etim_features'] ) ) {
					$items[] = $etim;
				}
			}
			if ( ! empty( $g['_etim_features'] ) ) {
				$items[] = [ '_etim_features' => $g['_etim_features'] ];
			}
		}
		// Last resort: recursively find _etim_features anywhere in product
		if ( empty( $items ) ) {
			$found = $this->find_etim_features_recursive( $product );
			foreach ( $found as $feat ) {
				$items[] = [ '_etim_features' => $feat ];
			}
		}
		if ( ! empty( $items ) ) {
			$total_features = 0;
			foreach ( $items as $etim ) {
				$features        = $etim['_etim_features'] ?? [];
				$total_features += is_array( $features ) && ! isset( $features[0] ) ? count( $features ) : count( (array) $features );
			}
			$this->logger->verbose(
				'ETIM collected',
				[
					'product'        => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
					'sources'        => [
						'product._etim'  => ! empty( $product['_etim'] ),
						'product_groups' => count( $groups ),
					],
					'etim_items'     => count( $items ),
					'total_features' => $total_features,
				]
			);
		}
		return $items;
	}

	/**
	 * Recursively find arrays that look like _etim_features (contain etim_feature_code).
	 */
	private function find_etim_features_recursive( array $data, int $depth = 0 ): array {
		if ( $depth > 10 ) {
			return [];
		}
		$found = [];
		foreach ( $data as $key => $val ) {
			if ( ! is_array( $val ) ) {
				continue;
			}
			$has_feature_code = false;
			$count            = 0;
			foreach ( $val as $v ) {
				if ( is_array( $v ) && isset( $v['etim_feature_code'] ) ) {
					$has_feature_code = true;
					++$count;
				}
			}
			if ( $has_feature_code && $count > 0 ) {
				$found[] = $val;
			} else {
				$found = array_merge( $found, $this->find_etim_features_recursive( $val, $depth + 1 ) );
			}
		}
		return $found;
	}

	/**
	 * Normalize features: object keyed by feature code -> array. Preserves code into each feature.
	 */
	private function normalize_etim_features( $features ): array {
		if ( empty( $features ) ) {
			return [];
		}
		$isAssoc = is_array( $features ) && ! isset( $features[0] );
		$list    = $isAssoc ? $features : (array) $features;
		$result  = [];
		foreach ( $list as $k => $feat ) {
			if ( ! is_array( $feat ) ) {
				continue;
			}
			if ( $isAssoc && empty( $feat['etim_feature_code'] ) && is_string( $k ) && preg_match( '/^[A-Za-z0-9]+$/', $k ) ) {
				$feat['etim_feature_code'] = $k;
			}
			$result[] = $feat;
		}
		return $result;
	}

	/**
	 * Normalize translation structure. Handles:
	 * - Array of {language: 'nl-NL', field: value}
	 * - Object keyed by language: {'nl-NL': {field: value}}
	 */
	private function normalize_etim_translations( $trans ): array {
		if ( empty( $trans ) ) {
			return [];
		}
		$list       = is_array( $trans ) && isset( $trans[0] ) ? $trans : array_values( (array) $trans );
		$normalized = [];
		foreach ( $list as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			if ( isset( $t['language'] ) ) {
				$normalized[] = $t;
				continue;
			}
		}
		if ( ! empty( $normalized ) ) {
			return $normalized;
		}
		$assoc = (array) $trans;
		foreach ( $assoc as $langCode => $data ) {
			if ( is_array( $data ) && preg_match( '/^[a-z]{2}(-[A-Z]{2})?$/i', (string) $langCode ) ) {
				$normalized[] = array_merge( [ 'language' => $langCode ], $data );
			}
		}
		return $normalized;
	}

	/**
	 * Resolve a human-readable label for an ETIM feature from its translations.
	 * Falls back to the raw etim_feature_code if no translation is found.
	 */
	public function resolve_etim_feature_label( array $feature, string $lang = '' ): string {
		if ( $lang === '' ) {
			$lang = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		}
		$trans = $this->normalize_etim_translations( $feature['_etim_feature_translations'] ?? [] );
		$label = $this->pick_etim_translation( $trans, $lang, 'etim_feature_description' );
		return $label !== '' ? $label : ( (string) ( $feature['etim_feature_code'] ?? '' ) );
	}

	private function pick_etim_translation( array $translations, string $lang, string $field ): string {
		foreach ( $translations as $t ) {
			$tlang = (string) ( $t['language'] ?? '' );
			if ( strcasecmp( $tlang, $lang ) === 0 ) {
				return (string) ( $t[ $field ] ?? '' );
			}
		}
		foreach ( $translations as $t ) {
			$tlang = (string) ( $t['language'] ?? '' );
			if ( strlen( $lang ) >= 2 && strlen( $tlang ) >= 2 && strcasecmp( substr( $tlang, 0, 2 ), substr( $lang, 0, 2 ) ) === 0 ) {
				return (string) ( $t[ $field ] ?? '' );
			}
		}
		$list  = array_values( (array) $translations );
		$first = $list[0] ?? [];
		return (string) ( is_array( $first ) ? ( $first[ $field ] ?? '' ) : '' );
	}

	private function format_etim_feature_value( array $feat, string $lang ): ?string {
		if ( ! empty( $feat['not_applicable'] ) ) {
			return null;
		}
		$type     = $feat['etim_feature_type'] ?? '';
		$valTrans = $this->normalize_etim_translations( $feat['_etim_value_translations'] ?? [] );
		if ( $type === 'A' && ! empty( $feat['etim_value_code'] ) ) {
			$val = $this->pick_etim_translation( $valTrans, $lang, 'etim_value_description' );
			return $val ?: $feat['etim_value_code'];
		}
		$unitTrans = $this->normalize_etim_translations( $feat['_etim_unit_translations'] ?? [] );
		if ( $type === 'N' && $feat['numeric_value'] !== null && $feat['numeric_value'] !== '' ) {
			$unit = $this->pick_etim_translation( $unitTrans, $lang, 'etim_unit_abbreviation' );
			$unit = $unit ?: $this->pick_etim_translation( $unitTrans, $lang, 'etim_unit_description' );
			return $feat['numeric_value'] . ( $unit ? ' ' . $unit : '' );
		}
		$isLogical = ( $type === 'L' || strtoupper( (string) $type ) === 'LOGICAL' || empty( $type ) );
		if ( $isLogical && array_key_exists( 'logical_value', $feat ) && $feat['logical_value'] !== null ) {
			return $feat['logical_value'] ? 'Ja' : 'Nee';
		}
		if ( $type === 'R' && ( $feat['range_min'] !== null || $feat['range_max'] !== null ) ) {
			$min  = $feat['range_min'] ?? '';
			$max  = $feat['range_max'] ?? '';
			$unit = $this->pick_etim_translation( $unitTrans, $lang, 'etim_unit_abbreviation' );
			$unit = $unit ?: $this->pick_etim_translation( $unitTrans, $lang, 'etim_unit_description' );
			$s    = $min . ( $min !== '' && $max !== '' ? ' - ' : '' ) . $max;
			return $s . ( $unit ? ' ' . $unit : '' );
		}
		if ( $type === 'A' && empty( $feat['etim_value_code'] ) && $feat['numeric_value'] !== null ) {
			return (string) $feat['numeric_value'];
		}
		if ( in_array( $type, [ 'C', 'M' ], true ) && ! empty( $feat['etim_value_code'] ) ) {
			$val = $this->pick_etim_translation( $valTrans, $lang, 'etim_value_description' );
			return $val ?: $feat['etim_value_code'];
		}
		return null;
	}

	/**
	 * Get eTIM feature values for specific codes (for variation attributes).
	 * Returns array of [etim_code => ['label' => string, 'value' => string, 'slug' => string]].
	 * Only includes features that have a value.
	 */
	public function get_etim_feature_values_for_codes( array $product, array $etim_codes, string $lang = '' ): array {
		if ( empty( $etim_codes ) ) {
			return [];
		}
		if ( $lang === '' ) {
			$lang = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		}
		$code_list = [];
		foreach ( $etim_codes as $c ) {
			$code_list[] = strtoupper( (string) ( $c['code'] ?? $c['etim_feature_code'] ?? '' ) );
		}
		$codes     = array_flip( array_filter( $code_list ) );
		$result    = [];
		$etimItems = $this->collect_etim_items( $product );

		foreach ( $etimItems as $etim ) {
			$features = $this->normalize_etim_features( $etim['_etim_features'] ?? [] );
			foreach ( $features as $feat ) {
				$code = strtoupper( (string) ( $feat['etim_feature_code'] ?? '' ) );
				if ( ! isset( $codes[ $code ] ) ) {
					continue;
				}
				$value = $this->format_etim_feature_value( $feat, $lang );
				if ( $value === null || $value === '' ) {
					continue;
				}
				$trans = $this->normalize_etim_translations( $feat['_etim_feature_translations'] ?? [] );
				$label = $this->pick_etim_translation( $trans, $lang, 'etim_feature_description' );
				if ( $label === '' ) {
					$label = $code;
				}
				$slug = sanitize_title( $value );
				if ( $slug === '' ) {
					$slug = sanitize_title( (string) $value );
				}
				$result[ $code ] = [
					'label' => $label,
					'value' => $value,
					'slug'  => $slug ?: 'val-' . $code,
				];
			}
		}
		return $result;
	}
}
