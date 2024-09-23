<?php

namespace Mediavine\Create\API\V1\CreationsArgs;

/**
 * Args accepted by <id> endpoints.
 *
 * @return array
 */
function validate_id() {
	$args = [];

	$args['id'] = [
		'description'       => esc_html__( 'ID of the card being referenced.', 'mediavine' ),
		'validate_callback' => function( $param, $request, $key ) {
			return is_numeric( $param );
		},
		'required'          => true,
	];

	return $args;
}

/**
 * Args accepted by <slug> endpoints.
 *
 * @return array
 */
function sanitize_slug() {
	$args = [];

	$args['slug'] = [
		'description'       => esc_html__( 'Slug of the setting being referenced.', 'mediavine' ),
		'sanitize_callback' => 'sanitize_title_for_query',
		'required'          => true,
	];

	return $args;
}
