<?php
/**
 * Skwirrel Admin Dashboard — modern Tailwind-styled admin interface.
 *
 * Replaces the tab-based UI with a block-grid dashboard and sub-pages.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin dashboard renderer.
 */
class Skwirrel_WC_Sync_Admin_Dashboard {

	private const PAGE_SLUG  = 'skwirrel-pim-sync';
	private const OPTION_KEY = 'skwirrel_wc_sync_settings';
	private const MASK       = '••••••••';

	/**
	 * Language options available in dropdowns.
	 */
	private const LANGUAGE_OPTIONS = array(
		'nl'    => 'Nederlands (nl)',
		'nl-NL' => 'Nederlands – Nederland (nl-NL)',
		'nl-BE' => 'Nederlands – België (nl-BE)',
		'en'    => 'English (en)',
		'en-GB' => 'English – UK (en-GB)',
		'en-US' => 'English – US (en-US)',
		'de'    => 'Deutsch (de)',
		'de-DE' => 'Deutsch – Deutschland (de-DE)',
		'fr'    => 'Français (fr)',
		'fr-FR' => 'Français – France (fr-FR)',
		'fr-BE' => 'Français – Belgique (fr-BE)',
	);

	/**
	 * Render the full admin page.
	 *
	 * @param string $active_view The current view to render.
	 */
	public function render( string $active_view ): void {
		$sync_in_progress = (bool) get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS );
		$base_url         = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		?>
		<div id="skwirrel-dashboard" class="skw-dashboard">

			<?php // -- Header -- ?>
			<div class="skw-header">
				<div class="skw-header-inner">
					<div class="skw-header-left">
						<div class="skw-header-icon">
							<img src="<?php echo esc_url( SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/s.png' ); ?>" alt="Skwirrel" width="28" height="28" />
						</div>
						<div>
							<h1 class="skw-header-title"><?php esc_html_e( 'Skwirrel PIM sync', 'skwirrel-pim-sync' ); ?></h1>
							<p class="skw-header-sub"><?php echo esc_html( 'v' . SKWIRREL_WC_SYNC_VERSION ); ?></p>
						</div>
					</div>
					<?php if ( 'dashboard' !== $active_view ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" class="skw-back-btn">
							<svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.25a.75.75 0 0 1 0-1.08l5.5-5.25a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd" /></svg>
							<?php esc_html_e( 'Dashboard', 'skwirrel-pim-sync' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<?php // -- Notices slot (filled by JS) -- ?>
			<div id="skwirrel-notices" class="skw-notices"></div>

			<?php // -- Sync Progress Banner -- ?>
			<?php if ( $sync_in_progress ) : ?>
				<?php $this->render_sync_progress(); ?>
			<?php endif; ?>

			<?php // -- Content -- ?>
			<div class="skw-content">
				<?php
				switch ( $active_view ) {
					case 'history':
						$this->render_page_history();
						break;
					case 'settings':
						$this->render_page_settings();
						break;
					case 'debug':
						$this->render_page_debug();
						break;
					default:
						$this->render_page_dashboard( $sync_in_progress );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the dashboard grid with action blocks.
	 *
	 * @param bool $sync_in_progress Whether a sync is currently running.
	 */
	private function render_page_dashboard( bool $sync_in_progress ): void {
		$base_url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$last_sync   = Skwirrel_WC_Sync_History::get_last_sync();
		$last_result = Skwirrel_WC_Sync_History::get_last_result();
		$history     = Skwirrel_WC_Sync_History::get_sync_history();

		// Last sync status summary.
		if ( $last_result ) {
			$created = (int) ( $last_result['created'] ?? 0 );
			$updated = (int) ( $last_result['updated'] ?? 0 );
			$failed  = (int) ( $last_result['failed'] ?? 0 );
			$total   = $created + $updated + $failed;
		}

		?>
		<?php // -- Last sync result card -- ?>
		<?php if ( ! $sync_in_progress && $last_result ) : ?>
			<div class="skw-status-card <?php echo esc_attr( $last_result['success'] ? 'skw-status-success' : 'skw-status-error' ); ?>">
				<div class="skw-status-icon">
					<?php if ( $last_result['success'] ) : ?>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
					<?php else : ?>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
					<?php endif; ?>
				</div>
				<div class="skw-status-body">
					<p class="skw-status-title">
						<?php echo $last_result['success'] ? esc_html__( 'Last sync successful', 'skwirrel-pim-sync' ) : esc_html__( 'Last sync failed', 'skwirrel-pim-sync' ); ?>
					</p>
					<p class="skw-status-meta">
						<?php echo $last_sync ? esc_html( $this->format_datetime( $last_sync ) ) : ''; ?>
						<?php if ( $last_result['success'] ) : ?>
							&mdash;
							<span class="skw-c-green"><?php echo esc_html( (string) $created ); ?> <?php esc_html_e( 'created', 'skwirrel-pim-sync' ); ?></span>,
							<span class="skw-c-blue"><?php echo esc_html( (string) $updated ); ?> <?php esc_html_e( 'updated', 'skwirrel-pim-sync' ); ?></span>,
							<span class="skw-c-red"><?php echo esc_html( (string) $failed ); ?> <?php esc_html_e( 'failed', 'skwirrel-pim-sync' ); ?></span>
						<?php endif; ?>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<?php // -- Action Blocks Grid -- ?>
		<div class="skw-grid">

			<?php // -- Sync Now -- ?>
			<?php if ( $sync_in_progress ) : ?>
				<div class="skw-block skw-block-disabled">
					<div class="skw-block-icon skw-bg-blue">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24" class="skw-spin"><path d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M21.015 4.356v4.992" stroke-linecap="round" stroke-linejoin="round" /></svg>
					</div>
					<div class="skw-block-body">
						<h3 class="skw-block-title"><?php esc_html_e( 'Sync in progress…', 'skwirrel-pim-sync' ); ?></h3>
						<p class="skw-block-desc"><?php esc_html_e( 'Products are being synchronized. This page refreshes automatically.', 'skwirrel-pim-sync' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skwirrel_wc_sync_run' ), 'skwirrel_wc_sync_run', '_wpnonce' ) ); ?>" class="skw-block">
					<div class="skw-block-icon skw-bg-blue">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
					</div>
					<div class="skw-block-body">
						<h3 class="skw-block-title"><?php esc_html_e( 'Sync Now', 'skwirrel-pim-sync' ); ?></h3>
						<p class="skw-block-desc"><?php esc_html_e( 'Start a full product synchronization from Skwirrel PIM.', 'skwirrel-pim-sync' ); ?></p>
					</div>
					<span class="skw-block-arrow"><svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" /></svg></span>
				</a>
			<?php endif; ?>

			<?php // -- Sync History -- ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'history', $base_url ) ); ?>" class="skw-block">
				<div class="skw-block-icon skw-bg-teal">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><path d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
				</div>
				<div class="skw-block-body">
					<h3 class="skw-block-title"><?php esc_html_e( 'Sync History', 'skwirrel-pim-sync' ); ?></h3>
					<p class="skw-block-desc">
						<?php
						/* translators: %d = number of history entries */
						echo esc_html( sprintf( __( '%d sync runs recorded.', 'skwirrel-pim-sync' ), count( $history ) ) );
						?>
					</p>
				</div>
				<span class="skw-block-arrow"><svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" /></svg></span>
			</a>

			<?php // -- Settings -- ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>" class="skw-block">
				<div class="skw-block-icon skw-bg-slate">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><path d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.431.992a7.723 7.723 0 0 1 0 .255c-.007.378.138.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" stroke-linecap="round" stroke-linejoin="round" /><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
				</div>
				<div class="skw-block-body">
					<h3 class="skw-block-title"><?php esc_html_e( 'Settings', 'skwirrel-pim-sync' ); ?></h3>
					<p class="skw-block-desc"><?php esc_html_e( 'Configure API connection, sync options, and scheduling.', 'skwirrel-pim-sync' ); ?></p>
				</div>
				<span class="skw-block-arrow"><svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" /></svg></span>
			</a>

			<?php
			// -- Sync Logs (direct link to WooCommerce logs) -- .
			$logger  = new Skwirrel_WC_Sync_Logger();
			$log_url = $logger->get_log_file_url();
			?>
			<a href="<?php echo esc_url( $log_url ? $log_url : '#' ); ?>" class="skw-block" <?php echo $log_url ? 'target="_blank"' : ''; ?>>
				<div class="skw-block-icon skw-bg-yellow">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><path d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
				</div>
				<div class="skw-block-body">
					<h3 class="skw-block-title"><?php esc_html_e( 'Sync Logs', 'skwirrel-pim-sync' ); ?></h3>
					<p class="skw-block-desc"><?php esc_html_e( 'View detailed sync log files in WooCommerce.', 'skwirrel-pim-sync' ); ?></p>
				</div>
				<span class="skw-block-arrow"><svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" /></svg></span>
			</a>

			<?php // -- Debug -- ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'debug', $base_url ) ); ?>" class="skw-block skw-block-compact">
				<div class="skw-block-icon skw-bg-rose">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20"><path d="M12 12.75c1.148 0 2.278.08 3.383.237 1.037.146 1.866.966 1.866 2.013 0 3.728-2.35 6.75-5.25 6.75S6.75 18.728 6.75 15c0-1.046.83-1.867 1.866-2.013A24.204 24.204 0 0 1 12 12.75Zm0 0c2.883 0 5.647.508 8.207 1.44a23.91 23.91 0 0 1-1.152-6.135 3.24 3.24 0 0 0-.399-1.003 3.278 3.278 0 0 0-.755-.89 3.245 3.245 0 0 0-1.614-.637 23.834 23.834 0 0 0-8.574 0 3.245 3.245 0 0 0-2.37 1.527 3.24 3.24 0 0 0-.398 1.003 23.91 23.91 0 0 1-1.152 6.135A23.856 23.856 0 0 1 12 12.75ZM2.695 18.678a25.076 25.076 0 0 1 3.197-7.8c.07-.116.145-.229.225-.34A3 3 0 0 1 8.46 9.15a24.795 24.795 0 0 1 7.078 0 3 3 0 0 1 2.345 1.388c.08.111.155.224.225.34a25.076 25.076 0 0 1 3.197 7.8 24.237 24.237 0 0 1-9.305 1.822 24.237 24.237 0 0 1-9.305-1.822Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
				</div>
				<div class="skw-block-body">
					<h3 class="skw-block-title"><?php esc_html_e( 'Debug', 'skwirrel-pim-sync' ); ?></h3>
					<p class="skw-block-desc"><?php esc_html_e( 'Troubleshoot variation attribute issues.', 'skwirrel-pim-sync' ); ?></p>
				</div>
				<span class="skw-block-arrow"><svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" /></svg></span>
			</a>

			<?php // -- Danger Zone -- ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) . '#skwirrel-danger-zone' ); ?>" class="skw-block skw-block-compact skw-block-danger">
				<div class="skw-block-icon skw-bg-red">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
				</div>
				<div class="skw-block-body">
					<h3 class="skw-block-title"><?php esc_html_e( 'Danger Zone', 'skwirrel-pim-sync' ); ?></h3>
					<p class="skw-block-desc"><?php esc_html_e( 'Delete all synced products and start fresh.', 'skwirrel-pim-sync' ); ?></p>
				</div>
				<span class="skw-block-arrow"><svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" /></svg></span>
			</a>

		</div>

		<?php // -- Quick History (last 5 entries) -- ?>
		<?php if ( ! empty( $history ) ) : ?>
			<?php $recent = array_slice( $history, 0, 5 ); ?>
			<div class="skw-section">
				<div class="skw-section-header">
					<h2 class="skw-section-title"><?php esc_html_e( 'Recent syncs', 'skwirrel-pim-sync' ); ?></h2>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'history', $base_url ) ); ?>" class="skw-link"><?php esc_html_e( 'View all', 'skwirrel-pim-sync' ); ?> &rarr;</a>
				</div>
				<?php $this->render_history_table( $recent ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render sync progress banner.
	 */
	private function render_sync_progress(): void {
		$progress = Skwirrel_WC_Sync_History::get_sync_progress();
		$opts     = get_option( self::OPTION_KEY, array() );

		$taxonomy_label = ! empty( $opts['sync_manufacturers'] )
			? __( 'Assign categories, brands & manufacturers', 'skwirrel-pim-sync' )
			: __( 'Assign categories & brands', 'skwirrel-pim-sync' );

		$phases = array(
			Skwirrel_WC_Sync_History::PHASE_FETCH      => __( 'Fetch products from API', 'skwirrel-pim-sync' ),
			Skwirrel_WC_Sync_History::PHASE_PRODUCTS   => __( 'Create & update products', 'skwirrel-pim-sync' ),
			Skwirrel_WC_Sync_History::PHASE_TAXONOMY   => $taxonomy_label,
			Skwirrel_WC_Sync_History::PHASE_ATTRIBUTES => __( 'Connect attributes', 'skwirrel-pim-sync' ),
			Skwirrel_WC_Sync_History::PHASE_MEDIA      => __( 'Download images & documents', 'skwirrel-pim-sync' ),
			Skwirrel_WC_Sync_History::PHASE_CLEANUP    => __( 'Cleanup & finalize', 'skwirrel-pim-sync' ),
		);

		?>
		<div class="skw-progress-banner">
			<div class="skw-progress-header">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20" class="skw-spin"><path d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M21.015 4.356v4.992" stroke-linecap="round" stroke-linejoin="round" /></svg>
				<span><?php esc_html_e( 'Sync in progress…', 'skwirrel-pim-sync' ); ?></span>
			</div>
			<div class="skw-progress-phases">
				<?php foreach ( $phases as $key => $label ) : ?>
					<?php
					$phase_data = $progress['phases'][ $key ] ?? null;
					$status     = $phase_data['status'] ?? 'pending';
					$current    = $phase_data['current'] ?? 0;
					$total      = $phase_data['total'] ?? 0;

					$counter = '';
					if ( $phase_data && $total > 0 ) {
						$counter = sprintf( '%d / %d', $current, $total );
					} elseif ( $phase_data && 'in_progress' === $status && Skwirrel_WC_Sync_History::PHASE_FETCH === $key ) {
						/* translators: %d = number of products fetched */
						$counter = sprintf( __( '%d fetched', 'skwirrel-pim-sync' ), $current );
					}
					?>
					<div class="skw-phase skw-phase-<?php echo esc_attr( $status ); ?>">
						<span class="skw-phase-icon">
							<?php if ( 'completed' === $status ) : ?>
								<svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
							<?php elseif ( 'in_progress' === $status ) : ?>
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" class="skw-spin"><path d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M21.015 4.356v4.992" stroke-linecap="round" stroke-linejoin="round" /></svg>
							<?php else : ?>
								<span class="skw-phase-dot"></span>
							<?php endif; ?>
						</span>
						<span class="skw-phase-label"><?php echo esc_html( $label ); ?></span>
						<?php if ( $counter ) : ?>
							<span class="skw-phase-counter"><?php echo esc_html( $counter ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="skw-progress-note"><?php esc_html_e( 'This page refreshes automatically every 5 seconds.', 'skwirrel-pim-sync' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the full history page with date-grouped table.
	 */
	private function render_page_history(): void {
		$history = Skwirrel_WC_Sync_History::get_sync_history();
		?>
		<div class="skw-section">
			<div class="skw-section-header">
				<div>
					<h2 class="skw-section-title"><?php esc_html_e( 'Sync History', 'skwirrel-pim-sync' ); ?></h2>
					<p class="skw-section-desc"><?php esc_html_e( 'Overview of all synchronization runs, grouped by date.', 'skwirrel-pim-sync' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="skw-history-controls">
					<input type="hidden" name="action" value="skwirrel_wc_sync_clear_history" />
					<?php wp_nonce_field( 'skwirrel_wc_sync_clear_history', '_wpnonce' ); ?>
					<select name="history_period" class="skw-select">
						<option value="all"><?php esc_html_e( 'All', 'skwirrel-pim-sync' ); ?></option>
						<option value="7"><?php esc_html_e( 'Older than 7 days', 'skwirrel-pim-sync' ); ?></option>
						<option value="30"><?php esc_html_e( 'Older than 30 days', 'skwirrel-pim-sync' ); ?></option>
						<option value="90"><?php esc_html_e( 'Older than 90 days', 'skwirrel-pim-sync' ); ?></option>
					</select>
					<button type="submit" class="skw-btn skw-btn-secondary" id="skwirrel-clear-history-btn"><?php esc_html_e( 'Delete history', 'skwirrel-pim-sync' ); ?></button>
				</form>
			</div>
			<?php if ( empty( $history ) ) : ?>
				<div class="skw-empty"><?php esc_html_e( 'No sync history available.', 'skwirrel-pim-sync' ); ?></div>
			<?php else : ?>
				<?php $this->render_history_table( $history, true ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the history table with optional date grouping.
	 *
	 * @param array $entries    History entries.
	 * @param bool  $group_dates Whether to group by date headers.
	 */
	private function render_history_table( array $entries, bool $group_dates = false ): void {
		$trigger_labels = array(
			Skwirrel_WC_Sync_History::TRIGGER_MANUAL    => _x( 'Manual', 'sync trigger', 'skwirrel-pim-sync' ),
			Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED => __( 'Scheduled', 'skwirrel-pim-sync' ),
			Skwirrel_WC_Sync_History::TRIGGER_PURGE     => __( 'Purge', 'skwirrel-pim-sync' ),
		);

		$today     = wp_date( 'Y-m-d' );
		$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$week_ago  = time() - ( 7 * DAY_IN_SECONDS );
		$prev_date = '';

		?>
		<div class="skw-table-wrap">
			<table class="skw-table">
				<thead>
					<tr>
						<th class="skw-th-left"><?php esc_html_e( 'Time', 'skwirrel-pim-sync' ); ?></th>
						<th class="skw-th-left"><?php esc_html_e( 'Trigger', 'skwirrel-pim-sync' ); ?></th>
						<th class="skw-th-left"><?php esc_html_e( 'Status', 'skwirrel-pim-sync' ); ?></th>
						<th class="skw-th-right"><?php esc_html_e( 'Created', 'skwirrel-pim-sync' ); ?></th>
						<th class="skw-th-right"><?php esc_html_e( 'Updated', 'skwirrel-pim-sync' ); ?></th>
						<th class="skw-th-right"><?php esc_html_e( 'Failed', 'skwirrel-pim-sync' ); ?></th>
						<th class="skw-th-right"><?php esc_html_e( 'Deleted', 'skwirrel-pim-sync' ); ?></th>
						<th class="skw-th-right"><?php esc_html_e( 'Total', 'skwirrel-pim-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						$ts            = (int) ( $entry['timestamp'] ?? 0 );
						$entry_date    = $ts ? wp_date( 'Y-m-d', $ts ) : '';
						$entry_time    = $ts ? wp_date( get_option( 'time_format' ), $ts ) : '-';
						$is_success    = ! empty( $entry['success'] );
						$entry_trigger = $entry['trigger'] ?? Skwirrel_WC_Sync_History::TRIGGER_MANUAL;
						$is_purge      = ( Skwirrel_WC_Sync_History::TRIGGER_PURGE === $entry_trigger );
						$created       = (int) ( $entry['created'] ?? 0 );
						$updated       = (int) ( $entry['updated'] ?? 0 );
						$failed        = (int) ( $entry['failed'] ?? 0 );
						$trashed       = (int) ( $entry['trashed'] ?? 0 );
						$total         = $is_purge ? $trashed : ( $created + $updated + $failed );
						$trigger_label = $trigger_labels[ $entry_trigger ] ?? $trigger_labels[ Skwirrel_WC_Sync_History::TRIGGER_MANUAL ];

						// Date group header.
						if ( $group_dates && $prev_date !== $entry_date && '' !== $entry_date ) :
							$prev_date = $entry_date;

							if ( $today === $entry_date ) {
								$date_label = __( 'Today', 'skwirrel-pim-sync' );
							} elseif ( $yesterday === $entry_date ) {
								$date_label = __( 'Yesterday', 'skwirrel-pim-sync' );
							} elseif ( $ts > $week_ago ) {
								$date_label = wp_date( 'l', $ts ); // Day name.
							} else {
								$date_label = wp_date( get_option( 'date_format' ), $ts );
							}
							?>
							<tr class="skw-date-row">
								<th colspan="8" class="skw-date-header"><?php echo esc_html( $date_label ); ?></th>
							</tr>
						<?php endif; ?>
						<tr class="skw-entry-row <?php echo $is_purge ? 'skw-row-purge' : ''; ?>">
							<td class="skw-td-left skw-td-medium"><?php echo esc_html( $entry_time ); ?></td>
							<td class="skw-td-left"><?php echo esc_html( $trigger_label ); ?></td>
							<td class="skw-td-left">
								<?php if ( $is_purge ) : ?>
									<span class="skw-badge skw-badge-yellow"><?php esc_html_e( 'Purged', 'skwirrel-pim-sync' ); ?></span>
								<?php elseif ( $is_success ) : ?>
									<span class="skw-badge skw-badge-green"><?php esc_html_e( 'Success', 'skwirrel-pim-sync' ); ?></span>
								<?php else : ?>
									<span class="skw-badge skw-badge-red"><?php esc_html_e( 'Failed', 'skwirrel-pim-sync' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="skw-td-right skw-c-green"><?php echo esc_html( (string) $created ); ?></td>
							<td class="skw-td-right skw-c-blue"><?php echo esc_html( (string) $updated ); ?></td>
							<td class="skw-td-right skw-c-red"><?php echo esc_html( (string) $failed ); ?></td>
							<td class="skw-td-right skw-c-yellow"><?php echo esc_html( (string) $trashed ); ?></td>
							<td class="skw-td-right skw-td-bold"><?php echo esc_html( (string) $total ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the settings page (keeps existing form logic).
	 */
	private function render_page_settings(): void {
		$opts         = get_option( self::OPTION_KEY, array() );
		$token_masked = Skwirrel_WC_Sync_Admin_Settings::get_auth_token() !== '' ? self::MASK : '';

		?>
		<div class="skw-section">
			<h2 class="skw-section-title"><?php esc_html_e( 'Settings', 'skwirrel-pim-sync' ); ?></h2>
			<p class="skw-section-desc"><?php esc_html_e( 'Configure your Skwirrel PIM API connection and synchronization options.', 'skwirrel-pim-sync' ); ?></p>

			<form method="post" action="options.php" id="skwirrel-sync-settings-form" class="skw-settings-form">
				<?php wp_nonce_field( 'options-options' ); ?>
				<?php settings_fields( 'skwirrel_wc_sync' ); ?>

				<?php // -- API Connection -- ?>
				<div class="skw-fieldgroup">
					<h3 class="skw-fieldgroup-title"><?php esc_html_e( 'API Connection', 'skwirrel-pim-sync' ); ?></h3>
					<?php
					$full_url  = rtrim( $opts['endpoint_url'] ?? '', '/' );
					$subdomain = '';
					if ( preg_match( '#^https?://(.+)\.skwirrel\.eu(?:/jsonrpc)?$#i', $full_url, $m ) ) {
						$subdomain = $m[1];
					}
					?>
					<div class="skw-field">
						<label for="skwirrel_subdomain" class="skw-label"><?php esc_html_e( 'Skwirrel subdomain', 'skwirrel-pim-sync' ); ?></label>
						<div class="skw-input-affixed">
							<span class="skw-input-prefix">https://</span>
							<input type="text" id="skwirrel_subdomain" value="<?php echo esc_attr( $subdomain ); ?>" class="skw-input" placeholder="yourcompany" required />
							<span class="skw-input-suffix">.skwirrel.eu/jsonrpc</span>
						</div>
						<input type="hidden" id="endpoint_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[endpoint_url]" value="<?php echo esc_attr( $full_url ); ?>" />
					</div>
					<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auth_type]" value="token" />
					<div class="skw-field">
						<label for="auth_token" class="skw-label"><?php esc_html_e( 'API Token', 'skwirrel-pim-sync' ); ?></label>
						<input type="password" id="auth_token" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auth_token]" value="<?php echo esc_attr( $token_masked ); ?>" class="skw-input" autocomplete="off" />
						<p class="skw-field-hint">
							<?php
							printf(
								/* translators: %s = URL to Skwirrel webservice page */
								esc_html__( 'Create a static API token at %s.', 'skwirrel-pim-sync' ),
								'<a href="https://' . esc_attr( $subdomain ? $subdomain : '<sub>' ) . '.skwirrel.eu/data/webservice" target="_blank" id="skwirrel-token-link">https://<span id="skwirrel-token-domain">' . esc_html( $subdomain ? $subdomain : '&lt;your-subdomain&gt;' ) . '</span>.skwirrel.eu/data/webservice</a>'
							);
							?>
						</p>
					</div>
					<div class="skw-field-row">
						<div class="skw-field">
							<label for="timeout" class="skw-label"><?php esc_html_e( 'Timeout (seconds)', 'skwirrel-pim-sync' ); ?></label>
							<input type="number" id="timeout" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[timeout]" value="<?php echo esc_attr( (string) ( $opts['timeout'] ?? 30 ) ); ?>" min="5" max="120" class="skw-input skw-input-sm" />
						</div>
						<div class="skw-field">
							<label for="retries" class="skw-label"><?php esc_html_e( 'Retries', 'skwirrel-pim-sync' ); ?></label>
							<input type="number" id="retries" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[retries]" value="<?php echo esc_attr( (string) ( $opts['retries'] ?? 2 ) ); ?>" min="0" max="5" class="skw-input skw-input-sm" />
						</div>
					</div>
					<div class="skw-field-actions">
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skwirrel_wc_sync_test' ), 'skwirrel_wc_sync_test', '_wpnonce' ) ); ?>" class="skw-btn skw-btn-secondary"><?php esc_html_e( 'Test connection', 'skwirrel-pim-sync' ); ?></a>
					</div>
				</div>

				<?php // -- Scheduling -- ?>
				<div class="skw-fieldgroup">
					<h3 class="skw-fieldgroup-title"><?php esc_html_e( 'Scheduling', 'skwirrel-pim-sync' ); ?></h3>
					<div class="skw-field-row">
						<div class="skw-field">
							<label for="sync_interval" class="skw-label"><?php esc_html_e( 'Sync interval', 'skwirrel-pim-sync' ); ?></label>
							<select id="sync_interval" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_interval]" class="skw-select">
								<?php foreach ( Skwirrel_WC_Sync_Action_Scheduler::get_interval_options() as $k => $v ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $opts['sync_interval'] ?? '', $k ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="skw-field">
							<label for="batch_size" class="skw-label"><?php esc_html_e( 'Batch size', 'skwirrel-pim-sync' ); ?></label>
							<input type="number" id="batch_size" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( (string) ( $opts['batch_size'] ?? 100 ) ); ?>" min="1" max="500" class="skw-input skw-input-sm" />
							<p class="skw-field-hint"><?php esc_html_e( 'Products per API request (1–500).', 'skwirrel-pim-sync' ); ?></p>
						</div>
					</div>
				</div>

				<?php // -- Sync Options -- ?>
				<div class="skw-fieldgroup">
					<h3 class="skw-fieldgroup-title"><?php esc_html_e( 'Sync Options', 'skwirrel-pim-sync' ); ?></h3>
					<div class="skw-checkbox-group">
						<label class="skw-checkbox"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_categories]" value="1" <?php checked( ! empty( $opts['sync_categories'] ) ); ?> /> <?php esc_html_e( 'Sync categories', 'skwirrel-pim-sync' ); ?></label>
						<label class="skw-checkbox"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_grouped_products]" value="1" <?php checked( ! empty( $opts['sync_grouped_products'] ) ); ?> /> <?php esc_html_e( 'Sync grouped products (variable)', 'skwirrel-pim-sync' ); ?></label>
						<label class="skw-checkbox"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_manufacturers]" value="1" <?php checked( ! empty( $opts['sync_manufacturers'] ) ); ?> /> <?php esc_html_e( 'Sync manufacturers', 'skwirrel-pim-sync' ); ?></label>
						<label class="skw-checkbox"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_custom_classes]" value="1" <?php checked( ! empty( $opts['sync_custom_classes'] ) ); ?> /> <?php esc_html_e( 'Sync custom classes', 'skwirrel-pim-sync' ); ?></label>
						<label class="skw-checkbox skw-checkbox-indent"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_trade_item_custom_classes]" value="1" <?php checked( ! empty( $opts['sync_trade_item_custom_classes'] ) ); ?> /> <?php esc_html_e( 'Include trade item custom classes', 'skwirrel-pim-sync' ); ?></label>
					</div>
					<div class="skw-field-row">
						<div class="skw-field">
							<label for="super_category_id" class="skw-label"><?php esc_html_e( 'Super category ID', 'skwirrel-pim-sync' ); ?></label>
							<input type="text" id="super_category_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[super_category_id]" value="<?php echo esc_attr( $opts['super_category_id'] ?? '' ); ?>" class="skw-input skw-input-sm" placeholder="<?php esc_attr_e( 'e.g. 42', 'skwirrel-pim-sync' ); ?>" />
							<p class="skw-field-hint">
								<?php
								printf(
									/* translators: %s = link to Skwirrel categories page */
									esc_html__( 'Find your category IDs at %s.', 'skwirrel-pim-sync' ),
									'<a href="https://' . esc_attr( $subdomain ? $subdomain : '<sub>' ) . '.skwirrel.eu/base/categories" target="_blank" id="skwirrel-categories-link">https://<span class="skwirrel-link-domain">' . esc_html( $subdomain ? $subdomain : '&lt;your-subdomain&gt;' ) . '</span>.skwirrel.eu/base/categories</a>'
								);
								?>
							</p>
						</div>
						<div class="skw-field">
							<label for="collection_ids" class="skw-label"><?php esc_html_e( 'Selection IDs', 'skwirrel-pim-sync' ); ?></label>
							<input type="text" id="collection_ids" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[collection_ids]" value="<?php echo esc_attr( $opts['collection_ids'] ?? '' ); ?>" class="skw-input" placeholder="<?php esc_attr_e( 'e.g. 123, 456', 'skwirrel-pim-sync' ); ?>" />
							<p class="skw-field-hint">
								<?php
								printf(
									/* translators: %s = link to Skwirrel selections page */
									esc_html__( 'Find your selection IDs at %s.', 'skwirrel-pim-sync' ),
									'<a href="https://' . esc_attr( $subdomain ? $subdomain : '<sub>' ) . '.skwirrel.eu/data/selections" target="_blank" id="skwirrel-selections-link">https://<span class="skwirrel-link-domain">' . esc_html( $subdomain ? $subdomain : '&lt;your-subdomain&gt;' ) . '</span>.skwirrel.eu/data/selections</a>'
								);
								?>
							</p>
						</div>
					</div>
					<div class="skw-field-row">
						<div class="skw-field">
							<?php $cc_mode = $opts['custom_class_filter_mode'] ?? ''; ?>
							<label for="custom_class_filter_mode" class="skw-label"><?php esc_html_e( 'Custom class filter', 'skwirrel-pim-sync' ); ?></label>
							<select id="custom_class_filter_mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_class_filter_mode]" class="skw-select">
								<option value="" <?php selected( $cc_mode, '' ); ?>><?php esc_html_e( 'No filter', 'skwirrel-pim-sync' ); ?></option>
								<option value="whitelist" <?php selected( $cc_mode, 'whitelist' ); ?>><?php esc_html_e( 'Whitelist', 'skwirrel-pim-sync' ); ?></option>
								<option value="blacklist" <?php selected( $cc_mode, 'blacklist' ); ?>><?php esc_html_e( 'Blacklist', 'skwirrel-pim-sync' ); ?></option>
							</select>
						</div>
						<div class="skw-field">
							<label for="custom_class_filter_ids" class="skw-label"><?php esc_html_e( 'Class IDs / codes', 'skwirrel-pim-sync' ); ?></label>
							<input type="text" id="custom_class_filter_ids" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_class_filter_ids]" value="<?php echo esc_attr( $opts['custom_class_filter_ids'] ?? '' ); ?>" class="skw-input" placeholder="<?php esc_attr_e( 'e.g. 12, 45, BUIS', 'skwirrel-pim-sync' ); ?>" />
						</div>
					</div>
				</div>

				<?php // -- Media & Language -- ?>
				<div class="skw-fieldgroup">
					<h3 class="skw-fieldgroup-title"><?php esc_html_e( 'Media & Language', 'skwirrel-pim-sync' ); ?></h3>
					<div class="skw-field-row">
						<div class="skw-field">
							<label for="sync_images" class="skw-label"><?php esc_html_e( 'Import images', 'skwirrel-pim-sync' ); ?></label>
							<select id="sync_images" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_images]" class="skw-select">
								<option value="yes" <?php selected( ( $opts['sync_images'] ?? true ), true ); ?>><?php esc_html_e( 'Yes', 'skwirrel-pim-sync' ); ?></option>
								<option value="no" <?php selected( ( $opts['sync_images'] ?? true ), false ); ?>><?php esc_html_e( 'No', 'skwirrel-pim-sync' ); ?></option>
							</select>
						</div>
						<div class="skw-field">
							<label for="use_sku_field" class="skw-label"><?php esc_html_e( 'SKU field', 'skwirrel-pim-sync' ); ?></label>
							<select id="use_sku_field" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[use_sku_field]" class="skw-select">
								<option value="internal_product_code" <?php selected( $opts['use_sku_field'] ?? 'internal_product_code', 'internal_product_code' ); ?>>internal_product_code</option>
								<option value="manufacturer_product_code" <?php selected( $opts['use_sku_field'] ?? '', 'manufacturer_product_code' ); ?>>manufacturer_product_code</option>
							</select>
						</div>
					</div>
					<div class="skw-field-row">
						<div class="skw-field">
							<?php
							$current_lang = $opts['image_language'] ?? 'nl';
							$is_custom    = ! isset( self::LANGUAGE_OPTIONS[ $current_lang ] );
							?>
							<label for="image_language_select" class="skw-label"><?php esc_html_e( 'Content language', 'skwirrel-pim-sync' ); ?></label>
							<select id="image_language_select" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[image_language_select]" class="skw-select">
								<?php foreach ( self::LANGUAGE_OPTIONS as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_lang, $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								<option value="_custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Other…', 'skwirrel-pim-sync' ); ?></option>
							</select>
							<span id="image_language_custom_wrap" style="display:<?php echo esc_attr( $is_custom ? 'inline-block' : 'none' ); ?>; margin-top: 6px;">
								<input type="text" id="image_language_custom" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[image_language_custom]" value="<?php echo esc_attr( $is_custom ? $current_lang : '' ); ?>" class="skw-input skw-input-sm" pattern="[a-z]{2}(-[A-Z]{2})?" placeholder="e.g. es-ES" />
							</span>
						</div>
					</div>
					<div class="skw-field">
						<label class="skw-label"><?php esc_html_e( 'API languages (include_languages)', 'skwirrel-pim-sync' ); ?></label>
						<?php
						$saved_langs  = ! empty( $opts['include_languages'] ) && is_array( $opts['include_languages'] ) ? $opts['include_languages'] : array( 'nl-NL', 'nl' );
						$known_codes  = array_keys( self::LANGUAGE_OPTIONS );
						$custom_langs = array_diff( $saved_langs, $known_codes );
						?>
						<div class="skw-checkbox-group skw-checkbox-inline">
							<?php foreach ( self::LANGUAGE_OPTIONS as $code => $label ) : ?>
								<label class="skw-checkbox"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[include_languages_checkboxes][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $saved_langs, true ) ); ?> /> <?php echo esc_html( $code ); ?></label>
							<?php endforeach; ?>
						</div>
						<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[include_languages_custom]" value="<?php echo esc_attr( implode( ', ', $custom_langs ) ); ?>" class="skw-input" placeholder="<?php esc_attr_e( 'Additional: e.g. es, pt-BR', 'skwirrel-pim-sync' ); ?>" style="margin-top: 6px;" />
					</div>
				</div>

				<?php
				// -- Permalinks -- .
				$permalink_opts   = Skwirrel_WC_Sync_Permalink_Settings::get_options();
				$slug_labels      = [
					'product_name'              => __( 'Product name', 'skwirrel-pim-sync' ),
					'internal_product_code'     => __( 'Internal product code (SKU)', 'skwirrel-pim-sync' ),
					'manufacturer_product_code' => __( 'Manufacturer product code', 'skwirrel-pim-sync' ),
					'external_product_id'       => __( 'External product ID', 'skwirrel-pim-sync' ),
					'product_id'                => __( 'Skwirrel product ID', 'skwirrel-pim-sync' ),
				];
				$current_source   = $slug_labels[ $permalink_opts['slug_source_field'] ] ?? $permalink_opts['slug_source_field'];
				$current_suffix   = '' !== $permalink_opts['slug_suffix_field']
					? ( $slug_labels[ $permalink_opts['slug_suffix_field'] ] ?? $permalink_opts['slug_suffix_field'] )
					: __( 'None (auto-number)', 'skwirrel-pim-sync' );
				$update_on_resync = ! empty( $permalink_opts['update_slug_on_resync'] );
				$permalinks_url   = admin_url( 'options-permalink.php#skwirrel_slug_source_field' );
				$resync_needed    = get_option( 'skwirrel_wc_sync_slug_resync_needed', false );
				?>
				<div class="skw-fieldgroup">
					<h3 class="skw-fieldgroup-title"><?php esc_html_e( 'Permalinks', 'skwirrel-pim-sync' ); ?></h3>

					<?php if ( $resync_needed && $update_on_resync ) : ?>
					<div class="skw-notice skw-notice-warning" id="skwirrel-slug-warning">
						<strong><?php esc_html_e( 'Slug settings have changed.', 'skwirrel-pim-sync' ); ?></strong>
						<?php esc_html_e( 'Run a full sync to update product URLs. Changing slugs may break existing links and SEO rankings.', 'skwirrel-pim-sync' ); ?>
					</div>
					<?php endif; ?>

					<table class="skw-summary-table">
						<tr>
							<th><?php esc_html_e( 'Slug source', 'skwirrel-pim-sync' ); ?></th>
							<td><?php echo esc_html( $current_source ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Suffix on duplicate', 'skwirrel-pim-sync' ); ?></th>
							<td><?php echo esc_html( $current_suffix ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Update on re-sync', 'skwirrel-pim-sync' ); ?></th>
							<td>
								<select id="skwirrel-update-slug-resync" class="skw-inline-select">
									<option value="0" <?php selected( ! $update_on_resync ); ?>><?php esc_html_e( 'No', 'skwirrel-pim-sync' ); ?></option>
									<option value="1" <?php selected( $update_on_resync ); ?>><?php esc_html_e( 'Yes', 'skwirrel-pim-sync' ); ?></option>
								</select>
								<span id="skwirrel-slug-saved" class="skw-saved-indicator" style="display:none;">✓</span>
							</td>
						</tr>
					</table>
					<p class="skw-field-hint" id="skwirrel-slug-resync-hint" <?php echo $update_on_resync ? '' : 'style="display:none;"'; ?>>
						<?php esc_html_e( 'Existing product URLs will be overwritten on the next sync. This may break existing links and SEO rankings.', 'skwirrel-pim-sync' ); ?>
					</p>
					<p class="skw-field-hint">
						<a href="<?php echo esc_url( $permalinks_url ); ?>"><?php esc_html_e( 'Edit permalink settings', 'skwirrel-pim-sync' ); ?> →</a>
					</p>
				</div>

				<?php // -- Advanced -- ?>
				<div class="skw-fieldgroup">
					<h3 class="skw-fieldgroup-title"><?php esc_html_e( 'Advanced', 'skwirrel-pim-sync' ); ?></h3>
					<div class="skw-checkbox-group">
						<label class="skw-checkbox"><input type="checkbox" id="verbose_logging" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[verbose_logging]" value="1" <?php checked( ! empty( $opts['verbose_logging'] ) ); ?> /> <?php esc_html_e( 'Verbose logging', 'skwirrel-pim-sync' ); ?></label>
						<label class="skw-checkbox"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[purge_stale_products]" value="1" <?php checked( ! empty( $opts['purge_stale_products'] ) ); ?> /> <?php esc_html_e( 'Clean up deleted products after full sync', 'skwirrel-pim-sync' ); ?></label>
						<label class="skw-checkbox"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_delete_warning]" value="1" <?php checked( $opts['show_delete_warning'] ?? true ); ?> /> <?php esc_html_e( 'Show delete warning on Skwirrel products', 'skwirrel-pim-sync' ); ?></label>
					</div>
				</div>

				<div class="skw-field-actions">
					<button type="submit" class="skw-btn skw-btn-primary"><?php esc_html_e( 'Save settings', 'skwirrel-pim-sync' ); ?></button>
				</div>
			</form>
		</div>

		<?php // -- Danger Zone -- ?>
		<div id="skwirrel-danger-zone" class="skw-section skw-danger-zone">
			<h2 class="skw-section-title skw-c-red"><?php esc_html_e( 'Danger zone', 'skwirrel-pim-sync' ); ?></h2>
			<p class="skw-section-desc"><?php esc_html_e( 'Delete all products created or synced by Skwirrel. This cannot be undone if you empty the trash.', 'skwirrel-pim-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="skwirrel-purge-form">
				<input type="hidden" name="action" value="skwirrel_wc_sync_purge" />
				<?php wp_nonce_field( 'skwirrel_wc_sync_purge', '_wpnonce' ); ?>
				<label class="skw-checkbox"><input type="checkbox" name="skwirrel_purge_empty_trash" value="1" id="skwirrel-purge-permanent" /> <?php esc_html_e( 'Also empty the trash (permanently delete)', 'skwirrel-pim-sync' ); ?></label>
				<div class="skw-field-actions" style="margin-top: 12px;">
					<button type="submit" class="skw-btn skw-btn-danger"><?php esc_html_e( 'Delete all Skwirrel products', 'skwirrel-pim-sync' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the debug page.
	 */
	private function render_page_debug(): void {
		?>
		<div class="skw-section">
			<h2 class="skw-section-title"><?php esc_html_e( 'Debug Variation Attributes', 'skwirrel-pim-sync' ); ?></h2>
			<p class="skw-section-desc"><?php esc_html_e( 'If variations show "Any Colour" or "Any Number of cups" instead of real values:', 'skwirrel-pim-sync' ); ?></p>
			<ol class="skw-debug-steps">
				<li><?php esc_html_e( 'Add to wp-config.php:', 'skwirrel-pim-sync' ); ?> <code>define('SKWIRREL_WC_SYNC_DEBUG_ETIM', true);</code></li>
				<li><?php esc_html_e( 'Run "Sync Now" from the dashboard.', 'skwirrel-pim-sync' ); ?></li>
				<li><?php esc_html_e( 'Check:', 'skwirrel-pim-sync' ); ?> <code>wp-content/uploads/skwirrel-pim-sync/skwirrel-variation-debug.log</code></li>
				<li><?php esc_html_e( 'If etim_values_found is empty: API languages must match (e.g. en, en-GB).', 'skwirrel-pim-sync' ); ?></li>
				<li><?php esc_html_e( 'If ATTR VERIFY FAIL: check wp_postmeta for attribute_pa_ entries.', 'skwirrel-pim-sync' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Format a timestamp or ISO date string for display.
	 *
	 * @param string|int $value Unix timestamp or ISO date string.
	 */
	private function format_datetime( $value ): string {
		$ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		if ( ! $ts ) {
			return '';
		}
		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		return $formatted ? $formatted : '';
	}
}
