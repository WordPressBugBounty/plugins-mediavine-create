<?php

namespace Mediavine\Create;

/**
 * FlyingPress Remove Unused CSS compatibility.
 *
 * Verified against FlyingPress 5.0.2 source:
 * - Config key `css_rucss_include_selectors` (src/Config.php)
 * - Sent to the cloud optimizer as part of `Config::$config`
 *   (src/CloudOptimizer.php::fetch_from_api)
 *
 * FlyingPress has no public apply_filters() hook for include selectors (confirmed
 * against their Developer Reference and 5.0.2 source). The documented UI field
 * "Include CSS Selectors" maps to that config key and supports partial matches
 * (e.g. `.cs-` keeps `.cs-widget-toolbar`).
 *
 * We merge Create's prefixes into the in-memory config only. Because
 * `Config::update_config()` persists whatever is in `Config::$config` before
 * firing `flying_press_update_config:after`, that after-hook must strip our
 * prefixes back out of the saved option (then re-merge into memory) so they
 * never stick in `FLYING_PRESS_CONFIG`.
 *
 * Class name uses underscore so the rascal autoloader picks it up (it
 * title-cases dash-separated filename segments — see
 * class-plugin-checker.php::load_rascal_plugin_classes).
 *
 * @see https://docs.flyingpress.com/en/articles/11406594-remove-unused-css
 */
class Flying_Press extends Rascal_Plugin {
	protected $slug        = 'flying-press';
	protected $class_check = [ 'FlyingPress\Config' ];

	/**
	 * Partial selector prefixes preserved by FlyingPress used-CSS (docs:
	 * partial matches are supported; leading `.` optional but clearer).
	 *
	 * @var string[]
	 */
	const INCLUDE_SELECTORS = [
		'.cs-',
		'.mv-create-',
	];

	/**
	 * Runs necessary hooks if FlyingPress is activated.
	 *
	 * @return void
	 */
	public function register_plugin_disable() {
		// Heal any prefixes previously leaked into the option, then apply in memory.
		$this->strip_persisted_include_selectors();
		$this->merge_include_selectors();
		add_action( 'flying_press_update_config:after', [ $this, 'after_config_update' ] );
	}

	/**
	 * After FlyingPress persists config, drop Create prefixes from the option
	 * and re-apply them in memory for the rest of the request.
	 *
	 * @return void
	 */
	public function after_config_update() {
		$this->strip_persisted_include_selectors();
		$this->merge_include_selectors();
	}

	/**
	 * Remove Create's selector prefixes from the persisted FlyingPress option.
	 *
	 * Uses update_option() directly (not Config::update_config()) to avoid
	 * re-entering flying_press_update_config:after.
	 *
	 * @return void
	 */
	public function strip_persisted_include_selectors() {
		if ( ! class_exists( '\FlyingPress\Config' ) || ! is_array( \FlyingPress\Config::$config ) ) {
			return;
		}

		$existing = [];
		if ( ! empty( \FlyingPress\Config::$config['css_rucss_include_selectors'] ) && is_array( \FlyingPress\Config::$config['css_rucss_include_selectors'] ) ) {
			$existing = \FlyingPress\Config::$config['css_rucss_include_selectors'];
		}

		$without_create = array_values( array_diff( $existing, self::INCLUDE_SELECTORS ) );
		if ( $without_create === array_values( $existing ) ) {
			return;
		}

		\FlyingPress\Config::$config['css_rucss_include_selectors'] = $without_create;
		update_option( 'FLYING_PRESS_CONFIG', \FlyingPress\Config::$config );
	}

	/**
	 * Merge Create selector prefixes into FlyingPress's in-memory RUCSS config.
	 *
	 * @return void
	 */
	public function merge_include_selectors() {
		if ( ! class_exists( '\FlyingPress\Config' ) || ! is_array( \FlyingPress\Config::$config ) ) {
			return;
		}

		$existing = [];
		if ( ! empty( \FlyingPress\Config::$config['css_rucss_include_selectors'] ) && is_array( \FlyingPress\Config::$config['css_rucss_include_selectors'] ) ) {
			$existing = \FlyingPress\Config::$config['css_rucss_include_selectors'];
		}

		\FlyingPress\Config::$config['css_rucss_include_selectors'] = array_values(
			array_unique( array_merge( $existing, self::INCLUDE_SELECTORS ) )
		);
	}
}
