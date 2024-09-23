<?php
namespace Mediavine\Create;

use Mediavine\WordPress\Support\Str;
use Mediavine\WordPress\Support\Arr;

class Theme_Checker {

	public static $instance;

	private static $active_theme;

	private static $parent_theme;

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
		$this->get_active_theme();
		$this->load_rascal_theme_classes();
		add_action( 'plugins_loaded', [ $this, 'register_rascals' ] );
		//do the functions again after switching themes
		add_action( 'after_switch_theme', [ $this, 'reload_active_theme' ] );
		add_action( 'after_switch_theme', [ $this, 'call_load_rascal' ] );
		add_action( 'wp_head', [ $this, 'add_trellis_critical_css_allowlist' ] );

	}

	public function register_rascals() {
		do_action( 'mv_helpers_register_rascal_themes' );
	}

	public static function reload_active_theme() {
		self::$instance->get_active_theme();
	}

	/**
	 * Gets the active theme and parent theme, if necessary
	 *
	 * @return void
	 */
	private function get_active_theme() {
		$theme              = wp_get_theme();
		self::$active_theme = get_option( 'stylesheet' );
		self::$parent_theme = $theme->get_template();
	}

	public static function call_load_rascal() {
		self::$instance->load_rascal_theme_classes();
	}

	private function load_rascal_theme_classes() {
		$rascal_dir = dirname( __FILE__ ) . '/rapscallion_themes';
		foreach ( scandir( $rascal_dir ) as $rascal ) {
			if ( substr( $rascal, -4 ) !== '.php' ) {
				continue;
			}

			$rascal_class = Str::replace( '.php', '', $rascal );
			$rascal_class = Str::replace( 'class-', '', $rascal_class );
			$rascal_class = explode( '-', $rascal_class );
			$rascal_class = implode( '_', Arr::map( $rascal_class, 'ucwords' ) );

			if ( 'Rascal_Theme' === $rascal_class ) {
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
	 * Checks based on a slug to see if a theme is active
	 *
	 * @param string $theme_slug Slug of theme to check
	 * @return bool True if theme is active, false if not found
	 */
	public static function is_theme_active( $theme_slug ) {
		if ( $theme_slug === self::$active_theme || $theme_slug === self::$parent_theme ) {
			return true;
		}

		// No evidence of theme found
		return false;
	}

	/**
	 * Is theme Genesis or Genesis child?
	 *
	 * @return bool
	 */
	public static function is_genesis() {
		return apply_filters( 'mv_create_is_theme_genesis', self::is_theme_active( 'genesis' ) );
	}

	/**
	 * Is theme Trellis or Trellis child?
	 *
	 * @return bool
	 */
	public static function is_trellis() {
		return apply_filters( 'mv_create_is_theme_trellis', self::is_theme_active( 'mediavine-trellis' ) );
	}

	/**
	 * Checks if a theme supports specific args to a feature.
	 *
	 * @param string $feature The feature to check
	 * @param string|array $args Extra arguments to be checked against certain features
	 * @param string $operator Defaults to 'OR'. Only other option is 'AND'
	 * @return bool Does the theme have support
	 */
	public static function current_theme_supports_args( $feature, $args, $operator = 'OR' ) {
		$theme_support = get_theme_support( $feature );

		// If the feature doesn't exist or has no args, then just return early
		if ( empty( $theme_support[0] ) ) {
			return false;
		}

		// Reduce logic if we have a single string
		if ( is_string( $args ) ) {
			return ( in_array( $args, $theme_support[0], true ) );
		}

		if ( is_array( $args ) ) {
			// Check that ALL params exist
			if ( 'AND' === $operator ) {
				foreach ( $args as $arg ) {
					if ( ! in_array( $arg, $theme_support[0], true ) ) {
						return false;
					}
				}

				return true;
			// Check that any params exist, default
			} else {
				foreach ( $args as $arg ) {
					if ( in_array( $arg, $theme_support[0], true ) ) {
						return true;
					}
				}

				return false;
			}
		}

		// $args neither string nor array, so play on the safe site and return false
		return false;
	}

	/**
	 * Is theme THA enabled?
	 *
	 * @return bool
	 */
	public static function is_tha_enabled( $args = '' ) {
		if ( empty( $args ) ) {
			return current_theme_supports( 'tha_hooks' );
		}

		return self::current_theme_supports_args( 'tha_hooks', $args );
	}

	public function add_trellis_critical_css_allowlist() {
		$hands_free_setting = (bool) \Mediavine\Settings::get_setting( 'mv_create_enable_hands_free_mode', false );
		if ( self::is_trellis() && $hands_free_setting ) {
			add_filter( 'mv_trellis_css_allowlist', [ $this, 'add_handsfree_to_css_allowlist' ], 999 );
		}
	}

	public function add_handsfree_to_css_allowlist( array $allowlist ) {
		$allowlist[] = 'handsfree';
		$allowlist[] = 'hands-free';

		return $allowlist;
	}
}
