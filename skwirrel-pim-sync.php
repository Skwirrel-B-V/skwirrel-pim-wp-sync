<?php
/**
 * Plugin Name: Skwirrel PIM sync for WooCommerce
 * Plugin URI: https://github.com/Skwirrel-B-V/skwirrel-pim-sync-for-woocommerce
 * Description: Sync plugin for Skwirrel PIM via Skwirrel JSON-RPC API to WooCommerce.
 * Version: 2.0.7
 * Author: Skwirrel B.V.
 * Author URI: https://skwirrel.eu
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 10.6
 * License: GPL v2 or later
 * Text Domain: skwirrel-pim-sync
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SKWIRREL_WC_SYNC_VERSION', '2.0.7' );
define( 'SKWIRREL_WC_SYNC_PLUGIN_FILE', __FILE__ );
define( 'SKWIRREL_WC_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SKWIRREL_WC_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	function (): void {
		// Check WooCommerce dependency on activation
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
		if ( ! class_exists( 'WooCommerce' ) && ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) ), true ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'Skwirrel PIM sync for WooCommerce requires WooCommerce to function.', 'skwirrel-pim-sync' )
				. ' <a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">'
				. esc_html__( 'Install WooCommerce', 'skwirrel-pim-sync' ) . '</a>.',
				'Plugin Activation Error',
				[ 'back_link' => true ]
			);
		}

		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-action-scheduler.php';
		Skwirrel_WC_Sync_Action_Scheduler::instance()->schedule();
	}
);

/**
 * Plugin bootstrap.
 */
final class Skwirrel_WC_Sync_Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', fn() => $this->woocommerce_missing_notice() );
			return;
		}

		$this->load_dependencies();
		$this->register_hooks();
	}

	private function load_dependencies(): void {
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-logger.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-jsonrpc-client.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-media-importer.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-etim-extractor.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-custom-class-extractor.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-attachment-handler.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-product-mapper.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-product-lookup.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-sync-history.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-purge-handler.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-category-sync.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-brand-sync.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-taxonomy-manager.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-slug-resolver.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-permalink-settings.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-product-upserter.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-sync-service.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-action-scheduler.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-admin-settings.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-admin-dashboard.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-product-documents.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-variation-attributes-fix.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-delete-protection.php';
		require_once SKWIRREL_WC_SYNC_PLUGIN_DIR . 'includes/class-product-sync-meta-box.php';
	}

	private function register_hooks(): void {
		// Register taxonomies: product_brand as fallback (WC 9.4+ provides it natively),
		// product_manufacturer when enabled via settings.
		$options    = get_option( 'skwirrel_wc_sync_settings', [] );
		$brand_sync = new Skwirrel_WC_Sync_Brand_Sync( new Skwirrel_WC_Sync_Logger() );
		add_action( 'init', [ $brand_sync, 'maybe_register_brand_taxonomy' ] );
		if ( ! empty( $options['sync_manufacturers'] ) ) {
			add_action( 'init', [ $brand_sync, 'maybe_register_manufacturer_taxonomy' ] );
		}

		// Default product list columns: hide Tags, show Manufacturers
		add_filter( 'default_hidden_columns', [ $this, 'default_hidden_product_columns' ], 10, 2 );

		// Reorder columns: place Manufacturers after Brands, before Date
		add_filter( 'manage_edit-product_columns', [ $this, 'reorder_product_columns' ], 99 );

		// Add "Filter by manufacturer" dropdown on product list
		if ( ! empty( $options['sync_manufacturers'] ) ) {
			add_action( 'restrict_manage_posts', [ $this, 'add_manufacturer_filter_dropdown' ], 20 );
			add_filter( 'parse_query', [ $this, 'filter_products_by_manufacturer' ] );
		}

		// Add GTIN / Manufacturer code search on product list.
		add_action( 'restrict_manage_posts', [ $this, 'add_identifier_search_input' ], 25 );
		add_filter( 'parse_query', [ $this, 'filter_products_by_identifier' ] );

		Skwirrel_WC_Sync_Admin_Settings::instance();
		Skwirrel_WC_Sync_Permalink_Settings::instance();
		Skwirrel_WC_Sync_Action_Scheduler::instance();
		Skwirrel_WC_Sync_Product_Documents::instance();
		Skwirrel_WC_Sync_Variation_Attributes_Fix::init();
		Skwirrel_WC_Sync_Delete_Protection::instance();
		Skwirrel_WC_Sync_Product_Sync_Meta_Box::instance();
	}

	/**
	 * Reorder product list columns: place Manufacturers after Brands, before Date.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function reorder_product_columns( array $columns ): array {
		$manufacturer_key = 'taxonomy-' . Skwirrel_WC_Sync_Brand_Sync::MANUFACTURER_TAXONOMY;
		if ( ! isset( $columns[ $manufacturer_key ] ) ) {
			return $columns;
		}

		// Remove manufacturer from current position
		$manufacturer_label = $columns[ $manufacturer_key ];
		unset( $columns[ $manufacturer_key ] );

		// Insert before the date column
		$reordered = [];
		$inserted  = false;
		foreach ( $columns as $key => $label ) {
			if ( ! $inserted && $key === 'date' ) {
				$reordered[ $manufacturer_key ] = $manufacturer_label;
				$inserted                       = true;
			}
			$reordered[ $key ] = $label;
		}
		if ( ! $inserted ) {
			$reordered[ $manufacturer_key ] = $manufacturer_label;
		}

		return $reordered;
	}

	/**
	 * Render "Filter by manufacturer" dropdown on the product list page.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_manufacturer_filter_dropdown( string $post_type ): void {
		if ( $post_type !== 'product' || ! taxonomy_exists( Skwirrel_WC_Sync_Brand_Sync::MANUFACTURER_TAXONOMY ) ) {
			return;
		}

		$terms = get_terms(
			[
				'taxonomy'   => Skwirrel_WC_Sync_Brand_Sync::MANUFACTURER_TAXONOMY,
				'hide_empty' => false,
			]
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
		$selected = sanitize_text_field( wp_unslash( $_GET[ Skwirrel_WC_Sync_Brand_Sync::MANUFACTURER_TAXONOMY ] ?? '' ) );
		echo '<select name="' . esc_attr( Skwirrel_WC_Sync_Brand_Sync::MANUFACTURER_TAXONOMY ) . '">';
		echo '<option value="">' . esc_html__( 'Filter by manufacturer', 'skwirrel-pim-sync' ) . '</option>';
		foreach ( $terms as $term ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $term->slug ),
				selected( $selected, $term->slug, false ),
				esc_html( $term->name )
			);
		}
		echo '</select>';
	}

	/**
	 * Filter products by manufacturer taxonomy when dropdown is used.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function filter_products_by_manufacturer( \WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || $pagenow !== 'edit.php' || ( $query->get( 'post_type' ) !== 'product' ) ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
		$manufacturer = sanitize_text_field( wp_unslash( $_GET[ Skwirrel_WC_Sync_Brand_Sync::MANUFACTURER_TAXONOMY ] ?? '' ) );
		if ( $manufacturer === '' ) {
			return;
		}
		$tax_query   = $query->get( 'tax_query' ) ?: [];
		$tax_query[] = [
			'taxonomy' => Skwirrel_WC_Sync_Brand_Sync::MANUFACTURER_TAXONOMY,
			'field'    => 'slug',
			'terms'    => $manufacturer,
		];
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Render a search input for GTIN / Manufacturer product code on the product list page.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_identifier_search_input( string $post_type ): void {
		if ( 'product' !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
		$value = sanitize_text_field( wp_unslash( $_GET['skw_identifier'] ?? '' ) );
		printf(
			'<input type="text" name="skw_identifier" value="%s" placeholder="%s" style="width:180px;" />',
			esc_attr( $value ),
			esc_attr__( 'GTIN / Manufacturer code', 'skwirrel-pim-sync' )
		);
	}

	/**
	 * Filter products by GTIN or manufacturer product code meta.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function filter_products_by_identifier( \WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
		$identifier = sanitize_text_field( wp_unslash( $_GET['skw_identifier'] ?? '' ) );
		if ( '' === $identifier ) {
			return;
		}
		$existing     = $query->get( 'meta_query' );
		$meta_query   = is_array( $existing ) ? $existing : [];
		$meta_query[] = [
			'relation' => 'OR',
			[
				'key'     => '_product_gtin',
				'value'   => $identifier,
				'compare' => 'LIKE',
			],
			[
				'key'     => '_manufacturer_product_code',
				'value'   => $identifier,
				'compare' => 'LIKE',
			],
		];
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Set default hidden columns for the WooCommerce product list screen.
	 *
	 * Hides the Tags column and ensures Manufacturers is visible by default.
	 * Only applies to users who have not yet customised their column preferences.
	 *
	 * @param string[]   $hidden Default hidden column IDs.
	 * @param \WP_Screen $screen Current admin screen.
	 * @return string[]
	 */
	public function default_hidden_product_columns( array $hidden, \WP_Screen $screen ): array {
		if ( $screen->id !== 'edit-product' ) {
			return $hidden;
		}

		// Hide Tags by default
		if ( ! in_array( 'product_tag', $hidden, true ) ) {
			$hidden[] = 'product_tag';
		}

		// Show Manufacturers by default (remove from hidden list)
		$hidden = array_values( array_diff( $hidden, [ 'taxonomy-product_manufacturer' ] ) );

		return $hidden;
	}

	private function woocommerce_missing_notice(): void {
		$install_url  = admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );
		$activate_url = admin_url( 'plugins.php' );
		?>
		<div class="notice notice-error is-dismissible">
			<p><strong>Skwirrel PIM sync for WooCommerce</strong></p>
			<p>
			<?php
				printf(
					wp_kses(
						/* translators: %1$s = install URL, %2$s = activate URL */
						__( 'WooCommerce is required. <a href="%1$s">Install WooCommerce</a> or <a href="%2$s">activate WooCommerce</a>.', 'skwirrel-pim-sync' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					esc_url( $install_url ),
					esc_url( $activate_url )
				);
			?>
			</p>
		</div>
		<?php
	}
}

Skwirrel_WC_Sync_Plugin::instance();
