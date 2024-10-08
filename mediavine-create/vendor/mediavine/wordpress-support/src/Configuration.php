<?php

namespace Mediavine\WordPress\Support;

use ArrayAccess;
use Mediavine\WordPress\Support\Arr;
use Mediavine\WordPress\Support\Contracts\ConfigurationRepository;

class Configuration implements ArrayAccess, ConfigurationRepository {

	/**
	 * All of the configuration items.
	 *
	 * @var array
	 */
	protected $items = [];

	/**
	 * Create a new configuration repository.
	 *
	 * @param  array  $items
	 * @return void
	 */
	public function __construct( array $items = [] ) {
		$this->items = $items;
	}

	/**
	 * Determine if the given configuration value exists.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function has( $key ) {
		return Arr::has( $this->items, $key );
	}

	/**
	 * Get the specified configuration value.
	 *
	 * @param  array|string  $key
	 * @param  mixed   $default
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( is_array( $key ) ) {
			return $this->getMany( $key );
		}

		return Arr::get( $this->items, $key, $default );
	}

	/**
	 * Get many configuration values.
	 *
	 * @param  array  $keys
	 * @return array
	 */
	public function getMany( $keys ) {
		$config = [];
		if ( ! is_array( $keys ) ) {
			$keys = func_get_args();
		}

		foreach ( $keys as $key => $default ) {
			if ( is_numeric( $key ) ) {
				list($key, $default) = [ $default, null ];
			}

			$config[ $key ] = Arr::get( $this->items, $key, $default );
		}

		return $config;
	}

	/**
	 * Set a given configuration value.
	 *
	 * @param  array|string  $key
	 * @param  mixed $value
	 * @return mixed $value
	 */
	public function set( $key, $value = null ) {
		$keys = is_array( $key ) ? $key : [ $key => $value ];

		foreach ( $keys as $key => $value ) {
			Arr::set( $this->items, $key, $value );
		}
		return $value;
	}

	/**
	 * Alias for set.
	 *
	 * @param  array|string $key
	 * @param  mixed $value
	 * @return mixed $value
	 */
	function bind( $key, $value = null ) {
		return $this->set( $key, $value );
	}

	/**
	 * Prepend a value onto an array configuration value.
	 *
	 * @param  string  $key
	 * @param  mixed  $value
	 * @return void
	 */
	public function prepend( $key, $value ) {
		$array = $this->get( $key );

		if ( ! is_array( $array ) ) {
			$array = [ $array ];
		}
		array_unshift( $array, $value );

		$this->set( $key, $array );
	}

	/**
	 * Push a value onto an array configuration value.
	 *
	 * @param  string  $key
	 * @param  mixed  $value
	 * @return void
	 */
	public function push( $key, $value ) {
		$array = $this->get( $key );

		if ( ! is_array( $array ) ) {
			$array = [ $array ];
		}
		$array[] = $value;

		$this->set( $key, $array );
	}

	/**
	 * Get all of the configuration items for the application.
	 *
	 * @return array
	 */
	public function all() {
		return $this->items;
	}

	/**
	 * Determine if the given configuration option exists.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function offsetExists( $key ) {
		return $this->has( $key );
	}

	/**
	 * Get a configuration option.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function offsetGet( $key ) {
		return $this->get( $key );
	}

	/**
	 * Set a configuration option.
	 *
	 * @param  string  $key
	 * @param  mixed  $value
	 * @return void
	 */
	public function offsetSet( $key, $value ) {
		$this->set( $key, $value );
	}

	/**
	 * Unset a configuration option.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function offsetUnset( $key ) {
		unset( $this->items[ $key ] );
	}
}
