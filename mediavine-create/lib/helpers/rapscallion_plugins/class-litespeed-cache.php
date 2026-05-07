<?php


namespace Mediavine\Create;

// Class name uses lowercase 's' in 'Litespeed' so the rascal autoloader
// picks it up (it title-cases dash-separated filename segments — see
// class-plugin-checker.php::load_rascal_plugin_classes).
class Litespeed_Cache extends Rascal_Plugin {
	protected $slug        = 'litespeed-cache';
	protected $class_check = [ 'LiteSpeed\Core' ];

	/**
	 * Runs necessary hooks if LiteSpeed Cache plugin is activated
	 *
	 * @return void
	 */
	public function register_plugin_disable() {
		add_filter( 'litespeed_ucss_whitelist', [ $this, 'ucss_whitelist' ] );
	}

	/**
	 * Tells LiteSpeed Cache's "Unique CSS" (UCSS) feature to keep selectors
	 * used by Create cards and Create Studio widgets.
	 *
	 * UCSS strips selectors it can't see in the rendered HTML at scan time.
	 * Create Studio widgets mount via JS after the scan, so without this
	 * their `.cs-*` styles get pruned. LiteSpeed's whitelist accepts plain
	 * selector strings (regex via `/^.../` is also supported as of v6).
	 *
	 * @param array $whitelist Selector strings UCSS must not prune.
	 * @return array
	 */
	public function ucss_whitelist( $whitelist ) {
		if ( ! is_array( $whitelist ) ) {
			$whitelist = [];
		}
		$whitelist[] = '.cs-';
		$whitelist[] = '.mv-create-';

		return $whitelist;
	}
}
