<?php
/**
 * Plan Types class file.
 *
 * Defines the plan type constants used by the GravityWP licensing system.
 * These constants match the ones defined on the server side by
 * GravityWP_Plan_Detector in mygravitywp-plugin.
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Plan_Types' ) ) {

	/**
	 * Class Plan_Types
	 *
	 * Constants and helper methods for GravityWP plan types.
	 */
	class Plan_Types {

		/**
		 * Plan type constants. Must match server-side values.
		 */
		const AGENCY       = 'agency';
		const ALL_ACCESS   = 'all_access';
		const LIST_ADDONS  = 'list_addons';
		const SINGLE_ADDON = 'single_addon';
		const UNKNOWN      = 'unknown';

		/**
		 * Get the display label for a plan type.
		 *
		 * @param string $plan_type One of the plan type constants.
		 * @return string Display label.
		 */
		public static function get_label( $plan_type ) {
			$labels = self::get_all_labels();
			return isset( $labels[ $plan_type ] ) ? $labels[ $plan_type ] : $labels[ self::UNKNOWN ];
		}

		/**
		 * Get all plan type labels.
		 *
		 * @return array<string, string>
		 */
		public static function get_all_labels() {
			return array(
				self::AGENCY       => __( 'Agency', 'gravitywp-license-handler' ),
				self::ALL_ACCESS   => __( 'All Access', 'gravitywp-license-handler' ),
				self::LIST_ADDONS  => __( 'List Add-ons', 'gravitywp-license-handler' ),
				self::SINGLE_ADDON => __( 'Single Add-on', 'gravitywp-license-handler' ),
				self::UNKNOWN      => __( 'Unknown', 'gravitywp-license-handler' ),
			);
		}

		/**
		 * Get the display icon (dashicon class) for a plan type.
		 *
		 * @param string $plan_type One of the plan type constants.
		 * @return string Dashicon class name.
		 */
		public static function get_icon( $plan_type ) {
			$icons = array(
				self::AGENCY       => 'dashicons-star-filled',
				self::ALL_ACCESS   => 'dashicons-unlock',
				self::LIST_ADDONS  => 'dashicons-list-view',
				self::SINGLE_ADDON => 'dashicons-admin-plugins',
				self::UNKNOWN      => 'dashicons-editor-help',
			);
			return isset( $icons[ $plan_type ] ) ? $icons[ $plan_type ] : $icons[ self::UNKNOWN ];
		}

		/**
		 * Get the display color for a plan type.
		 *
		 * @param string $plan_type One of the plan type constants.
		 * @return string Hex color code.
		 */
		public static function get_color( $plan_type ) {
			$colors = array(
				self::AGENCY       => '#8E44AD', // Purple (premium)
				self::ALL_ACCESS   => '#27AE60', // Green
				self::LIST_ADDONS  => '#3498DB', // Blue
				self::SINGLE_ADDON => '#F39C12', // Orange
				self::UNKNOWN      => '#95A5A6', // Gray
			);
			return isset( $colors[ $plan_type ] ) ? $colors[ $plan_type ] : $colors[ self::UNKNOWN ];
		}

		/**
		 * Get a human-readable description of a plan type.
		 *
		 * @param string $plan_type One of the plan type constants.
		 * @return string Description.
		 */
		public static function get_description( $plan_type ) {
			$descriptions = array(
				self::AGENCY       => __( 'All plugins unlocked with unlimited sites — for agencies managing multiple client sites.', 'gravitywp-license-handler' ),
				self::ALL_ACCESS   => __( 'All plugins unlocked. One license key, unlimited access to the entire catalog.', 'gravitywp-license-handler' ),
				self::LIST_ADDONS  => __( 'The List Add-ons bundle — unlocks all list field related plugins.', 'gravitywp-license-handler' ),
				self::SINGLE_ADDON => __( 'A single plugin license. You can combine multiple Single Add-on keys.', 'gravitywp-license-handler' ),
				self::UNKNOWN      => __( 'Plan type could not be determined.', 'gravitywp-license-handler' ),
			);
			return isset( $descriptions[ $plan_type ] ) ? $descriptions[ $plan_type ] : $descriptions[ self::UNKNOWN ];
		}

		/**
		 * Check if a plan type allows individual plugin licensing.
		 *
		 * Only Single Add-on plans support per-plugin keys.
		 *
		 * @param string $plan_type One of the plan type constants.
		 * @return bool
		 */
		public static function supports_per_plugin_keys( $plan_type ) {
			return self::SINGLE_ADDON === $plan_type;
		}
	}
}
