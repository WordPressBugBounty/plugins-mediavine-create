<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;
use Mediavine\Create\Plugin_Checker;

/**
 * Settings group for List Ad Slots.
 *
 * Provides ad insertion settings for all publishers, not just Mediavine.
 * Mediavine publishers get automatic defaults; non-MV publishers can enable
 * ads and provide custom ad HTML.
 */
class List_Ads implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		$has_mcp = Plugin_Checker::is_mcp_active();

		$settings = [
			[
				'slug'  => Plugin::$settings_group . '_ad_provider',
				'value' => 'auto',
				'group' => Plugin::$settings_group . '_ads',
				'order' => 100,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Ad Provider', 'mediavine' ),
					'instructions' => __( 'Choose your ad provider. "Auto-detect" checks for Mediavine Control Panel automatically.', 'mediavine' ),
					'options'      => [
						[
							'label' => __( 'Auto-detect', 'mediavine' ),
							'value' => 'auto',
						],
						[
							'label' => __( 'Mediavine', 'mediavine' ),
							'value' => 'mediavine',
						],
						[
							'label' => __( 'Other / None', 'mediavine' ),
							'value' => 'none',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_list_ads_enabled',
				'value' => $has_mcp ? '1' : '0',
				'group' => Plugin::$settings_group . '_ads',
				'order' => 105,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Ads in Lists', 'mediavine' ),
					'instructions' => __( 'Enable ad slot insertion between list items.', 'mediavine' ),
					'default'      => $has_mcp ? __( 'Enabled', 'mediavine' ) : __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_list_items_between_ads',
				'value' => '3',
				'group' => Plugin::$settings_group . '_ads',
				'order' => 106,
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

		// Custom ad HTML for non-Mediavine publishers (visibility controlled by admin UI)
		$settings[] = [
			'slug'  => Plugin::$settings_group . '_list_ad_custom_html',
			'value' => '',
			'group' => Plugin::$settings_group . '_ads',
			'order' => 107,
			'data'  => [
				'type'         => 'textarea',
				'label'        => __( 'Ad Slot HTML', 'mediavine' ),
				'instructions' => __( 'Enter the HTML to insert between list items. Allowed tags: div, span. Allowed attributes: class, id, data-* attributes. Script tags and event handlers will be stripped.', 'mediavine' ),
				'default'      => '',
			],
		];

		return $settings;
	}
}
