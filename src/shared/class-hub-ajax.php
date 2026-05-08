<?php
/**
 * Hub AJAX — Install / Activate / Deactivate handlers.
 *
 * Replaces the old browser-download flow on the Hub page. Each handler
 * verifies a nonce + capability, performs the WP plumbing, and returns
 * the freshly rendered card footer HTML so the UI updates in place.
 *
 * @package GravityWP\Shared
 * @since   2.1.0
 */

namespace GravityWP\Shared;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\GravityWP\Shared\Hub_Ajax' ) ) {

	/**
	 * Class Hub_Ajax
	 *
	 * Static class. Three admin-ajax endpoints:
	 *   - wp_ajax_gwp_hub_install
	 *   - wp_ajax_gwp_hub_activate
	 *   - wp_ajax_gwp_hub_deactivate
	 */
	class Hub_Ajax {

		/**
		 * Nonce action used by all three endpoints.
		 *
		 * @var string
		 */
		const NONCE_ACTION = 'gwp_hub_ajax';

		/**
		 * Idempotency guard — prevents double-registration when the library
		 * is bundled inside multiple plugins.
		 *
		 * @var bool
		 */
		private static $registered = false;

		/**
		 * Register the AJAX hooks. Safe to call repeatedly.
		 *
		 * @return void
		 */
		public static function init() {
			if ( self::$registered ) {
				return;
			}
			self::$registered = true;

			add_action( 'wp_ajax_gwp_hub_install', array( self::class, 'ajax_install' ) );
			add_action( 'wp_ajax_gwp_hub_activate', array( self::class, 'ajax_activate' ) );
			add_action( 'wp_ajax_gwp_hub_deactivate', array( self::class, 'ajax_deactivate' ) );
			add_action( 'wp_ajax_gwp_hub_delete', array( self::class, 'ajax_delete' ) );
		}

		// ── Endpoints ─────────────────────────────────────────────────────

		/**
		 * Install a plugin from the hub catalog using Plugin_Upgrader.
		 *
		 * @return void
		 */
		public static function ajax_install() {
			$slug = self::verify_request( 'install_plugins' );

			// Always re-fetch authoritative plugin data — never trust client input.
			$plugin = Hub_Manager::get_plugin_data( $slug );
			if ( ! $plugin || empty( $plugin['has_access'] ) ) {
				self::fail( 403, __( 'License does not cover this plugin.', 'gravitywp-license-handler' ) );
			}

			$package = '';
			if ( ! empty( $plugin['download_link'] ) ) {
				$package = $plugin['download_link'];
			} elseif ( ! empty( $plugin['package'] ) ) {
				$package = $plugin['package'];
			}
			if ( empty( $package ) ) {
				self::fail( 400, __( 'Download URL missing from hub response.', 'gravitywp-license-handler' ) );
			}

			self::load_upgrader_includes();

			// AJAX cannot prompt for FTP credentials; bail early with a readable hint.
			if ( 'direct' !== get_filesystem_method() ) {
				self::fail(
					412,
					__( 'Filesystem requires FTP credentials. Please install via Plugins → Add New instead.', 'gravitywp-license-handler' )
				);
			}

			// Best-effort serialization across rapid double-clicks.
			$lock_key = 'gwp_hub_lock_' . md5( $slug );
			if ( get_transient( $lock_key ) ) {
				self::fail( 429, __( 'Operation already in progress.', 'gravitywp-license-handler' ) );
			}
			set_transient( $lock_key, 1, 60 );

			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $package );

			delete_transient( $lock_key );

			if ( is_wp_error( $skin->result ) ) {
				self::fail( 500, $skin->result->get_error_message() );
			}
			if ( $skin->get_errors()->has_errors() ) {
				self::fail( 500, implode( ' ', $skin->get_error_messages() ) );
			}
			if ( is_wp_error( $result ) ) {
				self::fail( 500, $result->get_error_message() );
			}
			if ( false === $result || null === $result ) {
				self::fail( 500, __( 'Install failed. Please try again.', 'gravitywp-license-handler' ) );
			}

			wp_clean_plugins_cache();
			$plugin_file = self::resolve_plugin_file( $upgrader, $slug );

			self::respond_success( $slug, $plugin_file, false );
		}

		/**
		 * Activate an already-installed plugin.
		 *
		 * @return void
		 */
		public static function ajax_activate() {
			$slug        = self::verify_request( 'activate_plugins' );
			$plugin_file = self::sanitize_plugin_file( isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '' );
			if ( '' === $plugin_file ) {
				self::fail( 400, __( 'Invalid plugin file.', 'gravitywp-license-handler' ) );
			}

			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			// Silent + non-network: returns WP_Error on fatal/load failure.
			$result = activate_plugin( $plugin_file, '', false, true );
			if ( is_wp_error( $result ) ) {
				self::fail( 500, $result->get_error_message() );
			}

			self::respond_success( $slug, $plugin_file, true );
		}

		/**
		 * Deactivate an active plugin.
		 *
		 * @return void
		 */
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

		/**
		 * Delete an installed (and inactive) plugin.
		 *
		 * Mirrors WP core: refuses to delete an active plugin — caller must
		 * deactivate first.
		 *
		 * @return void
		 */
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
				self::fail(
					412,
					__( 'Filesystem requires FTP credentials. Please delete via Plugins → Installed Plugins instead.', 'gravitywp-license-handler' )
				);
			}

			$result = delete_plugins( array( $plugin_file ) );
			if ( is_wp_error( $result ) ) {
				self::fail( 500, $result->get_error_message() );
			}
			if ( false === $result || null === $result ) {
				self::fail( 500, __( 'Delete failed. Please try again.', 'gravitywp-license-handler' ) );
			}

			wp_clean_plugins_cache();

			// After delete, plugin_file no longer exists — pass empty so card returns to "Install" state.
			self::respond_success( $slug, '', false );
		}

		// ── Helpers ───────────────────────────────────────────────────────

		/**
		 * Verify nonce + capability and return the sanitized slug.
		 *
		 * Bails (does not return) on failure.
		 *
		 * @param string $cap Required capability.
		 * @return string Sanitized slug.
		 */
		private static function verify_request( $cap ) {
			if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
				self::fail( 403, __( 'Security check failed.', 'gravitywp-license-handler' ) );
			}
			if ( ! current_user_can( $cap ) ) {
				self::fail( 403, __( 'Insufficient permission.', 'gravitywp-license-handler' ) );
			}
			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			if ( '' === $slug ) {
				self::fail( 400, __( 'Missing plugin slug.', 'gravitywp-license-handler' ) );
			}
			return $slug;
		}

		/**
		 * Validate a `dir/file.php` plugin path against the installed list.
		 *
		 * @param string $raw Raw input.
		 * @return string Validated plugin file or empty string.
		 */
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

		/**
		 * Find the just-installed plugin's main file.
		 *
		 * @param \Plugin_Upgrader $upgrader Upgrader instance.
		 * @param string           $slug     Plugin slug.
		 * @return string Plugin file path (e.g. "foo/foo.php"), or empty string.
		 */
		private static function resolve_plugin_file( $upgrader, $slug ) {
			if ( method_exists( $upgrader, 'plugin_info' ) ) {
				$info = $upgrader->plugin_info();
				if ( ! empty( $info ) ) {
					return $info;
				}
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $_data ) {
				if ( false !== strpos( $file, $slug ) ) {
					return $file;
				}
			}
			return '';
		}

		/**
		 * Pull in WP Upgrader infrastructure.
		 *
		 * @return void
		 */
		private static function load_upgrader_includes() {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}

		/**
		 * Send the canonical success payload — including server-rendered footer HTML.
		 *
		 * @param string $slug        Plugin slug.
		 * @param string $plugin_file Resolved plugin file path.
		 * @param bool   $is_active   Whether the plugin is now active.
		 * @return void
		 */
		private static function respond_success( $slug, $plugin_file, $is_active ) {
			$plugin = Hub_Manager::get_plugin_data( $slug );
			if ( ! $plugin ) {
				$plugin = array( 'slug' => $slug );
			}

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed_plugins = get_plugins();

			$status = ! empty( $plugin['has_access'] ) ? 'unlocked' : 'locked';

			$footer_html = '';
			if ( class_exists( '\GravityWP\Shared\Hub_Page' )
				&& method_exists( '\GravityWP\Shared\Hub_Page', 'render_card_footer_html' )
			) {
				$footer_html = Hub_Page::render_card_footer_html( $plugin, $installed_plugins, $status );
			}

			wp_send_json_success(
				array(
					'slug'        => $slug,
					'plugin_file' => $plugin_file,
					'is_active'   => (bool) $is_active,
					'footer_html' => $footer_html,
				)
			);
		}

		/**
		 * Send a failure response and exit.
		 *
		 * @param int    $http    HTTP status.
		 * @param string $message Message for the client.
		 * @return void
		 */
		private static function fail( $http, $message ) {
			wp_send_json_error(
				array( 'message' => (string) $message ),
				(int) $http
			);
		}
	}
}
