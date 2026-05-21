<?php
/**
 * Hub AJAX — Install / Activate / Deactivate / Delete handlers.
 *
 * Each handler verifies a nonce + capability, performs the WP plumbing, and
 * returns the freshly rendered card footer HTML so the UI updates in place.
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Hub_Ajax' ) ) {

	class Hub_Ajax {

		const NONCE_ACTION = 'gwp_hub_ajax';

		private static $registered = false;

		public static function init() {
			if ( self::$registered ) {
				return;
			}
			self::$registered = true;

			add_action( 'wp_ajax_gwp_hub_install', array( self::class, 'ajax_install' ) );
			add_action( 'wp_ajax_gwp_hub_update', array( self::class, 'ajax_update' ) );
			add_action( 'wp_ajax_gwp_hub_activate', array( self::class, 'ajax_activate' ) );
			add_action( 'wp_ajax_gwp_hub_deactivate', array( self::class, 'ajax_deactivate' ) );
			add_action( 'wp_ajax_gwp_hub_delete', array( self::class, 'ajax_delete' ) );
		}

		public static function ajax_install() {
			$slug = self::verify_request( 'install_plugins' );
			self::log( 'install start', array( 'slug' => $slug, 'user_id' => get_current_user_id() ) );

			$plugin = Hub_Manager::get_plugin_data( $slug );
			self::log( 'plugin fetched', array(
				'slug'          => $slug,
				'has_access'    => ! empty( $plugin['has_access'] ),
				'is_free'       => ! empty( $plugin['is_free'] ),
				'access_source' => $plugin['access_source'] ?? null,
				'download_link' => $plugin['download_link'] ?? null,
				'github_name'   => $plugin['github_name'] ?? null,
			) );

			if ( ! $plugin || empty( $plugin['has_access'] ) ) {
				self::fail( 403, Api_Error_Handler::message( 'insufficient_membership_level' ) );
			}

			$package = '';
			if ( ! empty( $plugin['download_link'] ) ) {
				$package = $plugin['download_link'];
			} elseif ( ! empty( $plugin['package'] ) ) {
				$package = $plugin['package'];
			}
			if ( empty( $package ) ) {
				self::fail( 400, Api_Error_Handler::message( 'download_failed' ) );
			}

			// Self-heal stale catalog URLs. Legacy `.latest-stable.zip` is a 302
			// redirect; some WP_HTTP setups save the redirect body as the "ZIP"
			// → PCLZIP_ERR_BAD_FORMAT. The canonical `.zip` is a direct 200 OK.
			if ( false !== strpos( $package, '.latest-stable.zip' ) ) {
				$package = str_replace( '.latest-stable.zip', '.zip', $package );
			}

			// wp.org canonical override for free plugins: ask WordPress's official
			// Plugin API for the authoritative download URL. Returns the versioned
			// `.{version}.zip` (a direct 200, no redirect) — works reliably across
			// every WP_HTTP setup. This bypasses any stale URL in the hub cache.
			if ( ! empty( $plugin['is_free'] ) ) {
				$wporg_slug = ! empty( $plugin['github_name'] ) ? $plugin['github_name'] : $slug;
				$canonical  = self::fetch_wporg_download_link( $wporg_slug );
				if ( '' !== $canonical ) {
					$package = $canonical;
				}
			}

			self::log( 'package resolved', array(
				'slug'    => $slug,
				'package' => $package,
				'is_free' => ! empty( $plugin['is_free'] ),
			) );

			self::load_upgrader_includes();

			// Idempotent: skip install if a valid plugin already lives at the slug.
			$existing = self::find_installed_plugin_file( $slug );
			if ( '' !== $existing ) {
				self::log( 'idempotent hit', array( 'plugin_file' => $existing, 'is_active' => is_plugin_active( $existing ) ) );
				wp_clean_plugins_cache();
				self::respond_success( $slug, $existing, is_plugin_active( $existing ) );
				return;
			}

			// Orphan-folder cleanup before extract.
			$cleanup = self::cleanup_orphan_plugin_folder( $slug );
			if ( is_wp_error( $cleanup ) ) {
				self::log( 'cleanup failed', array( 'err' => $cleanup->get_error_code(), 'data' => $cleanup->get_error_data() ) );
				self::fail( 500, self::format_wp_error( $cleanup ) );
			} elseif ( true === $cleanup ) {
				self::log( 'orphan removed', array( 'slug' => $slug ) );
			}

			if ( 'direct' !== get_filesystem_method() ) {
				self::fail( 412, Api_Error_Handler::message( 'filesystem_requires_ftp' ) );
			}

			$lock_key = 'gwp_hub_lock_' . md5( $slug );
			if ( get_transient( $lock_key ) ) {
				self::fail( 429, Api_Error_Handler::message( 'operation_in_progress' ) );
			}
			set_transient( $lock_key, 1, 60 );

			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $package );

			delete_transient( $lock_key );

			self::log( 'upgrader done', array(
				'package'   => $package,
				'is_wp_err' => is_wp_error( $result ),
				'skin_err'  => ( is_wp_error( $skin->result ) ) ? $skin->result->get_error_code() : null,
				'skin_data' => ( is_wp_error( $skin->result ) ) ? $skin->result->get_error_data() : null,
			) );

			// folder_exists retry: when the ZIP's internal folder name differs from
			// the catalog slug, our slug-based cleanup misses the actual destination.
			// WP returns the conflicting path in error data — use it to remove the
			// orphan and retry once.
			if ( is_wp_error( $skin->result ) && 'folder_exists' === $skin->result->get_error_code() ) {
				$blocked = $skin->result->get_error_data();
				if ( is_string( $blocked ) && self::remove_folder_safely_in_plugin_dir( $blocked ) ) {
					self::log( 'folder_exists retry', array( 'removed' => $blocked ) );
					set_transient( $lock_key, 1, 60 );
					$skin     = new \WP_Ajax_Upgrader_Skin();
					$upgrader = new \Plugin_Upgrader( $skin );
					$result   = $upgrader->install( $package );
					delete_transient( $lock_key );
					self::log( 'retry done', array(
						'is_wp_err' => is_wp_error( $result ),
						'skin_err'  => is_wp_error( $skin->result ) ? $skin->result->get_error_code() : null,
					) );
				}
			}

			if ( is_wp_error( $skin->result ) ) {
				self::fail( 500, self::format_wp_error( $skin->result ) );
			}
			$skin_errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
			if ( $skin_errors instanceof \WP_Error && $skin_errors->has_errors() ) {
				self::fail( 500, self::format_wp_error( $skin_errors ) );
			}
			if ( is_wp_error( $result ) ) {
				self::fail( 500, self::format_wp_error( $result ) );
			}
			if ( false === $result || null === $result ) {
				self::fail( 500, Api_Error_Handler::message( 'download_failed' ) );
			}

			wp_clean_plugins_cache();
			$plugin_file = self::resolve_plugin_file( $upgrader, $slug );
			self::log( 'install success', array( 'plugin_file' => $plugin_file ) );

			self::respond_success( $slug, $plugin_file, false );
		}

		public static function ajax_update() {
			$slug        = self::verify_request( 'update_plugins' );
			$plugin_file = self::sanitize_plugin_file( isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '' );
			if ( '' === $plugin_file ) {
				self::fail( 400, __( 'Invalid plugin file.', 'gravitywp-license-handler' ) );
			}

			self::log( 'update start', array( 'slug' => $slug, 'plugin_file' => $plugin_file, 'user_id' => get_current_user_id() ) );

			$plugin = Hub_Manager::get_plugin_data( $slug );
			self::log( 'plugin fetched', array(
				'slug'            => $slug,
				'has_access'      => ! empty( $plugin['has_access'] ),
				'is_free'         => ! empty( $plugin['is_free'] ),
				'access_source'   => $plugin['access_source'] ?? null,
				'download_link'   => $plugin['download_link'] ?? null,
				'github_name'     => $plugin['github_name'] ?? null,
				'new_version'     => $plugin['new_version'] ?? null,
			) );

			if ( ! $plugin || empty( $plugin['has_access'] ) ) {
				self::fail( 403, Api_Error_Handler::message( 'insufficient_membership_level' ) );
			}

			$package = '';
			if ( ! empty( $plugin['download_link'] ) ) {
				$package = $plugin['download_link'];
			} elseif ( ! empty( $plugin['package'] ) ) {
				$package = $plugin['package'];
			}
			if ( empty( $package ) ) {
				self::fail( 400, Api_Error_Handler::message( 'download_failed' ) );
			}

			// Self-heal stale catalog URLs (same as install).
			if ( false !== strpos( $package, '.latest-stable.zip' ) ) {
				$package = str_replace( '.latest-stable.zip', '.zip', $package );
			}

			// wp.org canonical override for free plugins.
			if ( ! empty( $plugin['is_free'] ) ) {
				$wporg_slug = ! empty( $plugin['github_name'] ) ? $plugin['github_name'] : $slug;
				$canonical  = self::fetch_wporg_download_link( $wporg_slug );
				if ( '' !== $canonical ) {
					$package = $canonical;
				}
			}

			self::log( 'package resolved', array(
				'slug'    => $slug,
				'package' => $package,
				'is_free' => ! empty( $plugin['is_free'] ),
			) );

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Idempotent: if installed version already matches/exceeds the
			// hub's new_version, treat as success without running the upgrader.
			$installed_plugins = get_plugins();
			$installed_version = isset( $installed_plugins[ $plugin_file ]['Version'] ) ? (string) $installed_plugins[ $plugin_file ]['Version'] : '';
			$target_version    = isset( $plugin['new_version'] ) ? (string) $plugin['new_version'] : '';
			if ( '' === $installed_version ) {
				self::fail( 404, __( 'Plugin is not installed.', 'gravitywp-license-handler' ) );
			}
			if ( '' !== $target_version && version_compare( $installed_version, $target_version, '>=' ) ) {
				self::log( 'already current', array( 'installed' => $installed_version, 'target' => $target_version ) );
				self::respond_success( $slug, $plugin_file, is_plugin_active( $plugin_file ) );
				return;
			}

			self::load_upgrader_includes();

			if ( 'direct' !== get_filesystem_method() ) {
				self::fail( 412, Api_Error_Handler::message( 'filesystem_requires_ftp' ) );
			}

			$lock_key = 'gwp_hub_lock_' . md5( $slug );
			if ( get_transient( $lock_key ) ) {
				self::fail( 429, __( 'Operation already in progress.', 'gravitywp-license-handler' ) );
			}
			set_transient( $lock_key, 1, 60 );

			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );

			// Run the upgrader directly with our package URL — bypasses the
			// update_plugins transient (which the legacy bundled pluginUpdater
			// poisons with a missing `package` field on customer sites running
			// older addons).
			$result = $upgrader->run( array(
				'package'           => $package,
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => true,
				'clear_working'     => true,
				'hook_extra'        => array(
					'plugin' => $plugin_file,
					'type'   => 'plugin',
					'action' => 'update',
				),
			) );

			delete_transient( $lock_key );

			self::log( 'upgrader done', array(
				'package'   => $package,
				'is_wp_err' => is_wp_error( $result ),
				'skin_err'  => ( is_wp_error( $skin->result ) ) ? $skin->result->get_error_code() : null,
				'skin_data' => ( is_wp_error( $skin->result ) ) ? $skin->result->get_error_data() : null,
			) );

			if ( is_wp_error( $skin->result ) ) {
				self::fail( 500, self::format_wp_error( $skin->result ) );
			}
			$skin_errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
			if ( $skin_errors instanceof \WP_Error && $skin_errors->has_errors() ) {
				self::fail( 500, self::format_wp_error( $skin_errors ) );
			}
			if ( is_wp_error( $result ) ) {
				self::fail( 500, self::format_wp_error( $result ) );
			}
			if ( false === $result || null === $result ) {
				self::fail( 500, Api_Error_Handler::message( 'download_failed' ) );
			}

			wp_clean_plugins_cache();
			Hub_Manager::clear_cache();

			$is_active = is_plugin_active( $plugin_file );
			self::log( 'update success', array( 'plugin_file' => $plugin_file, 'is_active' => $is_active ) );

			self::respond_success( $slug, $plugin_file, $is_active );
		}

		public static function ajax_activate() {
			$slug        = self::verify_request( 'activate_plugins' );
			$plugin_file = self::sanitize_plugin_file( isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '' );
			if ( '' === $plugin_file ) {
				self::fail( 400, __( 'Invalid plugin file.', 'gravitywp-license-handler' ) );
			}

			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$result = activate_plugin( $plugin_file, '', false, true );
			if ( is_wp_error( $result ) ) {
				self::fail( 500, $result->get_error_message() );
			}

			self::respond_success( $slug, $plugin_file, true );
		}

		public static function ajax_deactivate() {
			$slug        = self::verify_request( 'activate_plugins' );
			$plugin_file = self::sanitize_plugin_file( isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '' );
			if ( '' === $plugin_file ) {
				self::fail( 400, __( 'Invalid plugin file.', 'gravitywp-license-handler' ) );
			}

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( array( $plugin_file ), true );

			self::respond_success( $slug, $plugin_file, false );
		}

		public static function ajax_delete() {
			$slug        = self::verify_request( 'delete_plugins' );
			$plugin_file = self::sanitize_plugin_file( isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '' );
			if ( '' === $plugin_file ) {
				self::fail( 400, __( 'Invalid plugin file.', 'gravitywp-license-handler' ) );
			}

			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( is_plugin_active( $plugin_file ) ) {
				self::fail( 409, __( 'Deactivate the plugin before deleting it.', 'gravitywp-license-handler' ) );
			}

			self::load_upgrader_includes();
			if ( 'direct' !== get_filesystem_method() ) {
				self::fail( 412, Api_Error_Handler::message( 'filesystem_requires_ftp' ) );
			}

			$result = delete_plugins( array( $plugin_file ) );
			if ( is_wp_error( $result ) ) {
				self::fail( 500, $result->get_error_message() );
			}
			if ( false === $result || null === $result ) {
				self::fail( 500, __( 'Delete failed. Please try again.', 'gravitywp-license-handler' ) );
			}

			wp_clean_plugins_cache();
			self::respond_success( $slug, '', false );
		}

		// ── Helpers ──────────────────────────────────────────────────────

		private static function verify_request( $cap ) {
			if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
				self::fail( 403, Api_Error_Handler::message( 'security_check_failed' ) );
			}
			if ( ! current_user_can( $cap ) ) {
				self::fail( 403, __( 'Insufficient permission.', 'gravitywp-license-handler' ) );
			}
			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			if ( '' === $slug ) {
				self::fail( 400, Api_Error_Handler::message( 'rest_missing_callback_param', array( 'slug' ) ) );
			}
			return $slug;
		}

		private static function sanitize_plugin_file( $raw ) {
			$raw = (string) $raw;
			if ( '' === $raw ) {
				return '';
			}
			if ( false !== strpos( $raw, '..' ) ) {
				return '';
			}
			if ( ! preg_match( '#^[A-Za-z0-9_\-./]+\.php$#', $raw ) ) {
				return '';
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = get_plugins();
			return isset( $all[ $raw ] ) ? $raw : '';
		}

		private static function resolve_plugin_file( $upgrader, $slug ) {
			if ( method_exists( $upgrader, 'plugin_info' ) ) {
				$info = $upgrader->plugin_info();
				if ( ! empty( $info ) ) {
					return $info;
				}
			}
			return self::find_installed_plugin_file( $slug );
		}

		/**
		 * Fetch the canonical download URL for a wp.org-hosted plugin via the
		 * official WordPress Plugin API. Returns the versioned `.zip` (direct
		 * 200 OK, no redirect).
		 */
		private static function fetch_wporg_download_link( $wporg_slug ) {
			$wporg_slug = sanitize_title( (string) $wporg_slug );
			if ( '' === $wporg_slug ) {
				return '';
			}
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $wporg_slug,
					'fields' => array(
						'sections'          => false,
						'short_description' => false,
						'downloaded'        => false,
						'reviews'           => false,
						'ratings'           => false,
						'icons'             => false,
						'banners'           => false,
					),
				)
			);
			if ( is_wp_error( $api ) ) {
				self::log( 'plugins_api error', array( 'slug' => $wporg_slug, 'error' => $api->get_error_message() ) );
				return '';
			}
			if ( empty( $api->download_link ) ) {
				self::log( 'plugins_api: no download_link', array( 'slug' => $wporg_slug ) );
				return '';
			}
			self::log( 'plugins_api OK', array(
				'slug'          => $wporg_slug,
				'version'       => $api->version ?? '',
				'download_link' => $api->download_link,
			) );
			return (string) $api->download_link;
		}

		/** Write a diagnostic line to wp-content/debug.log (always — short lines). */
		private static function log( $msg, $context = array() ) {
			$line = '[GWP_HUB_AJAX] ' . $msg;
			if ( ! empty( $context ) ) {
				$line .= ' ' . wp_json_encode( $context );
			}
			error_log( $line );
		}

		/** Flatten a WP_Error to a single readable line including data + child errors. */
		private static function format_wp_error( $error ) {
			if ( ! $error instanceof \WP_Error ) {
				return (string) $error;
			}
			$parts = array();
			foreach ( $error->get_error_codes() as $code ) {
				$message = $error->get_error_message( $code );
				$data    = $error->get_error_data( $code );
				$line    = $message ? $message : (string) $code;

				if ( is_wp_error( $data ) ) {
					$line .= ' — ' . self::format_wp_error( $data );
				} elseif ( is_scalar( $data ) && '' !== (string) $data ) {
					$line .= ' — ' . (string) $data;
				} elseif ( is_array( $data ) || is_object( $data ) ) {
					$encoded = wp_json_encode( $data );
					if ( false !== $encoded && '{}' !== $encoded && '[]' !== $encoded ) {
						$line .= ' — ' . $encoded;
					}
				}
				if ( $code && false === stripos( $line, (string) $code ) ) {
					$line .= ' [' . $code . ']';
				}
				$parts[] = trim( $line );
			}
			$parts = array_filter( $parts );
			return ! empty( $parts )
				? implode( ' | ', $parts )
				: __( 'Install error (no details available).', 'gravitywp-license-handler' );
		}

		/** Remove orphan folder named after slug, safe within WP_PLUGIN_DIR only. */
		private static function cleanup_orphan_plugin_folder( $slug ) {
			$slug = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $slug );
			if ( '' === $slug ) {
				return null;
			}
			$plugins_root = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
			$candidate    = wp_normalize_path( $plugins_root . $slug );

			if ( 0 !== strpos( $candidate, $plugins_root ) || $candidate === untrailingslashit( $plugins_root ) ) {
				return null;
			}
			if ( ! is_dir( $candidate ) || is_link( $candidate ) ) {
				return null;
			}
			return self::remove_folder_safely_in_plugin_dir( $candidate )
				? true
				: new \WP_Error( 'orphan_cleanup_failed', __( 'Plugin folder exists and could not be removed automatically.', 'gravitywp-license-handler' ), $candidate );
		}

		/**
		 * Remove a specific folder inside WP_PLUGIN_DIR via WP_Filesystem.
		 * Used by both the slug-based cleanup and the folder_exists retry.
		 */
		private static function remove_folder_safely_in_plugin_dir( $path ) {
			$plugins_root = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
			$target       = wp_normalize_path( (string) $path );
			if ( 0 !== strpos( $target, $plugins_root ) || $target === untrailingslashit( $plugins_root ) ) {
				return false;
			}
			if ( ! is_dir( $target ) || is_link( $target ) ) {
				return false;
			}
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! WP_Filesystem() ) {
				return false;
			}
			global $wp_filesystem;
			$deleted = $wp_filesystem->delete( $target, true );
			clearstatcache();
			return $deleted && ! is_dir( $target );
		}

		private static function find_installed_plugin_file( $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				return '';
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Normalize slug once — strip everything that isn't alnum, lowercase.
			// This handles the case where the catalog slug has no dashes
			// (e.g. "gravitywpadvancednumberfield") but the installed folder
			// does (e.g. "gravitywp-advanced-number-field/foo.php").
			$slug_norm = preg_replace( '/[^a-z0-9]/i', '', strtolower( $slug ) );

			foreach ( get_plugins() as $file => $_data ) {
				// Fast path: direct strpos match.
				if ( false !== strpos( $file, $slug ) ) {
					return $file;
				}
				// Fuzzy path: compare normalized folder portion to normalized slug.
				if ( '' !== $slug_norm ) {
					$folder = strstr( $file, '/', true ); // 'foo/bar.php' → 'foo'
					if ( false !== $folder && '' !== $folder ) {
						$folder_norm = preg_replace( '/[^a-z0-9]/i', '', strtolower( $folder ) );
						if ( $folder_norm === $slug_norm ) {
							return $file;
						}
					}
				}
			}
			return '';
		}

		private static function load_upgrader_includes() {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}

		private static function respond_success( $slug, $plugin_file, $is_active ) {
			$plugin = Hub_Manager::get_plugin_data( $slug );
			if ( ! $plugin ) {
				$plugin = array( 'slug' => $slug );
			}

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed_plugins = get_plugins();
			$status            = ! empty( $plugin['has_access'] ) ? 'unlocked' : 'locked';

			$footer_html = '';
			if ( class_exists( '\GravityWP\Shared\Hub_Page' )
				&& method_exists( '\GravityWP\Shared\Hub_Page', 'render_card_footer_html' )
			) {
				$footer_html = Hub_Page::render_card_footer_html( $plugin, $installed_plugins, $status );
			}

			wp_send_json_success( array(
				'slug'        => $slug,
				'plugin_file' => $plugin_file,
				'is_active'   => (bool) $is_active,
				'footer_html' => $footer_html,
			) );
		}

		private static function fail( $http, $message ) {
			self::log( 'fail', array( 'http' => $http, 'msg' => $message ) );
			wp_send_json_error(
				array( 'message' => (string) $message ),
				(int) $http
			);
		}
	}
}
