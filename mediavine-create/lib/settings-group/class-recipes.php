<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;

class Recipes implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_enable_unit_conversion',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 85,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Unit Conversion', 'mediavine' ),
					'instructions' => __( 'Allows readers to toggle between US Customary and Metric units for recipe ingredients.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'gated'        => 'unit_conversion',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_unit_conversion_default_system',
				'value' => 'auto',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 86,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Default Measurement System', 'mediavine' ),
					'instructions' => __( 'Choose the default measurement system displayed to readers. "Auto" detects based on the reader\'s locale.', 'mediavine' ),
					'default'      => 'auto',
					'gated'        => 'unit_conversion',
					'options'      => [
						[
							'label' => __( 'Auto (detect from locale)', 'mediavine' ),
							'value' => 'auto',
						],
						[
							'label' => __( 'US Customary', 'mediavine' ),
							'value' => 'us_customary',
						],
						[
							'label' => __( 'Metric', 'mediavine' ),
							'value' => 'metric',
						],
					],
					'dependent_on' => Plugin::$settings_group . '_enable_unit_conversion',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_unit_conversion_label',
				'value' => 'Unit Conversion',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 87,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Unit Conversion Label', 'mediavine' ),
					'instructions' => __( 'Customize the label displayed next to the unit conversion toggle.', 'mediavine' ),
					'default'      => 'Unit Conversion',
					'gated'        => 'unit_conversion',
					'dependent_on' => Plugin::$settings_group . '_enable_unit_conversion',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_servings_adjustment',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 90,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Servings Adjustment', 'mediavine' ),
					'instructions' => __( 'Allows readers to scale recipe ingredient amounts by selecting serving multipliers (1x, 2x, 3x).', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'gated'        => 'servings_adjustment',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_servings_adjustment_label',
				'value' => 'Adjust Servings',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 91,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Servings Adjustment Label', 'mediavine' ),
					'instructions' => __( 'Customize the label displayed next to the servings adjustment buttons.', 'mediavine' ),
					'default'      => 'Adjust Servings',
					'gated'        => 'servings_adjustment',
					'dependent_on' => Plugin::$settings_group . '_enable_servings_adjustment',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_widget_toolbar_layout',
				'value' => 'toolbar',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 92,
				'data'  => [
					'type'         => 'layout_select',
					'label'        => __( 'Widget Toolbar Layout', 'mediavine' ),
					'instructions' => __( 'Choose how the servings adjuster and unit conversion controls are arranged on your recipe cards.', 'mediavine' ),
					'default'      => 'toolbar',
					'gated'        => 'unit_conversion',
					'dependent_on' => [ 'mv_create_enable_unit_conversion', 'mv_create_enable_servings_adjustment' ],
					'options'      => [
						[
							'label' => __( 'Toolbar', 'mediavine' ),
							'value' => 'toolbar',
						],
						[
							'label' => __( 'Left', 'mediavine' ),
							'value' => 'left',
						],
						[
							'label' => __( 'Right', 'mediavine' ),
							'value' => 'right',
						],
						[
							'label' => __( 'Centered', 'mediavine' ),
							'value' => 'centered',
						],
						[
							'label' => __( 'Inline', 'mediavine' ),
							'value' => 'inline',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_show_widget_labels',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 93,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Show Widget Labels', 'mediavine' ),
					'instructions' => __( 'Display labels above the servings adjuster and unit conversion controls.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
					'gated'        => 'unit_conversion',
					'dependent_on' => [ 'mv_create_enable_unit_conversion', 'mv_create_enable_servings_adjustment' ],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_nutrition',
				'value' => true,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 95,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Use Nutrition', 'mediavine' ),
					'instructions' => __( 'Unchecking the box will remove nutrition inputs from the recipe card interface and hide nutrition data for all recipes.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_use_realistic_nutrition_display',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 98,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Use Traditional Nutrition Display', 'mediavine' ),
					'instructions' => __( 'Checking the box will add a traditional nutrition display.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_nutrition_disclaimer',
				'value' => '',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 99,
				'data'  => [
					'type'         => 'textarea',
					'label'        => __( 'Calculated Nutrition Disclaimer', 'mediavine' ),
					'instructions' => __( 'If provided, this disclaimer will be automatically added to each recipe upon nutrition calculation.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_api_token',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_display_nutrition_zeros',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 100,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Display Zero Values For Net Carbs And Sugar Alcohols', 'mediavine' ),
					'instructions' => __( 'Checking this box will display the Net Carbohydrate and Sugar Alcohols fields on recipe nutrition when they have a value of "0", which are hidden by default. The display of zero values can be overridden for individual recipes.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
		];
	}
}
