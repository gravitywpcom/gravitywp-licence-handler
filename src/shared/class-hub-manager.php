<?php
/**
 * Hub Manager — Centralized cache for all GravityWP plugin data.
 *
 * Replaces per-plugin API calls with a single /hub request.
 * Supports BOTH a global license key AND per-plugin keys (for Single Add-on plans).
 *
 * Before: 10 plugins × 2 calls/day = 20 requests/day per customer.
 * After:  1 hub request every 12 hours = 2 requests/day per customer.
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Hub_Manager' ) ) {

	/**
	 * Class Hub_Manager
	 *
	 * Singleton that manages the centralized hub cache with multi-key support.
	 */
	class Hub_Manager {

		/**
		 * Hub API endpoint.
		 *
		 * @var string
		 */
		private static $hub_api_url = 'https://my.gravitywp.com/wp-json/paddlepress-api/v1/hub';

		/**
		 * Cache key in wp_options.
		 *
		 * @var string
		 */
		const CACHE_KEY = 'gravitywp_hub_cache';

		/**
		 * Cache version — bump to invalidate all caches when the response structure changes.
		 *
		 * @var string
		 */
		const CACHE_VERSION = '2.1.0';

		/**
		 * Cache TTL in seconds (12 hours).
		 *
		 * @var int
		 */
		const CACHE_TTL = 43200;

		/**
		 * Lock key to prevent concurrent API calls.
		 *
		 * @var string
		 */
		const LOCK_KEY = 'gravitywp_hub_lock';

		/**
		 * Option name for the global license key.
		 *
		 * @var string
		 */
		const GLOBAL_KEY_OPTION = 'gravitywp_global_license_key';

		/**
		 * Option name for per-plugin license keys (associative array).
		 *
		 * @var string
		 */
		const PLUGIN_KEYS_OPTION = 'gravitywp_plugin_license_keys';

		/**
		 * Get hub data — from cache if fresh, otherwise from API.
		 *
		 * @param bool $force_refresh Force a fresh API call (ignores cache).
		 *
		 * @return array|false Hub data array or false on failure.
		 */
		public static function get_hub_data( $force_refresh = false ) {
			if ( ! $force_refresh ) {
				$cached = self::get_cache();
				if ( false !== $cached ) {
					return $cached;
				}
			}
			return self::fetch_and_cache();
		}

		/**
		 * Get cached hub data if it's still fresh and matches current cache version.
		 *
		 * @return array|false Cached data or false if expired/missing/stale.
		 */
		private static function get_cache() {
			$cache = get_option( self::CACHE_KEY, false );

			if ( empty( $cache ) || ! is_array( $cache ) ) {
				return false;
			}

			// Invalidate caches from older versions (structure changed).
			if ( ! isset( $cache['version'] ) || self::CACHE_VERSION !== $cache['version'] ) {
				return false;
			}

			if ( ! isset( $cache['timestamp'] ) || ( time() - $cache['timestamp'] ) > self::CACHE_TTL ) {
				return false;
			}

			return $cache['data'] ?? false;
		}

		/**
		 * Fetch fresh data from the Hub API and cache it.
		 *
		 * @return array|false Hub data array or false on failure.
		 */
		private static function fetch_and_cache() {
			// Prevent concurrent requests.
			if ( get_transient( self::LOCK_KEY ) ) {
				$stale = get_option( self::CACHE_KEY, false );
				return ( ! empty( $stale['data'] ) ) ? $stale['data'] : self::get_empty_response();
			}

			set_transient( self::LOCK_KEY, true, 60 );

			$global_key          = get_option( self::GLOBAL_KEY_OPTION, '' );
			$plugin_license_keys = get_option( self::PLUGIN_KEYS_OPTION, array() );
			if ( ! is_array( $plugin_license_keys ) ) {
				$plugin_license_keys = array();
			}

			// Remove empty keys.
			$plugin_license_keys = array_filter( $plugin_license_keys );

			// No keys at all — return empty structure without hitting API.
			if ( empty( $global_key ) && empty( $plugin_license_keys ) ) {
				delete_transient( self::LOCK_KEY );
				$empty = self::get_empty_response();
				self::save_cache( $empty );
				return $empty;
			}

			$body = array(
				'license_url' => home_url(),
			);

			if ( ! empty( $global_key ) ) {
				$body['license_key'] = $global_key;
			}

			if ( ! empty( $plugin_license_keys ) ) {
				$body['plugin_license_keys'] = $plugin_license_keys;
			}

			$args = array(
				'body'      => $body,
				'timeout'   => 20,
				'sslverify' => (bool) apply_filters( 'paddlepress_api_request_verify_ssl', true ),
			);

			$response = wp_remote_post( self::$hub_api_url, $args );
			delete_transient( self::LOCK_KEY );

			if ( is_wp_error( $response ) ) {
				return self::return_stale_or_empty();
			}

			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );

			if ( 403 === $code && strpos( strtolower( $raw ), 'cloudflare' ) !== false ) {
				return self::return_stale_or_empty();
			}

			if ( 200 !== $code ) {
				return self::return_stale_or_empty();
			}

			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				return self::return_stale_or_empty();
			}

			self::save_cache( $data );
			return $data;
		}

		/**
		 * Return an empty but valid hub data structure.
		 *
		 * @return array
		 */
		private static function get_empty_response() {
			return array(
				'success' => false,
				'license' => array(
					'global'     => array(
						'status'    => 'no_key',
						'plan_type' => Plan_Types::UNKNOWN,
						'plan_name' => '',
						'expires'   => '',
						'errors'    => array(),
					),
					'per_plugin' => array(),
				),
				'plugins' => array(),
			);
		}

		/**
		 * Return stale cache if available, else empty response.
		 *
		 * @return array
		 */
		private static function return_stale_or_empty() {
			$stale = get_option( self::CACHE_KEY, false );
			return ( ! empty( $stale['data'] ) ) ? $stale['data'] : self::get_empty_response();
		}

		/**
		 * Save data to the cache.
		 *
		 * @param array $data Hub data to cache.
		 * @return void
		 */
		private static function save_cache( $data ) {
			update_option(
				self::CACHE_KEY,
				array(
					'version'   => self::CACHE_VERSION,
					'timestamp' => time(),
					'data'      => $data,
				),
				'no'
			);
		}

		/**
		 * Clear the hub cache.
		 *
		 * @return void
		 */
		public static function clear_cache() {
			delete_option( self::CACHE_KEY );
			delete_transient( self::LOCK_KEY );
		}

		/**
		 * Get the global license info from the hub data.
		 *
		 * @return array License info for the global key, or empty array.
		 */
		public static function get_global_license_info() {
			$data = self::get_hub_data();
			return ( ! empty( $data['license']['global'] ) ) ? $data['license']['global'] : array();
		}

		/**
		 * Get per-plugin license info from the hub data.
		 *
		 * @return array Map of [slug => license_info] or empty array.
		 */
		public static function get_per_plugin_license_info() {
			$data = self::get_hub_data();
			$info = $data['license']['per_plugin'] ?? array();
			if ( is_object( $info ) ) {
				$info = (array) $info;
			}
			return is_array( $info ) ? $info : array();
		}

		/**
		 * Get ALL plugins data from the hub.
		 *
		 * @return array Array of plugin data.
		 */
		public static function get_all_plugins() {
			$data = self::get_hub_data();
			return ( ! empty( $data['plugins'] ) ) ? $data['plugins'] : array();
		}

		/**
		 * Get data for a specific plugin by slug.
		 *
		 * @param string $slug Plugin slug (download_tag).
		 * @return array|false Plugin data or false if not found.
		 */
		public static function get_plugin_data( $slug ) {
			$plugins = self::get_all_plugins();

			foreach ( $plugins as $plugin ) {
				if ( isset( $plugin['slug'] ) && $plugin['slug'] === $slug ) {
					return $plugin;
				}
			}
			return false;
		}

		/**
		 * Check if a specific plugin is accessible (license covers it).
		 *
		 * @param string $slug Plugin slug.
		 * @return bool True if accessible.
		 */
		public static function has_access( $slug ) {
			$plugin = self::get_plugin_data( $slug );
			return ( ! empty( $plugin['has_access'] ) );
		}

		/**
		 * Get the access source for a plugin ('global' | 'per_plugin' | 'none').
		 *
		 * @param string $slug Plugin slug.
		 * @return string
		 */
		public static function get_access_source( $slug ) {
			$plugin = self::get_plugin_data( $slug );
			return ( ! empty( $plugin['access_source'] ) ) ? $plugin['access_source'] : 'none';
		}

		/**
		 * Check if the global license is valid.
		 *
		 * @return bool
		 */
		public static function is_global_license_valid() {
			$license = self::get_global_license_info();
			return ( ! empty( $license['status'] ) && 'valid' === $license['status'] );
		}

		/**
		 * Check if ANY license (global or per-plugin) is valid.
		 *
		 * @return bool
		 */
		public static function has_any_valid_license() {
			if ( self::is_global_license_valid() ) {
				return true;
			}

			$per_plugin = self::get_per_plugin_license_info();
			foreach ( $per_plugin as $info ) {
				if ( ! empty( $info['status'] ) && 'valid' === $info['status'] ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Get the plan type of the global license.
		 *
		 * @return string Plan type constant.
		 */
		public static function get_global_plan_type() {
			$license = self::get_global_license_info();
			return $license['plan_type'] ?? Plan_Types::UNKNOWN;
		}

		/**
		 * Get the time remaining until cache expires (seconds).
		 *
		 * @return int
		 */
		public static function get_cache_ttl_remaining() {
			$cache = get_option( self::CACHE_KEY, false );
			if ( empty( $cache['timestamp'] ) ) {
				return 0;
			}
			$remaining = self::CACHE_TTL - ( time() - $cache['timestamp'] );
			return max( 0, $remaining );
		}

		/**
		 * Count unlocked and locked plugins from the cache.
		 *
		 * @return array ['unlocked' => int, 'locked' => int, 'total' => int]
		 */
		public static function get_plugin_counts() {
			$plugins  = self::get_all_plugins();
			$unlocked = 0;
			$locked   = 0;

			foreach ( $plugins as $plugin ) {
				if ( ! empty( $plugin['has_access'] ) ) {
					++$unlocked;
				} else {
					++$locked;
				}
			}

			return array(
				'unlocked' => $unlocked,
				'locked'   => $locked,
				'total'    => count( $plugins ),
			);
		}
	}

	// Clear hub cache when any license key setting changes.
	add_action(
		'update_option_' . Hub_Manager::GLOBAL_KEY_OPTION,
		array( Hub_Manager::class, 'clear_cache' )
	);
	add_action(
		'update_option_' . Hub_Manager::PLUGIN_KEYS_OPTION,
		array( Hub_Manager::class, 'clear_cache' )
	);
	add_action(
		'add_option_' . Hub_Manager::GLOBAL_KEY_OPTION,
		array( Hub_Manager::class, 'clear_cache' )
	);
	add_action(
		'add_option_' . Hub_Manager::PLUGIN_KEYS_OPTION,
		array( Hub_Manager::class, 'clear_cache' )
	);
}
