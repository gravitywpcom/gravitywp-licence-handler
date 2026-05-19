<?php
/**
 * Hub Page — Plugin card render helpers.
 *
 * Since v2.1.0: This class no longer registers its own admin page.
 * The Hub UI is now part of the unified GravityWP page rendered by
 * Global_License_Key_Registry. This class only provides reusable
 * helpers for rendering individual plugin cards.
 *
 * Kept as a separate class for organizational clarity and easy reuse.
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Hub_Page' ) ) {

	/**
	 * Class Hub_Page
	 *
	 * Static helper methods for rendering plugin cards.
	 */
	class Hub_Page {

		/**
		 * Initialize. No-op since v2.1.0 — the unified page handles everything.
		 *
		 * Kept for backward compatibility with the loader.
		 *
		 * @return void
		 */
		public static function init() {
			// No-op. Menu registration is handled by Global_License_Key_Registry.
		}

		/**
		 * Render a single plugin card.
		 *
		 * Public so it can be called from Global_License_Key_Registry.
		 *
		 * @param array  $plugin            Plugin data from hub API.
		 * @param array  $installed_plugins Installed WP plugins (from get_plugins()).
		 * @param string $status            'unlocked' or 'locked'.
		 * @param array  $context           Optional render context. Recognized keys:
		 *                                  - 'global_plan_type' (string): plan_type from $global_info,
		 *                                    used to label the source badge ("All Access", "Agency",
		 *                                    "List Add-ons" instead of generic "Global").
		 * @return void
		 */
		public static function render_plugin_card( $plugin, $installed_plugins, $status, $context = array() ) {
			$slug          = $plugin['slug'] ?? '';
			$name          = $plugin['name'] ?? $slug;
			$description   = $plugin['description'] ?? '';
			$new_version   = $plugin['new_version'] ?? '';
			$access_source = $plugin['access_source'] ?? 'none';

			// Resolve the source badge (label + CSS class) based on access source.
			// Falls back to generic labels when plan type is unknown.
			$source_badge = self::resolve_source_badge( $access_source, $context );

			$icon = '';
			if ( ! empty( $plugin['icons']['2x'] ) ) {
				$icon = $plugin['icons']['2x'];
			} elseif ( ! empty( $plugin['icons']['1x'] ) ) {
				$icon = $plugin['icons']['1x'];
			}

			$state             = self::compute_install_state( $plugin, $installed_plugins );
			$installed_version = $state['installed_version'];
			$has_update        = $state['has_update'];

			// Strip HTML for the card's short description.
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

							<?php if ( 'unlocked' === $status && ! empty( $source_badge['label'] ) ) : ?>
								<span class="gwp-plugin-card__source-badge gwp-plugin-card__source-badge--<?php echo esc_attr( $source_badge['variant'] ); ?>">
									<?php echo esc_html( $source_badge['label'] ); ?>
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

				<div class="gwp-plugin-card__footer" data-slug="<?php echo esc_attr( $slug ); ?>">
					<?php
					// Helper output is already escaped per branch.
					echo self::render_card_footer_html( $plugin, $installed_plugins, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Resolve the source badge for a plugin card.
		 *
		 * Maps `access_source` (and the global plan_type when relevant) to a
		 * concrete plan label ("All Access", "Agency", "List Add-ons",
		 * "Single Add-on", "Free") plus a CSS variant for the badge color.
		 *
		 * @param string $access_source One of 'global' | 'per_plugin' | 'free' | 'none'.
		 * @param array  $context       Render context (see render_plugin_card).
		 * @return array { 'label' => string, 'variant' => string } — empty label means "no badge".
		 */
		private static function resolve_source_badge( $access_source, $context ) {
			if ( ! $access_source || 'none' === $access_source ) {
				return array(
					'label'   => '',
					'variant' => '',
				);
			}

			if ( 'free' === $access_source ) {
				return array(
					'label'   => __( 'Free', 'gravitywp-license-handler' ),
					'variant' => 'free',
				);
			}

			if ( 'per_plugin' === $access_source ) {
				return array(
					'label'   => Plan_Types::get_label( Plan_Types::SINGLE_ADDON ),
					'variant' => Plan_Types::SINGLE_ADDON,
				);
			}

			// 'global' — label depends on which global plan unlocks it.
			if ( 'global' === $access_source ) {
				$plan_type = isset( $context['global_plan_type'] ) ? (string) $context['global_plan_type'] : '';
				if ( $plan_type && Plan_Types::UNKNOWN !== $plan_type ) {
					return array(
						'label'   => Plan_Types::get_label( $plan_type ),
						'variant' => $plan_type,
					);
				}
				// Fallback when plan type couldn't be detected.
				return array(
					'label'   => __( 'Global', 'gravitywp-license-handler' ),
					'variant' => 'global',
				);
			}

			// Unknown access source — no badge.
			return array(
				'label'   => '',
				'variant' => '',
			);
		}

		/**
		 * Render the inner HTML of a plugin card's footer.
		 *
		 * Used in two places:
		 *   1. Initial server render (called from render_plugin_card()).
		 *   2. AJAX response payload from Hub_Ajax (Install/Activate/Deactivate).
		 *
		 * Both must produce identical markup so the in-place footer swap is seamless.
		 *
		 * @param array  $plugin            Plugin data from hub.
		 * @param array  $installed_plugins Installed WP plugins.
		 * @param string $status            'unlocked' or 'locked'.
		 * @return string HTML (escaped).
		 */
		public static function render_card_footer_html( $plugin, $installed_plugins, $status ) {
			$slug = $plugin['slug'] ?? '';

			if ( 'unlocked' === $status ) {
				return self::render_unlocked_footer( $plugin, $installed_plugins, $slug );
			}
			return self::render_locked_footer( $plugin, $slug );
		}

		/**
		 * Compute install/active state flags from hub + WP plugin data.
		 *
		 * @param array $plugin            Plugin data from hub.
		 * @param array $installed_plugins Installed WP plugins.
		 * @return array {
		 *     @type bool   $is_installed      Whether the plugin folder exists in wp-content/plugins.
		 *     @type bool   $is_active         Whether the plugin is currently active.
		 *     @type bool   $has_update        Whether a newer version is available.
		 *     @type string $installed_version Version currently installed (or empty).
		 *     @type string $plugin_file       Plugin main file path (e.g. 'foo/foo.php') or empty.
		 * }
		 */
		private static function compute_install_state( $plugin, $installed_plugins ) {
			$slug              = $plugin['slug'] ?? '';
			$new_version       = $plugin['new_version'] ?? '';
			$installed_version = '';
			$is_installed      = false;
			$has_update        = false;
			$plugin_file       = '';

			if ( $slug && is_array( $installed_plugins ) ) {
				// Pre-compute normalized slug. Handles "gravitywpadvancednumberfield"
				// (catalog) ↔ "gravitywp-advanced-number-field/foo.php" (folder)
				// where strpos misses but fuzzy alphanumeric match succeeds.
				$slug_norm = preg_replace( '/[^a-z0-9]/i', '', strtolower( $slug ) );

				foreach ( $installed_plugins as $file => $data ) {
					$matched = false;
					if ( false !== strpos( $file, $slug ) ) {
						$matched = true;
					} elseif ( '' !== $slug_norm ) {
						$folder = strstr( $file, '/', true );
						if ( false !== $folder && '' !== $folder ) {
							$folder_norm = preg_replace( '/[^a-z0-9]/i', '', strtolower( $folder ) );
							if ( $folder_norm === $slug_norm ) {
								$matched = true;
							}
						}
					}

					if ( $matched ) {
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
			}

			$is_active = false;
			if ( $is_installed && $plugin_file ) {
				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$is_active = is_plugin_active( $plugin_file );
			}

			return array(
				'is_installed'      => $is_installed,
				'is_active'         => $is_active,
				'has_update'        => $has_update,
				'installed_version' => $installed_version,
				'plugin_file'       => $plugin_file,
			);
		}

		/**
		 * Footer HTML for an unlocked plugin (license covers it).
		 *
		 * State priority:
		 *   1. Not installed → Install (AJAX)
		 *   2. Installed + update available → Update (legacy redirect)
		 *   3. Installed + active → Deactivate (AJAX)
		 *   4. Installed + inactive → Activate (AJAX)
		 *
		 * @param array  $plugin            Plugin data from hub.
		 * @param array  $installed_plugins Installed WP plugins.
		 * @param string $slug              Plugin slug.
		 * @return string
		 */
		private static function render_unlocked_footer( $plugin, $installed_plugins, $slug ) {
			$state             = self::compute_install_state( $plugin, $installed_plugins );
			$is_installed      = $state['is_installed'];
			$is_active         = $state['is_active'];
			$has_update        = $state['has_update'];
			$installed_version = $state['installed_version'];
			$plugin_file       = $state['plugin_file'];
			$new_version       = $plugin['new_version'] ?? '';

			$package = '';
			if ( ! empty( $plugin['download_link'] ) ) {
				$package = $plugin['download_link'];
			} elseif ( ! empty( $plugin['package'] ) ) {
				$package = $plugin['package'];
			}

			$nonce = wp_create_nonce( Hub_Ajax::NONCE_ACTION );

			ob_start();
			?>
			<?php if ( $is_installed && $has_update && $plugin_file && current_user_can( 'update_plugins' ) && '' !== $package ) : ?>
				<button
					type="button"
					class="gwp-btn gwp-btn--primary gwp-btn--sm gwp-hub-action"
					data-action="update"
					data-slug="<?php echo esc_attr( $slug ); ?>"
					data-plugin-file="<?php echo esc_attr( $plugin_file ); ?>"
					data-package="<?php echo esc_url( $package ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
				>
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Update', 'gravitywp-license-handler' ); ?>
				</button>
				<?php if ( $installed_version ) : ?>
					<span class="gwp-plugin-card__status">v<?php echo esc_html( $installed_version ); ?> → v<?php echo esc_html( $new_version ); ?></span>
				<?php endif; ?>
			<?php elseif ( ! $is_installed && '' !== $package && current_user_can( 'install_plugins' ) ) : ?>
				<button
					type="button"
					class="gwp-btn gwp-btn--primary gwp-btn--sm gwp-hub-action"
					data-action="install"
					data-slug="<?php echo esc_attr( $slug ); ?>"
					data-package="<?php echo esc_url( $package ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
				>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Install', 'gravitywp-license-handler' ); ?>
				</button>
			<?php elseif ( $is_installed && $is_active && $plugin_file && current_user_can( 'activate_plugins' ) ) : ?>
				<button
					type="button"
					class="gwp-btn gwp-btn--secondary gwp-btn--sm gwp-hub-action"
					data-action="deactivate"
					data-slug="<?php echo esc_attr( $slug ); ?>"
					data-plugin-file="<?php echo esc_attr( $plugin_file ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
				>
					<span class="dashicons dashicons-controls-pause"></span>
					<?php esc_html_e( 'Deactivate', 'gravitywp-license-handler' ); ?>
				</button>
				<?php if ( $installed_version ) : ?>
					<span class="gwp-plugin-card__status is-active">
						<span class="dashicons dashicons-yes"></span>
						<?php
						printf(
							/* translators: %s: version number */
							esc_html__( 'Active (v%s)', 'gravitywp-license-handler' ),
							esc_html( $installed_version )
						);
						?>
					</span>
				<?php endif; ?>
			<?php elseif ( $is_installed && ! $is_active && $plugin_file && current_user_can( 'activate_plugins' ) ) : ?>
				<div class="gwp-plugin-card__actions">
					<button
						type="button"
						class="gwp-btn gwp-btn--primary gwp-btn--sm gwp-hub-action"
						data-action="activate"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						data-plugin-file="<?php echo esc_attr( $plugin_file ); ?>"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
					>
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Activate', 'gravitywp-license-handler' ); ?>
					</button>
					<?php if ( current_user_can( 'delete_plugins' ) ) : ?>
						<button
							type="button"
							class="gwp-btn gwp-btn--icon gwp-btn--danger gwp-btn--sm gwp-hub-action"
							data-action="delete"
							data-slug="<?php echo esc_attr( $slug ); ?>"
							data-plugin-file="<?php echo esc_attr( $plugin_file ); ?>"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
							title="<?php esc_attr_e( 'Delete plugin', 'gravitywp-license-handler' ); ?>"
							aria-label="<?php esc_attr_e( 'Delete plugin', 'gravitywp-license-handler' ); ?>"
						>
							<span class="dashicons dashicons-trash"></span>
						</button>
					<?php endif; ?>
				</div>
				<?php if ( $installed_version ) : ?>
					<span class="gwp-plugin-card__status is-inactive">
						<?php
						printf(
							/* translators: %s: version number */
							esc_html__( 'Installed (v%s)', 'gravitywp-license-handler' ),
							esc_html( $installed_version )
						);
						?>
					</span>
				<?php endif; ?>
			<?php elseif ( $is_installed ) : ?>
				<span class="gwp-plugin-card__status is-up-to-date">
					<span class="dashicons dashicons-yes"></span>
					<?php esc_html_e( 'Up to date', 'gravitywp-license-handler' ); ?>
					<?php if ( $installed_version ) : ?>
						(v<?php echo esc_html( $installed_version ); ?>)
					<?php endif; ?>
				</span>
			<?php endif; ?>
			<span class="gwp-hub-action-status" aria-live="polite"></span>
			<?php
			return (string) ob_get_clean();
		}

		/**
		 * Footer HTML for a locked plugin (no license access).
		 *
		 * @param array  $plugin Plugin data.
		 * @param string $slug   Plugin slug.
		 * @return string
		 */
		private static function render_locked_footer( $plugin, $slug ) {
			$purchase_url = ! empty( $plugin['purchase_url'] )
				? $plugin['purchase_url']
				: 'https://gravitywp.com/pricing/?utm_source=hub&utm_medium=admin&utm_campaign=upgrade&utm_content=' . rawurlencode( $slug );
			$is_free = ! empty( $plugin['is_free'] );

			ob_start();
			?>
			<a
				href="<?php echo esc_url( $purchase_url ); ?>"
				target="_blank"
				rel="noopener"
				class="gwp-btn gwp-btn--secondary gwp-btn--sm"
			>
				<?php if ( $is_free ) : ?>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Get Free Plugin', 'gravitywp-license-handler' ); ?>
				<?php else : ?>
					<span class="dashicons dashicons-cart"></span>
					<?php esc_html_e( 'Get This Add-on', 'gravitywp-license-handler' ); ?>
				<?php endif; ?>
			</a>
			<?php if ( ! $is_free && ! empty( $plugin['price'] ) ) : ?>
				<span class="gwp-plugin-card__status" style="color: #666;">
					$<?php echo esc_html( number_format( (float) $plugin['price'], 0 ) ); ?>
				</span>
			<?php endif; ?>
			<?php
			return (string) ob_get_clean();
		}
	}
}
