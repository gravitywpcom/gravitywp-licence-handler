<?php
/**
 * GravityWP License handler.
 *
 * @package gravitywp-license-handler
 * @license MIT
 */

namespace GravityWP\LicenseHandler;

use GFCommon;
use GravityWP\Updater\Plugin_Updater;

defined( 'ABSPATH' ) || die();

/**
 * Handles GravityWP Licenses.
 *
 * Since v2.1.0:
 * - Supports BOTH a Global License Key (All Access, List Add-ons, Agency)
 *   AND per-plugin Individual Keys (Single Add-on plans).
 * - Uses the Hub_Manager cache for zero-extra-API-call license checks.
 * - Auto-migrates legacy per-plugin keys from addon settings.
 *
 * @version 2.1.0
 */
class LicenseHandler {

	/**
	 * Update endpoint of the API
	 *
	 * @var string
	 */
	private $api_url = '';

	/**
	 * HTTP parameters on API requests
	 *
	 * @var array
	 */
	private $api_data = array();

	/**
	 * Plugin Name
	 *
	 * @var string
	 */
	private $name = '';

	/**
	 * Whether update from the beta version or not
	 *
	 * @var bool
	 */
	private $beta;

	/**
	 * Current version of plugin
	 *
	 * @var string
	 */
	private $version = '2.1.0';

	/**
	 * WP Override flag
	 *
	 * @var bool
	 */
	private $wp_override = false;

	/**
	 * Cache key
	 *
	 * @var string
	 */
	private $cache_key = '';

	/**
	 * Product slug
	 *
	 * @var string
	 */
	private $_download_tag = '';

	/**
	 * Health check timeout
	 *
	 * @var int
	 */
	private $health_check_timeout = 5;

	/**
	 * Store the initialized paddlepress_pro client
	 *
	 * @since  1.0
	 * @access private
	 * @var    Object $_paddlepress_client The paddlepress_pro client instance.
	 */
	private $_paddlepress_client = null;

	/**
	 * Store the initialized paddlepress_pro license handler
	 *
	 * @since  1.0
	 * @access private
	 * @var    Object $_license_handler The Plugin_Updater instance.
	 */
	private $_license_handler = null;

	/**
	 * Store the GravityWP GF Addon classname
	 *
	 * @since  1.0
	 * @access private
	 * @var    string $_addon_class the GravityWP GF Addon classname.
	 */
	private $_addon_class = '';

	/**
	 * Store the GravityWP GF Addon slug
	 *
	 * @since  1.0
	 * @access private
	 * @var    string $_addon_slug the GravityWP GF Addon slug.
	 */
	private $_addon_slug = '';

	/**
	 * Store the GravityWP Global License Key.
	 *
	 * @since  2.1.0
	 * @access private
	 * @var    string $_global_license_key Global License Key.
	 */
	private $_global_license_key = '';

	/**
	 * Store the GravityWP GF Addon title
	 *
	 * @since  1.0
	 * @access private
	 * @var    string $_addon_title the GravityWP GF Addon title.
	 */
	private $_addon_title = '';

	/**
	 * Store the GravityWP GF Addon path
	 *
	 * @since  1.0
	 * @access private
	 * @var    string $_addon_file_path The GravityWP GF Addon path.
	 */
	private $_addon_file_path = '';

	/**
	 * Store the GravityWP constants
	 *
	 * @since  1.0
	 * @access protected
	 * @var    array<mixed>|string|false|null $_gwp_get_defined_constants The GravityWP constants.
	 */
	protected $_gwp_get_defined_constants;

	/**
	 * Constructor.
	 *
	 * @since 1.0
	 *
	 * @param string $gwp_addon_class  GravityWP GF Addon classname.
	 * @param string $plugin_file_path Path to main plugin file.
	 *
	 * @return void
	 */
	public function __construct( $gwp_addon_class, $plugin_file_path ) {
		// Load the loader class (only once).
		if ( ! class_exists( '\GravityWP\Shared\Global_License_Key_Loader' ) ) {
			require_once __DIR__ . '/shared/class-global-license-key-loader.php';
		}

		if ( $this->version ) {
			// Register this plugin's version.
			\GravityWP\Shared\Global_License_Key_Loader::register( $this->version, __DIR__ . '/shared/class-global-license-key-registry.php', $gwp_addon_class );
		}

		$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;
		if ( ! ( current_user_can( 'gform_full_access' ) || current_user_can( 'gravityforms_edit_settings' ) || current_user_can( 'gravityforms_view_settings' ) ) && ! $doing_cron ) {
			return;
		}
		$this->_addon_class     = $gwp_addon_class;
		$this->_addon_file_path = $plugin_file_path;
		$this->_addon_slug      = $gwp_addon_class::get_instance()->get_slug();
		$this->_addon_title     = $gwp_addon_class::get_instance()->plugin_page_title();

		// Auto-migrate: if no Global Key exists, try to find a per-plugin key in DB and promote it.
		$this->maybe_migrate_legacy_license_key();

		$this->_global_license_key = get_option( 'gravitywp_global_license_key', '' );
		$this->initialize_paddlepress_client();
	}

	/**
	 * Auto-migrate a legacy per-plugin license key.
	 *
	 * Migration strategy (v2.1.0):
	 * 1. Read the legacy per-plugin key from Gravity Forms addon settings
	 * 2. If Global Key is empty → promote to Global Key (first-wins)
	 * 3. Otherwise → store in the per-plugin keys map (gravitywp_plugin_license_keys)
	 *    This preserves Single Add-on licenses when a user also has a Global key
	 * 4. Clean up the old per-plugin key from addon settings
	 *
	 * Runs once per addon, tracked via `gravitywp_migrated_{slug}` flag.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	private function maybe_migrate_legacy_license_key() {
		$migration_flag = 'gravitywp_migrated_' . $this->_addon_slug;
		if ( get_option( $migration_flag, false ) ) {
			return; // Already migrated.
		}

		// Read the legacy per-plugin license key from addon settings.
		$legacy_key = $this->_addon_class::get_instance()->get_plugin_setting(
			$this->_addon_slug . '_license_key'
		);

		if ( ! empty( $legacy_key ) ) {
			$current_global = get_option( 'gravitywp_global_license_key', '' );

			if ( empty( $current_global ) ) {
				// No Global Key yet — promote this per-plugin key to Global.
				update_option( 'gravitywp_global_license_key', $legacy_key );
			} else {
				// Global Key already exists — keep the per-plugin key as an individual key.
				// This handles the case where user has BOTH an All Access and a Single Add-on.
				$plugin_keys = get_option( 'gravitywp_plugin_license_keys', array() );
				if ( ! is_array( $plugin_keys ) ) {
					$plugin_keys = array();
				}
				// Only set if not already present (don't overwrite a deliberately set key).
				if ( empty( $plugin_keys[ $this->_addon_slug ] ) && $legacy_key !== $current_global ) {
					$plugin_keys[ $this->_addon_slug ] = $legacy_key;
					update_option( 'gravitywp_plugin_license_keys', $plugin_keys );
				}
			}

			// Clean up the old per-plugin key from addon settings.
			$addon_instance = $this->_addon_class::get_instance();
			$settings       = $addon_instance->get_plugin_settings();
			if ( is_array( $settings ) && isset( $settings[ $this->_addon_slug . '_license_key' ] ) ) {
				unset( $settings[ $this->_addon_slug . '_license_key' ] );
				$addon_instance->update_plugin_settings( $settings );
			}
		}

		update_option( $migration_flag, true, 'no' );
	}

	/**
	 * Initialize or reinitialize the Paddlepress client.
	 *
	 * Since v2.1.0: Uses either the Global License Key OR a per-plugin key
	 * (from the per-plugin keys map), whichever is applicable for this addon.
	 *
	 * Key resolution order:
	 * 1. Check if this addon has a dedicated per-plugin key in gravitywp_plugin_license_keys
	 * 2. Fall back to the Global License Key
	 *
	 * @param string|null $field_setting Deprecated. Kept for backward compatibility.
	 * @return bool True if initialization succeeded, false otherwise.
	 */
	public function initialize_paddlepress_client( $field_setting = null ) {
		try {
			unset( $this->_paddlepress_client );
			unset( $this->_license_handler );

			// Resolve which license key to use for this specific addon.
			$license_key = $this->resolve_license_key_for_addon();

			$this->_license_handler = new Plugin_Updater(
				$this->_addon_file_path,
				array(
					'version'       => $this->_addon_class::get_instance()->get_version(),
					'license_key'   => $license_key,
					'license_url'   => home_url(),
					'download_tag'  => $this->_addon_slug,
					'beta'          => false,
					'handler_class' => $this,
				)
			);

			// Determine access using Hub_Manager if available, else fall back to direct check.
			$has_access = false;
			if ( class_exists( '\GravityWP\Shared\Hub_Manager' ) ) {
				$has_access = \GravityWP\Shared\Hub_Manager::has_access( $this->_addon_slug );
			} elseif ( ! empty( $license_key ) ) {
				$has_access = (bool) $this->_license_handler->gwp_is_valid( true, $license_key );
			}

			if ( $has_access ) {
				remove_action( 'admin_notices', array( $this, 'action_admin_notices' ) );
			} else {
				add_action( 'admin_notices', array( $this, 'action_admin_notices' ) );
			}
		} catch ( \Exception $e ) {
			$this->_addon_class::get_instance()->log_error( __CLASS__ . '::' . __METHOD__ . '(): License client failed to initialize: ' . $e->getMessage() );
			return false;
		}

		return true;
	}

	/**
	 * Resolve which license key to use for this specific addon.
	 *
	 * Resolution order:
	 * 1. Per-plugin key in gravitywp_plugin_license_keys[slug] (v2.1.0 Individual Keys)
	 * 2. Global key in gravitywp_global_license_key (v2.1.0 Global Key)
	 * 3. Legacy per-plugin key from addon settings: {slug}_license_key (v2.0.x compat)
	 *    → If found here, auto-migrates it to the appropriate new storage
	 *
	 * The 3rd source handles the case where a customer installed a plugin,
	 * entered the key in the old per-plugin settings field, and hasn't
	 * visited the new GravityWP page yet. The key is picked up automatically
	 * and domain activation happens on the next hub request or gwp_is_valid() call.
	 *
	 * @since 2.1.0
	 * @return string The license key to use (may be empty if neither is set).
	 */
	private function resolve_license_key_for_addon() {
		// 1. Check for per-plugin key (v2.1.0 Individual Keys).
		$plugin_keys = get_option( 'gravitywp_plugin_license_keys', array() );
		if ( is_array( $plugin_keys ) && ! empty( $plugin_keys[ $this->_addon_slug ] ) ) {
			return (string) $plugin_keys[ $this->_addon_slug ];
		}

		// 2. Check for global key (v2.1.0 Global Key).
		$this->_global_license_key = get_option( 'gravitywp_global_license_key', '' );
		if ( ! empty( $this->_global_license_key ) ) {
			return (string) $this->_global_license_key;
		}

		// 3. Legacy: check old per-plugin key from Gravity Forms addon settings.
		//    This covers customers who entered the key in the old plugin settings
		//    page and haven't migrated yet. We read it, USE it, AND auto-migrate it
		//    so the next request uses the new storage directly.
		$legacy_key = $this->read_legacy_addon_key();
		if ( ! empty( $legacy_key ) ) {
			$this->auto_migrate_discovered_key( $legacy_key );
			return (string) $legacy_key;
		}

		return '';
	}

	/**
	 * Read a legacy per-plugin license key from the addon's GF settings.
	 *
	 * This is the old v2.0.x storage: addon settings['{slug}_license_key'].
	 *
	 * @since 2.1.0
	 * @return string The legacy key, or empty string if not found.
	 */
	private function read_legacy_addon_key() {
		if ( empty( $this->_addon_class ) ) {
			return '';
		}

		try {
			$addon = $this->_addon_class::get_instance();
			if ( method_exists( $addon, 'get_plugin_setting' ) ) {
				$key = $addon->get_plugin_setting( $this->_addon_slug . '_license_key' );
				if ( ! empty( $key ) && is_string( $key ) ) {
					return trim( $key );
				}
			}
		} catch ( \Exception $e ) {
			// Silently fail — addon might not be fully initialized yet.
		}

		return '';
	}

	/**
	 * Auto-migrate a discovered legacy key to the new v2.1.0 storage.
	 *
	 * If no global key exists, promote the legacy key to global.
	 * If a global key already exists, store as a per-plugin individual key.
	 * Then clean up the old setting to prevent duplicate reads.
	 *
	 * @since 2.1.0
	 * @param string $key The legacy key to migrate.
	 * @return void
	 */
	private function auto_migrate_discovered_key( $key ) {
		$current_global = get_option( 'gravitywp_global_license_key', '' );

		if ( empty( $current_global ) ) {
			// No global key → promote legacy key to global.
			update_option( 'gravitywp_global_license_key', $key );
		} else {
			// Global key exists → store as individual plugin key (if different).
			if ( $key !== $current_global ) {
				$plugin_keys = get_option( 'gravitywp_plugin_license_keys', array() );
				if ( ! is_array( $plugin_keys ) ) {
					$plugin_keys = array();
				}
				if ( empty( $plugin_keys[ $this->_addon_slug ] ) ) {
					$plugin_keys[ $this->_addon_slug ] = $key;
					update_option( 'gravitywp_plugin_license_keys', $plugin_keys );
				}
			}
		}

		// Clean up the old setting.
		try {
			$addon    = $this->_addon_class::get_instance();
			$settings = $addon->get_plugin_settings();
			if ( is_array( $settings ) && isset( $settings[ $this->_addon_slug . '_license_key' ] ) ) {
				unset( $settings[ $this->_addon_slug . '_license_key' ] );
				$addon->update_plugin_settings( $settings );
			}
		} catch ( \Exception $e ) {
			// Silently fail.
		}

		// Mark as migrated.
		update_option( 'gravitywp_migrated_' . $this->_addon_slug, true, 'no' );
	}

	/**
	 * Display an admin notice when no valid Global License Key is found.
	 *
	 * Since v2.1.0: Points to the GravityWP Settings page (Global Key)
	 * instead of per-plugin settings.
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function action_admin_notices() {
		$global_settings_url = admin_url( 'admin.php?page=gravitywp' );
		$hub_url             = $global_settings_url; // Single page now.
		$site_slug           = $this->_addon_class::get_instance()->gwp_site_slug;

		if ( ! empty( $site_slug ) ) {
			$purchase_url = "https://gravitywp.com/add-on/{$site_slug}/?utm_source=admin_notice&utm_medium=admin&utm_content=inactive&utm_campaign=Admin%20Notice";
		} else {
			$purchase_url = 'https://gravitywp.com/add-ons/?utm_source=admin_notice&utm_medium=admin&utm_content=inactive&utm_campaign=Admin%20Notice';
		}

		/* translators: %1$s: addon title, %2$s-%3$s: settings link tags, %4$s-%5$s: purchase link tags, %6$s: line break */
		$message = esc_html__( 'Your %1$s license has not been activated. This means you are missing out on security fixes, updates and support.%6$sEnter a Global key or Individual key in %2$sGravityWP Settings%3$s or %4$sget a license here%5$s', 'gravitywp-license-handler' );
		$message = sprintf(
			$message,
			$this->_addon_title,
			'<a href="' . esc_url( $global_settings_url ) . '" class="button button-primary">',
			'</a>',
			'<a href="' . esc_url( $purchase_url ) . '" class="button button-secondary">',
			'</a>',
			'<br /><br />'
		);

		$key = $this->_addon_slug . '_license_message_notice';

		GFCommon::add_dismissible_message( $message, $key, 'warning', false, true );
	}

	/**
	 * Define plugin settings fields.
	 *
	 * Since v2.1.0: Shows plan-type-aware license status with access source
	 * (global vs per-plugin key). No license input here — all key management
	 * is done in the GravityWP Settings page.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function plugin_settings_license_fields() {
		$slug                = $this->_addon_slug;
		$global_settings_url = admin_url( 'admin.php?page=gravitywp' );
		$hub_url             = $global_settings_url; // Single page now.

		$license_field = array(
			'title'  => esc_html__( 'License Status', 'gravitywp-license-handler' ),
			'fields' => array(
				array(
					'name' => 'license_status_info',
					'type' => 'html',
					'html' => function () use ( $slug, $global_settings_url, $hub_url ) {
						$settings_link = '<a href="' . esc_url( $global_settings_url ) . '">' . esc_html__( 'GravityWP', 'gravitywp-license-handler' ) . '</a>';
						$hub_link      = $settings_link; // Same page.

						$global_key  = get_option( 'gravitywp_global_license_key', '' );
						$plugin_keys = get_option( 'gravitywp_plugin_license_keys', array() );
						$has_own_key = is_array( $plugin_keys ) && ! empty( $plugin_keys[ $slug ] );

						// Use Hub_Manager for access check if available.
						$has_access    = false;
						$access_source = 'none';
						if ( class_exists( '\GravityWP\Shared\Hub_Manager' ) ) {
							$has_access    = \GravityWP\Shared\Hub_Manager::has_access( $slug );
							$access_source = \GravityWP\Shared\Hub_Manager::get_access_source( $slug );
						}

						// No keys at all.
						if ( empty( $global_key ) && ! $has_own_key ) {
							$message = sprintf(
								/* translators: %s: link to settings */
								esc_html__( 'No license key found. Enter a Global License Key (for All Access / Agency plans) or an Individual Key (for Single Add-ons) in %s.', 'gravitywp-license-handler' ),
								$settings_link
							);
							return self::render_status_html( $message, 'warning' );
						}

						// Access granted.
						if ( $has_access ) {
							if ( 'global' === $access_source ) {
								$message = sprintf(
									/* translators: %s: settings link */
									esc_html__( 'Active via Global License Key. Manage your license in %s.', 'gravitywp-license-handler' ),
									$settings_link
								);
							} elseif ( 'per_plugin' === $access_source ) {
								$message = sprintf(
									/* translators: %s: settings link */
									esc_html__( 'Active via Individual Plugin Key. Manage your keys in %s.', 'gravitywp-license-handler' ),
									$settings_link
								);
							} else {
								$message = esc_html__( 'License active for this plugin.', 'gravitywp-license-handler' );
							}
							return self::render_status_html( $message, 'success' );
						}

						// Key(s) exist but no access for this plugin.
						$message = sprintf(
							/* translators: %1$s: settings link, %2$s: hub link, %3$s: pricing link */
							esc_html__( 'Your current license does not cover this plugin. Check %1$s, view the full catalog at %2$s, or upgrade your plan on %3$s.', 'gravitywp-license-handler' ),
							$settings_link,
							$hub_link,
							'<a href="https://gravitywp.com/pricing/" target="_blank" rel="noopener">gravitywp.com</a>'
						);
						return self::render_status_html( $message, 'danger' );
					},
				),
			),
		);

		return $license_field;
	}

	/**
	 * Render a status message with an appropriate color/icon.
	 *
	 * @param string $message HTML-safe message.
	 * @param string $type    'success' | 'warning' | 'danger'.
	 * @return string HTML output.
	 */
	private static function render_status_html( $message, $type ) {
		$map = array(
			'success' => array( '#27ae60', 'dashicons-yes-alt' ),
			'warning' => array( '#f39c12', 'dashicons-info' ),
			'danger'  => array( '#dc3232', 'dashicons-warning' ),
		);
		$color = $map[ $type ][0] ?? '#666';
		$icon  = $map[ $type ][1] ?? 'dashicons-info';

		return sprintf(
			'<p style="font-size: 14px; color:%1$s; line-height: 1.6;"><span class="dashicons %2$s" style="vertical-align: middle; margin-right: 5px;"></span>%3$s</p>',
			esc_attr( $color ),
			esc_attr( $icon ),
			$message
		);
	}

	/**
	 * Handle license key validation.
	 *
	 * Since v2.1.0: Per-plugin license validation is removed.
	 * This method is kept as a no-op for backward compatibility
	 * in case any addon still references it.
	 *
	 * @since 1.0
	 *
	 * @param array  $field         The field properties.
	 * @param string $field_setting The submitted value.
	 */
	public function license_validation( $field, $field_setting ) {
		// No-op: Per-plugin license keys are no longer supported.
		// All license management is done via the Global License Key in GravityWP Settings.
	}

	/**
	 * Determine if the current addon has valid access for field feedback icon.
	 *
	 * Since v2.1.0: Checks via Hub_Manager if available, which handles both
	 * global and per-plugin key sources.
	 *
	 * @param string $value The current value of the license_key field.
	 * @param array  $field The field properties.
	 *
	 * @since 1.0
	 *
	 * @return bool|null
	 */
	public function license_feedback( $value, $field ) {
		// Prefer Hub_Manager check (supports both key types).
		if ( class_exists( '\GravityWP\Shared\Hub_Manager' ) ) {
			if ( \GravityWP\Shared\Hub_Manager::has_access( $this->_addon_slug ) ) {
				GFCommon::remove_dismissible_message( $this->_addon_slug . '_license_message_notice' );
				return true;
			}
			// If no key at all, return null (neutral state), else false (red).
			$global_key  = get_option( 'gravitywp_global_license_key', '' );
			$plugin_keys = get_option( 'gravitywp_plugin_license_keys', array() );
			$has_own_key = is_array( $plugin_keys ) && ! empty( $plugin_keys[ $this->_addon_slug ] );
			if ( empty( $global_key ) && ! $has_own_key ) {
				return null;
			}
			return false;
		}

		// Fallback: direct check with resolved key.
		$key = $this->resolve_license_key_for_addon();
		if ( empty( $key ) ) {
			return null;
		}

		if ( $this->_license_handler && $this->_license_handler->gwp_is_valid( true, $key ) ) {
			GFCommon::remove_dismissible_message( $this->_addon_slug . '_license_message_notice' );
			return true;
		}

		return false;
	}
}
