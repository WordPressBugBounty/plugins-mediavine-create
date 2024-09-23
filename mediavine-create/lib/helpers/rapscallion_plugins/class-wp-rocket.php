<?php


namespace Mediavine\Create;

// Lowercase P so the class loads properly
class Wp_Rocket extends Rascal_Plugin {
	protected $slug        = 'wp-rocket';
	protected $class_check = [ 'WP_Rocket\Plugin' ];

	/**
	 * Runs necessary hooks if WP Rocket plugin is activated
	 *
	 * @return void
	 */
	public function register_plugin_disable() {
		add_action( 'rocket_excluded_inline_js_content', [ $this, 'exclude_create_inline_js' ] );
	}

	/**
	 * Exclude Create's inline code from being combined in WP Rocket
	 *
	 * @param array $excluded_inline Patterns of inline JS excluded from being combined
	 * @return array Updated patterns of inline JS excluded from being combined
	 */
	public function exclude_create_inline_js( $excluded_inline ) {
		$excluded_inline[] = 'MV_CREATE_SETTINGS';

		return $excluded_inline;
	}
}
