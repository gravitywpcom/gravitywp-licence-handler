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
		private static $hub_api_url = 'https://stg-mygravitywpcom-mygwpstage.kinsta.cloud/wp-json/paddlepress-api/v1/hub';

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
		const CACHE_VERSION = '2.3.0';

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
			// One-time: migrate any legacy keys found in old addon settings
			// into the new gravitywp_plugin_license_keys storage so they
			// show up in the UI AND get sent to the hub.
			self::maybe_migrate_legacy_keys();

			if ( ! $force_refresh ) {
				$cached = self::get_cache();
				if ( false !== $cached ) {
					return $cached;
				}
			}
			return self::fetch_and_cache();
		}

		/**
		 * Tracks whether legacy keys have been migrated this page load.
		 *
		 * @var bool
		 */
		private static $legacy_migrated = false;

		/**
		 * Migrate legacy per-plugin keys from old addon settings to new storage.
		 *
		 * Runs once per page load (on first call to get_hub_data). Scans ALL
		 * registered addons' GF settings for `{slug}_license_key` and copies
		 * any found keys into `gravitywp_plugin_license_keys` without overwriting
		 * keys the user already set in the new UI.
		 *
		 * This covers the scenario where the key was ALREADY SAVED in the old
		 * plugin settings (v2.0.x) before the v2.1.0 upgrade.
		 *
		 * @return void
		 */
		private static function maybe_migrate_legacy_keys() {
			if ( self::$legacy_migrated ) {
				return;
			}
			self::$legacy_migrated = true;

			$legacy_keys = self::scan_legacy_addon_keys();
			if ( empty( $legacy_keys ) ) {
				return;
			}

			$current_keys = get_option( self::PLUGIN_KEYS_OPTION, array() );
			if ( ! is_array( $current_keys ) ) {
				$current_keys = array();
			}

			$changed       = false;
			$global_key    = get_option( self::GLOBAL_KEY_OPTION, '' );
			$first_key_set = false;

			$migrated_slugs = array();

			foreach ( $legacy_keys as $slug => $key ) {
				// Don't overwrite keys the user already set in the new UI.
				if ( ! empty( $current_keys[ $slug ] ) ) {
					// Already in new storage — mark as migrated so we never
					// scan this legacy key again.
					$migrated_slugs[] = $slug;
					continue;
				}

				// If no global key exists and this is the first legacy key found,
				// promote it to global (same as the original migration logic).
				// We still do this branch so single-add-on users coming from
				// v2.0.x get their first key promoted automatically.
				if ( empty( $global_key ) && ! $first_key_set ) {
					update_option( self::GLOBAL_KEY_OPTION, $key );
					$global_key       = $key;
					$first_key_set    = true;
					$changed          = true;
					$migrated_slugs[] = $slug;
					continue;
				}

				// Store as individual plugin key — ALWAYS, even when the value
				// matches the global key. Previously we skipped duplicates, but
				// that hid legacy keys from the new UI which confused users who
				// had set per-plugin keys in v2.0.x addon settings. PaddlePress
				// activate() is idempotent on (license_key, site_url), so a key
				// that matches Global doesn't consume an extra activation slot.
				$current_keys[ $slug ] = $key;
				$changed               = true;
				$migrated_slugs[]      = $slug;
			}

			if ( $changed ) {
				if ( ! empty( $current_keys ) ) {
					update_option( self::PLUGIN_KEYS_OPTION, array_filter( $current_keys ) );
				}
				// Force cache refresh so the hub request includes the new keys.
				delete_option( self::CACHE_KEY );
			}

			// Mark all processed slugs as migrated. Setting this flag AFTER
			// update_option ensures we never lose data — if the save above
			// failed for some reason, the flag isn't set and we'll retry next
			// page load. Once set, scan_legacy_addon_keys() filters this slug
			// out, so deleting the key from the new UI won't bring it back.
			foreach ( $migrated_slugs as $slug ) {
				update_option( 'gravitywp_migrated_' . $slug, 1, false );
			}
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

			// Scan for legacy keys from old v2.0.x plugin settings.
			// This catches keys entered in the per-plugin settings page that haven't
			// been migrated yet. Legacy keys are merged UNDER the new keys (new wins).
			$legacy_keys = self::scan_legacy_addon_keys();
			if ( ! empty( $legacy_keys ) ) {
				$plugin_license_keys = array_merge( $legacy_keys, $plugin_license_keys );
			}

			// Remove empty keys.
			$plugin_license_keys = array_filter( $plugin_license_keys );

			// Note: even with no keys we still hit the API. The server returns
			// the public catalog (all plugins with has_access flags) so the Hub
			// can render free plugins as installable and premium plugins with
			// "Get This Add-on" CTAs — much better UX than an empty page that
			// forces a license-key entry before showing any value.
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

			// One-time re-keying: 8 of our add-ons use a legacy no-dash $_slug
			// in their GF addon class ('gravitywpfieldtoentries' etc.), so the
			// legacy-migration step above stores their per-plugin keys under
			// that legacy slug. The UI and the rest of the system, however,
			// use the canonical slug (= github_name, e.g. 'gravitywp-field-to-
			// entries'). Now that we have the hub data, we know the
			// download_tag → canonical slug mapping and can move the keys.
			self::normalize_plugin_keys_to_canonical( $data );

			return $data;
		}

		/**
		 * Re-key gravitywp_plugin_license_keys so each entry is stored under
		 * the canonical slug (= github_name) instead of the legacy GF addon
		 * $_slug / PaddlePress download_tag. Idempotent — safe to run on every
		 * hub fetch. Once all keys are canonical, this becomes a no-op.
		 *
		 * @param array $hub_data Hub response data.
		 * @return void
		 */
		private static function normalize_plugin_keys_to_canonical( $hub_data ) {
			if ( empty( $hub_data['plugins'] ) || ! is_array( $hub_data['plugins'] ) ) {
				return;
			}

			$current_keys = get_option( self::PLUGIN_KEYS_OPTION, array() );
			if ( ! is_array( $current_keys ) || empty( $current_keys ) ) {
				return;
			}

			$changed = false;
			foreach ( $hub_data['plugins'] as $plugin ) {
				$canonical = $plugin['slug'] ?? '';
				$legacy    = $plugin['download_tag'] ?? '';
				if ( empty( $canonical ) || empty( $legacy ) || $canonical === $legacy ) {
					continue;
				}
				// Move the key only when it exists under legacy AND the canonical
				// slot is still empty (don't overwrite user-set canonical keys).
				if ( ! empty( $current_keys[ $legacy ] ) && empty( $current_keys[ $canonical ] ) ) {
					$current_keys[ $canonical ] = $current_keys[ $legacy ];
					unset( $current_keys[ $legacy ] );
					$changed = true;
				}
			}

			if ( $changed ) {
				update_option( self::PLUGIN_KEYS_OPTION, array_filter( $current_keys ) );
			}
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
		 * Scan all registered GravityWP addons for legacy per-plugin license keys.
		 *
		 * In v2.0.x, each plugin stored its key in Gravity Forms addon settings as:
		 *   {addon_slug}_license_key
		 *
		 * This method reads those keys so the Hub request can include them
		 * even if the user never visited the new GravityWP settings page.
		 * The keys get sent to the hub, which validates and activates the domain.
		 *
		 * @return array [slug => license_key] of discovered legacy keys.
		 */
		private static function scan_legacy_addon_keys() {
			$keys = array();

			// Method 1: Scan via registered addon handlers (active plugins).
			if ( class_exists( '\GravityWP\Shared\Global_License_Key_Loader' ) ) {
				$handlers = Global_License_Key_Loader::get_registered_license_handlers();
				foreach ( $handlers as $handler ) {
					$addon_class = $handler['addon_class'] ?? '';
					if ( empty( $addon_class ) || ! class_exists( $addon_class ) ) {
						continue;
					}

					try {
						$addon = $addon_class::get_instance();
						if ( ! method_exists( $addon, 'get_slug' ) || ! method_exists( $addon, 'get_plugin_setting' ) ) {
							continue;
						}

						$slug = $addon->get_slug();
						if ( empty( $slug ) ) {
							continue;
						}

						$legacy_key = $addon->get_plugin_setting( $slug . '_license_key' );
						if ( ! empty( $legacy_key ) && is_string( $legacy_key ) ) {
							$keys[ $slug ] = trim( $legacy_key );
						}
					} catch ( \Exception $e ) {
						continue;
					}
				}
			}

			// Method 2: Direct DB scan for GF addon settings.
			// Catches keys from plugins that are inactive or haven't registered yet.
			// GF stores addon settings in: gravityformsaddon_{slug}_settings
			global $wpdb;
			$gf_options = $wpdb->get_results(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name LIKE 'gravityformsaddon_gravitywp%_settings'",
				ARRAY_A
			);

			if ( ! empty( $gf_options ) ) {
				foreach ( $gf_options as $row ) {
					$settings = maybe_unserialize( $row['option_value'] );
					if ( ! is_array( $settings ) ) {
						continue;
					}

					// Extract slug from option_name: gravityformsaddon_{slug}_settings
					$slug = preg_replace( '/^gravityformsaddon_(.+)_settings$/', '$1', $row['option_name'] );
					if ( empty( $slug ) || $slug === $row['option_name'] ) {
						continue;
					}

					// Look for {slug}_license_key in the settings array.
					$key_name = $slug . '_license_key';
					if ( ! empty( $settings[ $key_name ] ) && is_string( $settings[ $key_name ] ) ) {
						$legacy_key = trim( $settings[ $key_name ] );
						if ( ! empty( $legacy_key ) && ! isset( $keys[ $slug ] ) ) {
							$keys[ $slug ] = $legacy_key;
						}
					}
				}
			}

			// Filter out slugs that have ALREADY been migrated by either
			// LicenseHandler::maybe_migrate_legacy_license_key() (sets flag for
			// active plugins) or by a previous run of this Hub_Manager migration
			// (sets the same flag below). Without this filter, a user who
			// deletes a migrated per-plugin key from the new UI would see it
			// re-appear on the next page load, because we'd scan the old GF
			// settings again and re-migrate.
			foreach ( array_keys( $keys ) as $slug ) {
				if ( get_option( 'gravitywp_migrated_' . $slug, false ) ) {
					unset( $keys[ $slug ] );
				}
			}

			return $keys;
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
