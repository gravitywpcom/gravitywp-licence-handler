<?php
/**
 * Global License Key Registry.
 *
 * Renders the GravityWP Settings page with a modern tabbed interface:
 * - Tab 1: Global License — for All Access / List / Agency plans
 * - Tab 2: Individual Plugin Keys — for Single Add-on plans
 * - Tab 3: License Overview — summary of all active licenses
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
	 * Handles the settings page for both global and per-plugin license keys.
	 */
	class Global_License_Key_Registry {

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
		 * Base URL of the shared assets.
		 *
		 * @var string
		 */
		private static $base_url = '';

		/**
		 * Initialize the settings page.
		 *
		 * @param string $version  The handler version.
		 * @param string $base_dir Optional base directory for assets resolution.
		 * @return void
		 */
		public static function init( $version, $base_dir = '' ) {
			self::$version  = $version;
			self::$base_dir = $base_dir ? $base_dir : __DIR__;

			add_action( 'admin_menu', array( self::class, 'add_admin_menu' ) );
			add_action( 'admin_init', array( self::class, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		}

		/**
		 * Add the settings page under Gravity Forms menu.
		 *
		 * @return void
		 */
		public static function add_admin_menu() {
			add_submenu_page(
				'gf_edit_forms',
				__( 'GravityWP Settings', 'gravitywp-license-handler' ),
				'GravityWP',
				'manage_options',
				'gravitywp-settings',
				array( self::class, 'render_settings_page' )
			);
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
		 * Enqueue CSS and JS assets for the settings and hub pages.
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public static function enqueue_assets( $hook ) {
			if ( false === strpos( $hook, 'gravitywp-settings' ) && false === strpos( $hook, 'gravitywp-hub' ) ) {
				return;
			}

			$css_path = self::$base_dir . '/assets/css/gwp-admin.css';
			$js_path  = self::$base_dir . '/assets/js/gwp-admin.js';

			$css_url = self::resolve_asset_url( $css_path );
			$js_url  = self::resolve_asset_url( $js_path );

			if ( $css_url ) {
				wp_enqueue_style(
					'gwp-admin',
					$css_url,
					array( 'dashicons' ),
					self::$version
				);
			}

			if ( $js_url ) {
				wp_enqueue_script(
					'gwp-admin',
					$js_url,
					array(),
					self::$version,
					true
				);
			}
		}

		/**
		 * Resolve an absolute filesystem path to a plugin URL.
		 *
		 * Works regardless of how the library is bundled (lib/, vendor/, etc.).
		 *
		 * @param string $file_path Absolute filesystem path.
		 * @return string|false URL or false on failure.
		 */
		private static function resolve_asset_url( $file_path ) {
			if ( ! file_exists( $file_path ) ) {
				return false;
			}

			$file_path = wp_normalize_path( $file_path );
			$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );

			if ( strpos( $file_path, $plugins_dir ) === 0 ) {
				$relative = substr( $file_path, strlen( $plugins_dir ) );
				return plugins_url( $relative );
			}

			return false;
		}

		/**
		 * Render the settings page with tabs.
		 *
		 * @return void
		 */
		public static function render_settings_page() {
			wp_enqueue_style( 'dashicons' );

			$global_key = get_option( 'gravitywp_global_license_key', '' );
			$plugin_keys = get_option( 'gravitywp_plugin_license_keys', array() );
			if ( ! is_array( $plugin_keys ) ) {
				$plugin_keys = array();
			}

			$hub_data   = Hub_Manager::get_hub_data();
			$global_info = $hub_data['license']['global'] ?? array();
			$per_plugin_info = $hub_data['license']['per_plugin'] ?? array();
			if ( is_object( $per_plugin_info ) ) {
				$per_plugin_info = (array) $per_plugin_info;
			}
			$all_plugins = $hub_data['plugins'] ?? array();
			?>
			<div class="wrap gwp-admin">
				<div class="gwp-admin-header">
					<h1 class="gwp-admin-header__title">
						<span class="dashicons dashicons-admin-generic"></span>
						<?php esc_html_e( 'GravityWP Settings', 'gravitywp-license-handler' ); ?>
						<span class="gwp-admin-header__version">v<?php echo esc_html( self::$version ); ?></span>
					</h1>
					<div class="gwp-admin-header__actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=gravitywp-hub' ) ); ?>" class="gwp-btn gwp-btn--secondary">
							<span class="dashicons dashicons-admin-plugins"></span>
							<?php esc_html_e( 'View Hub', 'gravitywp-license-handler' ); ?>
						</a>
					</div>
				</div>

				<nav class="gwp-tabs" role="tablist">
					<a href="#global" class="gwp-tab is-active" data-gwp-tab="global" role="tab">
						<span class="dashicons dashicons-admin-network"></span>
						<?php esc_html_e( 'Global License', 'gravitywp-license-handler' ); ?>
					</a>
					<a href="#individual" class="gwp-tab" data-gwp-tab="individual" role="tab">
						<span class="dashicons dashicons-admin-plugins"></span>
						<?php esc_html_e( 'Individual Keys', 'gravitywp-license-handler' ); ?>
						<?php if ( ! empty( $plugin_keys ) ) : ?>
							<span class="gwp-tab__badge"><?php echo esc_html( count( $plugin_keys ) ); ?></span>
						<?php endif; ?>
					</a>
					<a href="#overview" class="gwp-tab" data-gwp-tab="overview" role="tab">
						<span class="dashicons dashicons-chart-pie"></span>
						<?php esc_html_e( 'Overview', 'gravitywp-license-handler' ); ?>
					</a>
				</nav>

				<form method="post" action="options.php">
					<?php settings_fields( 'gravitywp_settings_group' ); ?>

					<?php // ============ Tab 1: Global License ============ ?>
					<div class="gwp-tab-panel is-active" data-gwp-panel="global" role="tabpanel">
						<?php self::render_global_tab( $global_key, $global_info, $all_plugins ); ?>
					</div>

					<?php // ============ Tab 2: Individual Keys ============ ?>
					<div class="gwp-tab-panel" data-gwp-panel="individual" role="tabpanel">
						<?php self::render_individual_tab( $plugin_keys, $per_plugin_info, $all_plugins ); ?>
					</div>

					<?php // ============ Tab 3: Overview ============ ?>
					<div class="gwp-tab-panel" data-gwp-panel="overview" role="tabpanel">
						<?php self::render_overview_tab( $global_info, $per_plugin_info, $all_plugins ); ?>
					</div>

					<p style="margin-top: 24px;">
						<?php submit_button( __( 'Save All Settings', 'gravitywp-license-handler' ), 'primary', 'submit', false ); ?>
					</p>
				</form>
			</div>
			<?php
		}

		/**
		 * Render the Global License tab.
		 *
		 * @param string $global_key  Current global key.
		 * @param array  $global_info License info from hub.
		 * @param array  $all_plugins All plugins from hub.
		 * @return void
		 */
		private static function render_global_tab( $global_key, $global_info, $all_plugins ) {
			$status    = $global_info['status'] ?? 'no_key';
			$plan_type = $global_info['plan_type'] ?? Plan_Types::UNKNOWN;
			$plan_name = $global_info['plan_name'] ?? '';
			$expires   = $global_info['expires'] ?? '';
			$limit     = $global_info['license_limit'] ?? 0;
			$count     = $global_info['site_count'] ?? 0;

			// Count unlocked via global.
			$unlocked_global = 0;
			foreach ( $all_plugins as $p ) {
				if ( ! empty( $p['has_access'] ) && 'global' === ( $p['access_source'] ?? '' ) ) {
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
									<?php esc_html_e( 'Enter your key above and save. If you have a Single Add-on license, use the "Individual Keys" tab instead.', 'gravitywp-license-handler' ); ?>
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
										foreach ( $errors as $code => $messages ) {
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
		 * Render the Individual Keys tab.
		 *
		 * @param array $plugin_keys    Saved per-plugin keys.
		 * @param array $per_plugin_info License info per plugin from hub.
		 * @param array $all_plugins    All plugins from hub.
		 * @return void
		 */
		private static function render_individual_tab( $plugin_keys, $per_plugin_info, $all_plugins ) {
			?>
			<div class="gwp-card">
				<div class="gwp-card__header">
					<h2 class="gwp-card__title">
						<span class="dashicons dashicons-admin-plugins"></span>
						<?php esc_html_e( 'Individual Plugin Keys', 'gravitywp-license-handler' ); ?>
					</h2>
				</div>
				<div class="gwp-card__body">
					<p class="gwp-card__subtitle">
						<?php esc_html_e( 'For Single Add-on licenses. Each key unlocks exactly one specific plugin. You can add keys for multiple plugins below.', 'gravitywp-license-handler' ); ?>
					</p>

					<?php if ( empty( $all_plugins ) ) : ?>
						<div class="gwp-empty-state">
							<span class="dashicons dashicons-admin-plugins"></span>
							<h3 class="gwp-empty-state__title"><?php esc_html_e( 'No plugins available', 'gravitywp-license-handler' ); ?></h3>
							<p class="gwp-empty-state__text">
								<?php esc_html_e( 'Enter a license key or refresh to see available plugins.', 'gravitywp-license-handler' ); ?>
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
									$status_class = 'is-empty';
									$status_text  = __( 'Not set', 'gravitywp-license-handler' );
									$status_icon  = 'dashicons-minus';

									if ( ! empty( $current_key ) ) {
										if ( $license_data && ( $license_data['status'] ?? '' ) === 'valid' ) {
											$status_class = 'is-valid';
											$status_text  = __( 'Active', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-yes-alt';
										} else {
											$status_class = 'is-invalid';
											$status_text  = __( 'Invalid', 'gravitywp-license-handler' );
											$status_icon  = 'dashicons-warning';
										}
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
		 * Render the Overview tab.
		 *
		 * @param array $global_info     Global license info.
		 * @param array $per_plugin_info Per-plugin license info.
		 * @param array $all_plugins     All plugins from hub.
		 * @return void
		 */
		private static function render_overview_tab( $global_info, $per_plugin_info, $all_plugins ) {
			$counts         = array( 'unlocked' => 0, 'locked' => 0, 'total' => count( $all_plugins ) );
			$global_unlocks = 0;
			$per_unlocks    = 0;

			foreach ( $all_plugins as $p ) {
				if ( ! empty( $p['has_access'] ) ) {
					++$counts['unlocked'];
					if ( 'global' === ( $p['access_source'] ?? '' ) ) {
						++$global_unlocks;
					} elseif ( 'per_plugin' === ( $p['access_source'] ?? '' ) ) {
						++$per_unlocks;
					}
				} else {
					++$counts['locked'];
				}
			}

			$cache_remaining = Hub_Manager::get_cache_ttl_remaining();
			$cache_hours     = floor( $cache_remaining / 3600 );
			$cache_minutes   = floor( ( $cache_remaining % 3600 ) / 60 );
			?>
			<div class="gwp-info-grid">
				<div class="gwp-info-box">
					<p class="gwp-info-box__label"><?php esc_html_e( 'Total Plugins', 'gravitywp-license-handler' ); ?></p>
					<p class="gwp-info-box__value"><?php echo esc_html( $counts['total'] ); ?></p>
				</div>
				<div class="gwp-info-box">
					<p class="gwp-info-box__label"><?php esc_html_e( 'Unlocked', 'gravitywp-license-handler' ); ?></p>
					<p class="gwp-info-box__value" style="color: #27ae60;"><?php echo esc_html( $counts['unlocked'] ); ?></p>
					<?php if ( $global_unlocks || $per_unlocks ) : ?>
						<p class="gwp-info-box__sub">
							<?php
							if ( $global_unlocks ) {
								printf(
									/* translators: %d: count */
									esc_html__( '%d via global', 'gravitywp-license-handler' ),
									$global_unlocks
								);
							}
							if ( $global_unlocks && $per_unlocks ) {
								echo ' • ';
							}
							if ( $per_unlocks ) {
								printf(
									/* translators: %d: count */
									esc_html__( '%d individual', 'gravitywp-license-handler' ),
									$per_unlocks
								);
							}
							?>
						</p>
					<?php endif; ?>
				</div>
				<div class="gwp-info-box">
					<p class="gwp-info-box__label"><?php esc_html_e( 'Locked', 'gravitywp-license-handler' ); ?></p>
					<p class="gwp-info-box__value" style="color: #dc3232;"><?php echo esc_html( $counts['locked'] ); ?></p>
				</div>
				<div class="gwp-info-box">
					<p class="gwp-info-box__label"><?php esc_html_e( 'Cache Refresh', 'gravitywp-license-handler' ); ?></p>
					<p class="gwp-info-box__value" style="font-size: 20px;">
						<?php
						if ( $cache_remaining > 0 ) {
							printf( '%dh %dm', (int) $cache_hours, (int) $cache_minutes );
						} else {
							esc_html_e( 'Now', 'gravitywp-license-handler' );
						}
						?>
					</p>
					<p class="gwp-info-box__sub">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=gravitywp-settings&refresh=1#overview' ) ); ?>" class="gwp-refresh-link">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Refresh now', 'gravitywp-license-handler' ); ?>
						</a>
					</p>
				</div>
			</div>

			<div class="gwp-card">
				<div class="gwp-card__header">
					<h2 class="gwp-card__title">
						<span class="dashicons dashicons-admin-network"></span>
						<?php esc_html_e( 'Active Licenses', 'gravitywp-license-handler' ); ?>
					</h2>
				</div>
				<div class="gwp-card__body">
					<?php
					$has_any = false;

					// Global license.
					if ( ! empty( $global_info ) && ( $global_info['status'] ?? '' ) === 'valid' ) {
						$has_any = true;
						self::render_license_row(
							$global_info['plan_name'] ?? __( 'Global License', 'gravitywp-license-handler' ),
							$global_info,
							'global'
						);
					}

					// Per-plugin licenses.
					foreach ( $per_plugin_info as $slug => $info ) {
						if ( ( $info['status'] ?? '' ) === 'valid' ) {
							$has_any = true;
							$name    = $slug;
							foreach ( $all_plugins as $p ) {
								if ( ( $p['slug'] ?? '' ) === $slug ) {
									$name = $p['name'] ?? $slug;
									break;
								}
							}
							self::render_license_row( $name, $info, 'per_plugin' );
						}
					}

					if ( ! $has_any ) :
						?>
						<div class="gwp-empty-state" style="padding: 30px 20px;">
							<span class="dashicons dashicons-admin-network"></span>
							<p class="gwp-empty-state__text"><?php esc_html_e( 'No active licenses. Enter a license key in the Global or Individual tabs.', 'gravitywp-license-handler' ); ?></p>
						</div>
						<?php
					endif;
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render a single license row in the Overview.
		 *
		 * @param string $label  The display label.
		 * @param array  $info   The license info.
		 * @param string $source 'global' or 'per_plugin'.
		 * @return void
		 */
		private static function render_license_row( $label, $info, $source ) {
			$plan_type = $info['plan_type'] ?? Plan_Types::UNKNOWN;
			$expires   = $info['expires'] ?? '';
			$limit     = $info['license_limit'] ?? 0;
			$count     = $info['site_count'] ?? 0;
			?>
			<div class="gwp-alert gwp-alert--success" style="margin: 8px 0;">
				<span class="dashicons dashicons-yes-alt"></span>
				<div class="gwp-alert__content">
					<p class="gwp-alert__title">
						<?php echo esc_html( $label ); ?>
						<span class="gwp-plan-badge gwp-plan-badge--<?php echo esc_attr( $plan_type ); ?>" style="margin-left: 8px;">
							<?php echo esc_html( Plan_Types::get_label( $plan_type ) ); ?>
						</span>
					</p>
					<p class="gwp-alert__text">
						<?php
						printf(
							/* translators: %1$s: sites, %2$s: limit, %3$s: expires */
							esc_html__( 'Sites: %1$s / %2$s • Expires: %3$s', 'gravitywp-license-handler' ),
							esc_html( $count ),
							esc_html( 0 === (int) $limit ? __( 'Unlimited', 'gravitywp-license-handler' ) : $limit ),
							esc_html( $expires )
						);
						?>
					</p>
				</div>
			</div>
			<?php
		}
	}
}
