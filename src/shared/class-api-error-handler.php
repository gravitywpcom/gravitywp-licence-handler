<?php
/**
 * Centralized API error & message handler.
 *
 * Single source of truth for every user-visible string surfaced by the
 * license-handler stack: hub network failures, license-state interpretation,
 * install/update upgrader errors, and global-field key-type validation.
 *
 * Every render path (Hub_Manager, Hub_Ajax, Global_License_Key_Registry,
 * pluginUpdater) consults this class instead of building strings ad-hoc.
 * That keeps phrasing, severity, and CSS class consistent across the UI and
 * makes adding a new error code a one-line catalog change.
 *
 * @package GravityWP\Shared
 * @since   2.1.1
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Api_Error_Handler' ) ) {

	/**
	 * Class Api_Error_Handler
	 */
	class Api_Error_Handler {

		const SEVERITY_INFO    = 'info';
		const SEVERITY_WARN    = 'warn';
		const SEVERITY_ERROR   = 'error';
		const SEVERITY_SUCCESS = 'success';

		/**
		 * Effective-status values returned by {@see interpret_license()}.
		 *
		 * These are display-layer states, not the raw hub `status` field.
		 * The UI renderer maps them to badge classes/icons.
		 */
		const STATUS_ACTIVE       = 'active';
		const STATUS_INACTIVE     = 'inactive';     // Valid key, can't use on this site.
		const STATUS_INVALID      = 'invalid';      // Key itself is invalid / expired.
		const STATUS_WRONG_PLUGIN = 'wrong_plugin'; // Valid key, but doesn't unlock this row.
		const STATUS_VIA_GLOBAL   = 'via_global';   // No per-plugin key, covered by global.
		const STATUS_EMPTY        = 'empty';        // No key entered, no coverage.

		/**
		 * WP option name where we remember plan_types per key prefix.
		 *
		 * Used by {@see detect_key_plan_type()} so we can warn the user when
		 * they paste a key we know is a single-addon into the Global field.
		 * Stored as ['xxxxxxxx' => 'single_addon', ...], keyed by the first 8
		 * chars of the key. Best-effort detection only.
		 */
		const SEEN_KEYS_OPTION = 'gwp_seen_key_plan_types';

		/**
		 * Cached catalog. Built lazily on first call so __() runs after
		 * translations are loaded.
		 *
		 * @var array|null
		 */
		private static $catalog = null;

		/**
		 * Return the full code → metadata catalog.
		 *
		 * @return array<string, array{severity:string, blocking:bool, message:string}>
		 */
		public static function get_catalog() {
			if ( null !== self::$catalog ) {
				return self::$catalog;
			}

			self::$catalog = array(
				// ── License-state codes (from PaddlePress) ─────────────────
				'unregistered_license_domain' => array(
					'severity' => self::SEVERITY_INFO,
					'blocking' => false, // Becomes blocking only with can_not_add_new_domain.
					'message'  => __( 'Activating this site on your license…', 'gravitywp-license-handler' ),
				),
				'can_not_add_new_domain'      => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'Your license is at its site limit. Deactivate another site or upgrade your plan.', 'gravitywp-license-handler' ),
				),
				'insufficient_membership_level' => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( "This license doesn't cover this plugin.", 'gravitywp-license-handler' ),
				),
				'invalid_license_or_domain'   => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'License invalid for this site. Check the key or contact support.', 'gravitywp-license-handler' ),
				),
				'no_key'                      => array(
					'severity' => self::SEVERITY_INFO,
					'blocking' => true,
					'message'  => __( 'No license key entered.', 'gravitywp-license-handler' ),
				),
				// PaddlePress emits this when the key isn't in the payments
				// table at all (typo, deleted, never existed).
				'missing_license_key'         => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( "We couldn't find this license key. Double-check that you copied it correctly from your purchase email, or contact support.", 'gravitywp-license-handler' ),
				),
				'expired_license_key'         => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'This license key has expired. Renew it to continue receiving updates and use the key on new sites.', 'gravitywp-license-handler' ),
				),
				'blocked_license_domain'      => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'This site has been blocked for this license. Contact support if you believe this is a mistake.', 'gravitywp-license-handler' ),
				),
				'invalid_product'             => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( "This license key isn't valid for this product.", 'gravitywp-license-handler' ),
				),

				// ── Transport / network codes (raised by Hub_Manager) ──────
				'server_error'                => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'License server unavailable. Try again in a moment.', 'gravitywp-license-handler' ),
				),
				'cloudflare_blocked'          => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'Your hosting provider blocked the license check (Cloudflare). Contact your host to allow requests to my.gravitywp.com.', 'gravitywp-license-handler' ),
				),
				'network_timeout'             => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( "Couldn't reach the license server. Check your internet connection and try again.", 'gravitywp-license-handler' ),
				),
				'http_5xx'                    => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					/* translators: %d: HTTP status code */
					'message'  => __( 'License server returned an error (HTTP %d). Try again shortly.', 'gravitywp-license-handler' ),
				),
				'http_4xx'                    => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					/* translators: %d: HTTP status code */
					'message'  => __( 'License request rejected (HTTP %d).', 'gravitywp-license-handler' ),
				),
				'malformed_response'          => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'Unexpected response from the license server. Contact support if this persists.', 'gravitywp-license-handler' ),
				),

				// ── REST validator codes (when our request is malformed) ───
				'rest_missing_callback_param' => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					/* translators: %s: parameter name */
					'message'  => __( 'Missing required field: %s. This is a bug — please report it.', 'gravitywp-license-handler' ),
				),
				'rest_invalid_param'          => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					/* translators: %s: parameter name */
					'message'  => __( 'Invalid request parameter: %s. This is a bug — please report it.', 'gravitywp-license-handler' ),
				),

				// ── Install / update upgrader codes ────────────────────────
				'folder_exists'               => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'A plugin folder with this name already exists. Remove the orphan folder via FTP and retry.', 'gravitywp-license-handler' ),
				),
				'download_failed'             => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( "Couldn't download the plugin package. Check your license or try again.", 'gravitywp-license-handler' ),
				),
				'filesystem_requires_ftp'     => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'This site needs FTP credentials to install plugins. Install via Plugins → Add New instead.', 'gravitywp-license-handler' ),
				),
				'operation_in_progress'       => array(
					'severity' => self::SEVERITY_WARN,
					'blocking' => true,
					'message'  => __( 'Another install or update is already running. Wait a few seconds and try again.', 'gravitywp-license-handler' ),
				),
				'security_check_failed'       => array(
					'severity' => self::SEVERITY_ERROR,
					'blocking' => true,
					'message'  => __( 'Security check failed. Reload the page and try again.', 'gravitywp-license-handler' ),
				),

				// ── UI-state codes (raised by interpret_license) ───────────
				'wrong_plugin_key'            => array(
					'severity' => self::SEVERITY_WARN,
					'blocking' => false,
					'message'  => __( "This key is for a different plugin — move it to the matching row.", 'gravitywp-license-handler' ),
				),
				'wrong_field_for_plan_type'   => array(
					'severity' => self::SEVERITY_WARN,
					'blocking' => true,
					'message'  => __( 'This looks like a Single Add-on key. Add it under Individual Plugin Keys instead.', 'gravitywp-license-handler' ),
				),
				'global_key_in_per_plugin'    => array(
					'severity' => self::SEVERITY_INFO,
					'blocking' => false,
					'message'  => __( 'This is a multi-plugin license (All Access / Agency / List Add-ons). It works here, and it actually unlocks every plugin in your account on its own — you don\'t need to add it to other rows. For clarity, consider moving it to the Global License Key field instead.', 'gravitywp-license-handler' ),
				),
				'via_global'                  => array(
					'severity' => self::SEVERITY_SUCCESS,
					'blocking' => false,
					'message'  => __( 'Covered by your Global License Key.', 'gravitywp-license-handler' ),
				),
				'active'                      => array(
					'severity' => self::SEVERITY_SUCCESS,
					'blocking' => false,
					'message'  => __( 'Active', 'gravitywp-license-handler' ),
				),
			);

			return self::$catalog;
		}

		/**
		 * Get the user-facing message for a code.
		 *
		 * Unknown codes return a generic fallback. Supports sprintf args for
		 * the codes whose catalog message contains %s / %d placeholders.
		 *
		 * @param string $code Catalog code.
		 * @param array  $args sprintf arguments.
		 * @return string
		 */
		public static function message( $code, $args = array() ) {
			$catalog = self::get_catalog();
			if ( ! isset( $catalog[ $code ] ) ) {
				return __( 'An unexpected error occurred. Please try again.', 'gravitywp-license-handler' );
			}
			$template = $catalog[ $code ]['message'];
			if ( empty( $args ) ) {
				return $template;
			}
			return vsprintf( $template, (array) $args );
		}

		/**
		 * Get the severity class for a code.
		 *
		 * Maps to the existing `.gwp-alert--{class}` CSS in
		 * assets/css/gwp-admin.css (info|warn|danger|success).
		 *
		 * @param string $code Catalog code.
		 * @return string One of the SEVERITY_* constants.
		 */
		public static function severity( $code ) {
			$catalog = self::get_catalog();
			return $catalog[ $code ]['severity'] ?? self::SEVERITY_ERROR;
		}

		/**
		 * True if this error code should demote a "valid" license to unusable.
		 *
		 * Context-aware for {@see unregistered_license_domain}: alone with
		 * `activations_left > 0` it's just informational (the hub will
		 * auto-activate on the next call). With `can_not_add_new_domain`
		 * present, it becomes blocking.
		 *
		 * @param string $code         Catalog code.
		 * @param array  $license_data Full license_data block from the hub.
		 *                              Used for the context-aware check.
		 * @return bool
		 */
		public static function is_blocking( $code, $license_data = array() ) {
			$catalog = self::get_catalog();
			if ( ! isset( $catalog[ $code ] ) ) {
				return true; // Unknown code = treat as blocking to fail safe.
			}

			// Context-aware override: unregistered_license_domain is only
			// blocking when paired with can_not_add_new_domain.
			if ( 'unregistered_license_domain' === $code ) {
				$errors = isset( $license_data['errors'] ) && is_array( $license_data['errors'] )
					? $license_data['errors']
					: array();
				if ( isset( $errors['can_not_add_new_domain'] ) ) {
					return true;
				}
				$activations_left = isset( $license_data['activations_left'] )
					? (int) $license_data['activations_left']
					: 0;
				// With capacity remaining, the hub auto-activates → not blocking.
				return $activations_left <= 0;
			}

			return (bool) $catalog[ $code ]['blocking'];
		}

		/**
		 * Map a WP_Error from wp_remote_post() into a catalog code.
		 *
		 * @param \WP_Error $err Error returned by WP_Http.
		 * @return string Catalog code.
		 */
		public static function classify_wp_error( $err ) {
			if ( ! ( $err instanceof \WP_Error ) ) {
				return 'server_error';
			}
			$code = (string) $err->get_error_code();
			// WordPress wraps cURL CURLE_OPERATION_TIMEDOUT (28) and
			// CURLE_COULDNT_RESOLVE_HOST (6) all into 'http_request_failed',
			// so we have to inspect the message string to differentiate.
			$msg  = strtolower( (string) $err->get_error_message() );
			if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) ) {
				return 'network_timeout';
			}
			if ( false !== strpos( $msg, 'could not resolve' ) || false !== strpos( $msg, "couldn't resolve" ) ) {
				return 'network_timeout';
			}
			if ( 'http_request_failed' === $code ) {
				return 'network_timeout';
			}
			return 'server_error';
		}

		/**
		 * Map an HTTP response (status + body) into a catalog code.
		 *
		 * @param int    $status HTTP status code.
		 * @param string $body   Raw response body.
		 * @return string Catalog code.
		 */
		public static function classify_http_response( $status, $body ) {
			$status = (int) $status;
			$body   = (string) $body;

			// Cloudflare-fronted blocks return 403 with a specific HTML body.
			if ( 403 === $status && false !== stripos( $body, 'cloudflare' ) ) {
				return 'cloudflare_blocked';
			}

			// Did the body itself carry a REST validator error code?
			// Bodies look like: {"code":"rest_invalid_param", "message":"..."}.
			if ( '' !== $body && '{' === $body[0] ) {
				$decoded = json_decode( $body, true );
				if ( is_array( $decoded ) && ! empty( $decoded['code'] ) ) {
					$rest_code = (string) $decoded['code'];
					if ( 'rest_missing_callback_param' === $rest_code ) {
						return 'rest_missing_callback_param';
					}
					if ( 'rest_invalid_param' === $rest_code ) {
						return 'rest_invalid_param';
					}
				}
			}

			if ( $status >= 500 ) {
				return 'http_5xx';
			}
			if ( $status >= 400 ) {
				return 'http_4xx';
			}

			return 'malformed_response';
		}

		/**
		 * Interpret a per-plugin or global license_data block into a display
		 * state.
		 *
		 * The hub's raw `status` field reflects "is this PaddlePress license
		 * record valid?" (key exists, not expired, license_limit > 0). It
		 * does NOT reflect "can this site actually use this license for this
		 * plugin right now?". This method bridges that gap.
		 *
		 * @param array $license_data The block from `license.per_plugin[slug]`
		 *                            or `license.global`. May be empty/null.
		 * @param array $plugin_ctx   Context from the row's plugin catalog
		 *                            entry: ['has_access' => bool,
		 *                            'access_source' => string,
		 *                            'has_key' => bool].
		 * @return array{effective_status:string, primary_code:string, message:string, severity:string}
		 */
		public static function interpret_license( $license_data, $plugin_ctx = array() ) {
			$has_key       = ! empty( $plugin_ctx['has_key'] );
			$has_access    = ! empty( $plugin_ctx['has_access'] );
			$access_source = isset( $plugin_ctx['access_source'] ) ? (string) $plugin_ctx['access_source'] : 'none';

			// No key entered. Covered by global? Otherwise empty.
			if ( ! $has_key ) {
				if ( $has_access && 'global' === $access_source ) {
					return self::pack( self::STATUS_VIA_GLOBAL, 'via_global' );
				}
				return self::pack( self::STATUS_EMPTY, 'no_key' );
			}

			$status = isset( $license_data['status'] ) ? (string) $license_data['status'] : '';

			// Key itself invalid (expired, doesn't exist, etc.).
			if ( 'valid' !== $status ) {
				$primary = self::first_error_code( $license_data, 'invalid_license_or_domain' );
				return self::pack( self::STATUS_INVALID, $primary, $license_data );
			}

			// Multi-plugin license (All Access / Agency / List Add-ons)
			// submitted through a per-plugin row. This actually works:
			// PaddlePress grants download_permission for every plugin under
			// these plans, so the per_plugin loop on the hub side returns
			// has_access=true for every plugin via this one slot. It just
			// isn't the recommended placement — the Global field is the
			// idiomatic home. So we render the row as ACTIVE (green badge,
			// no red border) but surface an INFO-level note beneath telling
			// the user the key is a multi-plugin license and that they can
			// move it to Global for clarity. The renderer detects the
			// non-'active' primary_code and emits the note.
			$plan_type = isset( $license_data['plan_type'] ) ? (string) $license_data['plan_type'] : '';
			$is_multi_plugin_plan = in_array(
				$plan_type,
				array( Plan_Types::ALL_ACCESS, Plan_Types::AGENCY, Plan_Types::LIST_ADDONS ),
				true
			);
			if ( $is_multi_plugin_plan ) {
				return self::pack( self::STATUS_ACTIVE, 'global_key_in_per_plugin' );
			}

			// Already covered globally — the per-plugin key in this row is
			// just redundant storage, not an error. Render the row as
			// VIA_GLOBAL so the user sees the plugin is unlocked. They can
			// optionally clean up the unused per-plugin entry; the global
			// key is what's actually doing the work.
			if ( $has_access && 'global' === $access_source ) {
				return self::pack( self::STATUS_VIA_GLOBAL, 'via_global' );
			}

			// Check embedded blocking errors BEFORE the has_access /
			// wrong-plugin determination. The hub returns has_access=false
			// for two very different conditions:
			//
			//   (a) Wrong product — key activated fine, but its plan doesn't
			//       grant download permission to this plugin. The user put
			//       the key in the wrong row.
			//   (b) Activation failed — site limit reached, insufficient
			//       membership level, license expired on the server side.
			//       The key is for the right plugin; it just can't run here.
			//
			// PaddlePress only distinguishes these via the embedded `errors`
			// map. So we check it FIRST. If we find a blocking error, the
			// failure is activation-time (b) and we render a specific message
			// like "Site limit reached". Only if errors are clean do we fall
			// through to the wrong-plugin branch.
			//
			// unregistered_license_domain is deprioritized: its message is
			// informational ("Activating this site…"), so when it co-occurs
			// with a real blocker we surface the more specific one instead.
			$errors = isset( $license_data['errors'] ) && is_array( $license_data['errors'] )
				? $license_data['errors']
				: array();

			$blocking_primary   = null;
			$blocking_secondary = null;
			foreach ( $errors as $code => $_messages ) {
				$code_str = (string) $code;
				if ( ! self::is_blocking( $code_str, $license_data ) ) {
					continue;
				}
				if ( 'unregistered_license_domain' === $code_str ) {
					$blocking_secondary = $code_str;
					continue;
				}
				$blocking_primary = $code_str;
				break;
			}

			if ( $blocking_primary ) {
				return self::pack( self::STATUS_INACTIVE, $blocking_primary, $license_data );
			}
			if ( $blocking_secondary ) {
				return self::pack( self::STATUS_INACTIVE, $blocking_secondary, $license_data );
			}

			// No blocking errors. If the catalog still says we don't have
			// access, the only remaining explanation is that the key's
			// product genuinely differs from this row's plugin.
			if ( ! $has_access || 'per_plugin' !== $access_source ) {
				return self::pack( self::STATUS_WRONG_PLUGIN, 'wrong_plugin_key' );
			}

			return self::pack( self::STATUS_ACTIVE, 'active' );
		}

		/**
		 * Look up the plan type previously seen for a given key.
		 *
		 * Used by the Global License Key validator to warn when a user
		 * pastes a Single Add-on key into the wrong field. Best-effort: only
		 * keys that have been submitted at least once (and got a valid
		 * response) are in the cache. Unknown keys return UNKNOWN, which
		 * the validator treats as "let it through".
		 *
		 * Coerces both the legacy storage shape (string plan_type) and the
		 * current shape ([plan_type, slug]) so old caches keep working.
		 *
		 * @param string $key The license key.
		 * @return string One of the Plan_Types::* constants.
		 */
		public static function detect_key_plan_type( $key ) {
			$dest = self::detect_key_destination( $key );
			return $dest['plan_type'];
		}

		/**
		 * Full lookup: returns ['plan_type' => ..., 'slug' => ...] for a key
		 * we've previously remembered.
		 *
		 * - `plan_type` is one of Plan_Types::* (UNKNOWN if never seen).
		 * - `slug` is the canonical plugin slug a single_addon key unlocks,
		 *   filled in only after we observe the hub's plan_name. Always
		 *   null for non-single_addon keys.
		 *
		 * Used by sanitize_plugin_keys() to decide whether to refuse a save
		 * into the wrong per-plugin slot.
		 *
		 * @param string $key The license key.
		 * @return array{plan_type:string, slug:?string}
		 */
		public static function detect_key_destination( $key ) {
			$key = (string) $key;
			$default = array(
				'plan_type' => Plan_Types::UNKNOWN,
				'slug'      => null,
			);
			if ( '' === $key ) {
				return $default;
			}
			$seen = get_option( self::SEEN_KEYS_OPTION, array() );
			if ( ! is_array( $seen ) ) {
				return $default;
			}
			$fingerprint = self::key_fingerprint( $key );
			if ( ! isset( $seen[ $fingerprint ] ) ) {
				return $default;
			}
			$entry = $seen[ $fingerprint ];

			// Legacy storage: bare string plan_type. Coerce.
			if ( is_string( $entry ) ) {
				return array(
					'plan_type' => $entry,
					'slug'      => null,
				);
			}
			if ( ! is_array( $entry ) ) {
				return $default;
			}
			return array(
				'plan_type' => isset( $entry['plan_type'] ) ? (string) $entry['plan_type'] : Plan_Types::UNKNOWN,
				'slug'      => isset( $entry['slug'] ) && '' !== $entry['slug'] ? (string) $entry['slug'] : null,
			);
		}

		/**
		 * Remember a key → plan_type mapping after a successful hub call.
		 *
		 * Called from Hub_Manager once a fresh hub response is in hand, so
		 * subsequent Global-field saves can recognize a single-addon key.
		 *
		 * The `$unlock_slug` argument is only meaningful for single_addon
		 * keys: it's the canonical slug of the plugin the key actually
		 * unlocks (derived from `plan_name`). For multi-plugin plans
		 * (agency/all_access/list_addons), pass null — the slug field has
		 * no meaning. Stored as an array `[plan_type, slug]` going forward;
		 * the reader coerces legacy bare-string entries on the fly.
		 *
		 * @param string      $key         The license key.
		 * @param string      $plan_type   One of the Plan_Types::* constants.
		 * @param string|null $unlock_slug Canonical plugin slug the key
		 *                                 unlocks (single_addon only), or null.
		 */
		public static function remember_key_plan_type( $key, $plan_type, $unlock_slug = null ) {
			$key = (string) $key;
			if ( '' === $key || ! in_array( $plan_type, array(
				Plan_Types::AGENCY,
				Plan_Types::ALL_ACCESS,
				Plan_Types::LIST_ADDONS,
				Plan_Types::SINGLE_ADDON,
			), true ) ) {
				return;
			}
			$seen = get_option( self::SEEN_KEYS_OPTION, array() );
			if ( ! is_array( $seen ) ) {
				$seen = array();
			}
			$fingerprint = self::key_fingerprint( $key );

			// Cap the cache to 100 entries — we only need recently-seen keys.
			if ( count( $seen ) >= 100 && ! isset( $seen[ $fingerprint ] ) ) {
				array_shift( $seen );
			}

			// Preserve an existing slug if the caller doesn't know it yet
			// (e.g. an All-Access key whose plan_type we already know but
			// whose slug field is irrelevant). This way a later call with
			// just plan_type doesn't wipe a previously-learned slug.
			$existing_slug = null;
			if ( isset( $seen[ $fingerprint ] ) && is_array( $seen[ $fingerprint ] ) ) {
				$existing_slug = $seen[ $fingerprint ]['slug'] ?? null;
			}
			$slug = null !== $unlock_slug ? (string) $unlock_slug : $existing_slug;

			$seen[ $fingerprint ] = array(
				'plan_type' => $plan_type,
				'slug'      => Plan_Types::SINGLE_ADDON === $plan_type ? $slug : null,
			);
			update_option( self::SEEN_KEYS_OPTION, $seen, false );
		}

		// ── Internals ─────────────────────────────────────────────────────

		/**
		 * Fingerprint of a key for the seen-keys cache.
		 *
		 * Uses first 8 chars so we don't store the full key in wp_options.
		 * 8 chars of a UUID4 = ~4 billion combinations; collision risk for a
		 * <100-entry cache is negligible.
		 *
		 * @param string $key Raw license key.
		 * @return string Lower-case 8-char prefix.
		 */
		private static function key_fingerprint( $key ) {
			return strtolower( substr( (string) $key, 0, 8 ) );
		}

		/**
		 * Pull the first error code from a license_data block.
		 *
		 * @param array  $license_data The block.
		 * @param string $fallback     Code to return if `errors` is empty.
		 * @return string
		 */
		private static function first_error_code( $license_data, $fallback ) {
			$errors = isset( $license_data['errors'] ) && is_array( $license_data['errors'] )
				? $license_data['errors']
				: array();
			foreach ( $errors as $code => $_ ) {
				return (string) $code;
			}
			return $fallback;
		}

		/**
		 * Build the return shape used by {@see interpret_license()}.
		 *
		 * When `$primary_code` is in the catalog we use the catalog's
		 * localized message + severity. When it's not (PaddlePress could
		 * add codes we haven't catalogued yet — or upstream-only codes
		 * we don't care about), we fall back to the raw PaddlePress
		 * English from `license_data['errors'][$primary_code]` instead
		 * of the generic "An unexpected error occurred." The PaddlePress
		 * string is always more useful to the user than nothing.
		 *
		 * @param string $effective_status One of STATUS_*.
		 * @param string $primary_code     Catalog code carrying the message.
		 * @param array  $license_data     Optional hub license block; used
		 *                                 to pull a raw fallback string
		 *                                 when $primary_code isn't catalogued.
		 * @return array
		 */
		private static function pack( $effective_status, $primary_code, $license_data = array() ) {
			$catalog = self::get_catalog();

			if ( isset( $catalog[ $primary_code ] ) ) {
				$message  = self::message( $primary_code );
				$severity = self::severity( $primary_code );
			} else {
				// Try the raw PaddlePress error string for this code.
				$raw_messages = array();
				if ( is_array( $license_data )
					&& isset( $license_data['errors'][ $primary_code ] )
					&& is_array( $license_data['errors'][ $primary_code ] )
				) {
					$raw_messages = $license_data['errors'][ $primary_code ];
				}
				$message = ! empty( $raw_messages ) && is_string( $raw_messages[0] )
					? (string) $raw_messages[0]
					: self::message( $primary_code );
				// Unknown code = treat as error severity (safe default).
				$severity = self::SEVERITY_ERROR;
			}

			// Code-specific message enrichment using fields we know are present
			// in the hub's license_data. The catalog message is the LOCALIZED
			// shell; this layer appends a fact ("expired on …" or "(1/1 sites
			// used)") only when we have the data. Keeps the catalog free of
			// placeholder gymnastics while still giving the user the specifics.
			if ( 'expired_license_key' === $primary_code
				&& is_array( $license_data )
				&& ! empty( $license_data['expires'] )
				&& 'lifetime' !== $license_data['expires']
			) {
				$ts = strtotime( (string) $license_data['expires'] );
				if ( false !== $ts ) {
					$message .= ' ' . sprintf(
						/* translators: %s: human-readable expiry date */
						__( '(expired on %s)', 'gravitywp-license-handler' ),
						date_i18n( get_option( 'date_format', 'Y-m-d' ), $ts )
					);
				}
			} elseif ( 'can_not_add_new_domain' === $primary_code
				&& is_array( $license_data )
				&& isset( $license_data['site_count'], $license_data['license_limit'] )
			) {
				$used  = (int) $license_data['site_count'];
				$limit = (int) $license_data['license_limit'];
				if ( $limit > 0 ) {
					$message .= ' ' . sprintf(
						/* translators: 1: used count, 2: license limit */
						__( '(%1$d / %2$d sites used)', 'gravitywp-license-handler' ),
						$used,
						$limit
					);
				}
			}

			return array(
				'effective_status' => $effective_status,
				'primary_code'     => $primary_code,
				'message'          => $message,
				'severity'         => $severity,
			);
		}
	}
}
