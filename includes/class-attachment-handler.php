<?php
/**
 * Skwirrel → WooCommerce Attachment Handler.
 *
 * Handles all attachment/image/document-related logic extracted from Product Mapper.
 * Processes Skwirrel product attachments: images, downloadable files, and documents.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Attachment_Handler {

	private Skwirrel_WC_Sync_Logger $logger;
	private Skwirrel_WC_Sync_Media_Importer $media_importer;
	private string $image_language;

	public function __construct( string $image_language = 'nl' ) {
		$this->logger         = new Skwirrel_WC_Sync_Logger();
		$this->media_importer = new Skwirrel_WC_Sync_Media_Importer();
		$this->image_language = $image_language;
	}

	/**
	 * Get product_attachment_title and product_attachment_description for the configured image language.
	 * Language pattern: ^[a-z]{2}(-[A-Z]{2}){0,1}$ (e.g. nl, nl-NL).
	 */
	private function get_attachment_meta_for_language( array $att ): array {
		$lang         = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		$translations = $att['_attachment_translations'] ?? [];
		if ( empty( $translations ) ) {
			return [
				'title'       => $att['file_name'] ?? '',
				'description' => '',
			];
		}
		foreach ( $translations as $t ) {
			$tlang = (string) ( $t['language'] ?? '' );
			if ( strcasecmp( $tlang, $lang ) === 0 ) {
				return [
					'title'       => (string) ( $t['product_attachment_title'] ?? $att['file_name'] ?? '' ),
					'description' => (string) ( $t['product_attachment_description'] ?? '' ),
				];
			}
		}
		foreach ( $translations as $t ) {
			$tlang = (string) ( $t['language'] ?? '' );
			if ( strlen( $lang ) >= 2 && strlen( $tlang ) >= 2 && strcasecmp( substr( $tlang, 0, 2 ), substr( $lang, 0, 2 ) ) === 0 ) {
				return [
					'title'       => (string) ( $t['product_attachment_title'] ?? $att['file_name'] ?? '' ),
					'description' => (string) ( $t['product_attachment_description'] ?? '' ),
				];
			}
		}
		$list  = array_values( (array) $translations );
		$first = $list[0] ?? [];
		return [
			'title'       => (string) ( $first['product_attachment_title'] ?? $att['file_name'] ?? '' ),
			'description' => (string) ( $first['product_attachment_description'] ?? '' ),
		];
	}

	/**
	 * Check if string is a valid HTTP(S) URL. Uses filter_var with parse_url fallback.
	 */
	private function is_valid_url( string $url ): bool {
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return true;
		}
		$parsed = wp_parse_url( $url );
		return isset( $parsed['scheme'], $parsed['host'] )
			&& in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true );
	}

	/**
	 * Normalize URL from API: replace JSON-escaped \/ with /, trim, then rawurldecode.
	 * Handles both single and double-escaped URLs from JSON.
	 */
	private function normalize_attachment_url( string $url ): string {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		while ( str_contains( $url, '\\/' ) ) {
			$url = str_replace( '\\/', '/', $url );
		}
		return rawurldecode( $url );
	}

	/**
	 * Get attachment IDs for images (featured + gallery).
	 * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
	 */
	public function get_image_attachment_ids( array $product, int $product_id = 0 ): array {
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		if ( isset( $opts['sync_images'] ) && ! $opts['sync_images'] ) {
			return [];
		}

		$attachments = $product['_attachments'] ?? $product['attachments'] ?? [];
		$ids         = [];
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['for_internal_use'] ) ) {
				continue;
			}
			$code = $att['product_attachment_type_code'] ?? '';
			if ( ! $this->media_importer->is_image_attachment_type( $code ) ) {
				continue;
			}
			$url = $this->normalize_attachment_url( $att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '' );
			if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
				$this->logger->debug(
					'Skipping image attachment: no valid URL',
					[
						'product'    => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
						'code'       => $code,
						'source_url' => $att['source_url'] ?? null,
					]
				);
				continue;
			}
			// Skip files that are clearly not images (e.g. PDFs with an image type code).
			if ( $this->media_importer->url_has_non_image_extension( $url ) ) {
				$this->logger->debug(
					'Skipping non-image file in image pipeline',
					[
						'url'  => $url,
						'code' => $code,
					]
				);
				continue;
			}
			$order = $att['product_attachment_order'] ?? $att['order'] ?? 999;
			$meta  = $this->get_attachment_meta_for_language( $att );
			$id    = $this->media_importer->import_image( $url, $att['file_name'] ?? '', $product_id, $meta['title'], $meta['description'] );
			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = [
					'id'    => $id,
					'order' => $order,
				];
			}
		}
		usort( $ids, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		$result = array_map( fn( $x ) => $x['id'], $ids );
		if ( ! empty( $attachments ) && empty( $result ) ) {
			$this->logger->debug(
				'Product has attachments but no image imports succeeded',
				[
					'product'          => $product['internal_product_code'] ?? $product['product_id'] ?? '?',
					'attachment_count' => count( $attachments ),
					'sample'           => array_slice( $attachments, 0, 2 ),
				]
			);
		}
		return $result;
	}

	/**
	 * Get downloadable file URLs/names for product (MAN, DAT, etc.).
	 * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
	 */
	public function get_downloadable_files( array $product, int $product_id = 0 ): array {
		$attachments = $product['_attachments'] ?? $product['attachments'] ?? [];
		$files       = [];
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['for_internal_use'] ) ) {
				continue;
			}
			$code = $att['product_attachment_type_code'] ?? '';
			$url  = $this->normalize_attachment_url( $att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '' );
			if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
				continue;
			}
			// Skip true images; rescue misclassified documents (image type code but non-image extension).
			if ( $this->media_importer->is_image_attachment_type( $code ) && ! $this->media_importer->url_has_non_image_extension( $url ) ) {
				continue;
			}
			$name = $att['file_name'] ?? 'Download';
			$id   = $this->media_importer->import_file( $url, $name, $product_id );
			if ( $id ) {
				$guid = wp_get_attachment_url( $id );
				if ( $guid ) {
					$files[] = [
						'name' => $name,
						'file' => $guid,
					];
				}
			}
		}
		return $files;
	}

	/**
	 * Get document attachments (PDF, etc.) for product tab and dashboard.
	 * Uses same non-image sources as downloadable files but returns full metadata for display.
	 * @param int $product_id WooCommerce product post ID to attach media to (0 = no parent).
	 * @return array<int, array{id: int, url: string, name: string, type: string, type_label: string}>
	 */
	public function get_document_attachments( array $product, int $product_id = 0 ): array {
		$raw         = $product['_attachments'] ?? $product['attachments'] ?? [];
		$attachments = is_array( $raw ) && isset( $raw[0] ) ? $raw : ( is_array( $raw ) ? array_values( $raw ) : [] );
		$docs        = [];
		foreach ( $attachments as $att ) {
			if ( ! is_array( $att ) ) {
				continue;
			}
			if ( ! empty( $att['for_internal_use'] ) ) {
				continue;
			}
			$code = (string) ( $att['product_attachment_type_code'] ?? $att['attachment_type_code'] ?? '' );
			$url  = $this->normalize_attachment_url( $att['source_url'] ?? $att['file_source_url'] ?? $att['url'] ?? '' );
			if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
				continue;
			}
			// Skip true images; rescue misclassified documents (image type code but non-image extension).
			if ( $this->media_importer->is_image_attachment_type( $code ) && ! $this->media_importer->url_has_non_image_extension( $url ) ) {
				continue;
			}
			$name = (string) ( $att['file_name'] ?? $att['product_attachment_title'] ?? '' );
			if ( $name === '' ) {
				$path = wp_parse_url( $url, PHP_URL_PATH );
				$name = $path ? basename( $path ) : 'Document';
			}
			$id = $this->media_importer->import_file( $url, $name, $product_id );
			if ( $id ) {
				$guid = wp_get_attachment_url( $id );
				if ( $guid ) {
					$docs[] = [
						'id'         => $id,
						'url'        => $guid,
						'name'       => $name,
						'type'       => $code,
						'type_label' => $this->get_document_type_label( $code ),
					];
				}
			}
		}
		return $docs;
	}

	/** Document type codes from Skwirrel: MAN=Manual, DAT=Datasheet, etc. */
	private function get_document_type_label( string $code ): string {
		$labels = [
			'MAN' => __( 'Manual', 'skwirrel-pim-sync' ),
			'DAT' => __( 'Datasheet', 'skwirrel-pim-sync' ),
			'CER' => __( 'Certificate', 'skwirrel-pim-sync' ),
			'WAR' => __( 'Warranty', 'skwirrel-pim-sync' ),
			'OTV' => __( 'Other document', 'skwirrel-pim-sync' ),
		];
		return $labels[ strtoupper( $code ) ] ?? $code;
	}
}
