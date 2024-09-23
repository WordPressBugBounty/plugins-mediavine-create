<?php

namespace Mediavine\Create;

use Mediavine\Create\Plugin_Checker;

abstract class Rascal_Plugin {

	protected $slug           = null;
	protected $class_check    = [];
	protected $function_check = [];

	function init() {
		add_action( 'mv_helpers_register_rascal', [ $this, 'maybe_register' ] );
	}

	/**
	 * if this plugin exists, register the disable function
	 */
	function maybe_register() {
		if ( empty( $this->slug ) ) {
			//maybe throw error
			return;
		}

		$this->register_plugin_check();

		if ( ! Plugin_Checker::is_plugin_active( $this->slug ) ) {
			return;
		}

		$this->register_plugin_disable();

	}

	/**
	 * add class/function check to the plugin checker
	 */
	public function register_plugin_check() {
		if ( ! empty( $this->class_check ) ) {
			Plugin_Checker::$class_checks[ $this->slug ] = $this->class_check;
			return;
		}

		if ( ! empty( $this->function_check ) ) {
			Plugin_Checker::$function_checks[ $this->slug ] = $this->function_check;
			return;
		}
	}

	/**
	 * hook in to disable this plugin as needed
	 *
	 * @return null
	 */
	abstract function register_plugin_disable();
}
