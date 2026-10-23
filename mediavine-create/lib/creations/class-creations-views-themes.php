<?php
namespace Mediavine\Create;

/**
 * Single source of truth for card theme slugs, labels, and Pro gating.
 *
 * Consumed by style registration, shortcode theme preview/fallback,
 * apply-theme validation, and the preview banner.
 */
class Creations_Views_Themes {

	/**
	 * Free style used when a gated theme declares no fallback of its own.
	 */
	const DEFAULT_FALLBACK = 'big-image';

	/**
	 * Theme registry keyed by style slug.
	 *
	 * Order is the user-facing preview-selector order. `dark` is CSS-only
	 * (falls back to square hooks). Gated themes declare a free fallback.
	 *
	 * @return array<string, array{label: string, gated: bool, fallback?: string}>
	 */
	public static function get_registry() {
		return [
			'square'        => [
				'label' => 'Simple Square',
				'gated' => false,
			],
			'dark'          => [
				'label' => 'Dark Simple Square',
				'gated' => false,
			],
			'centered'      => [
				'label' => 'Classy Circle',
				'gated' => false,
			],
			'centered-dark' => [
				'label' => 'Dark Classy Circle',
				'gated' => false,
			],
			'big-image'     => [
				'label' => 'Hero Image',
				'gated' => false,
			],
			'editorial'     => [
				'label'    => 'Editorial',
				'gated'    => true,
				'fallback' => 'big-image',
			],
			'modern'        => [
				'label'    => 'Modern',
				'gated'    => true,
				'fallback' => 'big-image',
			],
		];
	}

	/**
	 * All valid theme style slugs (includes CSS-only styles like dark).
	 *
	 * @return string[]
	 */
	public static function get_style_slugs() {
		return array_keys( self::get_registry() );
	}

	/**
	 * Theme slugs that require Pro (or higher).
	 *
	 * @return string[]
	 */
	public static function get_gated_style_slugs() {
		$gated = [];
		foreach ( self::get_registry() as $slug => $meta ) {
			if ( ! empty( $meta['gated'] ) ) {
				$gated[] = $slug;
			}
		}
		return $gated;
	}

	/**
	 * Map of style slug => display label for previews/selectors.
	 *
	 * @return array<string, string>
	 */
	public static function get_labels() {
		$labels = [];
		foreach ( self::get_registry() as $slug => $meta ) {
			$labels[ $slug ] = $meta['label'];
		}
		return $labels;
	}

	/**
	 * Whether a style slug is a known theme.
	 *
	 * @param string $style Style slug.
	 * @return bool
	 */
	public static function is_valid( $style ) {
		return isset( self::get_registry()[ $style ] );
	}

	/**
	 * Whether a style is Pro-gated.
	 *
	 * @param string $style Style slug.
	 * @return bool
	 */
	public static function is_gated( $style ) {
		$registry = self::get_registry();
		return isset( $registry[ $style ] ) && ! empty( $registry[ $style ]['gated'] );
	}

	/**
	 * Theme gating map for the admin localization.
	 *
	 * Shipped as `themes` inside the __SUBSCRIPTION__ payload so the admin reads
	 * which themes are gated (and what each falls back to) from this registry
	 * instead of a hand-copied JS list.
	 *
	 * @return array{gated: string[], fallbacks: array<string, string>, default_fallback: string}
	 */
	public static function get_localized_gating() {
		$fallbacks = [];
		foreach ( self::get_registry() as $slug => $meta ) {
			if ( ! empty( $meta['gated'] ) ) {
				$fallbacks[ $slug ] = isset( $meta['fallback'] ) ? $meta['fallback'] : self::DEFAULT_FALLBACK;
			}
		}

		return [
			'gated'            => array_keys( $fallbacks ),
			'fallbacks'        => $fallbacks,
			'default_fallback' => self::DEFAULT_FALLBACK,
		];
	}

	/**
	 * Fallback style for a gated theme (or the style itself if not gated / unknown).
	 *
	 * @param string $style Style slug.
	 * @return string
	 */
	public static function get_fallback_style( $style ) {
		$registry = self::get_registry();
		if ( isset( $registry[ $style ]['fallback'] ) ) {
			return $registry[ $style ]['fallback'];
		}
		return $style;
	}
}
