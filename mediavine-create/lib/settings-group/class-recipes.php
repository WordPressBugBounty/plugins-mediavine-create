<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;
use Mediavine\Create\GateKeeper;

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
					'type'          => 'checkbox',
					'subgroup'      => 'premium_features',
					'label'         => __( 'Enable Unit Conversion', 'mediavine-create' ),
					'instructions'  => __( 'Allows readers to toggle between US Customary and Metric units for recipe ingredients.', 'mediavine-create' ),
					'default_label' => __( 'Disabled', 'mediavine-create' ),
					'gated'         => GateKeeper::FEATURE_UNIT_CONVERSION,
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_unit_conversion_default_system',
				'value' => 'auto',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 86,
				'data'  => [
					'type'          => 'select',
					'subgroup'      => 'premium_features',
					'label'         => __( 'Default Measurement System', 'mediavine-create' ),
					'instructions'  => __( 'Choose the default measurement system displayed to readers. "Auto" detects based on the reader\'s locale.', 'mediavine-create' ),
					'default_label' => __( 'Auto (detect from locale)', 'mediavine-create' ),
					'gated'         => GateKeeper::FEATURE_UNIT_CONVERSION,
					'options'       => [
						[
							'label' => __( 'Auto (detect from locale)', 'mediavine-create' ),
							'value' => 'auto',
						],
						[
							'label' => __( 'US Customary', 'mediavine-create' ),
							'value' => 'us_customary',
						],
						[
							'label' => __( 'Metric', 'mediavine-create' ),
							'value' => 'metric',
						],
					],
					'dependent_on'  => Plugin::$settings_group . '_enable_unit_conversion',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_unit_conversion_label',
				'value' => 'Unit Conversion',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 87,
				'data'  => [
					'type'          => 'text',
					'subgroup'      => 'premium_features',
					'label'         => __( 'Unit Conversion Label', 'mediavine-create' ),
					'instructions'  => __( 'Customize the label displayed next to the unit conversion toggle.', 'mediavine-create' ),
					'default_label' => __( 'Unit Conversion', 'mediavine-create' ),
					'gated'         => GateKeeper::FEATURE_UNIT_CONVERSION,
					'dependent_on'  => Plugin::$settings_group . '_enable_unit_conversion',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_servings_adjustment',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 90,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'premium_features',
					'label'         => __( 'Enable Servings Adjustment', 'mediavine-create' ),
					'instructions'  => __( 'Allows readers to scale recipe ingredient amounts by selecting serving multipliers (1x, 2x, 3x).', 'mediavine-create' ),
					'default_label' => __( 'Disabled', 'mediavine-create' ),
					'gated'         => GateKeeper::FEATURE_SERVINGS_ADJUSTMENT,
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_servings_adjustment_label',
				'value' => 'Adjust Servings',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 91,
				'data'  => [
					'type'          => 'text',
					'subgroup'      => 'premium_features',
					'label'         => __( 'Servings Adjustment Label', 'mediavine-create' ),
					'instructions'  => __( 'Customize the label displayed next to the servings adjustment buttons.', 'mediavine-create' ),
					'default_label' => __( 'Adjust Servings', 'mediavine-create' ),
					'gated'         => GateKeeper::FEATURE_SERVINGS_ADJUSTMENT,
					'dependent_on'  => Plugin::$settings_group . '_enable_servings_adjustment',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_widget_toolbar_layout',
				'value' => 'toolbar',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 92,
				'data'  => [
					'type'          => 'layout_select',
					'subgroup'      => 'premium_features',
					'label'         => __( 'Widget Toolbar Layout', 'mediavine-create' ),
					'instructions'  => __( 'Choose how the servings adjuster and unit conversion controls are arranged on your recipe cards.', 'mediavine-create' ),
					'default_label' => __( 'Toolbar', 'mediavine-create' ),
					'gated'         => GateKeeper::FEATURE_UNIT_CONVERSION,
					'dependent_on'  => [ 'mv_create_enable_unit_conversion', 'mv_create_enable_servings_adjustment' ],
					'options'       => [
						[
							'label' => __( 'Toolbar', 'mediavine-create' ),
							'value' => 'toolbar',
						],
						[
							'label' => __( 'Left', 'mediavine-create' ),
							'value' => 'left',
						],
						[
							'label' => __( 'Right', 'mediavine-create' ),
							'value' => 'right',
						],
						[
							'label' => __( 'Centered', 'mediavine-create' ),
							'value' => 'centered',
						],
						[
							'label' => __( 'Inline', 'mediavine-create' ),
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
					'type'          => 'checkbox',
					'subgroup'      => 'premium_features',
					'label'         => __( 'Show Widget Labels', 'mediavine-create' ),
					'instructions'  => __( 'Display labels above the servings adjuster and unit conversion controls.', 'mediavine-create' ),
					'default_label' => __( 'Enabled', 'mediavine-create' ),
					'gated'         => GateKeeper::FEATURE_UNIT_CONVERSION,
					'dependent_on'  => [ 'mv_create_enable_unit_conversion', 'mv_create_enable_servings_adjustment' ],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_nutrition',
				'value' => true,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 95,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'nutrition',
					'label'         => __( 'Use Nutrition', 'mediavine-create' ),
					'instructions'  => __( 'Unchecking the box will remove nutrition inputs from the recipe card interface and hide nutrition data for all recipes.', 'mediavine-create' ),
					'default_label' => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_use_realistic_nutrition_display',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 98,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'nutrition',
					'label'         => __( 'Use Traditional Nutrition Display', 'mediavine-create' ),
					'instructions'  => __( 'Checking the box will add a traditional nutrition display.', 'mediavine-create' ),
					'default_label' => __( 'Disabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_nutrition_disclaimer',
				'value' => '',
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 99,
				'data'  => [
					'type'         => 'textarea',
					'subgroup'     => 'nutrition',
					'label'        => __( 'Calculated Nutrition Disclaimer', 'mediavine-create' ),
					'instructions' => __( 'If provided, this disclaimer will be automatically added to each recipe upon nutrition calculation.', 'mediavine-create' ),
					'dependent_on' => Plugin::$settings_group . '_api_token',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_display_nutrition_zeros',
				'value' => false,
				'group' => Plugin::$settings_group . '_recipes',
				'order' => 100,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'nutrition',
					'label'         => __( 'Display Zero Values For Net Carbs And Sugar Alcohols', 'mediavine-create' ),
					'instructions'  => __( 'Checking this box will display the Net Carbohydrate and Sugar Alcohols fields on recipe nutrition when they have a value of "0", which are hidden by default. The display of zero values can be overridden for individual recipes.', 'mediavine-create' ),
					'default_label' => __( 'Disabled', 'mediavine-create' ),
				],
			],
		];
	}
}
