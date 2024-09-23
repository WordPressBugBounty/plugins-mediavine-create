<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;


class Display implements Settings_Group {
	public static function settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_color',
				'value' => null,
				'group' => Plugin::$settings_group . '_display',
				'order' => 0,
				'data'  => [
					'type'         => 'color_picker',
					'label'        => __( 'Theme Colors' ),
					'instructions' => null,
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_photo_ratio',
				'value' => 'mv_create_no_ratio',
				'group' => Plugin::$settings_group . '_display',
				'order' => 30,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Photo Ratio', 'mediavine' ),
					'instructions' => __( 'Select an aspect ratio for photo display. Some card styles, such as Classy Circle, will ignore this setting.', 'mediavine' ),
					'default'      => __( 'No fixed ratio', 'mediavine' ),
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
				'group' => Plugin::$settings_group . '_display',
				'order' => 35,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Print Thumbnails', 'mediavine' ),
					'instructions' => __( 'By default, card thumbnails will display in the print view. This can be disabled.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_pinterest_location',
				'value' => 'mv-pinterest-btn-right',
				'group' => Plugin::$settings_group . '_display',
				'order' => 40,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Pinterest Button Location', 'mediavine' ),
					'instructions' => __( 'Select location for Pinterest button. Note: On the list card styles Numbered and Circles, the Pinterest button will still display to the right.', 'mediavine' ),
					'default'      => __( 'Top Right', 'mediavine' ),
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
				'group' => Plugin::$settings_group . '_display',
				'order' => 50,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Force Uppercase', 'mediavine' ),
					'instructions' => __( 'By default, recipe cards show some pieces of text as all-uppercase, which for certain typefaces may not be desired.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_inherit_theme_fontsize',
				'value' => false,
				'group' => Plugin::$settings_group . '_display',
				'order' => 51,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Use Theme Body Font Size', 'mediavine' ),
					'instructions' => __( 'If enabled, the Create card body font size will match that of the theme.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_aggressive_lists',
				'value' => false,
				'group' => Plugin::$settings_group . '_display',
				'order' => 55,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Aggressive List CSS', 'mediavine' ),
					'instructions' => __( 'Some themes may remove bullets and numbers from lists. This forces them to display in Create by Mediavine Cards.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_aggressive_buttons',
				'value' => false,
				'group' => Plugin::$settings_group . '_display',
				'order' => 60,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Aggressive Buttons CSS', 'mediavine' ),
					'instructions' => __( "Some themes may not have button styles, or they won't look good with your theme. This forces a generic button style.", 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_center_cards',
				'value' => true,
				'group' => Plugin::$settings_group . '_display',
				'order' => 65,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Center Full Width Cards', 'mediavine' ),
					'instructions' => __( 'When a card reaches its max width of 700px, center the card within the content area.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_card_style',
				'value' => 'square',
				'group' => Plugin::$settings_group . '_display',
				'order' => 90,
				'data'  => [
					'type'    => 'image_select',
					'label'   => __( 'Card Style', 'mediavine' ),
					'default' => __( 'Simple Square', 'mediavine' ),
					'options' => [
						[
							'label' => __( 'Simple Square by Purr Design', 'mediavine' ),
							'value' => 'square',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-default.png' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Simple Square<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Dark Simple Square by Purr Design', 'mediavine' ),
							'value' => 'dark',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-dark.png' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Dark Simple Square<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Classy Circle by Purr Design', 'mediavine' ),
							'value' => 'centered',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-centered.png' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Classy Circle<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Dark Classy Circle by Purr Design', 'mediavine' ),
							'value' => 'centered-dark',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-centered-dark.png' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Dark Classy Circle<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
						[
							'label' => __( 'Hero Image by Purr Design', 'mediavine' ),
							'value' => 'big-image',
							'image' => mv_create_plugin_dir_url( 'admin/img/card-style-big-image.png' ),
							/* translators: credit name and url */
							'title' => sprintf( __( 'Hero Image<br>by %s', 'mediavine' ), '<a href="https://www.purrdesign.com/" target="_blank">Purr Design<span class="dashicons dashicons-external"></span></a>' ),
						],
					],
				],
			],
		];
	}
}
