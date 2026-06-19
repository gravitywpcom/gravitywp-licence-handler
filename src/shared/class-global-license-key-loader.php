<?php
/**
 * Global License Key Loader.
 *
 * Dynamically loads the highest-version shared components across GravityWP
 * plugins. Each plugin bundles its own copy of the license handler; this
 * loader picks the newest version to avoid conflicts.
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Global_License_Key_Loader' ) ) {

	/**
	 * Class Global_License_Key_Loader
	 */
	class Global_License_Key_Loader {

		/**
		 * Registered candidate versions.
		 *
		 * @var array<int, array{version: string, file: string, addon_class: string}>
		 */
		private static $candidates = array();

		/**
		 * Register a version and its file path.
		 *
		 * @param string $version         Version number.
		 * @param string $file_path       Absolute path to the registry file.
		 * @param string $gwp_addon_class GF addon class name.
		 * @return void
		 */
		public static function register( $version, $file_path, $gwp_addon_class = '' ) {
			self::$candidates[] = array(
				'version'     => $version,
				'file'        => $file_path,
				'addon_class' => $gwp_addon_class,
			);
		}

		/**
		 * Load the highest version of each shared component.
		 *
		 * Load order:
		 * 1. Plan_Types (constants used by everything)
		 * 2. Hub_Manager (cache; used by Registry)
		 * 3. Hub_Page (plugin card render helpers; used by Registry)
		 * 3b. Hub_Ajax (admin-ajax handlers for Install/Activate/Deactivate)
		 * 4. Registry (the unified GravityWP page — registers menu)
		 *
		 * Since v2.1.0, Hub_Page no longer registers its own menu. Both the
		 * catalog and license keys live on a single page rendered by Registry.
		 *
		 * @return void
		 */
		public static function load_last_version() {
			if ( empty( self::$candidates ) ) {
				return;
			}

			// Sort candidates descending by version.
			usort(
				self::$candidates,
				function ( $a, $b ) {
					return version_compare( $b['version'], $a['version'] );
				}
			);

			$last     = self::$candidates[0];
			$base_dir = dirname( $last['file'] );

			// 1. Plan_Types — constants/labels used by everything.
			$plan_types_file = $base_dir . '/class-plan-types.php';
			if ( file_exists( $plan_types_file ) ) {
				require_once $plan_types_file;
			}

			// 1b. Api_Error_Handler — centralized error catalog. Depends on
			// Plan_Types; consumed by Hub_Manager, Hub_Ajax, and Registry.
			// Must load before Hub_Manager so the classifier is available
			// during the very first hub call.
			$api_error_file = $base_dir . '/class-api-error-handler.php';
			if ( file_exists( $api_error_file ) ) {
				require_once $api_error_file;
			}

			// 2. Hub_Manager — cache layer used by Registry.
			$hub_manager_file = $base_dir . '/class-hub-manager.php';
			if ( file_exists( $hub_manager_file ) ) {
				require_once $hub_manager_file;
			}

			// 3. Hub_Page — plugin card render helpers, called from Registry.
			$hub_page_file = $base_dir . '/class-hub-page.php';
			if ( file_exists( $hub_page_file ) ) {
				require_once $hub_page_file;
				if ( class_exists( '\GravityWP\Shared\Hub_Page' ) ) {
					\GravityWP\Shared\Hub_Page::init(); // No-op since v2.1.0.
				}
			}

			// 3b. Hub_Ajax — admin-ajax handlers for install/activate/deactivate.
			$hub_ajax_file = $base_dir . '/class-hub-ajax.php';
			if ( file_exists( $hub_ajax_file ) ) {
				require_once $hub_ajax_file;
				if ( class_exists( '\GravityWP\Shared\Hub_Ajax' ) ) {
					\GravityWP\Shared\Hub_Ajax::init( $last['addon_class'] );
				}
			}

			// 4. Registry — the unified GravityWP page (registers menu, renders UI).
			require_once $last['file'];
			if ( class_exists( '\GravityWP\Shared\Global_License_Key_Registry' ) ) {
				\GravityWP\Shared\Global_License_Key_Registry::init( $last['version'], $base_dir );
			}
		}

		/**
		 * Return all registered license handlers.
		 *
		 * @return array
		 */
		public static function get_registered_license_handlers() {
			return self::$candidates;
		}
	}

	add_action( 'init', array( '\GravityWP\Shared\Global_License_Key_Loader', 'load_last_version' ), 999 );
}
