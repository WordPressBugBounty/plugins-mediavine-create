<?php

namespace Mediavine\Create;

/**
 * Checks if filter is currently being processed.
 *
 * Wrapper for core WP function, but this can be filtered for unit testing purposes.
 *
 * @param null|string $filter Filter to check. Defaults to null, which checks if any filter is currently being run.
 * @return bool Whether the filter is currently in the stack.
 */
function doing_filter( $filter = null ) {
	$doing_filter = \doing_filter( $filter );

	/**
	 * Overrides if the filter is currently being processed. For PHPUnit tests only
	 *
	 * @param bool $doing_filter
	 */
	return apply_filters( 'mv_create_doing_filter_' . $filter, $doing_filter );
}

function get_current_post_id() {
	global $id;

	if ( empty( $id ) ) {
		$id = get_the_ID();
	}

	if ( empty( $id ) ) {
		$id = apply_filters( 'mv_create_current_post_id', $id );
	}

	return (int) $id;
}
