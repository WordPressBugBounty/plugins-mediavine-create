<?php

namespace Mediavine\Create;

/**
 * Adds Create's `mv` field (featured-image id + medium URL) to core post REST
 * responses, which the card editor uses to pick a post thumbnail.
 */
class REST_Post_Fields {

	/**
	 * Extend default REST API with useful data.
	 *
	 * @param [object] $data the current object outbound to rest response
	 * @param [object] $post post object for use in the outbound response
	 * @param [object] $request the wp rest request object.
	 * @return [object] update $data object
	 */
	public static function rest_prepare_post( $data, $post, $request ) {
		$_data                        = $data->data;
		$_data['mv']                  = [];
		$_data['mv']['thumbnail_id']  = null;
		$_data['mv']['thumbnail_uri'] = null;

		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		if ( empty( $thumbnail_id ) ) {
			$data->data = $_data;
			return $data;
		}

		$thumbnail                   = wp_get_attachment_image_src( $thumbnail_id, 'medium' );
		$_data['mv']['thumbnail_id'] = $thumbnail_id;

		if ( isset( $thumbnail[0] ) ) {
			$_data['mv']['thumbnail_uri'] = $thumbnail[0];
		}

		$data->data = $_data;
		return $data;
	}
}
