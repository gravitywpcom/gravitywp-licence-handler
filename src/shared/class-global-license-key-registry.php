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
					'sanitize_callback' => array( self::class, 'sanitize_global_key' ),
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
		 * Sanitize the Global License Key setting.
		 *
		 * Rejects keys we've previously seen the hub identify as
		 * `single_addon` plans — those belong in the Individual Plugin Keys
		 * table, not the Global field. An unknown key (never submitted
		 * before) is allowed through; the hub's next response will tell us
		 * its plan_type, and the *next* save attempt will be validated.
		 *
		 * @param mixed $raw Raw input from the form.
		 * @return string Sanitized key, or '' if rejected.
		 */
		public static function sanitize_global_key( $raw ) {
			$key = sanitize_text_field( trim( (string) $raw ) );
			if ( '' === $key ) {
				return '';
			}

			// Honor existing behavior for the no-op case (user saves the
			// settings without changing the key value): re-detection isn't
			// useful, just return as-is. Same key as already stored = no
			// validation needed.
			$existing = (string) get_option( 'gravitywp_global_license_key', '' );
			if ( $existing === $key ) {
				return $key;
			}

			if ( class_exists( '\GravityWP\Shared\Api_Error_Handler' ) ) {
				$detected = Api_Error_Handler::detect_key_plan_type( $key );
				if ( Plan_Types::SINGLE_ADDON === $detected ) {
					add_settings_error(
						'gravitywp_global_license_key',
						'gwp_wrong_field_for_plan_type',
						Api_Error_Handler::message( 'wrong_field_for_plan_type' ),
						'error'
					);
					// Keep the previously-saved value so the user doesn't
					// lose a working Global key while we reject the paste.
					return $existing;
				}
			}

			return $key;
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

			// Read the previously-saved map once. We use it both to detect
			// no-op saves (same key in same slot, skip validation) and to
			// keep a working value when we reject a paste.
			$existing = get_option( 'gravitywp_plugin_license_keys', array() );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$has_detector = class_exists( '\GravityWP\Shared\Api_Error_Handler' );

			$clean = array();
			foreach ( $raw as $slug => $key ) {
				$slug_clean = sanitize_key( $slug );
				$key_clean  = sanitize_text_field( trim( (string) $key ) );

				if ( empty( $slug_clean ) ) {
					continue;
				}

				// Empty submission for this slot: persist as cleared (the
				// final array_filter at the bottom of this method drops it).
				if ( empty( $key_clean ) ) {
					continue;
				}

				// No-op: same key as already saved → accept without
				// re-validating. Matches the behavior of sanitize_global_key.
				$prior = isset( $existing[ $slug_clean ] ) ? (string) $existing[ $slug_clean ] : '';
				if ( $prior === $key_clean ) {
					$clean[ $slug_clean ] = $key_clean;
					continue;
				}

				// Detection: look up what we know about this key from
				// previous hub responses. Unknown keys (never submitted
				// before) get passed through — the next hub call will
				// classify them and Hub_Manager's relocator / interpret_license
				// will surface any issue post-hoc.
				if ( $has_detector ) {
					$dest = Api_Error_Handler::detect_key_destination( $key_clean );

					// Reject case A: this key is a Single Add-on that we've
					// previously seen unlock a DIFFERENT plugin. Keep the
					// prior value for this slot so a working key isn't lost.
					if ( Plan_Types::SINGLE_ADDON === $dest['plan_type']
						&& null !== $dest['slug']
						&& $dest['slug'] !== $slug_clean
					) {
						add_settings_error(
							'gravitywp_plugin_license_keys',
							'gwp_wrong_plugin_key_' . $slug_clean,
							Api_Error_Handler::message( 'wrong_plugin_key' ),
							'error'
						);
						if ( '' !== $prior ) {
							$clean[ $slug_clean ] = $prior;
						}
						continue;
					}

					// Multi-plugin licenses (All Access / Agency / List Add-ons)
					// pasted into a per-plugin row USED to be rejected here,
					// but they actually work — PaddlePress grants download
					// permission for every plugin under these plans, so one
					// such key in any per_plugin slot unlocks everything.
					// We allow the save and show a non-blocking 'info' notice
					// + an inline note under the row (rendered by
					// Api_Error_Handler::interpret_license returning
					// STATUS_ACTIVE with primary_code='global_key_in_per_plugin').
					// The notice nudges the user toward the cleaner Global
					// placement without taking away a working configuration.
					if ( in_array(
						$dest['plan_type'],
						array( Plan_Types::ALL_ACCESS, Plan_Types::AGENCY, Plan_Types::LIST_ADDONS ),
						true
					) ) {
						add_settings_error(
							'gravitywp_plugin_license_keys',
							'gwp_global_key_in_per_plugin_' . $slug_clean,
							Api_Error_Handler::message( 'global_key_in_per_plugin' ),
							'info' // 'info' (not 'error') — save proceeds.
						);
						$clean[ $slug_clean ] = $key_clean;
						continue;
					}
				}

				$clean[ $slug_clean ] = $key_clean;
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
						'updating'      => __( 'Updating…', 'gravitywp-license-handler' ),
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
						. ".gwp-tabs{display:flex;align-items:center;gap:6px;padding:6px;background:#fff;border:1px solid #dcdcde;border-radius:12px;margin:0 0 24px;position:sticky;top:32px;z-index:50}"
						. ".gwp-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;margin:0;border:none;background:transparent;border-radius:8px;text-decoration:none;color:#495057;font-family:inherit;font-size:14px;font-weight:500;line-height:1.2;cursor:pointer}"
						. ".gwp-tab.is-active,.gwp-tab--action{background:#327397;color:#fff;font-weight:600}"
						. ".gwp-tab--action{margin-left:auto}"
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

			// Display any settings errors raised by our sanitize callbacks
			// (sanitize_global_key rejects Single Add-on keys in the Global
			// field; sanitize_plugin_keys may add others in future). WP only
			// auto-displays settings_errors() on a small set of core admin
			// pages — our custom `gravitywp` page isn't one of them, so the
			// add_settings_error() output is silently dropped unless we call
			// it explicitly here. Without this, a user pasting a bad Global
			// key sees no feedback at all.
			settings_errors( 'gravitywp_global_license_key' );
			settings_errors( 'gravitywp_plugin_license_keys' );

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

			// Count rows that are TRULY unlocked via a per-plugin key — the
			// catalog's has_access + access_source is the source of truth
			// (cf. Api_Error_Handler::interpret_license). The hero's badge
			// used to count `per_plugin_info[*].status === 'valid'` which
			// over-counted wrong-plugin keys and keys with blocking errors.
			$valid_per_count = 0;
			foreach ( $all_plugins as $_p ) {
				if ( ! empty( $_p['has_access'] ) && 'per_plugin' === ( $_p['access_source'] ?? '' ) ) {
					++$valid_per_count;
				}
			}

			// Hub failures (Cloudflare, network timeout, HTTP 5xx, malformed
			// JSON) attach a `hub_error` block to the data returned by
			// Hub_Manager. Surface it as an inline banner at the top of the
			// page — previously these failures silently rendered an empty
			// catalog with no explanation.
			$hub_error = isset( $hub_data['hub_error'] ) && is_array( $hub_data['hub_error'] )
				? $hub_data['hub_error']
				: null;

			// One-shot relocation notice: Hub_Manager auto-moved a Single
			// Add-on key out of the Global field into the matching per-plugin
			// row. We surface a green success notice exactly once, then the
			// transient is consumed and the notice disappears on the next
			// load. The relocation itself already mutated $hub_data in-place,
			// so the page below renders the post-relocation state.
			$relocated = get_transient( Hub_Manager::RELOCATED_NOTICE_TRANSIENT );
			if ( $relocated ) {
				delete_transient( Hub_Manager::RELOCATED_NOTICE_TRANSIENT );
			}
			?>
			<div class="wrap gwp-admin">
				<?php if ( is_array( $relocated ) && ! empty( $relocated['name'] ) ) : ?>
					<div class="gwp-alert gwp-alert--success" role="status" style="margin-bottom:16px;">
						<span class="dashicons dashicons-yes-alt"></span>
						<div class="gwp-alert__content">
							<p class="gwp-alert__title"><?php esc_html_e( 'Single Add-on key moved automatically', 'gravitywp-license-handler' ); ?></p>
							<p class="gwp-alert__text">
								<?php
								printf(
									/* translators: %s: plugin display name */
									esc_html__( 'You entered a Single Add-on key in the Global License Key field. We detected it belongs to %s and moved it to the matching row under Individual Plugin Keys.', 'gravitywp-license-handler' ),
									'<strong>' . esc_html( $relocated['name'] ) . '</strong>'
								);
								?>
							</p>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $hub_error ) : ?>
					<div class="gwp-alert gwp-alert--danger" role="alert" style="margin-bottom:16px;">
						<span class="dashicons dashicons-warning"></span>
						<div class="gwp-alert__content">
							<p class="gwp-alert__title"><?php esc_html_e( 'License server unreachable', 'gravitywp-license-handler' ); ?></p>
							<p class="gwp-alert__text">
								<?php echo esc_html( $hub_error['message'] ?? '' ); ?>
								<?php
								$retry_url = add_query_arg(
									array(
										'page'    => self::PAGE_SLUG,
										'refresh' => '1',
									),
									admin_url( 'admin.php' )
								);
								?>
								<a href="<?php echo esc_url( $retry_url ); ?>" class="button button-secondary" style="margin-left:8px;">
									<?php esc_html_e( 'Retry', 'gravitywp-license-handler' ); ?>
								</a>
							</p>
						</div>
					</div>
				<?php endif; ?>

				<?php self::render_hero( $global_info, $per_plugin_info, $global_key, $has_any_license, count( $unlocked ), count( $all_plugins ), $valid_per_count ); ?>

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
					<button type="submit" form="gwp-license-keys-form" name="submit" id="submit" class="gwp-tab gwp-tab--action">
						<span class="dashicons dashicons-yes" aria-hidden="true"></span>
						<?php esc_html_e( 'Save License Keys', 'gravitywp-license-handler' ); ?>
					</button>
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
					<form id="gwp-license-keys-form" method="post" action="options.php">
						<?php settings_fields( 'gravitywp_settings_group' ); ?>
						<?php
						// Individual keys are only meaningful for paid add-ons. Free plugins
						// update via wp.org and never use a license key, so they're hidden
						// from the per-plugin keys card.
						$paid_plugins = array_filter(
							$all_plugins,
							static function ( $p ) {
								return empty( $p['is_free'] );
							}
						);
						?>
						<?php self::render_global_license_card( $global_key, $global_info, $unlocked ); ?>
						<?php self::render_individual_keys_card( $plugin_keys, $per_plugin_info, $paid_plugins ); ?>
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
		 * @param int    $valid_per_count Number of plugins truly unlocked via
		 *                                a per-plugin key (catalog-derived,
		 *                                not raw `status === valid`).
		 * @return void
		 */
		private static function render_hero( $global_info, $per_plugin_info, $global_key, $has_any_license, $unlocked_count, $total_count, $valid_per_count = 0 ) {
			$has_per_plugin = ! empty( $per_plugin_info );
			$plan_badge     = null;
			$plan_label     = '';
			$expires        = '';
			$site_count     = 0;
			$site_limit     = 0;

			// Detect "single-addon key in the Global slot" up front so the hero
			// doesn't shout "Your plugins are unlocked and ready to use." for
			// a key that actually unlocks zero plugins via the global path.
			$global_plan_type      = $global_info['plan_type'] ?? Plan_Types::UNKNOWN;
			$global_status         = $global_info['status'] ?? '';
			$wrong_field_for_global = (
				'valid' === $global_status
				&& Plan_Types::SINGLE_ADDON === $global_plan_type
				&& ! empty( $global_key )
			);

			if ( empty( $global_key ) && ! $has_per_plugin ) {
				$state    = 'empty';
				$title    = __( 'Welcome to GravityWP', 'gravitywp-license-handler' );
				$subtitle = __( 'Enter a license key to unlock and manage your plugins.', 'gravitywp-license-handler' );
			} elseif ( $wrong_field_for_global && 0 === (int) $valid_per_count ) {
				// Saved Global key is a Single Add-on AND no per-plugin keys
				// are working either → the site has no functional license.
				// Render as a warning, not as success.
				$state      = 'invalid';
				$plan_badge = Plan_Types::SINGLE_ADDON;
				$plan_label = Plan_Types::get_label( $plan_badge );
				$title      = __( 'Wrong field for this license type', 'gravitywp-license-handler' );
				$subtitle   = __( 'Your Single Add-on key belongs under Individual Plugin Keys, not the Global License Key field.', 'gravitywp-license-handler' );
			} elseif ( $has_any_license ) {
				$state         = 'active';
				$global_valid  = ( 'valid' === $global_status );

				if ( $global_valid && ! $wrong_field_for_global ) {
					$plan_badge = $global_plan_type;
					$plan_label = Plan_Types::get_label( $plan_badge );
					$title      = $global_info['plan_name'] ?? __( 'License Active', 'gravitywp-license-handler' );
					$expires    = $global_info['expires'] ?? '';
					$site_count = (int) ( $global_info['site_count'] ?? 0 );
					$site_limit = (int) ( $global_info['license_limit'] ?? 0 );
					$subtitle   = __( 'Your plugins are unlocked and ready to use.', 'gravitywp-license-handler' );
				} else {
					// Use catalog-derived count (passed in) instead of iterating
					// per_plugin_info — the latter would over-count wrong-plugin
					// keys and keys with blocking errors that don't actually
					// unlock anything on this site.
					$valid_per  = (int) $valid_per_count;
					$plan_badge = Plan_Types::SINGLE_ADDON;
					$plan_label = Plan_Types::get_label( $plan_badge );

					if ( 0 === $valid_per ) {
						// has_any_license is true (PaddlePress says at least
						// one per_plugin status='valid') but $valid_per_count
						// is 0, which means every entered key has a blocking
						// embedded error — site limit reached, wrong product,
						// expired-on-server, etc. Reading "0 Individual
						// Licenses Active" as a headline is confusing when
						// the user has clearly entered a key. Flip the hero
						// to the invalid state and point them at the
						// per-row alerts for the specific reason.
						$state    = 'invalid';
						$title    = __( 'License entered but not active', 'gravitywp-license-handler' );
						$subtitle = __( 'Check the rows below for the specific reason.', 'gravitywp-license-handler' );
					} else {
						$title    = sprintf(
							/* translators: %d: count */
							_n( '%d Individual License Active', '%d Individual Licenses Active', $valid_per, 'gravitywp-license-handler' ),
							$valid_per
						);
						$subtitle = __( 'Single Add-on licenses applied.', 'gravitywp-license-handler' );
					}
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

			$astronaut_url = '';
			$base_url      = self::get_assets_base_url();
			if ( $base_url ) {
				$astronaut_url = $base_url . 'assets/img/astronaut.svg';
			}
			?>
			<div class="gwp-hero gwp-hero--<?php echo esc_attr( $state ); ?>">
				<div class="gwp-hero__bg" aria-hidden="true"></div>
				<?php if ( $astronaut_url ) : ?>
					<img class="gwp-hero__astronaut" src="<?php echo esc_url( $astronaut_url ); ?>" alt="" aria-hidden="true" />
				<?php endif; ?>

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

			// Reject case: the saved Global key is a Single Add-on key. The
			// hub reports `status: valid, plan_type: single_addon`, which
			// previously rendered as a green "Active … Unlocks 0 plugins"
			// banner — a self-contradicting message. Now we surface it as a
			// warning and tell the user where the key actually belongs.
			//
			// Why this lives at render-time as well as in sanitize_global_key:
			// the sanitize validator can only refuse a save when we've
			// previously seen the key (via per-plugin submission) and know
			// its plan_type. A FRESH paste straight into Global bypasses the
			// validator, lands in the option store, the hub identifies it
			// as single_addon on the next fetch, and we discover the mistake
			// only here. Surfacing it instead of silently labeling it Active
			// is the professional behavior.
			$wrong_field_for_global = ( 'valid' === $status )
				&& ( Plan_Types::SINGLE_ADDON === $plan_type )
				&& ! empty( $global_key );

			// "Bad" Global input states — a key was entered but the hub
			// doesn't accept it. Without this flag the JS shape validator
			// happily paints the input green (UUID-shape match) over a
			// server-known-invalid value. Same defense-in-depth pattern as
			// the per-plugin rows: add the is-invalid class AND a
			// data-gwp-force-state="invalid" attribute so the JS validator
			// won't overwrite it on focus/blur.
			$global_is_bad = ! empty( $global_key )
				&& 'valid' !== $status
				&& ! $wrong_field_for_global;
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
							class="gwp-input <?php echo $global_is_bad ? 'is-invalid' : ''; ?>"
							placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
							data-gwp-validate="license-key"
							<?php if ( $global_is_bad ) : ?>data-gwp-force-state="invalid"<?php endif; ?>
							autocomplete="off"
						/>
						<p class="description">
							<?php esc_html_e( 'Enter the license key you received by email after purchase.', 'gravitywp-license-handler' ); ?>
						</p>
					</div>

					<?php if ( $wrong_field_for_global ) : ?>
						<div class="gwp-alert gwp-alert--warn">
							<span class="dashicons dashicons-warning"></span>
							<div class="gwp-alert__content">
								<p class="gwp-alert__title">
									<?php esc_html_e( 'Wrong field for this license type', 'gravitywp-license-handler' ); ?>
								</p>
								<p class="gwp-alert__text">
									<?php
									printf(
										/* translators: %s: plan name returned by the hub */
										esc_html__( 'The key you entered is a Single Add-on license (%s) and unlocks exactly one plugin. The Global License Key field is for All Access, List Add-ons, and Agency plans. Move this key to the matching row under Individual Plugin Keys below, then clear it from this field.', 'gravitywp-license-handler' ),
										esc_html( $plan_name )
									);
									?>
								</p>
							</div>
						</div>
					<?php elseif ( empty( $global_key ) ) : ?>
						<div class="gwp-alert gwp-alert--info">
							<span class="dashicons dashicons-info"></span>
							<div class="gwp-alert__content">
								<p class="gwp-alert__title"><?php esc_html_e( 'No global license key configured', 'gravitywp-license-handler' ); ?></p>
								<p class="gwp-alert__text">
									<?php esc_html_e( 'Enter your key above and save. If you have a Single Add-on license, use the Individual Plugin Keys section below.', 'gravitywp-license-handler' ); ?>
								</p>
							</div>
						</div>
					<?php elseif ( 'valid' === $status ) : ?>
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
					<?php else : ?>
						<div class="gwp-alert gwp-alert--danger" role="alert">
							<span class="dashicons dashicons-warning"></span>
							<div class="gwp-alert__content">
								<p class="gwp-alert__title">
									<?php
									// Title text varies with the underlying cause so the
									// banner is actionable at a glance, not just a generic
									// "License invalid or expired" line over every failure.
									// We reach this branch only with a non-empty $global_key
									// (the elseif chain above handles the empty case), so the
									// title never says "No license key provided" here.
									$errors     = isset( $global_info['errors'] ) && is_array( $global_info['errors'] ) ? $global_info['errors'] : array();
									$first_code = '';
									foreach ( $errors as $code => $_messages ) {
										$first_code = (string) $code;
										break;
									}
									switch ( $first_code ) {
										case 'missing_license_key':
											esc_html_e( 'License key not found', 'gravitywp-license-handler' );
											break;
										case 'expired_license_key':
											esc_html_e( 'License expired', 'gravitywp-license-handler' );
											break;
										case 'blocked_license_domain':
											esc_html_e( 'This site is blocked', 'gravitywp-license-handler' );
											break;
										case 'invalid_product':
											esc_html_e( "License doesn't match this product", 'gravitywp-license-handler' );
											break;
										case 'invalid_license_or_domain':
											esc_html_e( 'License key not recognized', 'gravitywp-license-handler' );
											break;
										case 'can_not_add_new_domain':
											esc_html_e( 'Site limit reached', 'gravitywp-license-handler' );
											break;
										case 'insufficient_membership_level':
											esc_html_e( "License doesn't cover this account", 'gravitywp-license-handler' );
											break;
										case 'unregistered_license_domain':
											esc_html_e( 'License not yet activated for this site', 'gravitywp-license-handler' );
											break;
										default:
											esc_html_e( 'License invalid or expired', 'gravitywp-license-handler' );
									}
									?>
								</p>
								<p class="gwp-alert__text">
									<?php
									// Surface every error from the hub. For each code, prefer
									// the Api_Error_Handler catalog message (translated, copy
									// reviewed) over PaddlePress's raw string (English,
									// developer-facing). Falls back to the raw string if the
									// code isn't in the catalog — better to show something
									// English than nothing.
									if ( ! empty( $errors ) ) {
										$catalog = Api_Error_Handler::get_catalog();
										$shown   = array();
										foreach ( $errors as $code => $messages ) {
											$code_str = (string) $code;
											if ( isset( $shown[ $code_str ] ) ) {
												continue;
											}
											$shown[ $code_str ] = true;
											if ( isset( $catalog[ $code_str ] ) ) {
												echo esc_html( Api_Error_Handler::message( $code_str ) ) . '<br />';
												continue;
											}
											foreach ( (array) $messages as $msg ) {
												echo esc_html( (string) $msg ) . '<br />';
											}
										}
									} else {
										// Empty errors + invalid status is what PaddlePress
										// returns for non-existent keys (the key isn't even
										// in the payments table). Be specific.
										esc_html_e( "We couldn't find this license key. Double-check that you copied it correctly from your purchase email, or contact support.", 'gravitywp-license-handler' );
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

									// Delegate to the centralized interpreter. It cross-references the
									// hub's raw `status` with the row's `has_access` + `access_source`
									// and the embedded error map, producing one of:
									//   ACTIVE | INACTIVE | INVALID | WRONG_PLUGIN | VIA_GLOBAL | EMPTY.
									// This collapses what used to be three special-case branches and a
									// silent "Active" lie when the key was for a different plugin.
									$interp = Api_Error_Handler::interpret_license(
										is_array( $license_data ) ? $license_data : array(),
										array(
											'has_access'    => $has_access,
											'access_source' => $access_src,
											'has_key'       => ! empty( $current_key ),
										)
									);

									switch ( $interp['effective_status'] ) {
										case Api_Error_Handler::STATUS_ACTIVE:
											$status_class = 'is-valid';
											$status_text  = __( 'Active', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-yes-alt';
											break;
										case Api_Error_Handler::STATUS_VIA_GLOBAL:
											$status_class = 'is-valid';
											$status_text  = __( 'Via Global', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-admin-network';
											break;
										case Api_Error_Handler::STATUS_INACTIVE:
											// Valid key but can't be used here — pick a SHORT badge
											// label per primary_code, since the Status column is
											// width-constrained (120px). The full catalog message
											// rides along in the inline alert row below.
											$status_class = 'is-invalid';
											$status_icon  = 'dashicons-warning';
											switch ( $interp['primary_code'] ) {
												case 'can_not_add_new_domain':
													$status_text = __( 'Site limit', 'gravitywp-license-handler' );
													break;
												case 'insufficient_membership_level':
													$status_text = __( 'Wrong plan', 'gravitywp-license-handler' );
													break;
												case 'invalid_license_or_domain':
													$status_text = __( 'Not active', 'gravitywp-license-handler' );
													break;
												case 'unregistered_license_domain':
													$status_text = __( 'Activating…', 'gravitywp-license-handler' );
													break;
												default:
													$status_text = __( 'Not active', 'gravitywp-license-handler' );
											}
											break;
										case Api_Error_Handler::STATUS_WRONG_PLUGIN:
											$status_class = 'is-invalid';
											$status_text  = __( 'Wrong plugin', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-warning';
											break;
										case Api_Error_Handler::STATUS_INVALID:
											$status_class = 'is-invalid';
											$status_icon  = 'dashicons-warning';
											switch ( $interp['primary_code'] ) {
												case 'missing_license_key':
													$status_text = __( 'Not found', 'gravitywp-license-handler' );
													break;
												case 'expired_license_key':
													$status_text = __( 'Expired', 'gravitywp-license-handler' );
													break;
												case 'blocked_license_domain':
													$status_text = __( 'Blocked', 'gravitywp-license-handler' );
													break;
												case 'invalid_product':
													$status_text = __( 'Wrong product', 'gravitywp-license-handler' );
													break;
												case 'invalid_license_or_domain':
													$status_text = __( 'Not recognized', 'gravitywp-license-handler' );
													break;
												case 'global_key_in_per_plugin':
													$status_text = __( 'Wrong field', 'gravitywp-license-handler' );
													break;
												case 'no_key':
													$status_text = __( 'Not set', 'gravitywp-license-handler' );
													break;
												default:
													$status_text = __( 'Invalid', 'gravitywp-license-handler' );
											}
											break;
										case Api_Error_Handler::STATUS_EMPTY:
										default:
											$status_class = 'is-empty';
											$status_text  = __( 'Not set', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-minus';
											break;
									}

									// Tooltip carries the precise catalog message so users see the
									// specific reason on hover (e.g. "Your license is at its site
									// limit" vs. the generic "Invalid" badge text).
									$status_tooltip = $interp['message'];

									// "Bad row" states: red input border + an inline alert below
									// the row showing the full catalog message. Active/Via Global/
									// Empty rows skip both.
									$bad_states = array(
										Api_Error_Handler::STATUS_WRONG_PLUGIN,
										Api_Error_Handler::STATUS_INVALID,
										Api_Error_Handler::STATUS_INACTIVE,
									);
									$is_bad_row = in_array( $interp['effective_status'], $bad_states, true );

									// Informational note state: the row IS working (status=active
									// or via_global, no red border) but the interpreter has
									// something useful to say — e.g. "This is a multi-plugin
									// license, you can move it to Global for clarity." Detected
									// by primary_code being something other than the literal
									// 'active' / 'via_global' codes that carry no extra info.
									$has_inline_note = ! $is_bad_row && ! in_array(
										$interp['primary_code'],
										array( 'active', 'via_global', 'no_key' ),
										true
									);
									$show_alert_row = $is_bad_row || $has_inline_note;

									// Map Api_Error_Handler::severity → CSS modifier so the alert
									// matches its meaning (red for errors, yellow for warnings,
									// blue for info).
									switch ( $interp['severity'] ) {
										case Api_Error_Handler::SEVERITY_ERROR:
											$alert_modifier = 'gwp-alert--danger';
											$alert_icon     = 'dashicons-warning';
											break;
										case Api_Error_Handler::SEVERITY_WARN:
											$alert_modifier = 'gwp-alert--warn';
											$alert_icon     = 'dashicons-warning';
											break;
										case Api_Error_Handler::SEVERITY_INFO:
										default:
											$alert_modifier = 'gwp-alert--info';
											$alert_icon     = 'dashicons-info';
											break;
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
												class="gwp-input <?php echo $is_bad_row ? 'is-invalid' : ''; ?>"
												placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
												data-gwp-validate="license-key"
												<?php if ( $is_bad_row ) : ?>data-gwp-force-state="invalid"<?php endif; ?>
												autocomplete="off"
											/>
										</td>
										<td>
											<span class="gwp-keys-table__status <?php echo esc_attr( $status_class ); ?>" title="<?php echo esc_attr( $status_tooltip ); ?>">
												<span class="dashicons <?php echo esc_attr( $status_icon ); ?>"></span>
												<?php echo esc_html( $status_text ); ?>
											</span>
										</td>
									</tr>
									<?php if ( $show_alert_row ) : ?>
										<tr class="gwp-keys-table__error-row">
											<td colspan="3">
												<div class="gwp-alert <?php echo esc_attr( $alert_modifier ); ?>" role="<?php echo $is_bad_row ? 'alert' : 'status'; ?>">
													<span class="dashicons <?php echo esc_attr( $alert_icon ); ?>"></span>
													<div class="gwp-alert__content">
														<p class="gwp-alert__text"><?php echo esc_html( $interp['message'] ); ?></p>
													</div>
												</div>
											</td>
										</tr>
									<?php endif; ?>
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
