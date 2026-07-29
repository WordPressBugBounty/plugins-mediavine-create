<?php

namespace Mediavine\Create;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

/**
 * Run a callable with a temporary filter that is always removed afterward.
 *
 * Prevents request-scoped add_filter toggles from leaking when remove_filter
 * uses the wrong callback or is skipped by an early return / conditional.
 *
 * @param string   $hook          Filter hook name.
 * @param callable $callback      Filter callback to add for the duration of $fn.
 * @param callable $fn            Work to run while the filter is active.
 * @param int      $priority      Filter priority.
 * @param int      $accepted_args Number of arguments the filter accepts.
 * @return mixed Return value of $fn.
 */
function with_filter( $hook, $callback, $fn, $priority = 10, $accepted_args = 1 ) {
	add_filter( $hook, $callback, $priority, $accepted_args );
	try {
		return $fn();
	} finally {
		remove_filter( $hook, $callback, $priority );
	}
}
