<?php


namespace Mediavine\Create\Settings;

/**
 * Interface Settings_Group
 *
 * @package Mediavine
 */
interface Settings_Group {
	/**
	 * Return array of settings as defined by a group
	 * @return array[]
	 */
	public static function settings();
}
