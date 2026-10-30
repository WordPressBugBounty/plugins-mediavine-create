<?php

namespace Mediavine\Create;

/**
 * Create's built-in per-card custom fields, offered through `mv_create_fields`.
 */
class Custom_Fields {

	/**
	 * Register custom fields for users.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'mv_create_fields', [ __CLASS__, 'default_fields' ] );
	}

	/**
	 * Append the built-in fields to the `mv_create_fields` list.
	 *
	 * @param array $arr Registered custom fields.
	 * @return array
	 */
	public static function default_fields( $arr ) {
		$arr[] = [
			'slug'         => 'class',
			'label'        => __( 'CSS Class', 'mediavine-create' ),
			'instructions' => __( 'Add an additional CSS class to this card.', 'mediavine-create' ),
			'type'         => 'text',
		];
		$arr[] = [
			'slug'         => 'mv_create_nutrition_disclaimer',
			'label'        => __( 'Custom Nutrition Disclaimer', 'mediavine-create' ),
			'instructions' => __( 'Example: Nutrition information isn\'t always accurate.', 'mediavine-create' ),
			'type'         => 'textarea',
			'card'         => 'recipe',
		];
		$arr[] = [
			'slug'         => 'mv_create_affiliate_message',
			'label'        => __( 'Custom Affiliate Message', 'mediavine-create' ),
			'instructions' => __( 'Override the default affiliate message for this card.', 'mediavine-create' ),
			'type'         => 'textarea',
			'card'         => [ 'recipe', 'diy', 'list' ],
		];
		$arr[] = [
			'slug'         => 'mv_create_show_list_affiliate_message',
			'label'        => __( 'Show Custom Affiliate Message', 'mediavine-create' ),
			'instructions' => __( 'Check this box to display an affiliate message on this List.', 'mediavine-create' ),
			'type'         => 'checkbox',
			'card'         => 'list',
		];

		// Social footer overrides
		if ( \Mediavine\Settings::get_setting( 'mv_create_social_footer', false ) ) {
			$arr[] = [
				'slug'         => 'mv_create_social_footer_icon',
				'label'        => __( 'Social Footer Icon', 'mediavine-create' ),
				'instructions' => __( 'Override the default social footer icon for this card.', 'mediavine-create' ),
				'type'         => 'select',
				'defaultValue' => 'default',
				'options'      => [
					'default'   => 'Use Default',
					'facebook'  => 'Facebook',
					'instagram' => 'Instagram',
					'pinterest' => 'Pinterest',
				],
				'card'         => [ 'recipe', 'diy' ],
			];
			$arr[] = [
				'slug'         => 'mv_create_social_footer_header',
				'label'        => __( 'Social Footer Heading', 'mediavine-create' ),
				'instructions' => __( 'Override the default social footer heading for this card.', 'mediavine-create' ),
				'type'         => 'text',
				'card'         => [ 'recipe', 'diy' ],
			];
			$arr[] = [
				'slug'         => 'mv_create_social_footer_content',
				'label'        => __( 'Social Footer Content', 'mediavine-create' ),
				'instructions' => __( 'Override the default social footer content for this card.', 'mediavine-create' ),
				'type'         => 'wysiwyg',
				'card'         => [ 'recipe', 'diy' ],
			];
		}


		return $arr;
	}
}
