<?php
namespace Mediavine\Create;

use Mediavine\WordPress\Support\Str;
use Mediavine\WordPress\Support\Arr;

/**
 * Plugin Checker class
 */
class Plugin_Checker {

	public static $instance;

	public static $class_checks = [
		'mediavine-control-panel' => [ 'Mediavine\MCP\MV_Control_Panel', 'MV_Control_Panel', 'MVCP' ],
		'mediavine-create'        => [ 'Mediavine\Create\Plugin' ],
		'wordpress-seo'           => [ 'WPSEO_Options' ],
	];

	public static $function_checks = [];

	/**
	 * Makes sure class is only instantiated once and runs init
	 *
	 * @return object Instantiated class
	 */
	static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Hooks to be run on class instantiation
	 *
	 * @return void
	 */
	public function init() {
		$this->load_rascal_plugin_classes();
		add_action( 'plugins_loaded', [ $this, 'register_rascals' ] );
	}

	public function register_rascals() {
		do_action( 'mv_helpers_register_rascal' );
	}


	private function load_rascal_plugin_classes() {
		$rascal_dir = dirname( __FILE__ ) . '/rapscallion_plugins';
		foreach ( scandir( $rascal_dir ) as $rascal ) {
			if ( substr( $rascal, -4 ) !== '.php' ) {
				continue;
			}

			$rascal_class = Str::replace( '.php', '', $rascal );
			$rascal_class = Str::replace( 'class-', '', $rascal_class );
			$rascal_class = explode( '-', $rascal_class );
			$rascal_class = implode( '_', Arr::map( $rascal_class, 'ucwords' ) );

			if ( 'Rascal_Plugin' === $rascal_class ) {
				continue;
			}

			$rascal_class = __NAMESPACE__ . '\\' . $rascal_class;

			if ( ! class_exists( $rascal_class ) ) {
				continue;
			}

			$rascal_instance = new $rascal_class;
			$rascal_instance->init();

		}
	}

	/**
	 * Checks based on a slug to see if a plugin is active
	 *
	 * @param string|array $plugin_slug Slug of plugin or an array of slugs to check
	 * @return bool True if plugin is active, false if not found
	 */
	public static function is_plugin_active( $plugin_slug ) {
		if ( is_array( $plugin_slug ) ) {
			foreach ( $plugin_slug as $slug ) {
				$active = self::is_plugin_active( $slug );

				// Return true as soon as we find a match
				if ( $active ) {
					return true;
				}
			}

			return false;
		}
		// Make sure this is included so `is_plugin_active()` works
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		/**
		 * Filters the plugin slugs and class names to check for active plugins
		 *
		 * @param array $class_checks Array with plugin slug as keys and classes to check in a value array
		 */
		$class_checks = apply_filters( 'mv_create_plugin_class_checks', self::$class_checks );

		// Loop through certain classes to check for active plugin
		if (
			! empty( $class_checks[ $plugin_slug ] ) &&
			is_array( $class_checks[ $plugin_slug ] )
		) {
			foreach ( $class_checks[ $plugin_slug ] as $class_to_check ) {
				if ( class_exists( $class_to_check ) ) {
					return true;
				}
			}

			// If plugin slug was found in the class checks, no sense performing extra logic
			return false;
		}

		/**
		 * Filters the plugin slugs and function names to check for active plugins
		 *
		 * @param array $class_checks Array with plugin slug as keys and functions to check in a value array
		 */
		$function_checks = apply_filters( 'mv_create_plugin_function_checks', self::$function_checks );

		// If classes not found, loop through certain functions to check for active plugin
		if (
			! empty( $function_checks[ $plugin_slug ] ) &&
			is_array( $function_checks[ $plugin_slug ] )
		) {
			foreach ( $function_checks[ $plugin_slug ] as $function_to_check ) {
				if ( function_exists( $function_to_check ) ) {
					return true;
				}
			}

			// If plugin slug was found in the function checks, no sense performing extra logic
			return false;
		}

		// If active plugin not found from filtered check array, do final checks, including basic is_plugin_active check
		if (
			class_exists( $plugin_slug ) ||
			function_exists( $plugin_slug ) ||
			is_plugin_active( "$plugin_slug/$plugin_slug.php" )
		) {
			return true;
		}

		// No evidence of plugin found
		return false;
	}

	public static function all_active_plugins() {
		/**
		 * Filters the plugin slugs and class names to check for active plugins
		 *
		 * @param array $class_checks Array with plugin slug as keys and classes to check in a value array
		 */
		$class_checks   = apply_filters( 'mv_create_plugin_class_checks', self::$class_checks );
		$active_plugins = [];

		foreach ( $class_checks as $plugin_slug => $plugin_class ) {
			if ( self::is_plugin_active( $plugin_slug ) ) {
				$active_plugins[] = $plugin_slug;
			}
		}

		return $active_plugins;
	}

	/**
	 * Checks if Mediavine Control Panel is active
	 * @return bool
	 */
	public static function is_mcp_active() {
		return class_exists( 'Mediavine\MCP\MV_Control_Panel' ) || class_exists( 'MV_Control_Panel' ) || class_exists( 'MVCP' );
	}

	/**
	 * Checks if is Journey site running Grow for WP.
	 * @return bool
	 */
	public static function is_journey_site() {
		// Same checks used by Grow for WP
		if ( empty( get_option( 'grow_site_uuid' ) ) ) {
			return false;
		}

		if ( empty( get_option( 'grow_journey_status' ) ) ) {
			return false;
		}

		return class_exists( 'Grow\Plugin' );
	}

	/**
	 * Checks if site running Mediavine or Journey ads.
	 * @return bool
	 */
	public static function has_mv_ads() {
		// MV ads
		if ( self::is_mcp_active() && get_option( 'MVCP_site_id' ) ) {
			return true;
		}

		return self::is_journey_site();
	}
}
