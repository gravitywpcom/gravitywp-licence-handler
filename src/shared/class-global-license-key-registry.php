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
					'glk_version' => esc_html( self::$version ),
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
										$gwp_addon_class = $gwp_addon['addon_class'] ?? '';
										if ( $gwp_addon_class && class_exists( $gwp_addon_class ) ) {
											$gwp_addon_slug = $gwp_addon_class::get_instance()->get_slug();
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
				<span class='gwp_settings_version'>
					(<?php echo esc_html( self::$version ); ?>)
				</span>
			</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gravitywp_settings_group' ); ?>					
				<div class="gwp-license-key-row">
					<label for="gravitywp_global_license_key" class="gwp-license-label">
						<?php echo esc_html__( 'Global License Key', 'gravitywp-license-handler' ); ?>
					</label>
					<div class="password-input-wrapper">
						<input type="password" id="gravitywp_global_license_key" name="gravitywp_global_license_key" class="gwp-license-input" value="<?php echo esc_attr( $global_key ); ?>">                
						<button type="button" class="toggle-visibility-button" data-target="gravitywp_global_license_key" aria-label="Show license key">
							<svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
							<svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
						</button>
					</div>
					<?php submit_button( 'Save Settings' ); ?>
				</div>				
			</form>	
			<?php if ( $data ) : ?>
				<div class="gwp-license-box">
					<div class="gwp-license-header">
						<span class="dashicons dashicons-yes"></span>
						<h2><?php esc_html_e( 'License Details', 'gravitywp-license-handler' ); ?></h2>
					</div>
					<div class="gwp-license-grid">
						<div class="gwp-license-item">
							<span class="label"><?php esc_html_e( 'Status', 'gravitywp-license-handler' ); ?></span>
							<span class="value <?php echo esc_attr( $data['license_status'] === 'valid' ? 'success' : 'error' ); ?>">
								<?php echo esc_html( ucfirst( $data['license_status'] ) ); ?>
							</span>
						</div>
						<div class="gwp-license-item">
							<span class="label"><?php esc_html_e( 'License Expires', 'gravitywp-license-handler' ); ?></span>
							<span class="value"><?php echo esc_html( $data['expires'] ?? '' ); ?></span>
						</div>
						<div class="gwp-license-item">
							<span class="label"><?php esc_html_e( 'License Limit', 'gravitywp-license-handler' ); ?></span>
							<span class="value"><?php echo esc_html( $data['license_limit'] ?? '' ); ?></span>
						</div>
						<div class="gwp-license-item">
							<span class="label"><?php esc_html_e( 'Activated Sites', 'gravitywp-license-handler' ); ?></span>
							<span class="value"><?php echo esc_html( $data['site_count'] ?? '' ); ?></span>
						</div>
						<div class="gwp-license-item">
							<span class="label"><?php esc_html_e( 'Activations Left', 'gravitywp-license-handler' ); ?></span>
							<span class="value"><?php echo esc_html( $data['activations_left'] ?? '' ); ?></span>
						</div>
					</div>
				</div>
				<?php elseif ( ! empty( $global_key ) ) : ?>
					<div class="gwp-license-error-box">
						<div class="gwp-license-error-header">
							<span class="dashicons dashicons-no"></span>
							<h2><?php echo nl2br( esc_html( $error_message ) ); ?></h2>
						</div>
						<?php if ( $error_details ) : ?>
							<div class="gwp-license-error-details">
								<?php echo wp_kses_post( $error_details ); ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
		</div>
		<script>
			/**
			 * Accessible Password Visibility Toggle
			 *
			 * This script waits for the DOM to be fully loaded, then attaches
			 * event listeners to all password toggle buttons. It uses data-attributes
			 * for robust targeting and updates ARIA attributes for accessibility.
			 */
			document.addEventListener('DOMContentLoaded', () => {
				// Select all toggle buttons on the page
				const toggleButtons = document.querySelectorAll('.toggle-visibility-button');

				// Define the SVG icons for clarity
				const iconEye = document.querySelector('.icon-eye');
				const iconEyeOff = document.querySelector('.icon-eye-off');

				// Attach a click event listener to each button
				toggleButtons.forEach(button => {
					button.addEventListener('click', function() {
						// Get the ID of the target input from the data-target attribute
						const targetInputId = this.dataset.target;
						if (!targetInputId) {
							console.error('Button is missing a data-target attribute.');
							return;
						}

						const input = document.getElementById(targetInputId);
						if (!input) {
							console.error(`Input with ID "${targetInputId}" not found.`);
							return;
						}
						
						// Get the icons within this specific button
						const showIcon = this.querySelector('.icon-eye');
						const hideIcon = this.querySelector('.icon-eye-off');

						// Check the current state and toggle it
						const isPassword = input.type === 'password';
						
						if (isPassword) {
							// If it's a password, change to text
							input.type = 'text';
							this.setAttribute('aria-label', 'Hide license key');
							showIcon.style.display = 'none';
							hideIcon.style.display = 'block';
						} else {
							// If it's text, change back to password
							input.type = 'password';
							this.setAttribute('aria-label', 'Show license key');
							showIcon.style.display = 'block';
							hideIcon.style.display = 'none';
						}
					});
				});
			});
		</script>
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
