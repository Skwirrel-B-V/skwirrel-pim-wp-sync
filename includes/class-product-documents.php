<?php
/**
 * Skwirrel Product Documents.
 *
 * Renders document attachments (PDFs, etc.) in a product tab and admin meta box.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Documents {

	private const META_KEY = '_skwirrel_document_attachments';

	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_product_tabs', [ $this, 'add_product_tab' ], 20 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
	}

	public function enqueue_styles(): void {
		if ( is_product() ) {
			wp_enqueue_style(
				'skwirrel-pim-sync-product-documents',
				SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/product-documents.css',
				[],
				SKWIRREL_WC_SYNC_VERSION
			);
		}
	}

	/**
	 * Add "Documenten" tab to product single page.
	 */
	public function add_product_tab( array $tabs ): array {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return $tabs;
		}
		$docs = $this->get_documents_for_product( $product );
		if ( empty( $docs ) ) {
			return $tabs;
		}
		$tabs['skwirrel_documents'] = [
			'title'    => __( 'Documents', 'skwirrel-pim-sync' ),
			'priority' => 25,
			'callback' => [ $this, 'render_product_tab' ],
		];
		return $tabs;
	}

	/**
	 * Render tab content on product single page.
	 */
	public function render_product_tab(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$docs = $this->get_documents_for_product( $product );
		if ( empty( $docs ) ) {
			return;
		}
		?>
		<div class="skwirrel-pim-sync-product-documents">
			<ul class="skwirrel-document-list">
				<?php foreach ( $docs as $doc ) : ?>
					<li class="skwirrel-document-item">
						<a href="<?php echo esc_url( $doc['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="skwirrel-document-link">
							<span class="skwirrel-document-icon"><?php echo esc_html( $this->get_file_icon( $doc['url'] ) ); ?></span>
							<span class="skwirrel-document-name"><?php echo esc_html( $doc['name'] ); ?></span>
							<?php if ( ! empty( $doc['type_label'] ) ) : ?>
								<span class="skwirrel-document-type">(<?php echo esc_html( $doc['type_label'] ); ?>)</span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Add meta box to product edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'skwirrel_product_documents',
			__( 'Skwirrel documents', 'skwirrel-pim-sync' ),
			[ $this, 'render_meta_box' ],
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render meta box content in admin.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$docs = $this->get_documents_for_product( $product );
		if ( empty( $docs ) ) {
			echo '<p>' . esc_html__( 'No documents linked to this product.', 'skwirrel-pim-sync' ) . '</p>';
			return;
		}
		echo '<ul class="skwirrel-admin-document-list" style="margin:0;padding-left:1.2em;">';
		foreach ( $docs as $doc ) {
			$edit_url = admin_url( 'post.php?post=' . $doc['id'] . '&action=edit' );
			echo '<li style="margin-bottom:6px;">';
			echo '<a href="' . esc_url( $doc['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $doc['name'] ) . '</a>';
			if ( ! empty( $doc['type_label'] ) ) {
				echo ' <span style="color:#666;">(' . esc_html( $doc['type_label'] ) . ')</span>';
			}
			echo ' <a href="' . esc_url( $edit_url ) . '" class="dashicons dashicons-edit" style="font-size:14px;text-decoration:none;" title="' . esc_attr__( 'Edit', 'skwirrel-pim-sync' ) . '"></a>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Get documents for product (from meta or parent for variations).
	 */
	private function get_documents_for_product( WC_Product $product ): array {
		$id = $product->get_id();
		if ( $product->is_type( 'variation' ) ) {
			$id = $product->get_parent_id();
		}
		$docs = get_post_meta( $id, self::META_KEY, true );
		if ( ! is_array( $docs ) ) {
			return [];
		}
		$valid = [];
		foreach ( $docs as $doc ) {
			if ( ! empty( $doc['url'] ) && ! empty( $doc['name'] ) ) {
				$valid[] = $doc;
			}
		}
		return $valid;
	}

	private function get_file_icon( string $url ): string {
		$path_part = wp_parse_url( $url, PHP_URL_PATH );
		$ext       = strtolower( pathinfo( $path_part ? $path_part : '', PATHINFO_EXTENSION ) );
		if ( 'pdf' === $ext ) {
			return '📄';
		}
		return '📎';
	}

	/**
	 * Get document attachments for a product (for use in templates).
	 *
	 * @param int|WC_Product $product Product ID or product object
	 * @return array<int, array{id: int, url: string, name: string, type: string, type_label: string}>
	 */
	public static function get_documents( $product ): array {
		$wc = $product instanceof WC_Product ? $product : wc_get_product( $product );
		if ( ! $wc instanceof WC_Product ) {
			return [];
		}
		$id = $wc->get_id();
		if ( $wc->is_type( 'variation' ) ) {
			$id = $wc->get_parent_id();
		}
		$docs = get_post_meta( $id, self::META_KEY, true );
		if ( ! is_array( $docs ) ) {
			return [];
		}
		$valid = [];
		foreach ( $docs as $doc ) {
			if ( ! empty( $doc['url'] ) && ! empty( $doc['name'] ) ) {
				$valid[] = $doc;
			}
		}
		return $valid;
	}
}
