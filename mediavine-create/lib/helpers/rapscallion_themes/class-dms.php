<?php


namespace Mediavine\Create;


class Dms extends Rascal_Theme {
	protected $slug = 'dms';

	public function register_theme_disable() {
		add_action( 'mv_create_card_preview_render_footer', [ $this, 'disable_dms_process_styles' ] );
		add_action( 'mv_create_card_before_print_render', [ $this, 'disable_dms_process_styles' ] );
	}

	/**
	 * remove/disable the offending code
	 */
	public function disable_dms_process_styles() {
		global $pagelines_editor;
		remove_action( 'wp_enqueue_scripts', [ $pagelines_editor, 'process_styles' ] );
	}
}
