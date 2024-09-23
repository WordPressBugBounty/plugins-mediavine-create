<?php

namespace Mediavine\Create;

use Mediavine\Create\Theme_Checker;

abstract class Rascal_Theme {

	protected $slug = null;

	function init() {
		add_action( 'mv_helpers_register_rascal_themes', [ $this, 'maybe_register' ] );
	}

	/**
	 * if this theme is active, or is the parent of the active theme, register the disable function
	 */
	function maybe_register() {
		if ( empty( $this->slug ) ) {
			//maybe throw error
			return;
		}

		if ( ! Theme_Checker::is_theme_active( $this->slug ) ) {
			return;
		}

		$this->register_theme_disable();

	}

	/**
	 * hook in to disable this plugin as needed
	 *
	 * @return null
	 */
	abstract function register_theme_disable();
}
