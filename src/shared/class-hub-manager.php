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
					// Run the misplaced-global-key relocator against cached
					// data too — otherwise a user sitting on a 12-hour cache
					// of the misleading "single_addon in Global" state would
					// only get the fix after the cache expires or they hit
					// the Refresh button. Idempotent: once the Global option
					// is empty, this method returns immediately.
					self::maybe_relocate_misplaced_global_key( $cached );
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

			// Pre-flight: build a request body that NEVER trips the hub's REST
			// validator (rest_missing_callback_param / rest_invalid_param).
			//
			// - license_url is REQUIRED by the hub schema. home_url() returns
			// '' on installs that haven't set the siteurl option; defaulting
			// to a non-empty placeholder keeps the request well-formed even
			// in that edge case. The hub uses it only as an activation
			// identifier, so a placeholder is safe.
			// - plugin_license_keys is typed `object` server-side. We only
			// include the key when populated AND assoc-keyed, so wp_remote_post's
			// form encoding produces `plugin_license_keys[slug]=key` (which
			// PHP's REST parser deserializes as an object). Numeric or empty
			// arrays serialize as `plugin_license_keys[0]=...` and trigger
			// rest_invalid_param — see Recipe H in the plan.
			$license_url = home_url();
			if ( empty( $license_url ) ) {
				$license_url = site_url();
			}
			if ( empty( $license_url ) ) {
				$license_url = 'https://unknown.local';
			}

			$body = array(
				'license_url' => $license_url,
			);

			if ( ! empty( $global_key ) ) {
				$body['license_key'] = $global_key;
			}

			if ( ! empty( $plugin_license_keys ) ) {
				// Belt & braces: drop any non-string-keyed entries that would
				// serialize as a numeric-indexed array on the wire.
				$assoc_only = array();
				foreach ( $plugin_license_keys as $slug => $key ) {
					if ( is_string( $slug ) && '' !== $slug ) {
						$assoc_only[ $slug ] = $key;
					}
				}
				if ( ! empty( $assoc_only ) ) {
					$body['plugin_license_keys'] = $assoc_only;
				}
			}

			$args = array(
				'body'      => $body,
				'timeout'   => 20,
				'sslverify' => (bool) apply_filters( 'paddlepress_api_request_verify_ssl', true ),
			);

			$response = wp_remote_post( self::$hub_api_url, $args );
			delete_transient( self::LOCK_KEY );

			// Classify every failure mode through Api_Error_Handler so the UI
			// can surface a specific, actionable message (Cloudflare blocked,
			// network timeout, HTTP 5xx, etc.) instead of silently rendering
			// an empty page.
			if ( is_wp_error( $response ) ) {
				$code = Api_Error_Handler::classify_wp_error( $response );
				return self::return_stale_or_empty(
					array(
						'code'    => $code,
						'message' => Api_Error_Handler::message( $code ),
					)
				);
			}

			$http_code = wp_remote_retrieve_response_code( $response );
			$raw       = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $http_code ) {
				$code = Api_Error_Handler::classify_http_response( $http_code, $raw );
				// http_5xx / http_4xx carry the status code in their template.
				$args = in_array( $code, array( 'http_5xx', 'http_4xx' ), true )
					? array( (int) $http_code )
					: array();
				return self::return_stale_or_empty(
					array(
						'code'    => $code,
						'message' => Api_Error_Handler::message( $code, $args ),
						'status'  => (int) $http_code,
					)
				);
			}

			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				return self::return_stale_or_empty(
					array(
						'code'    => 'malformed_response',
						'message' => Api_Error_Handler::message( 'malformed_response' ),
					)
				);
			}

			// Remember each key's plan_type so the Global-field validator can
			// recognize a Single Add-on key on paste. Best-effort: only keys
			// whose hub response is a valid license get remembered.
			self::remember_key_plan_types( $global_key, $plugin_license_keys, $data );

			// Auto-relocate: a Single Add-on key in the Global field is a
			// no-op (it unlocks zero plugins via the global path because
			// PaddlePress only grants per-product permission for single_addon
			// plans). Detect this and move the key to the matching per-plugin
			// row automatically — far more professional than leaving the user
			// stuck with a warning and no way out short of manual cleanup.
			// Mutates $data in-place so the very next render reflects the new
			// state without a second hub round-trip.
			self::maybe_relocate_misplaced_global_key( $data );

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
		 * Name of the one-shot transient that carries a relocation notice
		 * from the Hub_Manager into the Registry's admin notices. Stores
		 * ['slug' => string, 'name' => string] for one page-load.
		 *
		 * @var string
		 */
		const RELOCATED_NOTICE_TRANSIENT = 'gwp_global_key_relocated';

		/**
		 * If the saved Global License Key is actually a Single Add-on key,
		 * move it to the matching row under Individual Plugin Keys.
		 *
		 * The Global field is for All Access / List Add-ons / Agency plans.
		 * A Single Add-on key in that slot unlocks zero plugins because the
		 * hub's global-access check uses `has_download_permission` against
		 * `download_post_id`, which single_addon plans only grant for one
		 * product. Rather than rendering "✓ Active … Unlocks 0 plugins" (a
		 * self-contradicting message), or asking the user to clean up
		 * manually, we relocate the key and tell them via an admin notice.
		 *
		 * Mutation is in-place on $data so the current render sees the new
		 * state immediately — no second hub call, no two-page-load settle.
		 *
		 * @param array $data Decoded hub response. Modified in-place.
		 * @return void
		 */
		private static function maybe_relocate_misplaced_global_key( &$data ) {
			if ( ! is_array( $data ) ) {
				return;
			}
			$global_block = $data['license']['global'] ?? null;
			if ( ! is_array( $global_block ) ) {
				return;
			}
			if ( ( $global_block['status'] ?? '' ) !== 'valid' ) {
				return;
			}
			if ( ( $global_block['plan_type'] ?? '' ) !== Plan_Types::SINGLE_ADDON ) {
				return;
			}

			$global_key = (string) get_option( self::GLOBAL_KEY_OPTION, '' );
			if ( '' === $global_key ) {
				return;
			}

			$plan_name = (string) ( $global_block['plan_name'] ?? '' );
			$plugins   = isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array();
			$target    = self::find_plugin_for_plan_name( $plan_name, $plugins );
			if ( empty( $target ) ) {
				// Couldn't resolve which plugin this key belongs to — leave
				// the key alone. The render-time warning still surfaces the
				// problem; the user just has to move it manually.
				return;
			}

			$target_slug = (string) $target['slug'];
			$target_name = (string) $target['name'];

			// Persist the move.
			$current_keys = get_option( self::PLUGIN_KEYS_OPTION, array() );
			if ( ! is_array( $current_keys ) ) {
				$current_keys = array();
			}
			// Don't overwrite an existing per-plugin key — if the user already
			// has the same plugin under Individual Plugin Keys, just clear
			// Global. The duplicate would be silently deduped by PaddlePress's
			// idempotent activate() anyway.
			if ( empty( $current_keys[ $target_slug ] ) ) {
				$current_keys[ $target_slug ] = $global_key;
				update_option( self::PLUGIN_KEYS_OPTION, array_filter( $current_keys ) );
			}
			delete_option( self::GLOBAL_KEY_OPTION );

			// One-shot notice for the next render.
			set_transient(
				self::RELOCATED_NOTICE_TRANSIENT,
				array(
					'slug' => $target_slug,
					'name' => $target_name,
				),
				60
			);

			// Mirror the change in $data so the current render sees the
			// post-relocation state. The hub didn't grant per_plugin access
			// during THIS request (the key was sent as the global key), but
			// the next hub call will, so we mark it optimistically. If that
			// next call surfaces blocking errors (site limit etc.), the
			// Api_Error_Handler::interpret_license() flow will catch them
			// then — no permanent over-promise.
			$data['license']['global'] = array(
				'status'           => 'no_key',
				'plan_type'        => Plan_Types::UNKNOWN,
				'plan_name'        => '',
				'expires'          => '',
				'license_limit'    => 0,
				'site_count'       => 0,
				'activations_left' => 0,
				'errors'           => array(),
			);

			if ( ! isset( $data['license']['per_plugin'] ) || ! is_array( $data['license']['per_plugin'] ) ) {
				$data['license']['per_plugin'] = array();
			}
			// Hand the license block over to per_plugin so the row shows the
			// same plan_name / expiry it would after the next fetch.
			if ( empty( $data['license']['per_plugin'][ $target_slug ] ) ) {
				$data['license']['per_plugin'][ $target_slug ] = $global_block;
			}

			// Flip the catalog entry to reflect the (optimistic) per_plugin
			// access so the catalog tab doesn't render the row as locked
			// for one page load. Idempotent — only touches the matched slug.
			foreach ( $data['plugins'] as &$plugin ) {
				if ( ( $plugin['slug'] ?? '' ) === $target_slug ) {
					$plugin['has_access']    = true;
					$plugin['access_source'] = 'per_plugin';
					break;
				}
			}
			unset( $plugin );
		}

		/**
		 * Match a hub plan_name string to a catalog plugin.
		 *
		 * The hub formats plan_name like "GravityWP - List Number Format (1
		 * sites)". We strip the prefix and the site-count suffix, then
		 * normalize-compare against each plugin's `name` field. Returns the
		 * first match or [] if no plugin's name matches.
		 *
		 * @param string $plan_name Hub-formatted plan name.
		 * @param array  $plugins   Catalog from the hub response.
		 * @return array{slug:string, name:string} Empty array if no match.
		 */
		private static function find_plugin_for_plan_name( $plan_name, $plugins ) {
			if ( '' === $plan_name || empty( $plugins ) ) {
				return array();
			}

			// "GravityWP - List Number Format (1 sites)" → "List Number Format"
			$name = preg_replace( '/^GravityWP\s*[-\x{2013}\x{2014}]\s*/u', '', $plan_name );
			$name = preg_replace( '/\s*\(\s*\d+\s+sites?\s*\)\s*$/i', '', $name );
			$name = trim( (string) $name );
			if ( '' === $name ) {
				return array();
			}

			$normalize = static function ( $s ) {
				return preg_replace( '/[^a-z0-9]/i', '', strtolower( (string) $s ) );
			};
			$needle    = $normalize( $name );
			if ( '' === $needle ) {
				return array();
			}

			foreach ( $plugins as $plugin ) {
				if ( empty( $plugin['slug'] ) ) {
					continue;
				}
				$candidate = (string) ( $plugin['name'] ?? '' );
				if ( '' !== $candidate && $normalize( $candidate ) === $needle ) {
					return array(
						'slug' => (string) $plugin['slug'],
						'name' => $candidate,
					);
				}
				// Also try matching against github_name in case plan_name uses
				// the slug rather than the display name.
				$gh = (string) ( $plugin['github_name'] ?? '' );
				if ( '' !== $gh && $normalize( $gh ) === $needle ) {
					return array(
						'slug' => (string) $plugin['slug'],
						'name' => '' !== $candidate ? $candidate : $gh,
					);
				}
			}

			return array();
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
		 * Return stale cache if available, else empty response — with an
		 * optional `hub_error` block attached so the UI can render an inline
		 * notice ("Couldn't reach the license server…" + Retry link).
		 *
		 * The hub_error rides on the returned array under a top-level key
		 * that downstream consumers can ignore safely. Failures are NOT
		 * cached — we only attach the marker to whatever we return.
		 *
		 * @param array $hub_error Optional ['code' => string, 'message' => string].
		 * @return array
		 */
		private static function return_stale_or_empty( $hub_error = array() ) {
			$stale = get_option( self::CACHE_KEY, false );
			$data  = ( ! empty( $stale['data'] ) ) ? $stale['data'] : self::get_empty_response();
			if ( ! empty( $hub_error ) ) {
				$data['hub_error'] = $hub_error;
			}
			return $data;
		}

		/**
		 * Remember each submitted key → plan_type after a successful hub call.
		 *
		 * Walks the response and pushes any (key, plan_type) it can resolve
		 * into the Api_Error_Handler's seen-keys cache, so the Global-field
		 * validator can later refuse a Single Add-on key.
		 *
		 * @param string $global_key          Raw global key.
		 * @param array  $plugin_license_keys Submitted [slug => key] map.
		 * @param array  $data                Decoded hub response.
		 * @return void
		 */
		private static function remember_key_plan_types( $global_key, $plugin_license_keys, $data ) {
			if ( ! class_exists( '\GravityWP\Shared\Api_Error_Handler' ) ) {
				return;
			}

			$plugins = isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array();

			// Global key — only remember when the hub reports it as valid.
			// For single_addon (which would be misplaced in the Global slot),
			// also resolve the unlock slug from plan_name so the per-plugin
			// sanitize callback can reject a future re-save into the wrong row.
			if ( ! empty( $global_key )
				&& isset( $data['license']['global']['status'] )
				&& 'valid' === $data['license']['global']['status']
				&& ! empty( $data['license']['global']['plan_type'] )
			) {
				$plan_type   = (string) $data['license']['global']['plan_type'];
				$unlock_slug = null;
				if ( Plan_Types::SINGLE_ADDON === $plan_type ) {
					$target = self::find_plugin_for_plan_name(
						(string) ( $data['license']['global']['plan_name'] ?? '' ),
						$plugins
					);
					if ( ! empty( $target['slug'] ) ) {
						$unlock_slug = (string) $target['slug'];
					}
				}
				Api_Error_Handler::remember_key_plan_type( $global_key, $plan_type, $unlock_slug );
			}

			// Per-plugin keys — remember each valid one. The hub re-keys
			// per_plugin_data using canonical slug (= github_name), which
			// may differ from the slug the customer submitted under. So we
			// match license blocks back to their submitted key by scanning
			// per_plugin entries for plan_name + plan_type matches.
			$per_plugin = isset( $data['license']['per_plugin'] ) ? (array) $data['license']['per_plugin'] : array();
			foreach ( $plugin_license_keys as $submitted_slug => $key ) {
				if ( empty( $key ) ) {
					continue;
				}

				// Try the submitted slug first (canonical key in per_plugin).
				$info = $per_plugin[ $submitted_slug ] ?? null;
				if ( ! is_array( $info ) || ( $info['status'] ?? '' ) !== 'valid' ) {
					$info = null;
				}

				// Fallback: take the first valid per_plugin block. With
				// realistic payloads (one or two keys submitted), this is a
				// safe heuristic — multi-key payloads would deserve a more
				// careful key↔block match but PaddlePress doesn't expose
				// the underlying license_id in the response shape.
				if ( null === $info ) {
					foreach ( $per_plugin as $candidate ) {
						if ( is_array( $candidate )
							&& ( $candidate['status'] ?? '' ) === 'valid'
							&& ! empty( $candidate['plan_type'] )
						) {
							$info = $candidate;
							break;
						}
					}
				}

				if ( null === $info || empty( $info['plan_type'] ) ) {
					continue;
				}

				$plan_type   = (string) $info['plan_type'];
				$unlock_slug = null;
				if ( Plan_Types::SINGLE_ADDON === $plan_type ) {
					// Resolve which plugin THIS key actually unlocks. The
					// submitted slot may be wrong (e.g. LNF key in AMT slot),
					// so we trust plan_name over slot position.
					$target = self::find_plugin_for_plan_name(
						(string) ( $info['plan_name'] ?? '' ),
						$plugins
					);
					if ( ! empty( $target['slug'] ) ) {
						$unlock_slug = (string) $target['slug'];
					}
				}

				Api_Error_Handler::remember_key_plan_type( $key, $plan_type, $unlock_slug );
			}
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
