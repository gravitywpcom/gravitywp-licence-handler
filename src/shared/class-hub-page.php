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
		 * @return void
		 */
		public static function render_plugin_card( $plugin, $installed_plugins, $status ) {
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

			switch ( $status ) {
				case 'unlocked':
					return self::render_unlocked_footer( $plugin, $installed_plugins, $slug );
				case 'coming-soon':
					return self::render_coming_soon_footer( $plugin, $slug );
				case 'in-development':
					return self::render_in_development_footer( $plugin, $slug );
				case 'locked':
				default:
					return self::render_locked_footer( $plugin, $slug );
			}
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
				foreach ( $installed_plugins as $file => $data ) {
					if ( false !== strpos( $file, $slug ) ) {
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
			<?php if ( $is_installed && $has_update && $plugin_file ) : ?>
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

		/**
		 * Footer for plugins with status=coming-soon.
		 *
		 * Informational only — no install/activate. If a purchase_url is set,
		 * link to the product page so users can read about it.
		 *
		 * @param array  $plugin Plugin data.
		 * @param string $slug   Plugin slug.
		 * @return string
		 */
		private static function render_coming_soon_footer( $plugin, $slug ) {
			$learn_url = ! empty( $plugin['purchase_url'] ) ? $plugin['purchase_url'] : '';

			ob_start();
			?>
			<span class="gwp-plugin-card__status is-coming-soon">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Coming soon', 'gravitywp-license-handler' ); ?>
			</span>
			<?php if ( $learn_url ) : ?>
				<a
					href="<?php echo esc_url( $learn_url ); ?>"
					target="_blank"
					rel="noopener"
					class="gwp-btn gwp-btn--secondary gwp-btn--sm"
				>
					<span class="dashicons dashicons-external"></span>
					<?php esc_html_e( 'Learn more', 'gravitywp-license-handler' ); ?>
				</a>
			<?php endif; ?>
			<?php
			return (string) ob_get_clean();
		}

		/**
		 * Footer for plugins with status=in-development.
		 *
		 * Informational only — no install/activate, no external link
		 * (these aren't ready to be promoted yet).
		 *
		 * @param array  $plugin Plugin data.
		 * @param string $slug   Plugin slug.
		 * @return string
		 */
		private static function render_in_development_footer( $plugin, $slug ) {
			ob_start();
			?>
			<span class="gwp-plugin-card__status is-in-development">
				<span class="dashicons dashicons-hammer"></span>
				<?php esc_html_e( 'In development', 'gravitywp-license-handler' ); ?>
			</span>
			<?php
			return (string) ob_get_clean();
		}
	}
}
