<?php
/**
 * Skwirrel Media Importer.
 *
 * Downloads images and files from Skwirrel URLs and imports into WP media library.
 * Handles duplicate detection via file URL hash.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Media_Importer {

	private Skwirrel_WC_Sync_Logger $logger;
	private const META_SKWIRREL_URL  = '_skwirrel_source_url';
	private const META_SKWIRREL_HASH = '_skwirrel_url_hash';

	/** Image attachment type codes (from Skwirrel schema: PPI=Picture, PHI=Picture print, LOG=Logo, SCH=Diagram, PRT=Presentation, OTV=Other visual). */
	private const IMAGE_TYPES = [ 'IMG', 'PPI', 'PHI', 'LOG', 'SCH', 'PRT', 'OTV' ];

	public function __construct() {
		$this->logger = new Skwirrel_WC_Sync_Logger();
	}

	/**
	 * Import image from URL. Downloads file and creates attachment directly (bypasses upload validation).
	 * Attaches to $parent_id when given (product post ID).
	 * $title = product_attachment_title (alt text + caption). $description = product_attachment_description.
	 * Returns attachment ID or 0 on failure.
	 */
	public function import_image( string $url, string $title = '', int $parent_id = 0, string $alt_caption = '', string $description = '' ): int {
		$url = $this->normalize_download_url( $url );
		if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
			return 0;
		}

		$hash     = $this->url_hash( $url );
		$existing = $this->find_attachment_by_hash( $hash );
		if ( $existing ) {
			if ( $parent_id && 0 === wp_get_post_parent_id( $existing ) ) {
				wp_update_post(
					[
						'ID'          => $existing,
						'post_parent' => $parent_id,
					]
				);
			}
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = $this->download_to_temp( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$this->logger->warning(
				'Failed to download image',
				[
					'url'   => $url,
					'error' => $tmp->get_error_message(),
				]
			);
			return 0;
		}

		$image_info = @getimagesize( $tmp );
		if ( false === $image_info ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Downloaded file is not a valid image', [ 'url' => $url ] );
			return 0;
		}

		$ext_map  = [
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_GIF  => 'gif',
			IMAGETYPE_WEBP => 'webp',
		];
		$ext      = $ext_map[ $image_info[2] ?? 0 ] ?? 'jpg';
		$filename = 'skwirrel-' . substr( $hash, 0, 12 ) . '-' . time() . '.' . $ext;

		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Upload dir error', [ 'error' => $upload_dir['error'] ] );
			return 0;
		}

		$dest = $upload_dir['path'] . '/' . $filename;
		if ( ! copy( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Failed to copy image to uploads', [ 'url' => $url ] );
			return 0;
		}
		wp_delete_file( $tmp );

		$filetype   = wp_check_filetype( $filename, null );
		$label      = $alt_caption ? $alt_caption : ( $title ? $title : preg_replace( '/\.[^.]+$/', '', $filename ) );
		$attachment = [
			'post_mime_type' => $filetype['type'],
			'post_title'     => $label,
			'post_excerpt'   => $alt_caption,
			'post_content'   => $description,
			'post_status'    => 'inherit',
		];

		$id = wp_insert_attachment( $attachment, $dest, $parent_id );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $dest );
			$this->logger->warning(
				'Failed to create attachment',
				[
					'url'   => $url,
					'error' => $id->get_error_message(),
				]
			);
			return 0;
		}

		if ( $alt_caption ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt_caption );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $dest );
		if ( ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		update_post_meta( $id, self::META_SKWIRREL_URL, $url );
		update_post_meta( $id, self::META_SKWIRREL_HASH, $hash );

		$this->logger->debug(
			'Imported image',
			[
				'url'           => $url,
				'attachment_id' => $id,
			]
		);
		return $id;
	}

	/**
	 * Import file (PDF, etc.) from URL. Downloads and creates attachment directly (bypasses upload validation).
	 * Attaches to $parent_id when given (product post ID).
	 * Returns attachment ID or 0.
	 */
	public function import_file( string $url, string $name = '', int $parent_id = 0 ): int {
		$url = $this->normalize_download_url( $url );
		if ( empty( $url ) || ! $this->is_valid_url( $url ) ) {
			return 0;
		}

		$hash     = $this->url_hash( $url );
		$existing = $this->find_attachment_by_hash( $hash );
		if ( $existing ) {
			if ( $parent_id && 0 === wp_get_post_parent_id( $existing ) ) {
				wp_update_post(
					[
						'ID'          => $existing,
						'post_parent' => $parent_id,
					]
				);
			}
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = $this->download_to_temp( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			$this->logger->warning(
				'Failed to download file',
				[
					'url'   => $url,
					'error' => $tmp->get_error_message(),
				]
			);
			return 0;
		}

		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = $name ? $name : ( $path ? basename( $path ) : '' );
		$ext      = $basename ? pathinfo( $basename, PATHINFO_EXTENSION ) : '';
		if ( ! is_string( $ext ) || ! preg_match( '/^[a-z0-9]{2,5}$/i', $ext ) ) {
			$ext = 'pdf';
		}
		$filename = 'skwirrel-' . substr( $hash, 0, 12 ) . '-' . time() . '.' . $ext;

		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			wp_delete_file( $tmp );
			return 0;
		}

		$dest = $upload_dir['path'] . '/' . sanitize_file_name( $filename );
		if ( ! copy( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			$this->logger->warning( 'Failed to copy file to uploads', [ 'url' => $url ] );
			return 0;
		}
		wp_delete_file( $tmp );

		$filetype   = wp_check_filetype( $dest, null );
		$attachment = [
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$id = wp_insert_attachment( $attachment, $dest, $parent_id );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $dest );
			$this->logger->warning(
				'Failed to create attachment',
				[
					'url'   => $url,
					'error' => $id->get_error_message(),
				]
			);
			return 0;
		}

		update_post_meta( $id, self::META_SKWIRREL_URL, $url );
		update_post_meta( $id, self::META_SKWIRREL_HASH, $hash );

		return $id;
	}

	public function is_image_attachment_type( string $code ): bool {
		return in_array( strtoupper( $code ), self::IMAGE_TYPES, true );
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
	private function normalize_download_url( string $url ): string {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		while ( str_contains( $url, '\\/' ) ) {
			$url = str_replace( '\\/', '/', $url );
		}
		return rawurldecode( $url );
	}

	/**
	 * Download URL to temp file. Uses browser-like User-Agent to avoid 403/404 from strict CDNs.
	 * Download links do not require auth.
	 * @return string|WP_Error Temp file path or error.
	 */
	private function download_to_temp( string $url, int $timeout = 60 ) {
		$args     = [
			'timeout' => $timeout,
			'headers' => [
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			],
		];
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 400 <= $code ) {
			$message = wp_remote_retrieve_response_message( $response );
			return new WP_Error( 'http_' . $code, $message ? $message : 'HTTP ' . $code );
		}
		$body     = wp_remote_retrieve_body( $response );
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$tmp      = wp_tempnam( basename( $url_path ? $url_path : 'download' ) );
		if ( false === $tmp || false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing to wp_tempnam() temp file
			return new WP_Error( 'temp', 'Failed to write temp file' );
		}
		return $tmp;
	}

	private function url_hash( string $url ): string {
		return hash( 'sha256', $url );
	}

	private function find_attachment_by_hash( string $hash ): int {
		$posts = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => self::META_SKWIRREL_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}
}
