<?php
/**
 * Fix for variation attributes showing "Any" in admin when meta is correct.
 * Ensures variation attributes are returned from post meta when object has empty.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Variation_Attributes_Fix {

	public static function init(): void {
		// Priority 1 so we run before any other filter; fix get_attributes() used by admin template.
		add_filter( 'woocommerce_product_variation_get_attributes', [ __CLASS__, 'ensure_attributes_from_meta' ], 1, 2 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', [ __CLASS__, 'fix_rest_response_attributes' ], 10, 3 );
		// Patch variation object as soon as it is read, so any code path sees correct attributes.
		add_action( 'woocommerce_admin_process_variation_object', [ __CLASS__, 'patch_variation_attributes_on_process' ], 1, 1 );
	}

	/**
	 * Get all variation attribute values from post meta (attribute_* keys).
	 * Does not depend on parent product; reads meta directly.
	 */
	private static function get_attributes_from_meta_only( int $variation_id ): array {
		if ( ! $variation_id ) {
			return [];
		}
		$all_meta = get_post_meta( $variation_id );
		if ( ! is_array( $all_meta ) ) {
			return [];
		}
		$from_meta = [];
		foreach ( $all_meta as $key => $arr ) {
			if ( strpos( $key, 'attribute_' ) !== 0 || ! is_array( $arr ) || $arr[0] === '' ) {
				continue;
			}
			$tax = substr( $key, strlen( 'attribute_' ) );
			if ( $tax !== '' && taxonomy_exists( $tax ) ) {
				$from_meta[ $tax ] = wp_unslash( $arr[0] );
			}
		}
		return $from_meta;
	}

	/**
	 * Build attributes array using parent's variation attributes list, then fallback to meta-only.
	 * Used when we need to match parent structure (e.g. REST response).
	 */
	private static function get_attributes_from_meta( int $variation_id, ?int $parent_id ): array {
		$from_meta = self::get_attributes_from_meta_only( $variation_id );
		if ( ! empty( $from_meta ) ) {
			return $from_meta;
		}
		if ( ! $parent_id ) {
			return [];
		}
		$parent = wc_get_product( $parent_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return [];
		}
		foreach ( $parent->get_attributes() as $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			$tax = $attr->get_name();
			$val = get_post_meta( $variation_id, 'attribute_' . $tax, true );
			if ( $val !== '' && $val !== false ) {
				$from_meta[ $tax ] = wp_unslash( $val );
			}
		}
		return $from_meta;
	}

	/**
	 * When variation get_attributes returns empty or incomplete, fill from post meta.
	 * Runs at priority 1. Also updates the variation object so get_data() sees correct attributes.
	 */
	public static function ensure_attributes_from_meta( array $attributes, WC_Product_Variation $variation ): array {
		$vid = $variation->get_id();
		if ( ! $vid ) {
			return $attributes;
		}
		$from_meta = self::get_attributes_from_meta_only( $vid );
		if ( empty( $from_meta ) ) {
			return $attributes;
		}
		foreach ( $from_meta as $tax => $val ) {
			if ( ! isset( $attributes[ $tax ] ) || (string) $attributes[ $tax ] === '' ) {
				$attributes[ $tax ] = $val;
			}
		}
		$variation->set_attributes( $attributes );
		return $attributes;
	}

	/**
	 * When admin processes a variation (before save), ensure its in-memory attributes are correct from meta.
	 * This runs in save flow; we also use it when variations are loaded for the list (same object flow).
	 */
	public static function patch_variation_attributes_on_process( WC_Product_Variation $variation ): void {
		$vid = $variation->get_id();
		if ( ! $vid ) {
			return;
		}
		$from_meta = self::get_attributes_from_meta_only( $vid );
		if ( empty( $from_meta ) ) {
			return;
		}
		$current = $variation->get_attributes();
		$patched = false;
		foreach ( $from_meta as $tax => $val ) {
			if ( ! isset( $current[ $tax ] ) || (string) ( $current[ $tax ] ?? '' ) === '' ) {
				$current[ $tax ] = $val;
				$patched         = true;
			}
		}
		if ( $patched ) {
			$variation->set_attributes( $current );
		}
	}

	/**
	 * Fix attributes in REST API response so admin (or other clients) get correct values instead of "Any".
	 * Handles both formats: associative array (attribute_pa_xxx => value) and list of objects (id, name, option).
	 */
	public static function fix_rest_response_attributes( WP_REST_Response $response, $object, WP_REST_Request $request ): WP_REST_Response {
		if ( ! $object instanceof WC_Product_Variation ) {
			return $response;
		}
		$vid       = $object->get_id();
		$parent_id = $object->get_parent_id();
		if ( ! $vid || ! $parent_id ) {
			return $response;
		}
		$from_meta = self::get_attributes_from_meta_only( $vid );
		if ( empty( $from_meta ) ) {
			return $response;
		}
		$data    = $response->get_data();
		$current = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : [];
		$is_list = isset( $current[0] ) && is_array( $current[0] );
		if ( $is_list ) {
			$by_name = [];
			foreach ( $current as $item ) {
				$name = $item['name'] ?? $item['id'] ?? '';
				if ( $name !== '' ) {
					$by_name[ $name ] = $item;
				}
			}
			foreach ( $from_meta as $tax => $slug ) {
				if ( ! isset( $by_name[ $tax ] ) || (string) ( $by_name[ $tax ]['option'] ?? '' ) === '' ) {
					$by_name[ $tax ] = [
						'id'     => wc_attribute_taxonomy_id_by_name( str_replace( 'pa_', '', $tax ) ),
						'name'   => $tax,
						'option' => $slug,
					];
				}
			}
			$data['attributes'] = array_values( $by_name );
		} else {
			foreach ( $from_meta as $tax => $slug ) {
				$key = 'attribute_' . $tax;
				if ( ! isset( $current[ $key ] ) || (string) $current[ $key ] === '' ) {
					$current[ $key ] = $slug;
				}
			}
			$data['attributes'] = $current;
		}
		$response->set_data( $data );
		return $response;
	}
}
