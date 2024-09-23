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
