<?php
/**
 * Skwirrel Permalink Settings.
 *
 * Adds product slug configuration to WordPress Settings → Permalinks page,
 * alongside WooCommerce's own product permalink settings.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Permalink_Settings {

	/** Option key for permalink-specific settings. */
	public const OPTION_KEY = 'skwirrel_wc_sync_permalinks';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'save_settings' ] );
	}

	/**
	 * Get permalink settings with defaults.
	 *
	 * Includes backward compatibility: if no dedicated option exists yet,
	 * falls back to reading from the main plugin settings.
	 *
	 * @return array{slug_source_field: string, slug_suffix_field: string, update_slug_on_resync: bool}
	 */
	public static function get_options(): array {
		$defaults = [
			'slug_source_field'     => 'product_name',
			'slug_suffix_field'     => '',
			'update_slug_on_resync' => false,
			'manufacturer_base'     => 'manufacturer',
		];

		$opts = get_option( self::OPTION_KEY, [] );

		// Backward compatibility: migrate from main settings if not yet saved.
		if ( empty( $opts ) ) {
			$main = get_option( 'skwirrel_wc_sync_settings', [] );
			if ( isset( $main['slug_source_field'] ) || isset( $main['slug_suffix_field'] ) ) {
				$opts = [
					'slug_source_field'     => $main['slug_source_field'] ?? 'product_name',
					'slug_suffix_field'     => $main['slug_suffix_field'] ?? '',
					'update_slug_on_resync' => false,
				];
			}
		}

		return array_merge( $defaults, $opts );
	}

	/**
	 * Register settings section and fields on the Permalinks page.
	 */
	public function register_settings(): void {
		add_settings_section(
			'skwirrel-product-permalink',
			__( 'Skwirrel product slugs', 'skwirrel-pim-sync' ),
			[ $this, 'render_section' ],
			'permalink'
		);

		add_settings_field(
			'skwirrel_slug_source_field',
			__( 'Slug source', 'skwirrel-pim-sync' ),
			[ $this, 'render_slug_source_field' ],
			'permalink',
			'skwirrel-product-permalink'
		);

		add_settings_field(
			'skwirrel_slug_suffix_field',
			__( 'Slug suffix (on duplicate)', 'skwirrel-pim-sync' ),
			[ $this, 'render_slug_suffix_field' ],
			'permalink',
			'skwirrel-product-permalink'
		);

		add_settings_field(
			'skwirrel_update_slug_on_resync',
			__( 'Update slug on re-sync', 'skwirrel-pim-sync' ),
			[ $this, 'render_update_slug_on_resync_field' ],
			'permalink',
			'skwirrel-product-permalink'
		);

		add_settings_field(
			'skwirrel_manufacturer_base',
			__( 'Product manufacturer base', 'skwirrel-pim-sync' ),
			[ $this, 'render_manufacturer_base_field' ],
			'permalink',
			'skwirrel-product-permalink'
		);
	}

	/**
	 * Section description.
	 */
	public function render_section(): void {
		echo '<p>' . esc_html__( 'Configure how Skwirrel generates product URL slugs during sync.', 'skwirrel-pim-sync' ) . '</p>';
	}

	/**
	 * Render slug source dropdown.
	 */
	public function render_slug_source_field(): void {
		$opts  = self::get_options();
		$value = $opts['slug_source_field'];
		?>
		<select id="skwirrel_slug_source_field" name="skwirrel_slug_source_field">
			<option value="product_name" <?php selected( $value, 'product_name' ); ?>><?php esc_html_e( 'Product name (default)', 'skwirrel-pim-sync' ); ?></option>
			<option value="internal_product_code" <?php selected( $value, 'internal_product_code' ); ?>><?php esc_html_e( 'Internal product code (SKU)', 'skwirrel-pim-sync' ); ?></option>
			<option value="manufacturer_product_code" <?php selected( $value, 'manufacturer_product_code' ); ?>><?php esc_html_e( 'Manufacturer product code', 'skwirrel-pim-sync' ); ?></option>
			<option value="external_product_id" <?php selected( $value, 'external_product_id' ); ?>><?php esc_html_e( 'External product ID', 'skwirrel-pim-sync' ); ?></option>
			<option value="product_id" <?php selected( $value, 'product_id' ); ?>><?php esc_html_e( 'Skwirrel product ID', 'skwirrel-pim-sync' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'The source field used as the base URL (slug) for new products.', 'skwirrel-pim-sync' ); ?></p>
		<?php
	}

	/**
	 * Render slug suffix dropdown.
	 */
	public function render_slug_suffix_field(): void {
		$opts  = self::get_options();
		$value = $opts['slug_suffix_field'];
		?>
		<select id="skwirrel_slug_suffix_field" name="skwirrel_slug_suffix_field">
			<option value="" <?php selected( $value, '' ); ?>><?php esc_html_e( 'None — WordPress auto-numbers (-2, -3)', 'skwirrel-pim-sync' ); ?></option>
			<option value="internal_product_code" <?php selected( $value, 'internal_product_code' ); ?>><?php esc_html_e( 'Internal product code (SKU)', 'skwirrel-pim-sync' ); ?></option>
			<option value="manufacturer_product_code" <?php selected( $value, 'manufacturer_product_code' ); ?>><?php esc_html_e( 'Manufacturer product code', 'skwirrel-pim-sync' ); ?></option>
			<option value="external_product_id" <?php selected( $value, 'external_product_id' ); ?>><?php esc_html_e( 'External product ID', 'skwirrel-pim-sync' ); ?></option>
			<option value="product_id" <?php selected( $value, 'product_id' ); ?>><?php esc_html_e( 'Skwirrel product ID', 'skwirrel-pim-sync' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'If the slug already exists, this field is appended as a suffix. Existing slugs are not changed.', 'skwirrel-pim-sync' ); ?></p>
		<?php
	}

	/**
	 * Render "update slug on re-sync" checkbox.
	 */
	public function render_update_slug_on_resync_field(): void {
		$opts    = self::get_options();
		$checked = ! empty( $opts['update_slug_on_resync'] );
		?>
		<label>
			<input type="checkbox" name="skwirrel_update_slug_on_resync" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Also update slugs for existing products during sync', 'skwirrel-pim-sync' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, product slugs are updated on every sync based on the source field above. When disabled, only new products get a slug from Skwirrel.', 'skwirrel-pim-sync' ); ?></p>
		<?php
	}

	/**
	 * Render manufacturer base slug input.
	 */
	public function render_manufacturer_base_field(): void {
		$opts  = self::get_options();
		$value = $opts['manufacturer_base'];
		?>
		<input type="text" id="skwirrel_manufacturer_base" name="skwirrel_manufacturer_base" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'The base slug for the product manufacturer taxonomy URLs.', 'skwirrel-pim-sync' ); ?></p>
		<?php
	}

	/**
	 * Save settings from the Permalinks page.
	 *
	 * WordPress does not use register_setting() for the permalink page,
	 * so we handle saving manually (same approach as WooCommerce).
	 */
	public function save_settings(): void {
		if ( ! is_admin() ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below via wp_verify_nonce
		if ( ! isset( $_POST['skwirrel_slug_source_field'], $_POST['_wpnonce'] ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_key
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'update-permalink' ) ) {
			return;
		}

		$allowed_source = [ 'product_name', 'internal_product_code', 'manufacturer_product_code', 'external_product_id', 'product_id' ];
		$allowed_suffix = [ '', 'internal_product_code', 'manufacturer_product_code', 'external_product_id', 'product_id' ];

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above
		$source            = sanitize_text_field( wp_unslash( $_POST['skwirrel_slug_source_field'] ?? 'product_name' ) );
		$suffix            = sanitize_text_field( wp_unslash( $_POST['skwirrel_slug_suffix_field'] ?? '' ) );
		$update_on_resync  = ! empty( $_POST['skwirrel_update_slug_on_resync'] );
		$manufacturer_base = sanitize_title( wp_unslash( $_POST['skwirrel_manufacturer_base'] ?? 'manufacturer' ) );
        // phpcs:enable

		$opts = [
			'slug_source_field'     => in_array( $source, $allowed_source, true ) ? $source : 'product_name',
			'slug_suffix_field'     => in_array( $suffix, $allowed_suffix, true ) ? $suffix : '',
			'update_slug_on_resync' => $update_on_resync,
			'manufacturer_base'     => '' !== $manufacturer_base ? $manufacturer_base : 'manufacturer',
		];

		update_option( self::OPTION_KEY, $opts );
	}
}
