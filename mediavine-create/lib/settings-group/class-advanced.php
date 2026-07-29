<?php
namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;
use Mediavine\Create\Theme_Checker;

/**
 * Class Advanced
 * Settings class for Advanced group
 * @package Mediavine
 */
class Advanced implements Settings_Group {

	/**
	 * Return settings
	 *
	 * @return array[]
	 */
	public static function settings() {
		// @todo add filter?
		// @todo add a getter/setter to replace Plugin::$settings_group
		return [
			[
				'slug'  => Plugin::$settings_group . '_default_access_role',
				'value' => 'manage_options',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 9,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Default Access Role', 'mediavine-create' ),
					'instructions' => __( 'Select what user roles have access to edit Create Cards.', 'mediavine-create' ),
					'default'      => __( 'Administrators', 'mediavine-create' ),
					// QUESTION: Should these settings be created programmatically?
					'options'      => [
						[
							'label' => __( 'Administrators', 'mediavine-create' ),
							'value' => 'manage_options',
						],
						[
							'label' => __( 'Editors', 'mediavine-create' ),
							'value' => 'edit_others_posts',
						],
						[
							'label' => __( 'Authors', 'mediavine-create' ),
							'value' => 'edit_published_posts',
						],
						[
							'label' => __( 'Contributors', 'mediavine-create' ),
							'value' => 'edit_posts',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_hands_free_mode',
				'value' => false,
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 10,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Hands-free Mode', 'mediavine-create' ),
					'instructions' => __( 'Adds a toggle to Create cards to allow readers to keep their screen awake while reading on supported devices.', 'mediavine-create' ),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_checklists',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 11,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Checklists', 'mediavine-create' ),
					'instructions' => __( 'Add interactive checkboxes to ingredients and instructions for tracking cooking progress.', 'mediavine-create' ),
					'default'      => __( 'Disabled', 'mediavine-create' ),
					'gated'        => 'checklists',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_high_contrast',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 11,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable High Contrast', 'mediavine-create' ),
					'instructions' => __( 'By default, high contrast mode is disabled.', 'mediavine-create' ),
					'default'      => __( 'Disabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_copyright_attribution',
				'value' => null,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 15,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Default Copyright Attribution', 'mediavine-create' ),
					'instructions' => __( 'If left blank, the Create Card author will be displayed.', 'mediavine-create' ),
					'default'      => null,
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_copyright_override',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 16,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Override author', 'mediavine-create' ),
					'instructions' => __( 'Enabling this setting will cause the Default Copyright Attribution to display instead of the author.', 'mediavine-create' ),
					'default'      => 'false',
					'dependent_on' => Plugin::$settings_group . '_copyright_attribution',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_primary_headings',
				'value' => 'h2',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 18,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Primary Heading Tag', 'mediavine-create' ),
					'instructions' => sprintf(
					// translators: Link tags
						__( 'While having %1$smultiple H1s on a page is approved by Google%2$s, many still recommend maintaining the page to only a single H1. This allows you to choose what tag you want for the primary heading, properly adjusting the heading hierarchy throughout the card.', 'mediavine-create' ),
						'<a href="https://www.youtube.com/watch?v=WsgrSxCmMbM" target="_blank">',
						'</a>'
					),
					'default'      => __( 'H2', 'mediavine-create' ),
					'options'      => [
						[
							'label' => 'H1',
							'value' => 'h1',
						],
						[
							'label' => 'H2',
							'value' => 'h2',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_schema_in_head',
				'value' => true,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 70,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Output JSON-LD Schema in head', 'mediavine-create' ),
					'instructions' => __( 'If enabled, Create will output the JSON-LD schema in the wp_head hook. If disabled, it will be output just before the card.', 'mediavine-create' ),
					'default'      => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enhanced_search',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 80,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Use Enhanced Search', 'mediavine-create' ),
					'instructions' => __( 'Create has a search feature that allows users to match posts based on the content of the recipe cards included in the post. If you notice that this feature is causing an issue with other themes or plugins that modify the search query, you can disable this feature.', 'mediavine-create' ),
					'default'      => __( 'Disabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_autosave',
				'value' => true,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 85,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Autosave', 'mediavine-create' ),
					'instructions' => __( 'By default, we\'ll save your work as you edit, even if you haven\'t published your changes. If you disable this setting, we\'ll only save draft content if you specifically click the \'Save Draft\' button.', 'mediavine-create' ),
					'default'      => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_sync_scroll',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 86,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Sync Scroll', 'mediavine-create' ),
					'instructions' => __( 'When enabled, scrolling the list editor will automatically scroll the preview to the corresponding item.', 'mediavine-create' ),
					'default'      => __( 'Disabled', 'mediavine-create' ),
					'gated'        => 'sync_scroll',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_allow_reviews',
				'value' => true,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 100,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Allow Reviews', 'mediavine-create' ),
					'instructions' => __( 'Unchecking this box will prevent users from being able to leave reviews on your recipe cards.', 'mediavine-create' ),
					'default'      => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_reviews_ctas',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 104,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Display Review CTAs', 'mediavine-create' ),
					'instructions' => __( 'Display prompts encouraging site visitors to leave reviews.', 'mediavine-create' ),
					'default'      => __( 'Disabled', 'mediavine-create' ),
					'dependent_on' => Plugin::$settings_group . '_allow_reviews',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_affiliate_message',
				'value' => 'As an Amazon Associate and member of other affiliate programs, I earn from qualifying purchases.',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 80,
				'data'  => [
					'type'         => 'textarea',
					'label'        => __( 'Global Affiliate Message', 'mediavine-create' ),
					'instructions' => __( 'Set the default affiliate disclaimer message with this text. Affiliate messaging can be overridden in individual posts.', 'mediavine-create' ),
					// No localization because the default value does not get translated.
					'default'      => 'As an Amazon Associate and member of other affiliate programs, I earn from qualifying purchases.',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_allowed_types',
				'value' => '[]',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 0,
				'data'  => [
					'type'         => 'allowed_types',
					'label'        => __( 'Allowed Types', 'mediavine-create' ),
					'instructions' => __( 'If any types are selected, only they will be available for adding new cards. Existing cards of disallowed types will still function properly.', 'mediavine-create' ),
					'default'      => '[]',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_default_card_view',
				'value' => 'browse',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 1,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Default Card View', 'mediavine-create' ),
					'instructions' => __( 'When adding a card to a post, choose whether to show the card browser or the create new card form by default.', 'mediavine-create' ),
					'default'      => __( 'Browse Existing Cards', 'mediavine-create' ),
					'options'      => [
						[
							'label' => __( 'Browse Existing Cards', 'mediavine-create' ),
							'value' => 'browse',
						],
						[
							'label' => __( 'Create New Card', 'mediavine-create' ),
							'value' => 'create',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_default_admin_page',
				'value' => 'dashboard',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 2,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Default Admin Page', 'mediavine-create' ),
					'instructions' => __( 'Choose which page loads when you click the Create menu item in the WordPress admin sidebar.', 'mediavine-create' ),
					'default'      => __( 'Dashboard', 'mediavine-create' ),
					'options'      => [
						[
							'label' => __( 'Dashboard', 'mediavine-create' ),
							'value' => 'dashboard',
						],
						[
							'label' => __( 'All Create Cards', 'mediavine-create' ),
							'value' => 'all_cards',
						],
						[
							'label' => __( 'Recipes', 'mediavine-create' ),
							'value' => 'recipe',
						],
						[
							'label' => __( 'How-Tos', 'mediavine-create' ),
							'value' => 'diy',
						],
						[
							'label' => __( 'Lists', 'mediavine-create' ),
							'value' => 'list',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_allowed_cpt_types',
				'value' => '[]',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 5,
				'data'  => [
					'type'         => 'multiselect',
					'label'        => __( 'Allowed Custom Post Types', 'mediavine-create' ),
					'instructions' => __( 'If enabled, will allow specific custom post types to be added to Lists', 'mediavine-create' ),
					'default'      => 'Disabled',
					'options'      => [],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_anonymous_ratings',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 110,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Allow Anonymous Ratings', 'mediavine-create' ),
					'instructions' => __( 'If enabled, 4 and 5 star reviews will be submitted when the star is clicked. Users leaving a star rating will then see a popup modal prompting them to leave an optional review. Disabling this will not sumbit the rating until after the review has been added. The prompt will still display.', 'mediavine-create' ),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_public_reviews',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 120,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Public Reviews', 'mediavine-create' ),
					'instructions' => __( 'If enabled, card reviews will be publicly visible, displayed in a tab alongside comments. You must specify a DOM selector for your comments section.', 'mediavine-create' ),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_public_reviews_el',
				'value' => ( Theme_Checker::is_trellis() ? '#mv-trellis-comments' : '#comments' ),
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 125,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Comments Section', 'mediavine-create' ),
					'instructions' => __( 'Add the DOM selector of your comments section. (In most themes, this will be "#comments".)', 'mediavine-create' ),
					'default'      => '#comments',
					'dependent_on' => Plugin::$settings_group . '_enable_public_reviews',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_disable_image_sizes',
				'value' => '[]',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 130,
				'data'  => [
					'type'         => 'multiselect',
					'label'        => __( 'Prevent Image Size Generation', 'mediavine-create' ),
					'instructions' => __( 'If enabled, will disable specific image sizes created by Create', 'mediavine-create' ),
					'default'      => 'Disabled',
					'options'      => Plugin::get_image_size_values(),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_products_position',
				'value' => 'after_video',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 131,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Products Position', 'mediavine-create' ),
					'instructions' => __( 'Choose where the products section appears within your recipe and how-to cards.', 'mediavine-create' ),
					'default'      => __( 'After Video', 'mediavine-create' ),
					'options'      => [
						[
							'label' => __( 'Above Supplies/Ingredients', 'mediavine-create' ),
							'value' => 'above_supplies',
						],
						[
							'label' => __( 'Above Instructions', 'mediavine-create' ),
							'value' => 'above_instructions',
						],
						[
							'label' => __( 'Below Notes', 'mediavine-create' ),
							'value' => 'below_notes',
						],
						[
							'label' => __( 'After Video', 'mediavine-create' ),
							'value' => 'after_video',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_confetti_shortcut',
				'value' => true,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 199,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Confetti Shortcut', 'mediavine-create' ),
					'instructions' => __( 'Press Shift+C anywhere in Create to launch celebratory confetti. Disable to remove this keyboard shortcut.', 'mediavine-create' ),
					'default'      => __( 'Enabled', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_importers',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 200,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Recipe Importers', 'mediavine-create' ),
					'instructions' => __( 'Import recipes from other recipe plugins like WP Recipe Maker, Tasty Recipes, and more.', 'mediavine-create' ),
					'default'      => __( 'Disabled', 'mediavine-create' ),
				],
			],
		];
	}
}
