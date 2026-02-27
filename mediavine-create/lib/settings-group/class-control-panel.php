<?php


namespace Mediavine\Create\Settings;

/**
 * Settings group for MCP Control Panel.
 *
 * The list_items_between_ads setting has moved to List_Ads to support
 * all publishers. This class is kept for backward compatibility.
 *
 * @expectedDeprecation 1.9
 */
class Control_Panel implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		return [];
	}
}
