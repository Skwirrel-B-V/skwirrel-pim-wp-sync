<?php
/**
 * Skwirrel Custom Class Extractor.
 *
 * Extracts and formats custom class features from Skwirrel product data.
 * Split from Skwirrel_WC_Sync_Product_Mapper for single-responsibility.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Custom_Class_Extractor {

	/** Custom feature types that are stored as product attributes. */
	private const CC_ATTRIBUTE_TYPES = [ 'A', 'M', 'L', 'N', 'R', 'D', 'I' ];

	/** Custom feature types that are stored as product meta (long text). */
	private const CC_META_TYPES = [ 'T', 'B' ];

	private string $image_language;

	public function __construct( string $image_language ) {
		$this->image_language = $image_language;
	}

	/**
	 * Collect custom class objects from the product, optionally including trade-item level.
	 *
	 * @param array $product             Raw API product
	 * @param bool  $include_trade_items Also include _trade_item_custom_classes
	 * @return array<int, array>         Flat list of custom class objects
	 */
	public function collect_custom_classes( array $product, bool $include_trade_items = false ): array {
		$classes = [];
		// Product-level custom classes
		$raw = $product['_custom_classes'] ?? [];
		if ( is_array( $raw ) ) {
			foreach ( $raw as $cc ) {
				if ( is_array( $cc ) && ! empty( $cc['_custom_features'] ) ) {
					$classes[] = $cc;
				}
			}
		}
		// Trade-item level custom classes
		if ( $include_trade_items ) {
			$trade_items = $product['_trade_items'] ?? [];
			foreach ( $trade_items as $ti ) {
				$ti_classes = $ti['_trade_item_custom_classes'] ?? [];
				if ( ! is_array( $ti_classes ) ) {
					continue;
				}
				foreach ( $ti_classes as $cc ) {
					if ( is_array( $cc ) && ! empty( $cc['_custom_features'] ) ) {
						$classes[] = $cc;
					}
				}
			}
		}
		return $classes;
	}

	/**
	 * Filter custom classes by whitelist or blacklist.
	 *
	 * @param array  $classes     List of custom class objects
	 * @param string $mode        'whitelist' | 'blacklist' | '' (no filter)
	 * @param array  $filter_ids  Numeric class IDs
	 * @param array  $filter_codes String class codes
	 * @return array Filtered list
	 */
	public function filter_custom_classes( array $classes, string $mode, array $filter_ids, array $filter_codes ): array {
		if ( $mode === '' || ( empty( $filter_ids ) && empty( $filter_codes ) ) ) {
			return $classes;
		}
		return array_values(
			array_filter(
				$classes,
				static function ( array $cc ) use ( $mode, $filter_ids, $filter_codes ): bool {
					$id    = $cc['custom_class_id'] ?? null;
					$code  = $cc['custom_class_code'] ?? null;
					$match = ( $id !== null && in_array( (int) $id, $filter_ids, true ) )
					|| ( $code !== null && in_array( strtolower( (string) $code ), $filter_codes, true ) );
					return $mode === 'whitelist' ? $match : ! $match;
				}
			)
		);
	}

	/**
	 * Parse the filter IDs setting into numeric IDs and string codes.
	 *
	 * @return array{ids: int[], codes: string[]}
	 */
	public static function parse_custom_class_filter( string $raw ): array {
		$ids   = [];
		$codes = [];
		$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			if ( is_numeric( $part ) ) {
				$ids[] = (int) $part;
			} else {
				$codes[] = strtolower( $part );
			}
		}
		return [
			'ids'   => $ids,
			'codes' => $codes,
		];
	}

	/**
	 * Get custom class features as WC product attributes.
	 * Returns attribute-type features (A, M, L, N, R, D, I) as label => value.
	 * Skips T, B (long text) and not_applicable features.
	 *
	 * @param array $product             Raw API product
	 * @param bool  $include_trade_items Include trade-item custom classes
	 * @param string $filter_mode        'whitelist' | 'blacklist' | ''
	 * @param array  $filter_ids         Numeric class IDs to filter
	 * @param array  $filter_codes       String class codes to filter (lowercase)
	 * @return array<string, string>     label => formatted value
	 */
	public function get_custom_class_attributes(
		array $product,
		bool $include_trade_items = false,
		string $filter_mode = '',
		array $filter_ids = [],
		array $filter_codes = []
	): array {
		$lang    = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		$classes = $this->collect_custom_classes( $product, $include_trade_items );
		$classes = $this->filter_custom_classes( $classes, $filter_mode, $filter_ids, $filter_codes );

		$attrs = [];
		$seen  = [];
		foreach ( $classes as $cc ) {
			foreach ( $cc['_custom_features'] ?? [] as $feat ) {
				if ( ! is_array( $feat ) ) {
					continue;
				}
				$type = $feat['custom_feature_type'] ?? '';
				if ( ! in_array( $type, self::CC_ATTRIBUTE_TYPES, true ) ) {
					continue;
				}
				if ( ! empty( $feat['not_applicable'] ) ) {
					continue;
				}
				$value = $this->format_custom_feature_value( $feat, $lang );
				if ( $value === null || $value === '' ) {
					continue;
				}
				$label = $this->resolve_custom_feature_label( $feat, $lang );
				$key   = $feat['custom_feature_code'] ?? ( 'cc_' . ( $feat['custom_class_feature_id'] ?? ( $feat['custom_feature_id'] ?? '' ) ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ]    = true;
				$attrs[ $label ] = $value;
			}
		}
		return $attrs;
	}

	/**
	 * Get custom class long-text features as product meta.
	 * Returns T and B type features as meta_key => value.
	 *
	 * @return array<string, string>  '_skwirrel_cc_{code}' => text value
	 */
	public function get_custom_class_text_meta(
		array $product,
		bool $include_trade_items = false,
		string $filter_mode = '',
		array $filter_ids = [],
		array $filter_codes = []
	): array {
		$lang    = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		$classes = $this->collect_custom_classes( $product, $include_trade_items );
		$classes = $this->filter_custom_classes( $classes, $filter_mode, $filter_ids, $filter_codes );

		$meta = [];
		foreach ( $classes as $cc ) {
			foreach ( $cc['_custom_features'] ?? [] as $feat ) {
				if ( ! is_array( $feat ) ) {
					continue;
				}
				$type = $feat['custom_feature_type'] ?? '';
				if ( ! in_array( $type, self::CC_META_TYPES, true ) ) {
					continue;
				}
				if ( ! empty( $feat['not_applicable'] ) ) {
					continue;
				}
				$value = $type === 'B'
					? ( $feat['big_text_value'] ?? '' )
					: ( $feat['text_value'] ?? '' );
				if ( $value === '' || $value === null ) {
					continue;
				}
				$code              = $feat['custom_feature_code']
					?? ( 'id_' . ( $feat['custom_feature_id'] ?? $feat['custom_class_feature_id'] ?? '0' ) );
				$meta_key          = '_skwirrel_cc_' . sanitize_key( $code );
				$meta[ $meta_key ] = (string) $value;
			}
		}
		return $meta;
	}

	/**
	 * Resolve a human-readable label for a custom class feature.
	 */
	private function resolve_custom_feature_label( array $feat, string $lang ): string {
		$trans = $feat['_custom_feature_translations'] ?? [];
		if ( ! empty( $trans ) && is_array( $trans ) ) {
			$label = $this->pick_custom_translation( $trans, $lang, 'custom_feature_description' );
			if ( $label !== '' ) {
				return $label;
			}
		}
		return (string) ( $feat['custom_feature_code'] ?? $feat['custom_feature_id'] ?? '' );
	}

	/**
	 * Format a custom class feature value for display as product attribute.
	 */
	private function format_custom_feature_value( array $feat, string $lang ): ?string {
		if ( ! empty( $feat['not_applicable'] ) ) {
			return null;
		}
		$type = $feat['custom_feature_type'] ?? '';

		// A — Alphanumeric (single value from list)
		if ( $type === 'A' ) {
			if ( ! empty( $feat['_custom_values'] ) && is_array( $feat['_custom_values'] ) ) {
				foreach ( $feat['_custom_values'] as $v ) {
					$desc = $this->pick_custom_value_translation( $v, $lang );
					if ( $desc !== '' ) {
						return $desc;
					}
				}
			}
			return $feat['custom_value_code'] ?? null;
		}

		// M — Multi-alphanumeric (comma-separated values)
		if ( $type === 'M' ) {
			$values = [];
			if ( ! empty( $feat['_custom_values'] ) && is_array( $feat['_custom_values'] ) ) {
				foreach ( $feat['_custom_values'] as $v ) {
					$desc = $this->pick_custom_value_translation( $v, $lang );
					if ( $desc !== '' ) {
						$values[] = $desc;
					} else {
						$code = $v['custom_value_code'] ?? '';
						if ( $code !== '' ) {
							$values[] = $code;
						}
					}
				}
			}
			return ! empty( $values ) ? implode( ', ', $values ) : null;
		}

		// L — Logical
		if ( $type === 'L' && array_key_exists( 'logical_value', $feat ) && $feat['logical_value'] !== null ) {
			return $feat['logical_value'] ? 'Ja' : 'Nee';
		}

		// N — Numeric
		if ( $type === 'N' && $feat['numeric_value'] !== null && $feat['numeric_value'] !== '' ) {
			$unit = $this->resolve_custom_unit( $feat, $lang );
			return $feat['numeric_value'] . ( $unit !== '' ? ' ' . $unit : '' );
		}

		// R — Range
		if ( $type === 'R' && ( $feat['range_min'] !== null || $feat['range_max'] !== null ) ) {
			$min  = $feat['range_min'] ?? '';
			$max  = $feat['range_max'] ?? '';
			$unit = $this->resolve_custom_unit( $feat, $lang );
			$s    = $min . ( $min !== '' && $max !== '' ? ' – ' : '' ) . $max;
			return $s . ( $unit !== '' ? ' ' . $unit : '' );
		}

		// D — Date
		if ( $type === 'D' && ! empty( $feat['date_value'] ) ) {
			return (string) $feat['date_value'];
		}

		// I — Internationalized text (pick by language)
		if ( $type === 'I' ) {
			$texts = $feat['translated_texts'] ?? [];
			if ( ! empty( $texts ) && is_array( $texts ) ) {
				// Try exact match, then prefix match, then first
				foreach ( $texts as $t ) {
					if ( strcasecmp( (string) ( $t['language'] ?? '' ), $lang ) === 0 ) {
						return (string) ( $t['text'] ?? $t['value'] ?? '' );
					}
				}
				foreach ( $texts as $t ) {
					$tl = (string) ( $t['language'] ?? '' );
					if ( strlen( $lang ) >= 2 && strlen( $tl ) >= 2 && strcasecmp( substr( $tl, 0, 2 ), substr( $lang, 0, 2 ) ) === 0 ) {
						return (string) ( $t['text'] ?? $t['value'] ?? '' );
					}
				}
				$first = $texts[0] ?? [];
				return (string) ( $first['text'] ?? $first['value'] ?? '' );
			}
			return null;
		}

		return null;
	}

	/**
	 * Pick a translated label from custom feature/unit/value translations.
	 */
	private function pick_custom_translation( array $translations, string $lang, string $field ): string {
		// Exact match
		foreach ( $translations as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$tl = (string) ( $t['language'] ?? '' );
			if ( strcasecmp( $tl, $lang ) === 0 ) {
				return (string) ( $t[ $field ] ?? '' );
			}
		}
		// Prefix match (e.g. nl matches nl-NL)
		foreach ( $translations as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$tl = (string) ( $t['language'] ?? '' );
			if ( strlen( $lang ) >= 2 && strlen( $tl ) >= 2 && strcasecmp( substr( $tl, 0, 2 ), substr( $lang, 0, 2 ) ) === 0 ) {
				return (string) ( $t[ $field ] ?? '' );
			}
		}
		// Fallback to first
		$first = $translations[0] ?? [];
		return (string) ( is_array( $first ) ? ( $first[ $field ] ?? '' ) : '' );
	}

	/**
	 * Pick translated value description from a custom value object.
	 */
	private function pick_custom_value_translation( array $value, string $lang ): string {
		$trans = $value['_custom_value_translations'] ?? [];
		if ( ! empty( $trans ) && is_array( $trans ) ) {
			return $this->pick_custom_translation( $trans, $lang, 'custom_value_description' );
		}
		return (string) ( $value['custom_value_code'] ?? '' );
	}

	/**
	 * Resolve unit abbreviation from custom feature translations.
	 */
	private function resolve_custom_unit( array $feat, string $lang ): string {
		$trans = $feat['_custom_unit_translations'] ?? [];
		if ( empty( $trans ) || ! is_array( $trans ) ) {
			return (string) ( $feat['custom_unit_code'] ?? '' );
		}
		$abbr = $this->pick_custom_translation( $trans, $lang, 'custom_unit_abbreviation' );
		if ( $abbr !== '' ) {
			return $abbr;
		}
		return $this->pick_custom_translation( $trans, $lang, 'custom_unit_description' );
	}
}
