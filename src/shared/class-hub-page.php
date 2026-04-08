<?php
/**
 * Hub Page — Admin catalog showing all GravityWP plugins.
 *
 * Displays two main sections:
 * 1. Your Plugins (unlocked) — with access_source badges (global/per_plugin)
 * 2. Available with Upgrade (locked) — upgrade CTAs
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Hub_Page' ) ) {

	/**
	 * Class Hub_Page
	 */
	class Hub_Page {

		/**
		 * Initialize the Hub page.
		 *
		 * @return void
		 */
		public static function init() {
			// Priority 99 ensures Gravity Forms (priority 9) has registered its menu first.
			add_action( 'admin_menu', array( self::class, 'add_admin_menu' ), 99 );
		}

		/**
		 * Register the GravityWP Hub admin page.
		 *
		 * Adds as a submenu under Gravity Forms when available; otherwise falls back to
		 * a top-level menu. This ensures the page is accessible regardless of whether
		 * Gravity Forms is installed.
		 *
		 * @return void
		 */
		public static function add_admin_menu() {
			$page_title = __( 'GravityWP Hub', 'gravitywp-license-handler' );
			$menu_title = 'GravityWP Hub';
			$capability = 'manage_options';
			$menu_slug  = 'gravitywp-hub';
			$callback   = array( self::class, 'render_page' );

			// Detect if Gravity Forms is loaded and accessible.
			global $admin_page_hooks;
			$gf_active = ( isset( $admin_page_hooks['gf_edit_forms'] ) || class_exists( '\GFForms' ) );

			if ( $gf_active && current_user_can( 'gform_full_access' ) ) {
				// Preferred: nest under Gravity Forms.
				add_submenu_page(
					'gf_edit_forms',
					$page_title,
					$menu_title,
					$capability,
					$menu_slug,
					$callback
				);
				return;
			}

			// Fallback: ensure GravityWP top-level menu exists, then add as submenu.
			self::ensure_top_level_menu();
			add_submenu_page(
				'gravitywp-settings',
				$page_title,
				__( 'Hub', 'gravitywp-license-handler' ),
				$capability,
				$menu_slug,
				$callback
			);
		}

		/**
		 * Ensure the GravityWP top-level menu exists.
		 *
		 * Safe to call multiple times — only registers if not already present.
		 *
		 * @return void
		 */
		private static function ensure_top_level_menu() {
			global $admin_page_hooks;

			if ( isset( $admin_page_hooks['gravitywp-settings'] ) ) {
				return; // Already registered (probably by Registry).
			}

			add_menu_page(
				__( 'GravityWP', 'gravitywp-license-handler' ),
				'GravityWP',
				'manage_options',
				'gravitywp-settings',
				class_exists( '\GravityWP\Shared\Global_License_Key_Registry' )
					? array( '\GravityWP\Shared\Global_License_Key_Registry', 'render_settings_page' )
					: '__return_null',
				'dashicons-admin-generic',
				81
			);
		}

		/**
		 * Render the Hub page.
		 *
		 * @return void
		 */
		public static function render_page() {
			wp_enqueue_style( 'dashicons' );

			// Force refresh if requested.
			$force_refresh = isset( $_GET['refresh'] ) && '1' === $_GET['refresh']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$hub_data      = Hub_Manager::get_hub_data( $force_refresh );

			$global_info     = $hub_data['license']['global'] ?? array();
			$per_plugin_info = $hub_data['license']['per_plugin'] ?? array();
			if ( is_object( $per_plugin_info ) ) {
				$per_plugin_info = (array) $per_plugin_info;
			}
			$plugins    = $hub_data['plugins'] ?? array();
			$global_key = get_option( 'gravitywp_global_license_key', '' );

			// Categorize plugins.
			$unlocked = array();
			$locked   = array();
			foreach ( $plugins as $plugin ) {
				if ( ! empty( $plugin['has_access'] ) ) {
					$unlocked[] = $plugin;
				} else {
					$locked[] = $plugin;
				}
			}

			// Get installed plugins for version comparison.
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed_plugins = get_plugins();

			$cache_remaining = Hub_Manager::get_cache_ttl_remaining();
			$cache_hours     = floor( $cache_remaining / 3600 );
			$cache_minutes   = floor( ( $cache_remaining % 3600 ) / 60 );

			$has_any_license = Hub_Manager::has_any_valid_license();
			?>
			<div class="wrap gwp-admin">
				<div class="gwp-admin-header">
					<h1 class="gwp-admin-header__title">
						<span class="dashicons dashicons-admin-plugins"></span>
						<?php esc_html_e( 'GravityWP Hub', 'gravitywp-license-handler' ); ?>
					</h1>
					<div class="gwp-admin-header__actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=gravitywp-hub&refresh=1' ) ); ?>" class="gwp-btn gwp-btn--secondary gwp-refresh-link" data-gwp-refresh>
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Refresh', 'gravitywp-license-handler' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=gravitywp-settings' ) ); ?>" class="gwp-btn gwp-btn--primary">
							<span class="dashicons dashicons-admin-generic"></span>
							<?php esc_html_e( 'Settings', 'gravitywp-license-handler' ); ?>
						</a>
					</div>
				</div>

				<?php self::render_status_bar( $global_info, $per_plugin_info, $global_key, $has_any_license, $cache_hours, $cache_minutes, count( $unlocked ) ); ?>

				<?php if ( empty( $plugins ) ) : ?>
					<div class="gwp-empty-state">
						<span class="dashicons dashicons-admin-plugins"></span>
						<h3 class="gwp-empty-state__title"><?php esc_html_e( 'No plugins to show', 'gravitywp-license-handler' ); ?></h3>
						<p class="gwp-empty-state__text">
							<?php esc_html_e( 'Enter your license key to see the catalog.', 'gravitywp-license-handler' ); ?>
						</p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=gravitywp-settings' ) ); ?>" class="gwp-btn gwp-btn--primary">
							<span class="dashicons dashicons-admin-generic"></span>
							<?php esc_html_e( 'Go to Settings', 'gravitywp-license-handler' ); ?>
						</a>
					</div>
				<?php else : ?>

					<?php if ( ! empty( $unlocked ) ) : ?>
						<h2 class="gwp-section-title is-unlocked">
							<span class="dashicons dashicons-unlock"></span>
							<?php esc_html_e( 'Your Plugins', 'gravitywp-license-handler' ); ?>
							<span class="gwp-section-title__count"><?php echo esc_html( count( $unlocked ) ); ?></span>
						</h2>
						<div class="gwp-plugin-grid">
							<?php foreach ( $unlocked as $plugin ) : ?>
								<?php self::render_plugin_card( $plugin, $installed_plugins, 'unlocked' ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $locked ) ) : ?>
						<h2 class="gwp-section-title is-locked">
							<span class="dashicons dashicons-lock"></span>
							<?php esc_html_e( 'Available with Upgrade', 'gravitywp-license-handler' ); ?>
							<span class="gwp-section-title__count"><?php echo esc_html( count( $locked ) ); ?></span>
						</h2>
						<div class="gwp-plugin-grid">
							<?php foreach ( $locked as $plugin ) : ?>
								<?php self::render_plugin_card( $plugin, $installed_plugins, 'locked' ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render the license status bar at the top of the Hub page.
		 *
		 * @param array  $global_info     Global license info.
		 * @param array  $per_plugin_info Per-plugin license info.
		 * @param string $global_key      Current global key.
		 * @param bool   $has_any_license Whether any license is valid.
		 * @param int    $cache_hours     Hours until cache refresh.
		 * @param int    $cache_minutes   Minutes until cache refresh.
		 * @param int    $unlocked_count  Number of unlocked plugins.
		 * @return void
		 */
		private static function render_status_bar( $global_info, $per_plugin_info, $global_key, $has_any_license, $cache_hours, $cache_minutes, $unlocked_count ) {
			$has_per_plugin = ! empty( $per_plugin_info );

			if ( empty( $global_key ) && ! $has_per_plugin ) {
				// No keys at all.
				$state      = 'warning';
				$icon       = 'dashicons-info';
				$title      = __( 'No license key configured', 'gravitywp-license-handler' );
				$subtitle   = __( 'Enter a license key to unlock your GravityWP plugins.', 'gravitywp-license-handler' );
				$cta_label  = __( 'Enter License Key', 'gravitywp-license-handler' );
				$cta_url    = admin_url( 'admin.php?page=gravitywp-settings' );
				$plan_badge = null;
			} elseif ( $has_any_license ) {
				// At least one license is valid.
				$state    = 'valid';
				$icon     = 'dashicons-yes-alt';
				$cta_label = null;
				$cta_url  = null;

				$global_valid = ( ( $global_info['status'] ?? '' ) === 'valid' );

				if ( $global_valid ) {
					$title      = $global_info['plan_name'] ?? __( 'License Active', 'gravitywp-license-handler' );
					$plan_badge = $global_info['plan_type'] ?? Plan_Types::UNKNOWN;
					$subtitle   = self::format_license_meta( $global_info, $unlocked_count );
				} else {
					// Only per-plugin licenses are active.
					$valid_per_count = 0;
					foreach ( $per_plugin_info as $info ) {
						if ( ( $info['status'] ?? '' ) === 'valid' ) {
							++$valid_per_count;
						}
					}
					$title = sprintf(
						/* translators: %d: count */
						_n( '%d Individual License Active', '%d Individual Licenses Active', $valid_per_count, 'gravitywp-license-handler' ),
						$valid_per_count
					);
					$plan_badge = Plan_Types::SINGLE_ADDON;
					$subtitle   = sprintf(
						/* translators: %d: unlocked count */
						esc_html__( '%d plugins unlocked', 'gravitywp-license-handler' ),
						$unlocked_count
					);
				}
			} else {
				// Keys exist but none are valid.
				$state      = 'invalid';
				$icon       = 'dashicons-warning';
				$title      = __( 'License invalid or expired', 'gravitywp-license-handler' );
				$subtitle   = self::get_first_error_message( $global_info );
				$cta_label  = __( 'Check Settings', 'gravitywp-license-handler' );
				$cta_url    = admin_url( 'admin.php?page=gravitywp-settings' );
				$plan_badge = null;
			}
			?>
			<div class="gwp-status-bar is-<?php echo esc_attr( $state ); ?>">
				<div class="gwp-status-bar__left">
					<div class="gwp-status-bar__icon">
						<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
					</div>
					<div class="gwp-status-bar__content">
						<h2 class="gwp-status-bar__title">
							<?php echo esc_html( $title ); ?>
							<?php if ( $plan_badge ) : ?>
								<span class="gwp-plan-badge gwp-plan-badge--<?php echo esc_attr( $plan_badge ); ?>" style="margin-left: 8px; vertical-align: middle;">
									<span class="dashicons <?php echo esc_attr( Plan_Types::get_icon( $plan_badge ) ); ?>"></span>
									<?php echo esc_html( Plan_Types::get_label( $plan_badge ) ); ?>
								</span>
							<?php endif; ?>
						</h2>
						<div class="gwp-status-bar__meta">
							<span class="gwp-status-bar__meta-item"><?php echo wp_kses_post( $subtitle ); ?></span>
						</div>
					</div>
					<?php if ( $cta_label && $cta_url ) : ?>
						<a href="<?php echo esc_url( $cta_url ); ?>" class="gwp-btn gwp-btn--primary">
							<?php echo esc_html( $cta_label ); ?>
						</a>
					<?php endif; ?>
				</div>
				<div class="gwp-status-bar__right">
					<?php if ( $cache_hours > 0 || $cache_minutes > 0 ) : ?>
						<div>
							<?php
							printf(
								/* translators: %1$d: hours, %2$d: minutes */
								esc_html__( 'Cache refreshes in %1$dh %2$dm', 'gravitywp-license-handler' ),
								(int) $cache_hours,
								(int) $cache_minutes
							);
							?>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Format license meta text (sites, expires).
		 *
		 * @param array $info            License info.
		 * @param int   $unlocked_count  Number of unlocked plugins.
		 * @return string Formatted HTML.
		 */
		private static function format_license_meta( $info, $unlocked_count ) {
			$count   = $info['site_count'] ?? 0;
			$limit   = $info['license_limit'] ?? 0;
			$expires = $info['expires'] ?? '';

			$limit_display = ( 0 === (int) $limit )
				? __( 'Unlimited', 'gravitywp-license-handler' )
				: (string) $limit;

			return sprintf(
				/* translators: %1$s: site count, %2$s: limit, %3$s: expiry, %4$d: unlocked */
				esc_html__( 'Sites: %1$s / %2$s • Expires: %3$s • %4$d plugins unlocked', 'gravitywp-license-handler' ),
				esc_html( $count ),
				esc_html( $limit_display ),
				esc_html( $expires ),
				(int) $unlocked_count
			);
		}

		/**
		 * Get the first error message from a license info array.
		 *
		 * @param array $info License info.
		 * @return string
		 */
		private static function get_first_error_message( $info ) {
			$errors = $info['errors'] ?? array();
			if ( ! empty( $errors ) && is_array( $errors ) ) {
				foreach ( $errors as $messages ) {
					foreach ( (array) $messages as $msg ) {
						return (string) $msg;
					}
				}
			}
			return __( 'Please check your license or contact support.', 'gravitywp-license-handler' );
		}

		/**
		 * Render a single plugin card.
		 *
		 * @param array  $plugin            Plugin data from hub API.
		 * @param array  $installed_plugins Installed WP plugins.
		 * @param string $status            'unlocked' or 'locked'.
		 * @return void
		 */
		private static function render_plugin_card( $plugin, $installed_plugins, $status ) {
			$slug          = $plugin['slug'] ?? '';
			$name          = $plugin['name'] ?? $slug;
			$description   = $plugin['description'] ?? '';
			$new_version   = $plugin['new_version'] ?? '';
			$access_source = $plugin['access_source'] ?? 'none';

			$icon = '';
			if ( ! empty( $plugin['icons']['2x'] ) ) {
				$icon = $plugin['icons']['2x'];
			} elseif ( ! empty( $plugin['icons']['1x'] ) ) {
				$icon = $plugin['icons']['1x'];
			}

			// Check if installed and get current version.
			$installed_version = '';
			$is_installed      = false;
			$has_update        = false;
			$plugin_file       = '';

			foreach ( $installed_plugins as $file => $data ) {
				if ( strpos( $file, $slug ) !== false ) {
					$is_installed      = true;
					$installed_version = $data['Version'] ?? '';
					$plugin_file       = $file;
					if ( ! empty( $new_version ) && ! empty( $installed_version )
						&& version_compare( $installed_version, $new_version, '<' )
					) {
						$has_update = true;
					}
					break;
				}
			}

			// Strip HTML from description for card display.
			$description_plain = wp_strip_all_tags( $description );
			?>
			<div class="gwp-plugin-card is-<?php echo esc_attr( $status ); ?>">
				<div class="gwp-plugin-card__header">
					<div class="gwp-plugin-card__icon">
						<?php if ( $icon ) : ?>
							<img src="<?php echo esc_url( $icon ); ?>" alt="" />
						<?php else : ?>
							<span class="dashicons dashicons-admin-plugins"></span>
						<?php endif; ?>
					</div>
					<div class="gwp-plugin-card__content">
						<h3 class="gwp-plugin-card__name"><?php echo esc_html( $name ); ?></h3>

						<div class="gwp-plugin-card__meta">
							<?php if ( $new_version ) : ?>
								<span class="gwp-plugin-card__version">v<?php echo esc_html( $new_version ); ?></span>
							<?php endif; ?>

							<?php if ( 'unlocked' === $status && $access_source && 'none' !== $access_source ) : ?>
								<span class="gwp-plugin-card__source-badge gwp-plugin-card__source-badge--<?php echo esc_attr( $access_source ); ?>">
									<?php
									echo esc_html(
										'global' === $access_source
											? __( 'Global', 'gravitywp-license-handler' )
											: __( 'Individual', 'gravitywp-license-handler' )
									);
									?>
								</span>
							<?php endif; ?>

							<?php if ( $has_update ) : ?>
								<span class="gwp-plugin-card__update-notice">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Update available', 'gravitywp-license-handler' ); ?>
								</span>
							<?php endif; ?>
						</div>

						<?php if ( $description_plain ) : ?>
							<p class="gwp-plugin-card__description"><?php echo esc_html( $description_plain ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<div class="gwp-plugin-card__footer">
					<?php if ( 'unlocked' === $status ) : ?>
						<?php if ( $is_installed && $has_update && ! empty( $plugin['download_link'] ) && $plugin_file ) : ?>
							<a
								href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $plugin_file ) ), 'upgrade-plugin_' . $plugin_file ) ); ?>"
								class="gwp-btn gwp-btn--primary gwp-btn--sm"
							>
								<span class="dashicons dashicons-update"></span>
								<?php esc_html_e( 'Update', 'gravitywp-license-handler' ); ?>
							</a>
							<?php if ( $installed_version ) : ?>
								<span class="gwp-plugin-card__status">v<?php echo esc_html( $installed_version ); ?> → v<?php echo esc_html( $new_version ); ?></span>
							<?php endif; ?>
						<?php elseif ( ! $is_installed && ! empty( $plugin['download_link'] ) ) : ?>
							<a href="<?php echo esc_url( $plugin['download_link'] ); ?>" class="gwp-btn gwp-btn--primary gwp-btn--sm">
								<span class="dashicons dashicons-download"></span>
								<?php esc_html_e( 'Download', 'gravitywp-license-handler' ); ?>
							</a>
						<?php elseif ( $is_installed && ! $has_update ) : ?>
							<span class="gwp-plugin-card__status is-up-to-date">
								<span class="dashicons dashicons-yes"></span>
								<?php esc_html_e( 'Up to date', 'gravitywp-license-handler' ); ?>
								<?php if ( $installed_version ) : ?>
									(v<?php echo esc_html( $installed_version ); ?>)
								<?php endif; ?>
							</span>
						<?php endif; ?>
					<?php else : ?>
						<a
							href="https://gravitywp.com/pricing/?utm_source=hub&utm_medium=admin&utm_campaign=upgrade&utm_content=<?php echo esc_attr( $slug ); ?>"
							target="_blank"
							rel="noopener"
							class="gwp-btn gwp-btn--secondary gwp-btn--sm"
						>
							<span class="dashicons dashicons-lock"></span>
							<?php esc_html_e( 'Upgrade to Access', 'gravitywp-license-handler' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
	}
}
