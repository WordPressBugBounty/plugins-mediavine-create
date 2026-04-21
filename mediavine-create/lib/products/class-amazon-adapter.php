<?php
/**
 * Amazon API Adapter.
 *
 * Factory that returns the appropriate Amazon implementation based on
 * configured credentials.
 *
 * Priority:
 * 1. Creators API credentials exist → Amazon_Creators
 * 2. Fallback → Amazon (legacy PA-API)
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

use Mediavine\Settings;

class Amazon_Adapter {

	/**
	 * Get the appropriate Amazon API instance.
	 *
	 * @return Amazon|Amazon_Creators
	 */
	public static function get_instance() {
		$creators_id     = Settings::get_setting( 'mv_create_creators_credential_id', '' );
		$creators_secret = Settings::get_setting( 'mv_create_creators_credential_secret', '' );

		if ( ! empty( $creators_id ) && ! empty( $creators_secret ) ) {
			return Amazon_Creators::get_instance();
		}

		// Fall back to legacy PA-API.
		return Amazon::get_instance();
	}
}
