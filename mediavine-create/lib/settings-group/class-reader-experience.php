<?php
/**
 * Reader Experience Settings Group
 *
 * This group contains settings related to reader experience features including:
 * - Jump to Recipe button
 * - Interactive Features (checklists)
 * - Reviews and Ratings
 * - Social Sharing footer
 *
 * @package Mediavine\Create\Settings
 */

namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;
use Mediavine\Create\Theme_Checker;

/**
 * Class Reader_Experience
 * Settings class for Reader Experience group
 *
 * Consolidates settings from Pro and Advanced tabs:
 * - Jump to Recipe
 * - Interactive Features (Checklists)
 * - Reviews
 * - Social Sharing
 *
 * @package Mediavine\Create\Settings
 */
class Reader_Experience implements Settings_Group {

	/**
	 * Return settings
	 *
	 * @return array[]
	 */
	public static function settings() {
		return array_merge(
			self::jump_to_recipe_settings(),
			self::interactive_features_settings(),
			self::reviews_settings(),
			self::social_sharing_settings(),
			self::products_settings(),
			self::video_settings()
		);
	}

	/**
	 * Jump to Recipe settings
	 *
	 * @return array[]
	 */
	private static function jump_to_recipe_settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_enable_jump_to_recipe',
				'value' => false,
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 10,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Jump To Recipe Button', 'mediavine' ),
					'instructions' => __(
						'When enabled, use of a Jump Button means that readers will be able to bypass
						the content of your blog post, including any in-content ads that would have
						earned income.

						To mitigate some of this potential loss, when the button
						is pressed, our script will automatically optimize the Create card ad placements
						for Mediavine publishers.',
						'mediavine'
					),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_jump_to_recipe_text',
				'value' => __( 'Jump to Recipe', 'mediavine' ),
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 15,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Jump To Recipe Button Text', 'mediavine' ),
					'instructions' => __( 'The text of the Jump To Recipe Button', 'mediavine' ),
					'default'      => __( 'Jump to Recipe', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_enable_jump_to_recipe',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_jump_to_howto_text',
				'value' => __( 'Jump to How-To', 'mediavine' ),
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 20,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Jump To How-To Button Text', 'mediavine' ),
					'instructions' => __( 'The text of the Jump To How-To Button', 'mediavine' ),
					'default'      => __( 'Jump to How-To', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_enable_jump_to_recipe',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_jump_to_btn_color',
				'value' => 'gray',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 25,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Jump to Recipe Color', 'mediavine' ),
					'instructions' => __( 'Color for Jump to Recipe Button', 'mediavine' ),
					'default'      => __( 'Gray', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_enable_jump_to_recipe',
					'options'      => [
						[
							'label' => __( 'Gray', 'mediavine' ),
							'value' => 'gray',
						],
						[
							'label' => __( 'Custom Colors', 'mediavine' ),
							'value' => 'custom',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_jump_to_btn_style',
				'value' => 'mv-create-jtr-link',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 30,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Jump to Recipe Button Style', 'mediavine' ),
					'instructions' => __( 'Style for Jump to Recipe Button', 'mediavine' ),
					'default'      => __( 'Link', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_enable_jump_to_recipe',
					'options'      => [
						[
							'label' => __( 'Link', 'mediavine' ),
							'value' => 'mv-create-jtr-link',
						],
						[
							'label' => __( 'Hollow Button', 'mediavine' ),
							'value' => 'mv-create-jtr-button-hollow',
						],
						[
							'label' => __( 'Solid Button', 'mediavine' ),
							'value' => 'mv-create-jtr-button',
						],
					],
				],
			],
		];
	}

	/**
	 * Interactive Features settings (Checklists)
	 *
	 * @return array[]
	 */
	private static function interactive_features_settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_enable_interactive_mode',
				'value' => true,
				'group' => Plugin::$settings_group . '_interactive_mode',
				'order' => 10,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Interactive Mode', 'mediavine' ),
					'instructions' => __( 'Allow readers to experience step-by-step interactive cooking mode on recipe cards.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
					'gated'        => 'interactive_mode',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_interactive_mode_button_text',
				'value' => '',
				'group' => Plugin::$settings_group . '_interactive_mode',
				'order' => 20,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Interactive Mode Button Text', 'mediavine' ),
					'instructions' => __( 'Customize the text displayed on the Interactive Mode button. Leave blank for the default. This setting syncs with Create Studio.', 'mediavine' ),
					'default'      => '',
					'gated'        => 'interactive_mode',
					'dependent_on' => Plugin::$settings_group . '_enable_interactive_mode',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_interactive_mode_cta_variant',
				'value' => 'inline-banner',
				'group' => Plugin::$settings_group . '_interactive_mode',
				'order' => 25,
				'data'  => [
					'type'         => 'cta_variant_select',
					'label'        => __( 'CTA Style', 'mediavine' ),
					'instructions' => __( 'Choose how the Interactive Mode call-to-action appears on your recipe cards. This setting syncs with Create Studio.', 'mediavine' ),
					'default'      => 'inline-banner',
					'gated'        => 'interactive_mode',
					'dependent_on' => Plugin::$settings_group . '_enable_interactive_mode',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_interactive_mode_cta_title',
				'value' => '',
				'group' => Plugin::$settings_group . '_interactive_mode',
				'order' => 30,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'CTA Title', 'mediavine' ),
					'instructions' => __( 'Title text for the CTA element. Leave blank for the default. This setting syncs with Create Studio.', 'mediavine' ),
					'default'      => '',
					'gated'        => 'interactive_mode',
					'dependent_on' => Plugin::$settings_group . '_enable_interactive_mode',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_interactive_mode_cta_subtitle',
				'value' => '',
				'group' => Plugin::$settings_group . '_interactive_mode',
				'order' => 35,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'CTA Subtitle', 'mediavine' ),
					'instructions' => __( 'Subtitle text for the CTA element. Leave blank for the default. This setting syncs with Create Studio.', 'mediavine' ),
					'default'      => '',
					'gated'        => 'interactive_mode',
					'dependent_on' => Plugin::$settings_group . '_enable_interactive_mode',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_checklists',
				'value' => false,
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 100,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Checklists', 'mediavine' ),
					'instructions' => __( 'Add interactive checkboxes to ingredients and instructions for tracking cooking progress.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'gated'        => 'checklists',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_checklist_sections',
				'value' => 'ingredients',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 101,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Checklist Sections', 'mediavine' ),
					'instructions' => __( 'Choose which sections display interactive checkboxes.', 'mediavine' ),
					'default'      => 'ingredients',
					'gated'        => 'checklists',
					'dependent_on' => Plugin::$settings_group . '_enable_checklists',
					'options'      => [
						[
							'label' => __( 'Ingredients Only', 'mediavine' ),
							'value' => 'ingredients',
						],
						[
							'label' => __( 'Instructions Only', 'mediavine' ),
							'value' => 'instructions',
						],
						[
							'label' => __( 'Both', 'mediavine' ),
							'value' => 'both',
						],
					],
				],
			],
		];
	}

	/**
	 * Reviews settings
	 *
	 * @return array[]
	 */
	private static function reviews_settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_allow_reviews',
				'value' => true,
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 200,
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
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 210,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Display Review CTAs', 'mediavine' ),
					'instructions' => __( 'Display prompts encouraging site visitors to leave reviews.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_allow_reviews',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_enable_anonymous_ratings',
				'value' => false,
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 220,
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
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 230,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Public Reviews', 'mediavine' ),
					'instructions' => __( 'If enabled, card reviews will be publicly visible, displayed in a tab alongside comments. You must specify a DOM selector for your comments section.', 'mediavine' ),
					'default'      => 'Disabled',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_public_reviews_el',
				'value' => ( Theme_Checker::is_trellis() ? '#mv-trellis-comments' : '#comments' ),
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 240,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Comments Section', 'mediavine' ),
					'instructions' => __( 'Add the DOM selector of your comments section. (In most themes, this will be "#comments".)', 'mediavine' ),
					'default'      => '#comments',
					'dependent_on' => Plugin::$settings_group . '_enable_public_reviews',
				],
			],
		];
	}

	/**
	 * Social Sharing settings
	 *
	 * @return array[]
	 */
	private static function social_sharing_settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_social_footer',
				'value' => false,
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 300,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Enable Social Footer', 'mediavine' ),
					'instructions' => __( 'Adds a call to action to the bottom of each card encouraging social sharing.', 'mediavine' ),
					'default'      => __( 'Disabled', 'mediavine' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_service',
				'value' => 'instagram',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 310,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Social Sharing Service', 'mediavine' ),
					'instructions' => __( 'Select the social service to encourage.', 'mediavine' ),
					'default'      => __( 'Instagram', 'mediavine' ),
					'options'      => [
						[
							'label' => __( 'Facebook', 'mediavine' ),
							'value' => 'facebook',
						],
						[
							'label' => __( 'Instagram', 'mediavine' ),
							'value' => 'instagram',
						],
						[
							'label' => __( 'Pinterest', 'mediavine' ),
							'value' => 'pinterest',
						],
					],
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_cta_facebook_user',
				'value' => '',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 320,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Facebook Username', 'mediavine' ),
					'instructions' => __( 'Enter your Facebook username to link the Facebook icon on Facebook social footer cards.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_cta_instagram_user',
				'value' => '',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 330,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Instagram Username', 'mediavine' ),
					'instructions' => __( 'Enter your Instagram username to link the Instagram icon on Instagram social footer cards.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_cta_pinterest_user',
				'value' => '',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 340,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Pinterest Username', 'mediavine' ),
					'instructions' => __( 'Enter your Pinterest username to link the Pinterest icon on Pinterest social footer cards.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_cta_title_recipe',
				'value' => __( 'Did you make this recipe?', 'mediavine' ),
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 350,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Social Footer Heading - Recipe', 'mediavine' ),
					'instructions' => __( 'The title for the social footer on recipe cards. If left blank, "Did you make this recipe?" will display.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_cta_body_recipe',
				'value' => '',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 360,
				'data'  => [
					'type'         => 'wysiwyg',
					'label'        => __( 'Social Footer Content - Recipe', 'mediavine' ),
					'instructions' => __( 'The content for the social footer on recipe cards. If left blank, "Please leave a comment on the blog or share a photo on {service_name}" will display.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_cta_title_diy',
				'value' => __( 'Did you make this project?', 'mediavine' ),
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 370,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Social Footer Heading - How-To', 'mediavine' ),
					'instructions' => __( 'The title for the social footer on how-to cards. If left blank, "Did you make this project?" will display.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_social_cta_body_diy',
				'value' => '',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 380,
				'data'  => [
					'type'         => 'wysiwyg',
					'label'        => __( 'Social Footer Content - How-To', 'mediavine' ),
					'instructions' => __( 'The content for the social footer on how-to cards. If left blank, "Please leave a comment on the blog or share a photo on {service_name}" will display.', 'mediavine' ),
					'default'      => '',
					'dependent_on' => Plugin::$settings_group . '_social_footer',
				],
			],
		];
	}

	/**
	 * Video settings
	 *
	 * @return array[]
	 */
	private static function video_settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_video_position',
				'value' => '',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 390,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Video Section Position', 'mediavine' ),
					'instructions' => __( 'Choose where the video section appears on recipe and how-to cards. Individual cards can override this setting.', 'mediavine' ),
					'default'      => __( 'Below Notes', 'mediavine' ),
					'options'      => [
						[
							'label' => __( 'Default (Below Notes)', 'mediavine' ),
							'value' => '',
						],
						[
							'label' => __( 'Above Supplies', 'mediavine' ),
							'value' => 'above_supplies',
						],
						[
							'label' => __( 'Above Instructions', 'mediavine' ),
							'value' => 'above_instructions',
						],
						[
							'label' => __( 'Below Instructions', 'mediavine' ),
							'value' => 'below_instructions',
						],
					],
				],
			],
		];
	}

	/**
	 * Products settings
	 *
	 * @return array[]
	 */
	private static function products_settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_products_display_mode',
				'value' => 'gallery',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 400,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Products Display Mode', 'mediavine' ),
					'instructions' => __( 'Choose how products are displayed on recipe and how-to cards. Gallery shows products in a scrollable carousel, while List displays them in a clean text format.', 'mediavine' ),
					'default'      => __( 'Gallery', 'mediavine' ),
					'gated'        => 'products_list_display',
					'options'      => [
						[
							'label' => __( 'Gallery', 'mediavine' ),
							'value' => 'gallery',
						],
						[
							'label' => __( 'List', 'mediavine' ),
							'value' => 'list',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_products_section_title',
				'value' => __( 'Recommended Products', 'mediavine' ),
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 410,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Products Section Title', 'mediavine' ),
					'instructions' => __( 'The heading displayed above the products section on cards.', 'mediavine' ),
					'default'      => __( 'Recommended Products', 'mediavine' ),
					'gated'        => 'products_list_display',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_products_position',
				'value' => 'after_video',
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 420,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Products Section Position', 'mediavine' ),
					'instructions' => __( 'Choose where the products section appears on recipe and how-to cards.', 'mediavine' ),
					'default'      => __( 'After Video', 'mediavine' ),
					'gated'        => 'products_list_display',
					'options'      => [
						[
							'label' => __( 'Above Supplies', 'mediavine' ),
							'value' => 'above_supplies',
						],
						[
							'label' => __( 'Above Instructions', 'mediavine' ),
							'value' => 'above_instructions',
						],
						[
							'label' => __( 'Below Notes', 'mediavine' ),
							'value' => 'below_notes',
						],
						[
							'label' => __( 'After Video', 'mediavine' ),
							'value' => 'after_video',
						],
					],
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_products_list_show_disclaimer',
				'value' => true,
				'group' => Plugin::$settings_group . '_reader_experience',
				'order' => 430,
				'data'  => [
					'type'         => 'checkbox',
					'label'        => __( 'Show Affiliate Disclaimer', 'mediavine' ),
					'instructions' => __( 'Display an affiliate disclaimer below the products section when using the text list display mode. This helps with FTC compliance for affiliate links.', 'mediavine' ),
					'default'      => __( 'Enabled', 'mediavine' ),
				],
			],
		];
	}
}
