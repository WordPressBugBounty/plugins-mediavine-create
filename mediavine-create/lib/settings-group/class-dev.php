<?php

namespace Mediavine\Create\Settings;

use Mediavine\Create\Plugin;

/**
 * Settings group for development/debug options.
 * Only displayed when dev mode is enabled (see Plugin::is_dev_mode()).
 *
 * Note: The Dev tab content is rendered directly in the React Settings component
 * (admin/ui/src/views/Settings/index.tsx) rather than through the settings system,
 * because dev options are action buttons rather than configurable settings.
 */
class Dev implements Settings_Group {

	/**
	 * @inheritDoc
	 */
	public static function settings() {
		// Dev tab has no configurable settings - it only has action buttons
		// which are rendered directly in the React Settings component.
		// This method returns an empty array but the class is still needed
		// to be included in the settings merge for consistency.
		return [];
	}
}
