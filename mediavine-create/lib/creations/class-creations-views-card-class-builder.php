<?php
namespace Mediavine\Create;

/**
 * Builds CSS class lists for Create card markup.
 *
 * Extracted from Creations_Views::prep_creation_view() (CRE-129 step 4).
 */
class Creations_Views_Card_Class_Builder {

	/**
	 * Build the base card class list (before image-presence classes).
	 *
	 * @param array $atts Shortcode attributes (key, type, style, print).
	 * @return string[]
	 */
	public static function build_base_classes( $atts ) {
		$classes = [
			'mv-create-card',
			'mv-create-card-' . $atts['key'],
			'mv-' . $atts['type'] . '-card',
			'mv-create-card-style-' . str_replace( '/', '-', $atts['style'] ),
		];

		// Only have mv-no-js class if not print.
		if ( empty( $atts['print'] ) ) {
			$classes[] = 'mv-no-js';
		}

		// Add specific classes to print layout.
		if ( ! empty( $atts['print'] ) ) {
			$classes[] = 'mv-create-xl';
			$classes[] = 'js';
		}

		$settings_group = Plugin::$settings_group;

		$aggressive_buttons = \Mediavine\Settings::get_setting( $settings_group . '_aggressive_buttons' );
		if ( $aggressive_buttons ) {
			$classes[] = 'mv-create-aggressive-buttons';
		}

		$aggressive_widgets = \Mediavine\Settings::get_setting( $settings_group . '_aggressive_widgets' );
		if ( $aggressive_widgets ) {
			$classes[] = 'mv-create-aggressive-widgets';
		}

		$aggressive_nutrition = \Mediavine\Settings::get_setting( $settings_group . '_aggressive_nutrition' );
		if ( $aggressive_nutrition ) {
			$classes[] = 'mv-create-aggressive-nutrition';
		}

		$center_cards = \Mediavine\Settings::get_setting( $settings_group . '_center_cards', true );
		if ( $center_cards ) {
			$classes[] = 'mv-create-center-cards';
		}

		// We don't want to waste resources for lists.
		if ( 'list' !== $atts['type'] ) {
			$uppercase = \Mediavine\Settings::get_setting( $settings_group . '_force_uppercase' );
			if ( $uppercase || is_null( $uppercase ) ) { // Null means no setting, so we get default.
				$classes[] = 'mv-create-has-uppercase';
			}
			$aggressive_lists = \Mediavine\Settings::get_setting( $settings_group . '_aggressive_lists' );
			if ( $aggressive_lists ) {
				$classes[] = 'mv-create-aggressive-lists';
			}
			$use_ugly_nutrition_display = \Mediavine\Settings::get_setting( $settings_group . '_use_realistic_nutrition_display' );
			if ( $use_ugly_nutrition_display ) {
				$classes[] = 'mv-create-traditional-nutrition';
			}

			// Print view.
			if ( $atts['print'] ) {
				$classes[] = 'mv-create-print-view';

				// Hide images on print.
				$mv_create_enable_print_thumbnails = \Mediavine\Settings::get_setting( $settings_group . '_enable_print_thumbnails' );
				if ( empty( $mv_create_enable_print_thumbnails ) ) {
					$classes[] = 'mv-create-hide-img';
				}
			}
		}

		return $classes;
	}

	/**
	 * Append image-presence and list photo-ratio classes.
	 *
	 * @param string[] $classes  Existing class list.
	 * @param array    $atts     Shortcode attributes.
	 * @param bool     $has_image Whether the card has images.
	 * @param string   $img_size Photo ratio setting slug.
	 * @return string[]
	 */
	public static function append_image_classes( $classes, $atts, $has_image, $img_size ) {
		$classes[] = $has_image ? 'mv-create-has-image' : 'mv-create-no-image';

		// Add photo ratio class for list layouts.
		if ( 'list' === $atts['type'] && 'mv_create_no_ratio' !== $img_size ) {
			$classes[] = 'mv-create-list-ratio-' . str_replace( 'mv_create_', '', $img_size );
		}

		return $classes;
	}
}
