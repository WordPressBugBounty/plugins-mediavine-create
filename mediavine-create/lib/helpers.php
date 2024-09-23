<?php

use Mediavine\WordPress\Support\Collection;

if ( ! function_exists( 'class_basename' ) ) {
	/**
	 * Get the class "basename" of the given object / class.
	 *
	 * @param  string|object  $class
	 * @return string
	 */
	function class_basename( $class ) {
		$class = is_object( $class ) ? get_class( $class ) : $class;
		return basename( str_replace( '\\', '/', $class ) );
	}
}

if ( ! function_exists( 'collect' ) ) {
	/**
	 * Create a collection from the given value.
	 *
	 * @param  mixed  $value
	 * @return \Illuminate\Support\Collection
	 */
	function collect( $value = null ) {
		return new Collection( $value );
	}
}
