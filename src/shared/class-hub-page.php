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

			// Check installed state and current version.
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
						<?php
						// Use purchase_url from catalog if available, else generic pricing page.
						$purchase_url = ! empty( $plugin['purchase_url'] )
							? $plugin['purchase_url']
							: 'https://gravitywp.com/pricing/?utm_source=hub&utm_medium=admin&utm_campaign=upgrade&utm_content=' . rawurlencode( $slug );
						$is_free = ! empty( $plugin['is_free'] );
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
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
	}
}
