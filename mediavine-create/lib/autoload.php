<?php
/**
 * First-party authoritative classmap autoloader for Create.
 *
 * Replaces Composer runtime scaffolding in the shipped plugin. Developer tooling
 * (PHPUnit, PHPCS, PHPStan, Phing) still uses Composer via vendor/.
 *
 * @package Create
 */

// Prevent direct access. Loaded only after WordPress defines ABSPATH (plugin
// bootstrap, or tests after the WP test lib boots). Do not require this file
// early from CLI — a plain ABSPATH guard would exit PHPUnit with code 0.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$create_classmap = require __DIR__ . '/autoload-classmap.php';

spl_autoload_register(
	static function ( $class ) use ( $create_classmap ) {
		if ( ! isset( $create_classmap[ $class ] ) ) {
			return;
		}

		$create_path = dirname( __DIR__ ) . '/' . $create_classmap[ $class ];
		if ( is_readable( $create_path ) ) {
			require $create_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is from the committed classmap, not request input.
		}
	}
);

$create_autoload_files = [
	__DIR__ . '/functions.php',
	__DIR__ . '/functions-feature-flags.php',
	__DIR__ . '/functions-version-check.php',
	__DIR__ . '/api/v1/creations-args.php',
	__DIR__ . '/api/v1/creations-schema.php',
	__DIR__ . '/helpers/functions-helpers.php',
];

foreach ( $create_autoload_files as $create_autoload_file ) {
	require_once $create_autoload_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Fixed list of plugin files above.
}

unset( $create_classmap, $create_autoload_files, $create_autoload_file );
