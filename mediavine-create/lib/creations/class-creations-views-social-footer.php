<?php
namespace Mediavine\Create;

/**
 * Assembles social-footer fields for Create card markup.
 *
 * Extracted from Creations_Views::prep_creation_view() (CRE-129 step 4).
 */
class Creations_Views_Social_Footer {

	/**
	 * Decode custom fields and, when enabled, populate social footer content.
	 *
	 * @param array  $published_creation Published card data.
	 * @param string $type               Card type (recipe, diy, …).
	 * @return array
	 */
	public static function prepare( $published_creation, $type ) {
		// Custom fields need to be decoded before we prep the social footer content.
		$published_creation['custom_fields'] = json_decode( $published_creation['custom_fields'] ?: '{}', true );

		$settings_group = Plugin::$settings_group;

		// Get social footer content if enabled.
		$published_creation['social_footer'] = \Mediavine\Settings::get_setting( $settings_group . '_social_footer', false );
		if ( ! $published_creation['social_footer'] ) {
			return $published_creation;
		}

		// Get correct social footer content, either from settings or override.
		// Allowlist so a poisoned custom field cannot reach view includes (CRE-212).
		$published_creation['social_icon'] = Creations_Views::sanitize_social_icon(
			Creations_Views::get_custom_field(
				$published_creation,
				'mv_create_social_footer_icon',
				\Mediavine\Settings::get_setting( $settings_group . '_social_service' ),
				true
			)
		);
		$published_creation['social_cta_title'] = Creations_Views::get_custom_field(
			$published_creation,
			'mv_create_social_footer_header',
			\Mediavine\Settings::get_setting( $settings_group . '_social_cta_title_' . $type )
		);

		// Grab default title if empty.
		if ( empty( $published_creation['social_cta_title'] ) ) {
			$social_card_type = 'recipe';
			if ( 'diy' === $type ) {
				$social_card_type = 'project';
			}
			$published_creation['social_cta_title'] = sprintf(
				// Translators: Type of card. Will output either 'recipe' or 'project'
				__( 'Did you make this %s?', 'mediavine-create' ),
				$social_card_type
			);
		}

		$published_creation['social_cta_body'] = Creations_Views::get_custom_field(
			$published_creation,
			'mv_create_social_footer_content'
		);

		// The WYSIWYG changes empty values to `<p></p>` so we need to check for that and grab the global setting value.
		if ( '<p></p>' === $published_creation['social_cta_body'] || empty( $published_creation['social_cta_body'] ) ) {
			$published_creation['social_cta_body'] = \Mediavine\Settings::get_setting( $settings_group . '_social_cta_body_' . $type );
		}

		// Grab default message if body empty.
		if ( '<p></p>' === $published_creation['social_cta_body'] || empty( $published_creation['social_cta_body'] ) ) {
			$published_creation['social_cta_body'] = '<p>' . sprintf(
				// Translators: Social Service name with link
				__( 'Please leave a comment on the blog or share a photo on %s', 'mediavine-create' ),
				Creations_Views::get_social_link_tag( $published_creation['social_icon'] ) . ucfirst( $published_creation['social_icon'] ) . '</a>'
			) . '</p>';
		}

		$published_creation['social_body_kses'] = [
			'a'      => [
				'class'  => true,
				'href'   => true,
				'target' => true,
				'rel'    => true,
			],
			'strong' => [
				'class' => true,
			],
			'em'     => [
				'class' => true,
			],
		];

		return $published_creation;
	}
}
