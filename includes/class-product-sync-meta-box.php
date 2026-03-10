<?php
/**
 * Skwirrel Product Sync Meta Box.
 *
 * Adds a "Skwirrel" meta box to the WooCommerce product edit screen
 * allowing single-product sync directly from the product editor.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Sync_Meta_Box {

	/** AJAX action name. */
	private const AJAX_ACTION = 'skwirrel_wc_sync_single_product';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_ajax_sync' ] );
	}

	/**
	 * Register meta box on the product edit screen, positioned above Publish.
	 */
	public function register_meta_box(): void {
		add_meta_box(
			'skwirrel-product-sync',
			__( 'Skwirrel', 'skwirrel-pim-sync' ),
			[ $this, 'render_meta_box' ],
			'product',
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * Shows Skwirrel product ID and last sync timestamp if available,
	 * plus a "Sync this product" button.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ): void {
		$skwirrel_product_id = get_post_meta( $post->ID, '_skwirrel_product_id', true );
		$external_id         = get_post_meta( $post->ID, '_skwirrel_external_id', true );
		$synced_at           = get_post_meta( $post->ID, '_skwirrel_synced_at', true );

		// Only show sync button for Skwirrel-managed products.
		if ( empty( $skwirrel_product_id ) && empty( $external_id ) ) {
			echo '<p class="description">' . esc_html__( 'This product is not managed by Skwirrel.', 'skwirrel-pim-sync' ) . '</p>';
			return;
		}

		wp_nonce_field( self::AJAX_ACTION, 'skwirrel_sync_nonce', false );
		?>
		<div class="skwirrel-product-sync-box">
			<?php if ( $skwirrel_product_id ) : ?>
				<p>
					<strong><?php esc_html_e( 'Product ID:', 'skwirrel-pim-sync' ); ?></strong>
					<?php echo esc_html( (string) $skwirrel_product_id ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $synced_at ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last synced:', 'skwirrel-pim-sync' ); ?></strong>
					<?php
					$date_format = get_option( 'date_format', 'Y-m-d' );
					$time_format = get_option( 'time_format', 'H:i' );
					echo esc_html( wp_date( $date_format . ' ' . $time_format, (int) $synced_at ) );
					?>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary" id="skwirrel-sync-product-btn">
					<?php esc_html_e( 'Sync this product', 'skwirrel-pim-sync' ); ?>
				</button>
				<span class="spinner" id="skwirrel-sync-spinner" style="float: none; margin-top: 0;"></span>
			</p>
			<div id="skwirrel-sync-result" style="display: none; margin-top: 8px;"></div>
		</div>
		<script>
		(function() {
			var btn = document.getElementById('skwirrel-sync-product-btn');
			var spinner = document.getElementById('skwirrel-sync-spinner');
			var resultDiv = document.getElementById('skwirrel-sync-result');
			if (!btn) return;

			btn.addEventListener('click', function() {
				btn.disabled = true;
				spinner.classList.add('is-active');
				resultDiv.style.display = 'none';

				var data = new FormData();
				data.append('action', '<?php echo esc_js( self::AJAX_ACTION ); ?>');
				data.append('wc_product_id', '<?php echo esc_js( (string) $post->ID ); ?>');
				data.append('_wpnonce', document.getElementById('skwirrel_sync_nonce').value);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
				.then(function(response) { return response.json(); })
				.then(function(response) {
					spinner.classList.remove('is-active');
					btn.disabled = false;
					resultDiv.style.display = 'block';

					if (response.success) {
						resultDiv.innerHTML = '<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>';
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Sync failed.', 'skwirrel-pim-sync' ) ); ?>';
						resultDiv.innerHTML = '<div class="notice notice-error inline"><p>' + msg + '</p></div>';
					}
				})
				.catch(function() {
					spinner.classList.remove('is-active');
					btn.disabled = false;
					resultDiv.style.display = 'block';
					resultDiv.innerHTML = '<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Network error. Please try again.', 'skwirrel-pim-sync' ) ); ?></p></div>';
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle AJAX request for single-product sync.
	 */
	public function handle_ajax_sync(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'skwirrel-pim-sync' ) ], 403 );
		}

		check_ajax_referer( self::AJAX_ACTION, '_wpnonce' );

		$wc_product_id = isset( $_POST['wc_product_id'] ) ? absint( $_POST['wc_product_id'] ) : 0;
		if ( ! $wc_product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID.', 'skwirrel-pim-sync' ) ] );
		}

		$skwirrel_product_id = get_post_meta( $wc_product_id, '_skwirrel_product_id', true );
		if ( empty( $skwirrel_product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'No Skwirrel product ID found for this product.', 'skwirrel-pim-sync' ) ] );
		}

		$service = new Skwirrel_WC_Sync_Service();
		$result  = $service->sync_single_product( (int) $skwirrel_product_id );

		if ( $result['success'] ) {
			$outcome = $result['outcome'] ?? 'unknown';
			/* translators: %s: sync outcome (created/updated) */
			$message = sprintf( __( 'Product synced successfully (%s).', 'skwirrel-pim-sync' ), $outcome );
			wp_send_json_success(
				[
					'message' => $message,
					'outcome' => $outcome,
				]
			);
		} else {
			wp_send_json_error( [ 'message' => $result['error'] ?? __( 'Sync failed.', 'skwirrel-pim-sync' ) ] );
		}
	}
}
