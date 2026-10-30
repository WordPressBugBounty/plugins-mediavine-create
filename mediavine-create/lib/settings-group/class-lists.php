<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;
use Mediavine\Create\GateKeeper;

class Lists implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_list_layout',
				'value' => 'hero',
				'group' => Plugin::$settings_group . '_lists',
				'order' => 90,
				'data'  => [
					'type'          => 'list_layout_select',
					'subgroup'      => 'general',
					'label'         => __( 'Default List Layout', 'mediavine-create' ),
					'instructions'  => __( 'Lists set to “Site default” use this layout. Each list can override it in the card editor. Editorial, Leaderboard, Ranked, and Countdown are premium layouts.', 'mediavine-create' ),
					'default_label' => __( 'Hero', 'mediavine-create' ),
					// Premium layouts lead the list, then the free layouts.
					'options'       => [
						[
							'label' => __( 'Editorial', 'mediavine-create' ),
							'value' => 'editorial',
						],
						[
							'label' => __( 'Leaderboard', 'mediavine-create' ),
							'value' => 'leaderboard',
						],
						[
							'label' => __( 'Ranked', 'mediavine-create' ),
							'value' => 'ranked-top',
						],
						[
							'label' => __( 'Countdown', 'mediavine-create' ),
							'value' => 'ranked',
						],
						[
							'label' => __( 'Hero', 'mediavine-create' ),
							'value' => 'hero',
						],
						[
							'label' => __( 'Grid', 'mediavine-create' ),
							'value' => 'grid',
						],
						[
							'label' => __( 'Circles', 'mediavine-create' ),
							'value' => 'circles',
						],
						[
							'label' => __( 'Numbered', 'mediavine-create' ),
							'value' => 'numbered',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_list_show_ranks',
				'value' => true,
				'group' => Plugin::$settings_group . '_lists',
				'order' => 92,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'general',
					'label'         => __( 'Show rank numbers', 'mediavine-create' ),
					'instructions'  => __( 'Show the rank number on each item in the Editorial, Leaderboard, Ranked, and Countdown layouts. Each list can override this in the card editor.', 'mediavine-create' ),
					'default_label' => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_lists_rounded_corners',
				'value' => '0',
				'group' => Plugin::$settings_group . '_lists',
				'order' => 95,
				'data'  => [
					'type'          => 'select',
					'subgroup'      => 'general',
					'label'         => __( 'Rounded Corners', 'mediavine-create' ),
					'instructions'  => __( 'This value is used for buttons and other card elements.', 'mediavine-create' ),
					'default_label' => __( 'None', 'mediavine-create' ),
					'options'       => [
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
				'slug'  => Plugin::$settings_group . '_list_photo_ratio',
				'value' => 'default',
				'group' => Plugin::$settings_group . '_lists',
				'order' => 96,
				'data'  => [
					'type'          => 'select',
					'subgroup'      => 'general',
					'label'         => __( 'List Photo Ratio', 'mediavine-create' ),
					'instructions'  => __( 'Aspect ratio for list item photos, independent of the site-wide Photo Ratio. Circles, Leaderboard, and Ranked layouts use fixed thumbnails and ignore this.', 'mediavine-create' ),
					'default_label' => __( 'Same as site default', 'mediavine-create' ),
					'options'       => [
						[
							'label' => __( 'Same as site default', 'mediavine-create' ),
							'value' => 'default',
						],
						[
							'label' => __( 'No fixed ratio', 'mediavine-create' ),
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
				'slug'  => Plugin::$settings_group . '_external_link_tab',
				'value' => true,
				'group' => Plugin::$settings_group . '_lists',
				'order' => 100,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'general',
					'label'         => __( 'Open external list items in new tab', 'mediavine-create' ),
					'instructions'  => __( 'Checking the box will open external list items in a new tab', 'mediavine-create' ),
					'default_label' => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_internal_link_tab',
				'value' => false,
				'group' => Plugin::$settings_group . '_lists',
				'order' => 100,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'general',
					'label'         => __( 'Open internal list items in new tab', 'mediavine-create' ),
					'instructions'  => __( 'Checking the box will open internal list items in a new tab', 'mediavine-create' ),
					'default_label' => __( 'Disabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_list_sync_default',
				'value' => '0',
				'group' => Plugin::$settings_group . '_lists',
				'order' => 110,
				'data'  => [
					'type'          => 'checkbox',
					'subgroup'      => 'general',
					'gated'         => GateKeeper::FEATURE_LIST_ITEM_SYNC,
					'label'         => __( 'Keep internal list items in sync with the source post', 'mediavine-create' ),
					// Relative hrefs resolve against /wp-admin/ from the Settings
					// page, so no site URL gets baked into the stored row.
					'instructions'  => sprintf(
						/* translators: 1: link to the List Item Sync setup wizard, 2: link to the Lists collection */
						__( 'When enabled, new posts, pages, and Create cards added to a list will auto-update their title and featured image whenever the source is edited. Use the %1$s to start syncing existing list items more easily, or manage syncing per item in each %2$s.', 'mediavine-create' ),
						'<a href="edit.php?post_type=mv_create&#038;page=create_setup&#038;wizard=list-sync">' . __( 'setup wizard', 'mediavine-create' ) . '</a>',
						'<a href="edit.php?post_type=mv_create#cardType=list">' . __( 'List card', 'mediavine-create' ) . '</a>'
					),
					'default_label' => __( 'Disabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_custom_buttons',
				'value' => 'Continue Reading\nRead More\nGet Recipe',
				'group' => Plugin::$settings_group . '_lists',
				'order' => 126,
				'data'  => [
					'type'         => 'custom_buttons',
					'subgroup'     => 'general',
					'label'        => __( 'Button Action Defaults', 'mediavine-create' ),
					'instructions' => __( 'Add options for the Button Action dropdown in list items. Add a new line between items.', 'mediavine-create' ),
				],
			],
		];
	}
}
