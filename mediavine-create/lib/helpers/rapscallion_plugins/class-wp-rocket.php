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
		add_filter( 'rocket_rucss_safelist', [ $this, 'rucss_safelist' ] );
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

	/**
	 * Tells WP Rocket's "Remove Unused CSS" feature to keep selectors used by
	 * Create cards and Create Studio widgets.
	 *
	 * RUCSS prunes selectors it can't see in the rendered HTML at scan time.
	 * Create Studio widgets (servings adjuster, unit conversion, interactive
	 * mode CTAs) mount via JS after the scan, so without this their `.cs-*`
	 * styles get stripped — collapsing the toolbar and losing brand colors.
	 * The card chrome's `.mv-create-*` selectors can also be partially pruned
	 * when widgets are dynamically inserted into the card.
	 *
	 * Values are sent as-is to WP Rocket's SaaS as regex patterns (no `/`
	 * delimiters). This matches WP Rocket's own migration convention which
	 * rewrites user-entered `.class` entries to `(.*).class`. See
	 * inc/Engine/Optimization/RUCSS/Admin/Settings.php::update_safelist_items.
	 *
	 * @param array $safelist Regex patterns of selectors RUCSS must not prune.
	 * @return array
	 */
	public function rucss_safelist( $safelist ) {
		if ( ! is_array( $safelist ) ) {
			$safelist = [];
		}
		$safelist[] = '(.*).cs-';
		$safelist[] = '(.*).mv-create-';

		return $safelist;
	}
}
