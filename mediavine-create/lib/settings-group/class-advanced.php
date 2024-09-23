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
					'label'        => __( 'Default Access Role', 'mediavine' ),
					'instructions' => __( 'Select what user roles have access to edit Create Cards.', 'mediavine' ),
					'default'      => __( 'Administrators', 'mediavine' ),
					// QUESTION: Should these settings be created programmatically?
					'options'      => [
						[
							'label' => __( 'Administrators', 'mediavine' ),
							'value' => 'manage_options',
						],
						[
							'label' => __( 'Editors', 'mediavine' ),
							'value' => 'edit_others_posts',
						],
						[
							'label' => __( 'Authors', 'mediavine' ),
							'value' => 'edit_published_posts',
						],
						[
							'label' => __( 'Contributors', 'mediavine' ),
							'value' => 'edit_posts',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_hands_free_mode',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 10,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Hands-free Mode', 'mediavine' ),
					'instructions' => __( 'Adds a toggle to Create cards to allow readers to keep their screen awake while reading on supported devices.', 'mediavine' ),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_high_contrast',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 11,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable High Contrast', 'mediavine' ),
					'instructions' => __( 'By default, high contrast mode is disabled.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_copyright_attribution',
				'value' => null,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 15,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Default Copyright Attribution', 'mediavine' ),
					'instructions' => __( 'If left blank, the Create Card author will be displayed.', 'mediavine' ),
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
					'label'        => __( 'Override author', 'mediavine' ),
					'instructions' => __( 'Enabling this setting will cause the Default Copyright Attribution to display instead of the author.', 'mediavine' ),
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
					'label'        => __( 'Primary Heading Tag', 'mediavine' ),
					'instructions' => sprintf(
					// translators: Link tags
						__( 'While having %1$smultiple H1s on a page is approved by Google%2$s, many still recommend maintaining the page to only a single H1. This allows you to choose what tag you want for the primary heading, properly adjusting the heading hierarchy throughout the card.', 'mediavine' ),
						'<a href="https://www.youtube.com/watch?v=WsgrSxCmMbM" target="_blank">',
						'</a>'
					),
					'default'      => __( 'H2', 'mediavine' ),
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
					'label'        => __( 'Output JSON-LD Schema in head', 'mediavine' ),
					'instructions' => __( 'If enabled, Create will output the JSON-LD schema in the wp_head hook. If disabled, it will be output just before the card.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enhanced_search',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 80,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Use Enhanced Search', 'mediavine' ),
					'instructions' => __( 'Create by Mediavine has a search feature that allows users to match posts based on the content of the recipe cards included in the post. If you notice that this feature is causing an issue with other themes or plugins that modify the search query, you can disable this feature.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_autosave',
				'value' => true,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 85,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Autosave', 'mediavine' ),
					'instructions' => __( 'By default, we\'ll save your work as you edit, even if you haven\'t published your changes. If you disable this setting, we\'ll only save draft content if you specifically click the \'Save Draft\' button.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_allow_reviews',
				'value' => true,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 100,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Allow Reviews', 'mediavine' ),
					'instructions' => __( 'Unchecking this box will prevent users from being able to leave reviews on your recipe cards.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_reviews_ctas',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 104,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Display Review CTAs', 'mediavine' ),
					'instructions' => __( 'Checking this box will add prompts for users to leave reviews.', 'mediavine' ), // @todo this can/SHOULD be reworded better
					'default'      => __( 'Disabled', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_allow_reviews',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_logging',
				'value' => false,
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 105,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Error Reporting', 'mediavine' ),
					'instructions' => __( 'Checking this box allows the plugin to automatically send useful error reports to the development team. (You may still be prompted to manually send error reports, even if this box is unchecked.)', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_affiliate_message',
				'value' => 'As an Amazon Associate and member of other affiliate programs, I earn from qualifying purchases.',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 80,
				'data'  => [
					'type'         => 'textarea',
					'label'        => __( 'Global Affiliate Message', 'mediavine' ),
					'instructions' => __( 'Set the default affiliate disclaimer message with this text. Affiliate messaging can be overridden in individual posts.', 'mediavine' ),
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
					'label'        => __( 'Allowed Types', 'mediavine' ),
					'instructions' => null,
					'default'      => '[]',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_allowed_cpt_types',
				'value' => '[]',
				'group' => Plugin::$settings_group . '_advanced',
				'order' => 5,
				'data'  => [
					'type'         => 'multiselect',
					'label'        => __( 'Allowed Custom Post Types', 'mediavine' ),
					'instructions' => __( 'If enabled, will allow specific custom post types to be added to Lists', 'mediavine' ),
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
					'label'        => __( 'Allow Anonymous Ratings', 'mediavine' ),
					'instructions' => __( 'If enabled, 4 and 5 star reviews will be submitted when the star is clicked. Users leaving a star rating will then see a popup modal prompting them to leave an optional review. Disabling this will not sumbit the rating until after the review has been added. The prompt will still display.', 'mediavine' ),
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
					'label'        => __( 'Enable Public Reviews', 'mediavine' ),
					'instructions' => __( 'If enabled, card reviews will be publicly visible, displayed in a tab alongside comments. You must specify a DOM selector for your comments section.' ),
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
					'label'        => __( 'Comments Section', 'mediavine' ),
					// TODO: Add a link to help.mediavine.com
					'instructions' => __( 'Add the DOM selector of your comments section. (In most themes, this will be "#comments".)' ),
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
					'label'        => __( 'Prevent Image Size Generation', 'mediavine' ),
					'instructions' => __( 'If enabled, will disable specific image sizes created by Create', 'mediavine' ),
					'default'      => 'Disabled',
					'options'      => Plugin::get_image_size_values(),
				],
			],
		];
	}
}
