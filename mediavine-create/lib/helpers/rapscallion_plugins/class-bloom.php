<?php


namespace Mediavine\Create;


class Bloom extends Rascal_Plugin {
	protected $slug        = 'bloom';
	protected $class_check = [ 'ET_Bloom' ];

	public function register_plugin_disable() {
		add_action( 'mv_create_card_preview_render_footer', [ $this, 'disable_bloom' ] );
		add_action( 'mv_create_card_before_print_render', [ $this, 'disable_bloom' ] );
	}

	/**
	 * remove/disable the offending code
	 */
	public function disable_bloom() {
		global $et_bloom;
		remove_action( 'wp_footer', [ $et_bloom, 'display_popup' ] );
	}
}
