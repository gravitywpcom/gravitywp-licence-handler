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
		$global_key = get_option( 'gravitywp_global_license_key', '' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'GravityWP Settings', 'gravitywp-license-handler' ); ?>  <span style="font-size: 0.6em; color: #666; margin-left: 10px;">(<?php echo esc_html( self::$version ); ?>) </h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gravitywp_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="gravitywp_global_license_key"><?php echo esc_html__( 'Global License Key ', 'gravitywp-license-handler' ); ?></label></th>
						<td>
							<input type="password" name="gravitywp_global_license_key" value="<?php echo esc_attr( $global_key ); ?>" class="regular-text" />
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>
		</div>
		<?php
	}
}
