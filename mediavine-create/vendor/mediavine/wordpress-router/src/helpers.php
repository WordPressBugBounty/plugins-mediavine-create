<?php

use Mediavine\WordPress\Support\Arr;
use Mediavine\WordPress\Support\Collection;

if ( ! function_exists( 'mv_response' ) ) {
	/**
	 * Return a new response with the passed data.
	 *
	 * @param array|mixed $data
	 * @return \WP_REST_Response
	 */
	function mv_response( $data = [] ) {
		$data = Collection::make( $data )->all();
		return new \WP_REST_Response( $data );
	}
}

if ( ! function_exists( 'mv_get_request_params' ) ) {

	function mv_get_request_params( \WP_REST_Request $request, $attributes = [] ) {
		if ( ! $attributes ) {
			return $request->get_params();
		}
		return Arr::only( $request->get_params(), $attributes );
	}
}
