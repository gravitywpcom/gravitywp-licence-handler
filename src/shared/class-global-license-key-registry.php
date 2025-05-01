<?php
/**
 * Global License Key Registry class file.
 *
 * This file contains the Global_License_Key_Registry class,
 * which manages the global license key registration, validation, and storage
 * for GravityWP plugins.
 *
 * @package GravityWP\Shared
 */

namespace GravityWP\Shared;

/**
 * Class Global_License_Key_Registry
 *
 * Handles the registration, validation, and storage of the global license key
 * used across GravityWP plugins and extensions.
 */
class Global_License_Key_Registry {

	/**
	 * Used version of plugin.
	 *
	 * @var mixed|string
	 */
	private static $version;

	/**
	 * Initializes the GravityWP settings page functionality.
	 *
	 * Hooks into the WordPress admin lifecycle to:
	 * - Add the settings page to the admin menu.
	 * - Register the plugin settings.
	 *
	 * @param string $version The current License handler version to assign to the settings loader.
	 * @return void
	 */
	public static function init( $version ) {
		self::$version = $version;
		add_action( 'admin_menu', array( self::class, 'add_admin_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Adds the GravityWP settings page to the WordPress admin menu.
	 *
	 * This method adds a submenu page under the Gravity Forms menu
	 * where administrators can configure the global license key.
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
	 * Registers the settings for the GravityWP plugin.
	 *
	 * This method registers the 'gravitywp_global_license_key' option
	 * under the 'gravitywp_settings_group' settings group.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting( 'gravitywp_settings_group', 'gravitywp_global_license_key' );
	}

	/**
	 * Renders the GravityWP plugin settings page.
	 *
	 * Outputs the HTML form where administrators can input
	 * and save the global license key used across GravityWP plugins.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		$global_key    = get_option( 'gravitywp_global_license_key', '' );
		$data          = null;
		$error_message = '';
		$error_details = '';
		wp_enqueue_style( 'dashicons' );

		if ( ! empty( $global_key ) ) {
			$api_url = add_query_arg(
				array(
					'license_key' => $global_key,
					'license_url' => home_url(),
					'action'      => 'activate',
				),
				'https://my.gravitywp.com/wp-json/paddlepress-api/v1/license'
			);

			$response = wp_remote_get( esc_url_raw( $api_url ), array( 'timeout' => 15 ) );

			if ( is_wp_error( $response ) ) {
				$error_message = 'Network Error: ' . $response->get_error_message();
			} else {
				$code    = wp_remote_retrieve_response_code( $response );
				$body    = wp_remote_retrieve_body( $response );
				$headers = wp_remote_retrieve_headers( $response );

				// Check for block by Cloudflare.
				if ( $code === 403 && strpos( strtolower( $body ), 'cloudflare' ) !== false ) {
					$error_message = nl2br( 'Access to the license server was denied by Cloudflare. This happens when malicious activity was detected from your website\'s outgoing IP address. This often happens on shared hosting where other users use the same IP address for malicious activity. Contact your hosting provider to resolve this issue.' );
				} else {
					switch ( $code ) {
						case 200:
							$decoded = json_decode( $body, true );
							if ( is_array( $decoded ) && ! empty( $decoded['success'] ) && $decoded['license_status'] === 'valid' ) {
								$data = $decoded;
								if ( class_exists( '\GravityWP\Shared\Global_License_Key_Loader' ) ) {
									$gwp_addons = \GravityWP\Shared\Global_License_Key_Loader::get_registered_license_handlers();
									foreach ( $gwp_addons as $gwp_addon ) {
										if ( class_exists( $gwp_addon['gwp_addon_class'] ) ) {
											$gwp_addon_slug = $gwp_addon['gwp_addon_class']::get_instance()->get_slug();
											\GFCommon::remove_dismissible_message( $gwp_addon_slug . '_license_message_notice' );
										}
									}
								}
							} else {
								$error_message = 'Invalid license.';
								self::extract_error_details( $decoded, $error_details );
							}
							break;
						case 500:
							$error_message = 'Server Error: The server encountered an internal error. Please try again later.';
							break;
						default:
							// Format additional information into a string for logging or support use.
							$extra_info    = sprintf(
								"HTTP Status Code: %d\nResponse Headers: %s",
								$code,
								wp_json_encode( $headers )
							);
							$error_message = nl2br( sprintf( 'An unexpected error occurred. Please try again later. If the issue persists, provide the following information to support: %s', esc_html( $extra_info ) ) );
							self::extract_error_details( json_decode( $body, true ), $error_details );
							break;
					}
				}
			}
		}
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html__( 'GravityWP Settings', 'gravitywp-license-handler' ); ?>
				<span style="font-size: 0.6em; color: #666; margin-left: 10px;">
					(<?php echo esc_html( self::$version ); ?>)
				</span>
			</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gravitywp_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="gravitywp_global_license_key">
								<?php echo esc_html__( 'Global License Key', 'gravitywp-license-handler' ); ?>
							</label>
						</th>
						<td>
							<input type="password" name="gravitywp_global_license_key" value="<?php echo esc_attr( $global_key ); ?>" class="regular-text" />
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>
	
			<?php if ( $data ) : ?>
				<div class="license-info-box" style="margin-top: 30px; border: 1px solid #ccd0d4; background: #fefefe; padding: 20px; border-radius: 6px; max-width: 600px;">
					<h2 style="margin-top: 0; color: #2271b1;">
						<span class="dashicons dashicons-yes" style="color: #46b450; vertical-align: middle;"></span>
						<?php esc_html_e( 'License Details', 'gravitywp-license-handler' ); ?>
					</h2>
					<ul style="list-style: none; padding-left: 0;">
						<li><strong><?php esc_html_e( 'Status', 'gravitywp-license-handler' ); ?>:</strong> <?php echo esc_html( $data['license_status'] ); ?></li>
						<li><strong><?php esc_html_e( 'License Expires', 'gravitywp-license-handler' ); ?>:</strong> <?php echo esc_html( $data['expires'] ); ?></li>
						<li><strong><?php esc_html_e( 'License Limit', 'gravitywp-license-handler' ); ?>:</strong> <?php echo esc_html( $data['license_limit'] ); ?></li>
						<li><strong><?php esc_html_e( 'Activated Sites', 'gravitywp-license-handler' ); ?>:</strong> <?php echo esc_html( $data['site_count'] ); ?></li>
						<li><strong><?php esc_html_e( 'Activations Left', 'gravitywp-license-handler' ); ?>:</strong> <?php echo esc_html( $data['activations_left'] ); ?></li>
					</ul>
				</div>
			<?php elseif ( ! empty( $global_key ) ) : ?>
				<div class="license-error-box" style="margin-top: 30px; border: 1px solid #dc3232; background: #fff0f0; padding: 20px; border-radius: 6px; max-width: 600px;">
					<h2 style="margin-top: 0; color: #dc3232;">
						<span class="dashicons dashicons-no" style="color: #dc3232; vertical-align: middle;"></span>
						<?php echo nl2br( esc_html( $error_message ) ); ?>
					</h2>
					<?php if ( $error_details ) : ?>
						<div style="margin-top: 10px; color: #a00;"><?php echo wp_kses_post( $error_details ); ?></div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}


	/**
	 * Extracts and formats error messages from a decoded license response.
	 *
	 * Populates the passed-in variable with sanitized, HTML-formatted error details.
	 *
	 * @param array  $decoded       The decoded response containing possible errors.
	 * @param string $error_details The variable to store extracted and formatted error messages.
	 *
	 * @return void
	 */
	protected static function extract_error_details( $decoded, &$error_details ) {
		if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			$details = array();
			foreach ( $decoded['errors'] as $key => $messages ) {
				foreach ( (array) $messages as $msg ) {
					$details[] = esc_html( $msg );
				}
			}
			$error_details = implode( '<br>', $details );
		}
	}
}
