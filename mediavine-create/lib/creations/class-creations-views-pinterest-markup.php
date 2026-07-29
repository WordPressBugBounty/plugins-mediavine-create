<?php
namespace Mediavine\Create;

/**
 * Pinterest img-tag string surgery and display defaults for Create cards.
 *
 * Extracted from Creations_Views::prep_creation_view() (CRE-129 step 4).
 */
class Creations_Views_Pinterest_Markup {

	/**
	 * Inject data-pin attributes into image tags and set Pinterest display fields.
	 *
	 * @param array  $published_creation Published card data (must already have images).
	 * @param string $img_size           Active photo-ratio size slug.
	 * @return array
	 */
	public static function prepare( $published_creation, $img_size ) {
		if ( isset( $published_creation['images'][ $img_size ] ) ) {
			$description          = htmlentities( $published_creation['pinterest_description'] ?: '' );
			$data_pin_description = 'data-pin-description="' . $description . '"';

			$published_creation['images'][ $img_size ] = str_replace(
				' alt',
				" $data_pin_description alt",
				$published_creation['images'][ $img_size ]
			);
		}

		// Get Pinterest settings.
		$pinterest_location = \Mediavine\Settings::get_setting(
			Plugin::$settings_group . '_pinterest_location',
			'mv-creation-pin-button'
		);

		$published_creation['pinterest_class'] = $pinterest_location;

		if (
			isset( $published_creation['images'] ) &&
			isset( $published_creation['images']['mv_create_vert'] ) &&
			'off' !== $pinterest_location
		) {
			// Set Pinterest description as image alt text so browser extension picks it up.
			$pin_img          = $published_creation['images']['mv_create_vert'];
			$pin_img_alt_text = 'alt="" data-pin-description="' . htmlentities( $published_creation['pinterest_description'] ?: '' ) . '"';
			$published_creation['images']['mv_create_vert'] = str_replace( 'class', "$pin_img_alt_text class", $pin_img );

			$published_creation['pinterest_display'] = true;

			if ( empty( $published_creation['pinterest_description'] ) ) {
				$published_creation['pinterest_description'] = $published_creation['title'];
			}

			if ( empty( $published_creation['pinterest_url'] ) ) {
				$published_creation['pinterest_url'] = get_the_permalink();
			}

			if ( empty( $published_creation['pinterest_img_id'] ) ) {
				$published_creation['pinterest_img_id'] = $published_creation['thumbnail_id'];
			}
		}

		// Remove Pinterest image if the Pinterest button display is set to off.
		if ( isset( $published_creation['images'] ) && 'off' === $pinterest_location ) {
			unset( $published_creation['images']['mv_create_vert'] );
		}

		return $published_creation;
	}
}
