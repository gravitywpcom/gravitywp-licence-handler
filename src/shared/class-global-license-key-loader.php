<?php
/**
 * Global License Key Loader class file.
 *
 * Handles dynamic loading of the last available version of a shared component
 * across GravityWP plugins and extensions.
 *
 * @package GravityWP\Shared
 */

namespace GravityWP\Shared;

/*
 * Check if the Global_License_Key_Loader class has already been defined.
 * If not, define it to avoid redeclaration issues.
 */
if ( ! class_exists( '\GravityWP\Shared\Global_License_Key_Loader' ) ) {
	/**
	 * Class Global_License_Key_Loader
	 *
	 * Responsible for registering and loading the last version of a shared component.
	 */
	class Global_License_Key_Loader {
		/**
		 * Holds registered candidate versions with their file paths.
		 *
		 * @var array
		 */
		private static $candidates = array();
		/**
		 * Registers a version and its corresponding file path.
		 *
		 * This is called by various plugins or components that depend on this loader.
		 *
		 * @param string $version   The version number of the candidate.
		 * @param string $file_path The absolute path to the candidate file.
		 * @param string $gwp_addon_class The GF Addon class name.
		 *
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
		 * Loads the last (highest) version from the registered candidates.
		 *
		 * @return void
		 */
		public static function load_last_version() {
			if ( empty( self::$candidates ) ) {
				return;
			}

			/*
			 * Sort candidates in descending order by version number.
			 */
			usort(
				self::$candidates,
				function ( $a, $b ) {
					return version_compare( $b['version'], $a['version'] );
				}
			);

			$last = self::$candidates[0];
			require_once $last['file'];

			if ( class_exists( '\GravityWP\Shared\Global_License_Key_Registry' ) ) {
				\GravityWP\Shared\Global_License_Key_Registry::init( $last['version'] ); // Load the UI and logic.
			}
		}
		/**
		 * Returns the candidates array.
		 *
		 * @return array
		 */
		public static function get_registered_license_handlers() {
			return self::$candidates;
		}
	}

	add_action( 'init', array( '\GravityWP\Shared\Global_License_Key_Loader', 'load_last_version' ), 999 );
}
