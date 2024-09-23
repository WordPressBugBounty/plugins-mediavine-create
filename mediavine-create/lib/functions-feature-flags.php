<?php
namespace Mediavine\Create;

/**
 * Whether a feature flag is enabled.
 *
 * @param $name Feature flag unique name.
 * @return bool
 */
function feature_flag( $name ) {
	// Pass to Beta_Tester plugin.
	if ( function_exists( '\Mediavine\Beta\feature_flag' ) ) {
		return \Mediavine\Beta\feature_flag( $name );
	}

	return false;
}

/**
 * Declare the feature flags currently in use.
 *
 * @return array Registered flags.
 */
function register_flags() {
	// Load the manifest.
	$feature_flags = include __DIR__ . '/feature-flags.php';

	// Pass to Beta_Tester plugin.
	if ( function_exists( '\Mediavine\Beta\register_flags' ) ) {
		return \Mediavine\Beta\register_flags( $feature_flags );
	}

	return [];
}

// CREATE FEATURE FLAG CALLBACKS //
