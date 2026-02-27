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
		$has_mv_ads = Plugin_Checker::has_mv_ads();

		$settings = [
			[
				'slug'  => Plugin::$settings_group . '_list_ads_enabled',
				'value' => $has_mv_ads ? '1' : '0',
				'group' => Plugin::$settings_group . '_ads',
				'order' => 105,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Ads in Lists', 'mediavine' ),
					'instructions' => __( 'Enable ad slot insertion between list items.', 'mediavine' ),
					'default'      => $has_mv_ads ? __( 'Enabled', 'mediavine' ) : __( 'Disabled', 'mediavine' ),
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

		// Non-MV publishers enter custom HTML for their ad slot
		if ( ! $has_mv_ads ) {
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
		}

		return $settings;
	}
}
