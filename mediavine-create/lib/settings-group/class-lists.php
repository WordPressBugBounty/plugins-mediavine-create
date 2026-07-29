<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;

class Lists implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_lists_rounded_corners',
				'value' => '0',
				'group' => Plugin::$settings_group . '_lists',
				'order' => 95,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Rounded Corners', 'mediavine-create' ),
					'instructions' => __( 'This value is used for buttons and other card elements.', 'mediavine-create' ),
					'default'      => __( 'None', 'mediavine-create' ),
					'options'      => [
						[
							'label' => __( 'High', 'mediavine-create' ),
							'value' => '1rem',
						],
						[
							'label' => __( 'Low', 'mediavine-create' ),
							'value' => '3px',
						],
						[
							'label' => __( 'None', 'mediavine-create' ),
							'value' => '0',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_external_link_tab',
				'value' => true,
				'group' => Plugin::$settings_group . '_lists',
				'order' => 100,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Open external list items in new tab', 'mediavine-create' ),
					'instructions' => __( 'Checking the box will open external list items in a new tab', 'mediavine-create' ),
					'default'      => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_internal_link_tab',
				'value' => false,
				'group' => Plugin::$settings_group . '_lists',
				'order' => 100,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Open internal list items in new tab', 'mediavine-create' ),
					'instructions' => __( 'Checking the box will open internal list items in a new tab', 'mediavine-create' ),
					'default'      => __( 'Disabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_custom_buttons',
				'value' => 'Continue Reading\nRead More\nGet Recipe',
				'group' => Plugin::$settings_group . '_lists',
				'order' => 126,
				'data'  => [
					'type'         => 'custom_buttons',
					'label'        => __( 'Button Action Defaults', 'mediavine-create' ),
					'instructions' => __( 'Add options for the Button Action dropdown in list items. Add a new line between items.', 'mediavine-create' ),
					'default'      => '',
				],
			],
		];
	}
}
