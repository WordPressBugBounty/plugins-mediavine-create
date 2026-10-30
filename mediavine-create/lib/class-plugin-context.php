<?php

namespace Mediavine\Create;

/**
 * Where and how the plugin is running: dev mode, asset/activation paths, the
 * Create Studio URLs, and Mediavine Control Panel detection.
 *
 * The Studio URL statics stay on Plugin (Plugin::$services_api_url and
 * friends) because they are read from outside the class; resolve_studio_urls()
 * is the only writer.
 */
class Plugin_Context {

	/**
	 * Whether Mediavine Control Panel is active and connected. Set once by
	 * set_mcp_status() at the top of Plugin::init().
	 *
	 * @var bool
	 */
	public static $mcp_enabled = false;

	/**
	 * Whether dev mode is on: shortens caches, enables debug output, and allows
	 * JS bundles to come from a dev server. Off unless a dev-tools plugin
	 * (create-studio-dev) filters it on.
	 *
	 * @return bool
	 */
	public static function is_dev_mode(): bool {
		return (bool) apply_filters( 'create_dev_mode', false );
	}

	/**
	 * Origin serving a JS bundle in dev mode.
	 *
	 * @param string $which Which bundle set: 'admin' or 'client'.
	 * @return string Origin with no trailing slash, or '' to use built assets.
	 */
	public static function dev_asset_origin( string $which ): string {
		if ( ! self::is_dev_mode() ) {
			return '';
		}

		$origin = apply_filters( 'create_dev_asset_origin', '', $which );

		if ( ! is_string( $origin ) || '' === trim( $origin ) ) {
			return '';
		}

		return untrailingslashit( trim( $origin ) );
	}

	/**
	 * Plugin root URL, with trailing slash.
	 *
	 * @return string
	 */
	public static function assets_url() {
			return MV_CREATE_URL;
	}

	/**
	 * Absolute path to the main plugin file, as used by activation hooks.
	 *
	 * @return string
	 */
	public static function get_activation_path() {
		return MV_CREATE_DIR . '/' . Plugin::PLUGIN_ACTIVATION_FILE;
	}

	/**
	 * Point the three Create Studio statics on Plugin at `create_studio_base_url`.
	 *
	 * Idempotent — the API URLs are re-derived from the base, never mutated in place.
	 *
	 * @return void
	 */
	public static function resolve_studio_urls() {
		$base = apply_filters( 'create_studio_base_url', Plugin::$create_studio_base_url );

		if ( ! is_string( $base ) || '' === trim( $base ) ) {
			return;
		}

		Plugin::$create_studio_base_url = untrailingslashit( trim( $base ) );
		Plugin::$services_api_url       = Plugin::$create_studio_base_url . '/api/v2';
		// Trailing slash required: the admin bundle concatenates paths onto this.
		Plugin::$js_services_api_url    = Plugin::$create_studio_base_url . '/api/v2/';
	}

	/**
	 * Determine whether Mediavine Control Panel is enabled.
	 */
	public static function set_mcp_status() {
		if (
			(
				Plugin_Checker::is_mcp_active()
			) && get_option( 'MVCP_site_id' )
		) {
			self::$mcp_enabled = true;
		}
	}

	/**
	 * Get MCP site id if it exists
	 *
	 * @return  string|false  Site id if it exists and MCP is active, or false
	 */
	public static function get_mcp_data() {
		$mcp_data = false;
		if ( self::$mcp_enabled ) {
			// TODO: Check if video support exists and is authorized with identity service
			$mcp_data = [
				'site_id' => get_option( 'MVCP_site_id' ),
				'version' => get_option( 'mv_mcp_version', null ),
			];
		}

		return $mcp_data;
	}
}
