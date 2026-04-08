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
		 * Load order matters:
		 * 1. Plan_Types (used by Registry, Hub_Manager, Hub_Page)
		 * 2. Hub_Manager (used by Registry, Hub_Page)
		 * 3. Registry (settings page)
		 * 4. Hub_Page (catalog page)
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

			// 1. Load Plan_Types first — dependency of others.
			$plan_types_file = $base_dir . '/class-plan-types.php';
			if ( file_exists( $plan_types_file ) ) {
				require_once $plan_types_file;
			}

			// 2. Load Hub_Manager — dependency of Registry and Hub_Page.
			$hub_manager_file = $base_dir . '/class-hub-manager.php';
			if ( file_exists( $hub_manager_file ) ) {
				require_once $hub_manager_file;
			}

			// 3. Load Registry (settings page).
			require_once $last['file'];
			if ( class_exists( '\GravityWP\Shared\Global_License_Key_Registry' ) ) {
				\GravityWP\Shared\Global_License_Key_Registry::init( $last['version'], $base_dir );
			}

			// 4. Load Hub_Page (catalog page).
			$hub_page_file = $base_dir . '/class-hub-page.php';
			if ( file_exists( $hub_page_file ) ) {
				require_once $hub_page_file;
				if ( class_exists( '\GravityWP\Shared\Hub_Page' ) ) {
					\GravityWP\Shared\Hub_Page::init();
				}
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
