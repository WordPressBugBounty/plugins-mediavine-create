<?php


namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;


class Pro implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		return [
			[
				'slug'  => Plugin::$settings_group . '_enable_jump_to_recipe',
				'value' => false,
				'group' => Plugin::$settings_group . '_pro',
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 15,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Jump To Recipe Button Text', 'mediavine' ),
					'instructions' => __( 'The text of the Jump To Recipe Button' ),
					'default'      => __( 'Jump to Recipe', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_enable_jump_to_recipe',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_jump_to_howto_text',
				'value' => __( 'Jump to How-To', 'mediavine' ),
				'group' => Plugin::$settings_group . '_pro',
				'order' => 20,
				'data'  => [
					'type'         => 'text',
					'label'        => __( 'Jump To How-To Button Text', 'mediavine' ),
					'instructions' => __( 'The text of the Jump To How-To Button' ),
					'default'      => __( 'Jump to How-To', 'mediavine' ),
					'dependent_on' => Plugin::$settings_group . '_enable_jump_to_recipe',
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_jump_to_btn_color',
				'value' => 'gray',
				'group' => Plugin::$settings_group . '_pro',
				'order' => 25,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Jump to Recipe Color', 'mediavine' ),
					'instructions' => __( 'Color for Jump to Recipe Button', ' mediavine' ),
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 30,
				'data'  => [
					'type'         => 'select',
					'label'        => __( 'Jump to Recipe Button Style', 'mediavine' ),
					'instructions' => __( 'Style for Jump to Recipe Button', ' mediavine' ),
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
			[
				'slug'  => Plugin::$settings_group . '_social_footer',
				'value' => false,
				'group' => Plugin::$settings_group . '_pro',
				'order' => 35,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 40,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 42,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 44,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 46,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 48,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 50,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 52,
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
				'group' => Plugin::$settings_group . '_pro',
				'order' => 54,
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
}
