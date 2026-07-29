<?php

namespace Mediavine\Create;

/**
 * Perfmatters Remove Unused CSS compatibility.
 *
 * Verified against Perfmatters 2.0.9 source (inc/classes/CSS.php) and the
 * public filters docs at https://perfmatters.io/docs/filters/:
 * - `perfmatters_rucss_excluded_selectors`
 * - `perfmatters_rucss_excluded_stylesheets`
 *
 * Perfmatters matches excluded selectors as literal tokens ending at a CSS
 * boundary (`(?=\s|\.|\:|,|\[|$)`) — prefix wildcards like `.cs-` do NOT keep
 * `.cs-widget-toolbar` or BEM modifiers like `.cs-widget-toolbar--inline`.
 * Create Studio widget CSS (`.cs-*`) ships inside the local card stylesheets,
 * so excluding those stylesheets from Used-CSS generation is the reliable way
 * to preserve the full `.cs-*` / `.mv-create-*` families. Concrete `.cs-*`
 * selector exclusions remain as defense-in-depth for any other sheet that
 * still gets parsed.
 */
class Perfmatters extends Rascal_Plugin {
	protected $slug        = 'perfmatters';
	protected $class_check = [ 'Perfmatters\CSS' ];

	/**
	 * Create Studio widget class selectors that mount via JS after RUCSS scan.
	 *
	 * Kept in sync with classes emitted in client/build/card-base.*.css.
	 *
	 * @var string[]
	 */
	const CS_SELECTORS = [
		'.cs-interactive-mode',
		'.cs-interactive-mode-btn',
		'.cs-interactive-mode-sticky-btn',
		'.cs-interactive-mode-tooltip-try',
		'.cs-interactive-nav-btn',
		'.cs-interactive-secondary-btn',
		'.cs-interactive-submit-btn',
		'.cs-servings-adjuster',
		'.cs-servings-adjuster-btn',
		'.cs-servings-adjuster-buttons',
		'.cs-servings-adjuster-inner',
		'.cs-servings-adjuster-label',
		'.cs-unit-conversion',
		'.cs-unit-conversion-btn',
		'.cs-unit-conversion-buttons',
		'.cs-unit-conversion-label',
		'.cs-unit-conversion-wrapper',
		'.cs-widget-toolbar',
		'.cs-widget-toolbar--centered',
		'.cs-widget-toolbar--inline',
		'.cs-widget-toolbar--left',
		'.cs-widget-toolbar--right',
		'.cs-widget-toolbar--toolbar',
	];

	/**
	 * URL fragments matching Create's published card CSS filenames.
	 *
	 * Matched via strpos against the stylesheet href (see CSS.php). Excluding
	 * these leaves the original <link> tags untouched so full card + widget
	 * CSS stays available even when stylesheet behavior is Delay/Remove.
	 *
	 * @var string[]
	 */
	const STYLESHEET_EXCLUSIONS = [
		'client/build/card-base.',
		'client/build/card-big-image.',
		'client/build/card-centered.',
		'client/build/card-centered-dark.',
		'client/build/card-dark.',
		'client/build/card-editorial.',
		'client/build/card-modern.',
		'client/build/card-square.',
	];

	/**
	 * Runs necessary hooks if Perfmatters is activated.
	 *
	 * @return void
	 */
	public function register_plugin_disable() {
		add_filter( 'perfmatters_rucss_excluded_selectors', [ $this, 'excluded_selectors' ] );
		add_filter( 'perfmatters_rucss_excluded_stylesheets', [ $this, 'excluded_stylesheets' ] );
	}

	/**
	 * Keep Create Studio widget selectors out of Perfmatters' unused-CSS prune.
	 *
	 * @param array $selectors Selector strings RUCSS must not prune.
	 * @return array
	 */
	public function excluded_selectors( $selectors ) {
		if ( ! is_array( $selectors ) ) {
			$selectors = [];
		}

		return array_values( array_unique( array_merge( $selectors, self::CS_SELECTORS ) ) );
	}

	/**
	 * Keep Create card stylesheets out of Perfmatters Used-CSS generation.
	 *
	 * @param array $stylesheets URL fragments of stylesheets to leave intact.
	 * @return array
	 */
	public function excluded_stylesheets( $stylesheets ) {
		if ( ! is_array( $stylesheets ) ) {
			$stylesheets = [];
		}

		return array_values( array_unique( array_merge( $stylesheets, self::STYLESHEET_EXCLUSIONS ) ) );
	}
}
