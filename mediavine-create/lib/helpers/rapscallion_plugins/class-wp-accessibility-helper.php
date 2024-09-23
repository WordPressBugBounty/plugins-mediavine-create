<?php


namespace Mediavine\Create;


class Wp_Accessibility_Helper extends Rascal_Plugin {
	protected $slug           = 'wp-accessibility-helper';
	protected $function_check = [ 'wp_access_helper_create_container' ];

	public function register_plugin_disable() {
		add_action( 'mv_create_card_preview_render_footer', [ $this, 'disable_wp_accessibility_helper' ] );
		add_action( 'mv_create_card_before_print_render', [ $this, 'disable_wp_accessibility_helper' ] );
	}

	/**
	 * remove/disable the offending code
	 */
	public function disable_wp_accessibility_helper() {
		remove_action( 'wp_footer', 'wp_access_helper_create_container' );
	}
}
