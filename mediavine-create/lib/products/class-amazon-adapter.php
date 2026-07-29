<?php
/**
 * Amazon API Adapter.
 *
 * Factory that returns the Amazon Creators API implementation.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

class Amazon_Adapter {

	/**
	 * Get the Amazon Creators API instance.
	 *
	 * @return Amazon_Creators
	 */
	public static function get_instance() {
		return Amazon_Creators::get_instance();
	}
}
