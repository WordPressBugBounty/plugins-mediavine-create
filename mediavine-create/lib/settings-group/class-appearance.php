<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;


class Appearance implements Settings_Group {
	public static function settings() {
		return [
			// Theme subgroup
			[
				'slug'  => Plugin::$settings_group . '_card_style',
				'value' => 'square',
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 0,
				'data'  => [
					'type'     => 'theme_select',
					'label'    => __( 'Card Style', 'mediavine' ),
					'default'  => __( 'Simple Square', 'mediavine' ),
					'subgroup' => 'theme',
					'options'  => [
						[
							'label' => __( 'Editorial', 'mediavine' ),
							'value' => 'editorial',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-editorial.webp' ),
							'title' => __( 'Editorial<br>by Mischief Marmot', 'mediavine' ),
							'gated' => true,
						],
						[
							'label' => __( 'Modern Elegant', 'mediavine' ),
							'value' => 'modern',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-modern.webp' ),
							'title' => __( 'Modern Elegant<br>by Mischief Marmot', 'mediavine' ),
							'gated' => true,
						],
						[
							'label' => __( 'Hero Image by Purr Design', 'mediavine' ),
							'value' => 'big-image',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-big-image.webp' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Hero Image<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Simple Square by Purr Design', 'mediavine' ),
							'value' => 'square',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-default.webp' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Simple Square<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Dark Simple Square by Purr Design', 'mediavine' ),
							'value' => 'dark',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-dark.webp' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Dark Simple Square<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Classy Circle by Purr Design', 'mediavine' ),
							'value' => 'centered',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-centered.webp' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Classy Circle<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Dark Classy Circle by Purr Design', 'mediavine' ),
							'value' => 'centered-dark',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-centered-dark.webp' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Dark Classy Circle<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],

					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_color',
				'value' => null,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 5,
				'data'  => [
					'type'         => 'color_picker',
					'label'        => __( 'Theme Colors' ),
					'instructions' => null,
					'subgroup'     => 'theme',
				],
			],
			// Card Display subgroup
			[
				'slug'  => Plugin::$settings_group . '_photo_ratio',
				'value' => 'mv_create_no_ratio',
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 30,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Photo Ratio', 'mediavine' ),
					'instructions' => __( 'Select an aspect ratio for photo display on cards and lists. Circles list layout and Classy Circle card style will ignore this setting.', 'mediavine' ),
					'default'      => __( 'No fixed ratio', 'mediavine' ),
					'subgroup'     => 'card_display',
					'options'      => [
						[
							'label' => __( 'No fixed ratio', 'mediavine' ),
							'value' => 'mv_create_no_ratio',
						],
						[
							'label' => '1x1',
							'value' => 'mv_create_1x1',
						],
						[
							'label' => '4x3',
							'value' => 'mv_create_4x3',
						],
						[
							'label' => '16x9',
							'value' => 'mv_create_16x9',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_print_thumbnails',
				'value' => true,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 35,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Print Thumbnails', 'mediavine' ),
					'instructions' => __( 'By default, card thumbnails will display in the print view. This can be disabled.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
					'subgroup'     => 'card_display',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_pinterest_location',
				'value' => 'mv-pinterest-btn-right',
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 40,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Pinterest Button Location', 'mediavine' ),
					'instructions' => __( 'Select location for Pinterest button. Note: On the list card styles Numbered and Circles, the Pinterest button will still display to the right.', 'mediavine' ),
					'default'      => __( 'Top Right', 'mediavine' ),
					'subgroup'     => 'card_display',
					'options'      => [
						[
							'label' => __( 'Off', 'mediavine' ),
							'value' => 'off',
						],
						[
							'label' => __( 'Top Left', 'mediavine' ),
							'value' => 'mv-pinterest-btn-left',
						],
						[
							'label' => __( 'Inside Top Left', 'mediavine' ),
							'value' => 'mv-pinterest-btn-left-inside',
						],
						[
							'label' => __( 'Inside Top Right', 'mediavine' ),
							'value' => 'mv-pinterest-btn-right-inside',
						],
						[
							'label' => __( 'Top Right', 'mediavine' ),
							'value' => 'mv-pinterest-btn-right',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_force_uppercase',
				'value' => true,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 50,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Force Uppercase', 'mediavine' ),
					'instructions' => __( 'By default, recipe cards show some pieces of text as all-uppercase, which for certain typefaces may not be desired.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
					'subgroup'     => 'card_display',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_inherit_theme_fontsize',
				'value' => false,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 51,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Use Theme Body Font Size', 'mediavine' ),
					'instructions' => __( 'If enabled, the Create card body font size will match that of the theme.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'subgroup'     => 'card_display',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_center_cards',
				'value' => true,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 65,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Center Full Width Cards', 'mediavine' ),
					'instructions' => __( 'When a card reaches its max width of 700px, center the card within the content area.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
					'subgroup'     => 'card_display',
				],
			],
			// CSS Overrides subgroup
			[
				'slug'  => Plugin::$settings_group . '_aggressive_lists',
				'value' => false,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 55,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Aggressive List CSS', 'mediavine' ),
					'instructions' => __( 'Some themes may remove bullets and numbers from lists. This forces them to display in Create Cards.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'subgroup'     => 'css_overrides',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_aggressive_buttons',
				'value' => false,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 60,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Aggressive Buttons CSS', 'mediavine' ),
					'instructions' => __( "Some themes may not have button styles, or they won't look good with your theme. This forces a generic button style.", 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'subgroup'     => 'css_overrides',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_aggressive_widgets',
				'value' => false,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 61,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Aggressive Widget CSS', 'mediavine' ),
					'instructions' => __( 'Some themes override font sizes and spacing on card widgets (servings adjuster, unit conversion). This forces the intended widget styling.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'subgroup'     => 'css_overrides',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_aggressive_nutrition',
				'value' => false,
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 62,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Aggressive Nutrition CSS', 'mediavine' ),
					'instructions' => __( 'Some themes override font sizes and spacing in the nutrition label. This forces the intended nutrition styling.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'subgroup'     => 'css_overrides',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_custom_css',
				'value' => '',
				'group' => Plugin::$settings_group . '_appearance',
				'order' => 70,
				'data'  => [
					'type'         => 'textarea',
					'label'        => __( 'Custom CSS', 'mediavine' ),
					'instructions' => __( 'Add custom CSS to style Create cards. Use .mv-create-card as the root selector. Example: <code>.mv-create-card .mv-create-title-primary { font-size: 24px; }</code>', 'mediavine' ),
					'default'      => '',
					'subgroup'     => 'css_overrides',
					'gated'        => 'custom_css',
				],
			],
		];
	}
}
