<?php
/**
 * Skwirrel Sync - Verwijderbescherming.
 *
 * Toont waarschuwingen wanneer Skwirrel-beheerde producten of categorieën
 * in WooCommerce worden verwijderd. Skwirrel is leidend: verwijderde items
 * worden bij de volgende sync opnieuw aangemaakt.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Delete_Protection {

	private const FORCE_FULL_SYNC_OPTION = 'skwirrel_wc_sync_force_full_sync';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Waarschuwingsbanner op product-bewerkpagina
		add_action( 'admin_notices', [ $this, 'product_edit_notice' ] );

		// Trash-link aanpassen in productlijst (bevestigingsdialoog)
		add_filter( 'post_row_actions', [ $this, 'modify_product_row_actions' ], 10, 2 );

		// Delete-link aanpassen in categorieënlijst
		add_filter( 'product_cat_row_actions', [ $this, 'modify_category_row_actions' ], 10, 2 );

		// JS bevestigingsdialoog op productlijst en categoriepagina
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Na verwijdering: forceer volledige sync zodat product terugkomt
		add_action( 'wp_trash_post', [ $this, 'on_product_trashed' ] );
		add_action( 'before_delete_post', [ $this, 'on_product_trashed' ] );

		// Na verwijdering categorie: forceer volledige sync
		add_action( 'pre_delete_term', [ $this, 'on_category_deleted' ], 10, 2 );
	}

	/**
	 * Controleer of de waarschuwingsbanners ingeschakeld zijn.
	 * Standaard ingeschakeld (true) totdat de instelling expliciet is uitgeschakeld.
	 */
	private function is_enabled(): bool {
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		if ( ! array_key_exists( 'show_delete_warning', $opts ) ) {
			return true; // Standaard aan bij nieuwe installatie
		}
		return ! empty( $opts['show_delete_warning'] );
	}

	/**
	 * Controleer of een product door Skwirrel wordt beheerd.
	 */
	private function is_skwirrel_product( int $post_id ): bool {
		$ext_id = get_post_meta( $post_id, '_skwirrel_external_id', true );
		if ( ! empty( $ext_id ) ) {
			return true;
		}
		$grouped_id = get_post_meta( $post_id, '_skwirrel_grouped_product_id', true );
		return ! empty( $grouped_id );
	}

	/**
	 * Controleer of een categorie door Skwirrel is aangemaakt.
	 */
	private function is_skwirrel_category( int $term_id ): bool {
		$skwirrel_id = get_term_meta( $term_id, '_skwirrel_category_id', true );
		return ! empty( $skwirrel_id );
	}

	/**
	 * Toon waarschuwingsbanner op de product-bewerkpagina.
	 */
	public function product_edit_notice(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		// Alleen op de individuele product-bewerkpagina
		if ( 'post' !== $screen->base ) {
			return;
		}

		global $post;
		if ( ! $post || ! $this->is_skwirrel_product( $post->ID ) ) {
			return;
		}

		$synced_at  = get_post_meta( $post->ID, '_skwirrel_synced_at', true );
		$synced_str = $synced_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $synced_at ) : '';

		?>
		<div class="notice notice-warning is-dismissible skwirrel-sync-delete-warning">
			<p>
				<strong>Skwirrel Sync:</strong>
				<?php esc_html_e( 'This product is managed by Skwirrel. Changes to product data should be made in Skwirrel.', 'skwirrel-pim-sync' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'If you delete or trash this product, it will be automatically recreated during the next sync.', 'skwirrel-pim-sync' ); ?>
				<?php if ( $synced_str ) : ?>
					<?php /* translators: %s = last sync datetime */ ?>
					<br><small><?php echo esc_html( sprintf( __( 'Last synced: %s', 'skwirrel-pim-sync' ), $synced_str ) ); ?></small>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Voeg CSS class toe aan trash-link voor Skwirrel-producten in productlijst.
	 */
	public function modify_product_row_actions( array $actions, \WP_Post $post ): array {
		if ( ! $this->is_enabled() ) {
			return $actions;
		}

		if ( 'product' !== $post->post_type ) {
			return $actions;
		}

		if ( ! $this->is_skwirrel_product( $post->ID ) ) {
			return $actions;
		}

		if ( isset( $actions['trash'] ) ) {
			$actions['trash'] = str_replace(
				'class="submitdelete"',
				'class="submitdelete skwirrel-protected-trash"',
				$actions['trash']
			);
		}

		return $actions;
	}

	/**
	 * Voeg CSS class toe aan delete-link voor Skwirrel-categorieën.
	 */
	public function modify_category_row_actions( array $actions, object $term ): array {
		if ( ! $this->is_enabled() ) {
			return $actions;
		}

		if ( ! $this->is_skwirrel_category( $term->term_id ) ) {
			return $actions;
		}

		if ( isset( $actions['delete'] ) ) {
			$actions['delete'] = str_replace(
				'class="delete-tag"',
				'class="delete-tag skwirrel-protected-delete"',
				$actions['delete']
			);
		}

		return $actions;
	}

	/**
	 * Enqueue JavaScript bevestigingsdialogen op productlijst en categoriepagina.
	 */
	public function enqueue_scripts(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( 'edit-product' === $screen->id ) {
			$msg = __( 'This product is managed by Skwirrel and will be recreated during the next sync.\n\nAre you sure you want to trash this product?', 'skwirrel-pim-sync' );
			wp_register_script( 'skwirrel-pim-sync-delete-protection', false, [], SKWIRREL_WC_SYNC_VERSION, true );
			wp_enqueue_script( 'skwirrel-pim-sync-delete-protection' );
			wp_add_inline_script(
				'skwirrel-pim-sync-delete-protection',
				'(function() {'
				. ' var msg = ' . wp_json_encode( $msg ) . ';'
				. ' document.addEventListener("click", function(e) {'
				. '  var link = e.target.closest(".skwirrel-protected-trash");'
				. '  if (!link) return;'
				. '  if (!confirm(msg)) { e.preventDefault(); e.stopPropagation(); }'
				. ' }, true);'
				. '})();'
			);
		}

		if ( 'product_cat' === $screen->taxonomy ) {
			$msg = __( 'This category was created by Skwirrel Sync and will be recreated during the next sync.\n\nAre you sure you want to delete this category?', 'skwirrel-pim-sync' );
			wp_register_script( 'skwirrel-pim-sync-delete-protection-cat', false, [], SKWIRREL_WC_SYNC_VERSION, true );
			wp_enqueue_script( 'skwirrel-pim-sync-delete-protection-cat' );
			wp_add_inline_script(
				'skwirrel-pim-sync-delete-protection-cat',
				'(function() {'
				. ' var msg = ' . wp_json_encode( $msg ) . ';'
				. ' document.addEventListener("click", function(e) {'
				. '  var link = e.target.closest(".skwirrel-protected-delete");'
				. '  if (!link) return;'
				. '  if (!confirm(msg)) { e.preventDefault(); e.stopPropagation(); }'
				. ' }, true);'
				. '})();'
			);
		}
	}

	/**
	 * Wanneer een Skwirrel-product wordt verwijderd/getrashed in WC,
	 * forceer de volgende geplande sync als volledige sync.
	 */
	public function on_product_trashed( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}

		if ( ! $this->is_skwirrel_product( $post_id ) ) {
			return;
		}

		update_option( self::FORCE_FULL_SYNC_OPTION, true, false );
	}

	/**
	 * Wanneer een Skwirrel-categorie wordt verwijderd in WC,
	 * forceer de volgende geplande sync als volledige sync.
	 */
	public function on_category_deleted( int $term_id, string $taxonomy ): void {
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}

		if ( ! $this->is_skwirrel_category( $term_id ) ) {
			return;
		}

		update_option( self::FORCE_FULL_SYNC_OPTION, true, false );
	}
}
