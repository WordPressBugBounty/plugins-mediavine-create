<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;
use Mediavine\Create\Plugin_Checker;

/**
 * Settings group for MCP Control Panel
 * @expectedDeprecation 1.9
 */
class Control_Panel implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		if ( ! Plugin_Checker::has_mv_ads() ) {
			return [];
		}

		return [
			[
				'slug'  => Plugin::$settings_group . '_list_items_between_ads',
				'value' => '3',
				'group' => Plugin::$settings_group . '_mvp',
				'order' => 110,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'List Items Between Ads', 'mediavine' ),
					'instructions' => __( 'Choose the number of list items between each ad in the card.', 'mediavine' ),
					'options'      => [
						[
							'label' => __( 'Disable ads in lists', 'mediavine' ),
							'value' => 0,
						],
						[
							'label' => __( '2', 'mediavine' ),
							'value' => '2',
						],
						[
							'label' => __( '3', 'mediavine' ),
							'value' => '3',
						],
						[
							'label' => __( '4', 'mediavine' ),
							'value' => '4',
						],
						[
							'label' => __( '5', 'mediavine' ),
							'value' => '5',
						],
					],
				],
			],
		];
	}
}
