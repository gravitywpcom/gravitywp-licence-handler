<?php
/**
 * GravityWP — Unified Admin Page (Registry).
 *
 * Single premium admin page that combines:
 * - Hero with license status
 * - Tab 1: Plugins (catalog)
 * - Tab 2: License Keys (Global + Individual)
 *
 * Renders the entire page; uses Hub_Page only for plugin-card helpers.
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Global_License_Key_Registry' ) ) {

	/**
	 * Class Global_License_Key_Registry
	 *
	 * The unified GravityWP admin page (formerly two separate pages).
	 */
	class Global_License_Key_Registry {

		/**
		 * Page slug used for the single combined page.
		 *
		 * @var string
		 */
		const PAGE_SLUG = 'gravitywp';

		/**
		 * Used version of the handler.
		 *
		 * @var string
		 */
		private static $version = '';

		/**
		 * Base directory of the highest-version shared folder.
		 *
		 * @var string
		 */
		private static $base_dir = '';

		/**
		 * Initialize the page.
		 *
		 * @param string $version  The handler version.
		 * @param string $base_dir Optional base directory for assets resolution.
		 * @return void
		 */
		public static function init( $version, $base_dir = '' ) {
			self::$version  = $version;
			self::$base_dir = $base_dir ? $base_dir : __DIR__;

			// Priority 98: after Gravity Forms (priority 9), before Hub_Page legacy compat (99).
			add_action( 'admin_menu', array( self::class, 'add_admin_menu' ), 98 );
			add_action( 'admin_init', array( self::class, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		}

		/**
		 * Register the unified GravityWP admin page.
		 *
		 * Adds as a submenu under Gravity Forms when available; otherwise creates
		 * a top-level menu so it works on any WordPress install.
		 *
		 * @return void
		 */
		public static function add_admin_menu() {
			$page_title = __( 'GravityWP', 'gravitywp-license-handler' );
			$menu_title = 'GravityWP';
			$capability = 'manage_options';
			$callback   = array( self::class, 'render_page' );

			// Detect if Gravity Forms is loaded and accessible.
			global $admin_page_hooks;
			$gf_active = ( isset( $admin_page_hooks['gf_edit_forms'] ) || class_exists( '\GFForms' ) );

			if ( $gf_active && current_user_can( 'gform_full_access' ) ) {
				// Preferred: nest under Gravity Forms menu.
				add_submenu_page(
					'gf_edit_forms',
					$page_title,
					$menu_title,
					$capability,
					self::PAGE_SLUG,
					$callback
				);
			} else {
				// Fallback: create a top-level menu.
				add_menu_page(
					$page_title,
					$menu_title,
					$capability,
					self::PAGE_SLUG,
					$callback,
					'dashicons-admin-generic',
					81
				);
			}

			// Backward-compatible aliases — if anything still links to the old slugs,
			// route them to this same page so users never see "page does not exist".
			self::register_legacy_aliases( $capability, $callback );
		}

		/**
		 * Register legacy slug aliases so old links don't 404.
		 *
		 * @param string   $capability Capability required.
		 * @param callable $callback   Render callback.
		 * @return void
		 */
		private static function register_legacy_aliases( $capability, $callback ) {
			global $admin_page_hooks;

			$aliases = array( 'gravitywp-settings', 'gravitywp-hub' );
			foreach ( $aliases as $alias ) {
				if ( isset( $admin_page_hooks[ $alias ] ) ) {
					continue;
				}
				// Hidden submenu (no parent in menu): provides URL routing only.
				add_submenu_page(
					'',
					'',
					'',
					$capability,
					$alias,
					$callback
				);
			}
		}

		/**
		 * Register settings.
		 *
		 * @return void
		 */
		public static function register_settings() {
			register_setting(
				'gravitywp_settings_group',
				'gravitywp_global_license_key',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				)
			);

			register_setting(
				'gravitywp_settings_group',
				'gravitywp_plugin_license_keys',
				array(
					'type'              => 'array',
					'sanitize_callback' => array( self::class, 'sanitize_plugin_keys' ),
					'default'           => array(),
				)
			);
		}

		/**
		 * Sanitize the plugin license keys option.
		 *
		 * @param mixed $raw Raw input.
		 * @return array Sanitized [slug => key] array.
		 */
		public static function sanitize_plugin_keys( $raw ) {
			if ( ! is_array( $raw ) ) {
				return array();
			}

			$clean = array();
			foreach ( $raw as $slug => $key ) {
				$slug_clean = sanitize_key( $slug );
				$key_clean  = sanitize_text_field( trim( (string) $key ) );
				if ( ! empty( $slug_clean ) && ! empty( $key_clean ) ) {
					$clean[ $slug_clean ] = $key_clean;
				}
			}
			return $clean;
		}

		/**
		 * Enqueue CSS and JS assets.
		 *
		 * Loads on the unified GravityWP page and all legacy aliases.
		 * Uses multiple detection methods and defensive path resolution.
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public static function enqueue_assets( $hook ) {
			// Detect if we're on our page via hook name OR the ?page= query arg.
			// Both checks cover edge cases with legacy aliases and different parent menus.
			$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_our_page  = (
				( is_string( $hook ) && false !== strpos( $hook, 'gravitywp' ) )
				|| 0 === strpos( $current_page, 'gravitywp' )
			);

			if ( ! $is_our_page ) {
				return;
			}

			// Resolve a URL base for our assets using the registry file's location.
			// This works across Strauss-bundled, vendor/, lib/, or standalone installs.
			$base_url = self::get_assets_base_url();
			if ( ! $base_url ) {
				// Last-resort fallback: emit inline critical CSS so the page isn't unstyled.
				self::emit_inline_critical_css();
				return;
			}

			// Prefer source files (always up to date); use minified only if source is missing.
			$css_src_exists = file_exists( self::$base_dir . '/assets/css/gwp-admin.css' );
			$js_src_exists  = file_exists( self::$base_dir . '/assets/js/gwp-admin.js' );
			$css_file       = $css_src_exists ? 'gwp-admin.css' : 'gwp-admin.min.css';
			$js_file        = $js_src_exists ? 'gwp-admin.js' : 'gwp-admin.min.js';

			$css_url = $base_url . 'assets/css/' . $css_file;
			$js_url  = $base_url . 'assets/js/' . $js_file;

			wp_enqueue_style( 'gravitywp-licence-ui', $css_url, array( 'dashicons' ), self::$version );
			wp_enqueue_script( 'gravitywp-licence-ui', $js_url, array(), self::$version, true );

			// Expose AJAX endpoint + canonical nonce + translatable strings to the script.
			$nonce_action = class_exists( '\GravityWP\Shared\Hub_Ajax' )
				? Hub_Ajax::NONCE_ACTION
				: 'gwp_hub_ajax';
			wp_localize_script(
				'gravitywp-licence-ui',
				'gwpHub',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( $nonce_action ),
					'i18n'    => array(
						'installing'    => __( 'Installing…', 'gravitywp-license-handler' ),
						'activating'    => __( 'Activating…', 'gravitywp-license-handler' ),
						'deactivating'  => __( 'Deactivating…', 'gravitywp-license-handler' ),
						'deleting'      => __( 'Deleting…', 'gravitywp-license-handler' ),
						'confirmDelete' => __( 'Delete this plugin and its files? This cannot be undone.', 'gravitywp-license-handler' ),
						'genericError'  => __( 'Something went wrong. Please try again.', 'gravitywp-license-handler' ),
					),
				)
			);
		}

		/**
		 * Get the URL for the assets directory.
		 *
		 * Tries multiple strategies so it works with any install layout:
		 * 1. plugin_dir_url() on the registry file (covers most cases)
		 * 2. Manual translation from WP_PLUGIN_DIR
		 * 3. Manual translation from WP_CONTENT_DIR
		 *
		 * @return string|false URL ending in a slash, or false.
		 */
		private static function get_assets_base_url() {
			$base_dir_norm = wp_normalize_path( self::$base_dir );

			// Strategy 1: Use WordPress's built-in resolver via a file inside our dir.
			$registry_file = $base_dir_norm . '/class-global-license-key-registry.php';
			if ( file_exists( $registry_file ) ) {
				$url = plugin_dir_url( $registry_file );
				if ( ! empty( $url ) && false !== strpos( $url, 'http' ) ) {
					return $url;
				}
			}

			// Strategy 2: Translate from WP_PLUGIN_DIR.
			$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
			if ( 0 === strpos( $base_dir_norm, $plugins_dir ) ) {
				$relative = ltrim( substr( $base_dir_norm, strlen( $plugins_dir ) ), '/' );
				return trailingslashit( plugins_url() . '/' . $relative );
			}

			// Strategy 3: Translate from WP_CONTENT_DIR (covers muplugins, symlinks, etc).
			$content_dir = wp_normalize_path( WP_CONTENT_DIR );
			if ( 0 === strpos( $base_dir_norm, $content_dir ) ) {
				$relative = ltrim( substr( $base_dir_norm, strlen( $content_dir ) ), '/' );
				return trailingslashit( content_url() . '/' . $relative );
			}

			return false;
		}

		/**
		 * Emit critical inline CSS as a fallback when asset URLs can't be resolved.
		 *
		 * Only runs in the rare case that all path-resolution strategies fail —
		 * ensures the page isn't completely unstyled.
		 *
		 * @return void
		 */
		private static function emit_inline_critical_css() {
			add_action(
				'admin_head',
				function () {
					echo "<style>\n"
						. ".gwp-tab-panel{display:none}"
						. ".gwp-tab-panel.is-active{display:block}"
						. ".gwp-tabs{display:flex;gap:6px;padding:6px;background:#fff;border:1px solid #dcdcde;border-radius:12px;margin:0 0 24px}"
						. ".gwp-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:8px;text-decoration:none;color:#495057;font-weight:500}"
						. ".gwp-tab.is-active{background:#2271b1;color:#fff}"
						. ".gwp-hero{padding:32px;border-radius:16px;margin:0 0 28px;background:linear-gradient(135deg,#1a5fb4 0%,#2d8f5f 100%);color:#fff}"
						. ".gwp-plugin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}"
						. ".gwp-plugin-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px}"
						. "</style>\n";
				}
			);
		}

		/**
		 * Render the unified GravityWP page.
		 *
		 * @return void
		 */
		public static function render_page() {
			wp_enqueue_style( 'dashicons' );

			// Allow refresh via URL parameter.
			$force_refresh = isset( $_GET['refresh'] ) && '1' === $_GET['refresh']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$hub_data      = Hub_Manager::get_hub_data( $force_refresh );

			$global_info     = $hub_data['license']['global'] ?? array();
			$per_plugin_info = $hub_data['license']['per_plugin'] ?? array();
			if ( is_object( $per_plugin_info ) ) {
				$per_plugin_info = (array) $per_plugin_info;
			}
			$all_plugins = $hub_data['plugins'] ?? array();
			$global_key  = get_option( 'gravitywp_global_license_key', '' );
			$plugin_keys = get_option( 'gravitywp_plugin_license_keys', array() );
			if ( ! is_array( $plugin_keys ) ) {
				$plugin_keys = array();
			}

			// Categorize plugins by license access.
			$unlocked = array();
			$locked   = array();
			foreach ( $all_plugins as $p ) {
				if ( ! empty( $p['has_access'] ) ) {
					$unlocked[] = $p;
				} else {
					$locked[] = $p;
				}
			}

			// Get installed plugins for version comparison.
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed_plugins = get_plugins();

			$has_any_license = Hub_Manager::has_any_valid_license();
			?>
			<div class="wrap gwp-admin">
				<?php self::render_hero( $global_info, $per_plugin_info, $global_key, $has_any_license, count( $unlocked ), count( $all_plugins ) ); ?>

				<nav class="gwp-tabs" role="tablist">
					<a href="#plugins" class="gwp-tab is-active" data-gwp-tab="plugins" role="tab">
						<span class="dashicons dashicons-admin-plugins"></span>
						<?php esc_html_e( 'Plugins', 'gravitywp-license-handler' ); ?>
						<?php if ( ! empty( $all_plugins ) ) : ?>
							<span class="gwp-tab__badge"><?php echo esc_html( count( $all_plugins ) ); ?></span>
						<?php endif; ?>
					</a>
					<a href="#license-keys" class="gwp-tab" data-gwp-tab="license-keys" role="tab">
						<span class="dashicons dashicons-admin-network"></span>
						<?php esc_html_e( 'License Keys', 'gravitywp-license-handler' ); ?>
					</a>
				</nav>

				<?php
				$render_context = array(
					'global_plan_type' => $global_info['plan_type'] ?? Plan_Types::UNKNOWN,
				);
				?>
				<?php // ================ Tab 1: Plugins ================ ?>
				<div class="gwp-tab-panel is-active" data-gwp-panel="plugins" role="tabpanel">
					<?php self::render_plugins_tab( $unlocked, $locked, $installed_plugins, $global_key, count( $plugin_keys ), $render_context ); ?>
				</div>

				<?php // ================ Tab 2: License Keys ================ ?>
				<div class="gwp-tab-panel" data-gwp-panel="license-keys" role="tabpanel">
					<form method="post" action="options.php">
						<?php settings_fields( 'gravitywp_settings_group' ); ?>
						<?php self::render_global_license_card( $global_key, $global_info, $unlocked ); ?>
						<?php self::render_individual_keys_card( $plugin_keys, $per_plugin_info, $all_plugins ); ?>
						<p class="gwp-form-actions">
							<?php submit_button( __( 'Save License Keys', 'gravitywp-license-handler' ), 'primary large', 'submit', false, array( 'class' => 'button button-primary button-large' ) ); ?>
						</p>
					</form>
				</div>

			</div>
			<?php
		}

		/**
		 * Render the premium hero section at the top of the page.
		 *
		 * @param array  $global_info     Global license info.
		 * @param array  $per_plugin_info Per-plugin license info.
		 * @param string $global_key      Current global key.
		 * @param bool   $has_any_license Whether any license is valid.
		 * @param int    $unlocked_count  Number of unlocked plugins.
		 * @param int    $total_count     Total plugins.
		 * @return void
		 */
		private static function render_hero( $global_info, $per_plugin_info, $global_key, $has_any_license, $unlocked_count, $total_count ) {
			$has_per_plugin = ! empty( $per_plugin_info );
			$plan_badge     = null;
			$plan_label     = '';
			$expires        = '';
			$site_count     = 0;
			$site_limit     = 0;

			if ( empty( $global_key ) && ! $has_per_plugin ) {
				$state    = 'empty';
				$title    = __( 'Welcome to GravityWP', 'gravitywp-license-handler' );
				$subtitle = __( 'Enter a license key to unlock and manage your plugins.', 'gravitywp-license-handler' );
			} elseif ( $has_any_license ) {
				$state         = 'active';
				$global_valid  = ( ( $global_info['status'] ?? '' ) === 'valid' );

				if ( $global_valid ) {
					$plan_badge = $global_info['plan_type'] ?? Plan_Types::UNKNOWN;
					$plan_label = Plan_Types::get_label( $plan_badge );
					$title      = $global_info['plan_name'] ?? __( 'License Active', 'gravitywp-license-handler' );
					$expires    = $global_info['expires'] ?? '';
					$site_count = (int) ( $global_info['site_count'] ?? 0 );
					$site_limit = (int) ( $global_info['license_limit'] ?? 0 );
					$subtitle   = __( 'Your plugins are unlocked and ready to use.', 'gravitywp-license-handler' );
				} else {
					$valid_per = 0;
					foreach ( $per_plugin_info as $info ) {
						if ( ( $info['status'] ?? '' ) === 'valid' ) {
							++$valid_per;
						}
					}
					$plan_badge = Plan_Types::SINGLE_ADDON;
					$plan_label = Plan_Types::get_label( $plan_badge );
					$title      = sprintf(
						/* translators: %d: count */
						_n( '%d Individual License Active', '%d Individual Licenses Active', $valid_per, 'gravitywp-license-handler' ),
						$valid_per
					);
					$subtitle = __( 'Single Add-on licenses applied.', 'gravitywp-license-handler' );
				}
			} else {
				$state    = 'invalid';
				$title    = __( 'License Invalid or Expired', 'gravitywp-license-handler' );
				$subtitle = self::get_first_error_message( $global_info );
			}

			$cache_remaining = Hub_Manager::get_cache_ttl_remaining();
			$cache_hours     = (int) floor( $cache_remaining / 3600 );
			$cache_minutes   = (int) floor( ( $cache_remaining % 3600 ) / 60 );
			$refresh_url     = add_query_arg( array( 'page' => self::PAGE_SLUG, 'refresh' => '1' ), admin_url( 'admin.php' ) );
			?>
			<div class="gwp-hero gwp-hero--<?php echo esc_attr( $state ); ?>">
				<div class="gwp-hero__bg" aria-hidden="true"></div>

				<div class="gwp-hero__content">
					<div class="gwp-hero__brand">
						<div class="gwp-hero__logo">
							<svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
								<path d="M16 2L4 8v10c0 6.6 5 12 12 12s12-5.4 12-12V8L16 2z" fill="currentColor" opacity=".15"/>
								<path d="M16 2L4 8v10c0 6.6 5 12 12 12s12-5.4 12-12V8L16 2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
								<path d="M11 16l3.5 3.5L21 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="gwp-hero__brand-text">
							<div class="gwp-hero__eyebrow"><?php esc_html_e( 'GravityWP', 'gravitywp-license-handler' ); ?></div>
							<h1 class="gwp-hero__title">
								<?php echo esc_html( $title ); ?>
								<?php if ( $plan_badge ) : ?>
									<span class="gwp-plan-badge gwp-plan-badge--<?php echo esc_attr( $plan_badge ); ?>">
										<span class="dashicons <?php echo esc_attr( Plan_Types::get_icon( $plan_badge ) ); ?>"></span>
										<?php echo esc_html( $plan_label ); ?>
									</span>
								<?php endif; ?>
							</h1>
							<p class="gwp-hero__subtitle"><?php echo esc_html( $subtitle ); ?></p>
						</div>
					</div>

					<?php if ( 'active' === $state ) : ?>
						<div class="gwp-hero__stats">
							<div class="gwp-hero__stat">
								<div class="gwp-hero__stat-value">
									<?php echo esc_html( $unlocked_count ); ?><span class="gwp-hero__stat-divider">/</span><?php echo esc_html( $total_count ); ?>
								</div>
								<div class="gwp-hero__stat-label"><?php esc_html_e( 'Plugins unlocked', 'gravitywp-license-handler' ); ?></div>
							</div>
							<?php if ( $site_count || $site_limit ) : ?>
								<div class="gwp-hero__stat">
									<div class="gwp-hero__stat-value">
										<?php echo esc_html( $site_count ); ?><span class="gwp-hero__stat-divider">/</span><?php echo esc_html( 0 === $site_limit ? '∞' : $site_limit ); ?>
									</div>
									<div class="gwp-hero__stat-label"><?php esc_html_e( 'Sites used', 'gravitywp-license-handler' ); ?></div>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $expires ) ) : ?>
								<div class="gwp-hero__stat">
									<div class="gwp-hero__stat-value gwp-hero__stat-value--small">
										<?php
										if ( 'lifetime' === $expires ) {
											esc_html_e( 'Lifetime', 'gravitywp-license-handler' );
										} else {
											echo esc_html( gmdate( 'M j, Y', strtotime( $expires ) ) );
										}
										?>
									</div>
									<div class="gwp-hero__stat-label"><?php esc_html_e( 'Expires', 'gravitywp-license-handler' ); ?></div>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="gwp-hero__actions">
					<a href="<?php echo esc_url( $refresh_url ); ?>" class="gwp-hero__refresh" title="<?php esc_attr_e( 'Refresh data', 'gravitywp-license-handler' ); ?>" data-gwp-refresh>
						<span class="dashicons dashicons-update"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Refresh', 'gravitywp-license-handler' ); ?></span>
					</a>
					<?php if ( $cache_remaining > 0 ) : ?>
						<div class="gwp-hero__cache-info">
							<?php
							printf(
								/* translators: %1$d: hours, %2$d: minutes */
								esc_html__( 'Updated • next refresh in %1$dh %2$dm', 'gravitywp-license-handler' ),
								$cache_hours,
								$cache_minutes
							);
							?>
						</div>
					<?php endif; ?>
					<div class="gwp-hero__version">v<?php echo esc_html( self::$version ); ?></div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the Plugins tab — catalog of all plugins.
		 *
		 * @param array  $unlocked          Plugins the user has license access to (or free).
		 * @param array  $locked            Premium plugins the license doesn't cover.
		 * @param array  $installed_plugins Installed WP plugins.
		 * @param string $global_key        Current global key.
		 * @param int    $plugin_keys_count Number of saved per-plugin keys.
		 * @param array  $context           Render context forwarded to Hub_Page::render_plugin_card.
		 *                                  Carries 'global_plan_type' so source badges can show
		 *                                  the actual plan name (All Access / Agency / etc).
		 * @return void
		 */
		private static function render_plugins_tab( $unlocked, $locked, $installed_plugins, $global_key, $plugin_keys_count, $context = array() ) {
			if ( empty( $unlocked ) && empty( $locked ) ) {
				?>
				<div class="gwp-empty-state">
					<span class="dashicons dashicons-admin-plugins"></span>
					<h3 class="gwp-empty-state__title"><?php esc_html_e( 'No plugins to show yet', 'gravitywp-license-handler' ); ?></h3>
					<p class="gwp-empty-state__text">
						<?php
						if ( empty( $global_key ) && 0 === $plugin_keys_count ) {
							esc_html_e( 'Enter a license key to see your plugins.', 'gravitywp-license-handler' );
						} else {
							esc_html_e( 'Try refreshing the data to see the latest catalog.', 'gravitywp-license-handler' );
						}
						?>
					</p>
					<?php if ( empty( $global_key ) && 0 === $plugin_keys_count ) : ?>
						<a href="#license-keys" class="gwp-btn gwp-btn--primary" data-gwp-tab-link="license-keys">
							<span class="dashicons dashicons-admin-network"></span>
							<?php esc_html_e( 'Add a License Key', 'gravitywp-license-handler' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<?php
				return;
			}

			if ( ! empty( $unlocked ) ) :
				?>
				<h2 class="gwp-section-title is-unlocked">
					<span class="dashicons dashicons-unlock"></span>
					<?php esc_html_e( 'Your Plugins', 'gravitywp-license-handler' ); ?>
					<span class="gwp-section-title__count"><?php echo esc_html( count( $unlocked ) ); ?></span>
				</h2>
				<div class="gwp-plugin-grid">
					<?php foreach ( $unlocked as $plugin ) : ?>
						<?php Hub_Page::render_plugin_card( $plugin, $installed_plugins, 'unlocked', $context ); ?>
					<?php endforeach; ?>
				</div>
				<?php
			endif;

			if ( ! empty( $locked ) ) :
				?>
				<h2 class="gwp-section-title is-locked">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Available with Upgrade', 'gravitywp-license-handler' ); ?>
					<span class="gwp-section-title__count"><?php echo esc_html( count( $locked ) ); ?></span>
				</h2>
				<div class="gwp-plugin-grid">
					<?php foreach ( $locked as $plugin ) : ?>
						<?php Hub_Page::render_plugin_card( $plugin, $installed_plugins, 'locked', $context ); ?>
					<?php endforeach; ?>
				</div>
				<?php
			endif;
		}

		/**
		 * Render the Global License card (in License Keys tab).
		 *
		 * @param string $global_key  Current global key.
		 * @param array  $global_info License info from hub.
		 * @param array  $unlocked    Unlocked plugins.
		 * @return void
		 */
		private static function render_global_license_card( $global_key, $global_info, $unlocked ) {
			$status    = $global_info['status'] ?? 'no_key';
			$plan_type = $global_info['plan_type'] ?? Plan_Types::UNKNOWN;
			$plan_name = $global_info['plan_name'] ?? '';
			$expires   = $global_info['expires'] ?? '';
			$limit     = $global_info['license_limit'] ?? 0;
			$count     = $global_info['site_count'] ?? 0;

			$unlocked_global = 0;
			foreach ( $unlocked as $p ) {
				if ( 'global' === ( $p['access_source'] ?? '' ) ) {
					++$unlocked_global;
				}
			}
			?>
			<div class="gwp-card">
				<div class="gwp-card__header">
					<h2 class="gwp-card__title">
						<span class="dashicons dashicons-admin-network"></span>
						<?php esc_html_e( 'Global License Key', 'gravitywp-license-handler' ); ?>
					</h2>
					<?php if ( 'valid' === $status ) : ?>
						<span class="gwp-plan-badge gwp-plan-badge--<?php echo esc_attr( $plan_type ); ?>">
							<span class="dashicons <?php echo esc_attr( Plan_Types::get_icon( $plan_type ) ); ?>"></span>
							<?php echo esc_html( Plan_Types::get_label( $plan_type ) ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div class="gwp-card__body">
					<p class="gwp-card__subtitle">
						<?php esc_html_e( 'Use a global license key for All Access, List Add-ons, or Agency plans. One key unlocks multiple plugins.', 'gravitywp-license-handler' ); ?>
					</p>

					<div class="gwp-form-row">
						<label for="gravitywp_global_license_key">
							<?php esc_html_e( 'License Key', 'gravitywp-license-handler' ); ?>
						</label>
						<input
							type="text"
							id="gravitywp_global_license_key"
							name="gravitywp_global_license_key"
							value="<?php echo esc_attr( $global_key ); ?>"
							class="gwp-input"
							placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
							data-gwp-validate="license-key"
							autocomplete="off"
						/>
						<p class="description">
							<?php esc_html_e( 'Enter the license key you received by email after purchase.', 'gravitywp-license-handler' ); ?>
						</p>
					</div>

					<?php if ( 'valid' === $status ) : ?>
						<div class="gwp-alert gwp-alert--success">
							<span class="dashicons dashicons-yes-alt"></span>
							<div class="gwp-alert__content">
								<p class="gwp-alert__title">
									<?php
									printf(
										/* translators: %s: plan name */
										esc_html__( 'Active: %s', 'gravitywp-license-handler' ),
										esc_html( $plan_name )
									);
									?>
								</p>
								<p class="gwp-alert__text">
									<?php
									printf(
										/* translators: %1$s: site count, %2$s: license limit, %3$s: expiry, %4$d: unlocked plugins */
										esc_html__( 'Sites: %1$s / %2$s • Expires: %3$s • Unlocks %4$d plugins', 'gravitywp-license-handler' ),
										esc_html( $count ),
										esc_html( 0 === (int) $limit ? __( 'Unlimited', 'gravitywp-license-handler' ) : $limit ),
										esc_html( $expires ),
										$unlocked_global
									);
									?>
								</p>
							</div>
						</div>
					<?php elseif ( 'no_key' === $status || empty( $global_key ) ) : ?>
						<div class="gwp-alert gwp-alert--info">
							<span class="dashicons dashicons-info"></span>
							<div class="gwp-alert__content">
								<p class="gwp-alert__title"><?php esc_html_e( 'No global license key configured', 'gravitywp-license-handler' ); ?></p>
								<p class="gwp-alert__text">
									<?php esc_html_e( 'Enter your key above and save. If you have a Single Add-on license, use the Individual Plugin Keys section below.', 'gravitywp-license-handler' ); ?>
								</p>
							</div>
						</div>
					<?php else : ?>
						<div class="gwp-alert gwp-alert--danger">
							<span class="dashicons dashicons-warning"></span>
							<div class="gwp-alert__content">
								<p class="gwp-alert__title"><?php esc_html_e( 'License invalid or expired', 'gravitywp-license-handler' ); ?></p>
								<p class="gwp-alert__text">
									<?php
									$errors = $global_info['errors'] ?? array();
									if ( ! empty( $errors ) && is_array( $errors ) ) {
										foreach ( $errors as $messages ) {
											foreach ( (array) $messages as $msg ) {
												echo esc_html( $msg ) . '<br />';
											}
										}
									} else {
										esc_html_e( 'Please check your key or contact support.', 'gravitywp-license-handler' );
									}
									?>
								</p>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the Individual Plugin Keys card (in License Keys tab).
		 *
		 * @param array $plugin_keys     Saved per-plugin keys.
		 * @param array $per_plugin_info License info per plugin from hub.
		 * @param array $all_plugins     All plugins from hub.
		 * @return void
		 */
		private static function render_individual_keys_card( $plugin_keys, $per_plugin_info, $all_plugins ) {
			?>
			<div class="gwp-card">
				<div class="gwp-card__header">
					<h2 class="gwp-card__title">
						<span class="dashicons dashicons-admin-plugins"></span>
						<?php esc_html_e( 'Individual Plugin Keys', 'gravitywp-license-handler' ); ?>
					</h2>
					<?php if ( ! empty( $plugin_keys ) ) : ?>
						<span class="gwp-tab__badge"><?php echo esc_html( count( $plugin_keys ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="gwp-card__body">
					<p class="gwp-card__subtitle">
						<?php esc_html_e( 'For Single Add-on licenses. Each key unlocks exactly one specific plugin. You can add keys for multiple plugins below.', 'gravitywp-license-handler' ); ?>
					</p>

					<?php if ( empty( $all_plugins ) ) : ?>
						<div class="gwp-empty-state" style="padding: 30px 20px;">
							<span class="dashicons dashicons-admin-plugins"></span>
							<p class="gwp-empty-state__text">
								<?php esc_html_e( 'No plugins available. Save a license key first or refresh the data.', 'gravitywp-license-handler' ); ?>
							</p>
						</div>
					<?php else : ?>
						<table class="gwp-keys-table">
							<thead>
								<tr>
									<th scope="col" style="width: 35%;"><?php esc_html_e( 'Plugin', 'gravitywp-license-handler' ); ?></th>
									<th scope="col"><?php esc_html_e( 'License Key', 'gravitywp-license-handler' ); ?></th>
									<th scope="col" style="width: 120px;"><?php esc_html_e( 'Status', 'gravitywp-license-handler' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $all_plugins as $plugin ) :
									$slug         = $plugin['slug'] ?? '';
									$name         = $plugin['name'] ?? $slug;
									$current_key  = $plugin_keys[ $slug ] ?? '';
									$license_data = $per_plugin_info[ $slug ] ?? null;
									$has_access   = ! empty( $plugin['has_access'] );
									$access_src   = $plugin['access_source'] ?? 'none';
									$status_class = 'is-empty';
									$status_text  = __( 'Not set', 'gravitywp-license-handler' );
									$status_icon  = 'dashicons-minus';

									if ( ! empty( $current_key ) ) {
										// Has an individual key set — check if it's valid.
										if ( $license_data && ( $license_data['status'] ?? '' ) === 'valid' ) {
											$status_class = 'is-valid';
											$status_text  = __( 'Active', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-yes-alt';
										} else {
											$status_class = 'is-invalid';
											$status_text  = __( 'Invalid', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-warning';
										}
									} elseif ( $has_access && 'global' === $access_src ) {
										// No individual key, but covered by the Global License Key.
										$status_class = 'is-valid';
										$status_text  = __( 'Via Global', 'gravitywp-license-handler' );
										$status_icon  = 'dashicons-admin-network';
									}

									$icon = '';
									if ( ! empty( $plugin['icons']['2x'] ) ) {
										$icon = $plugin['icons']['2x'];
									} elseif ( ! empty( $plugin['icons']['1x'] ) ) {
										$icon = $plugin['icons']['1x'];
									}
									?>
									<tr>
										<td>
											<div class="gwp-keys-table__plugin">
												<div class="gwp-keys-table__icon">
													<?php if ( $icon ) : ?>
														<img src="<?php echo esc_url( $icon ); ?>" alt="" />
													<?php else : ?>
														<span class="dashicons dashicons-admin-plugins"></span>
													<?php endif; ?>
												</div>
												<div class="gwp-keys-table__plugin-name"><?php echo esc_html( $name ); ?></div>
											</div>
										</td>
										<td>
											<input
												type="text"
												name="gravitywp_plugin_license_keys[<?php echo esc_attr( $slug ); ?>]"
												value="<?php echo esc_attr( $current_key ); ?>"
												class="gwp-input"
												placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
												data-gwp-validate="license-key"
												autocomplete="off"
											/>
										</td>
										<td>
											<span class="gwp-keys-table__status <?php echo esc_attr( $status_class ); ?>">
												<span class="dashicons <?php echo esc_attr( $status_icon ); ?>"></span>
												<?php echo esc_html( $status_text ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
			<?php
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
	}
}
