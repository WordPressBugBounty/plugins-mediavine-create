<?php
/**
 * First-party authoritative classmap autoloader for Create.
 *
 * Replaces Composer runtime scaffolding in the shipped plugin. Developer tooling
 * (PHPUnit, PHPCS, PHPStan, Phing) still uses Composer via vendor/.
 *
 * @package Mediavine_Create
 */

// Prevent direct access. Loaded only after WordPress defines ABSPATH (plugin
// bootstrap, or tests after the WP test lib boots). Do not require this file
// early from CLI — a plain ABSPATH guard would exit PHPUnit with code 0.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mv_create_classmap = require __DIR__ . '/autoload-classmap.php';

spl_autoload_register(
	static function ( $class ) use ( $mv_create_classmap ) {
		if ( ! isset( $mv_create_classmap[ $class ] ) ) {
			return;
		}

		$mv_create_path = dirname( __DIR__ ) . '/' . $mv_create_classmap[ $class ];
		if ( is_readable( $mv_create_path ) ) {
			require $mv_create_path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is from the committed classmap, not request input.
		}
	}
);

$mv_create_autoload_files = [
	__DIR__ . '/functions.php',
	__DIR__ . '/functions-feature-flags.php',
	__DIR__ . '/functions-version-check.php',
	__DIR__ . '/helpers.php',
	__DIR__ . '/api/v1/creations-args.php',
	__DIR__ . '/api/v1/creations-schema.php',
	__DIR__ . '/helpers/functions-helpers.php',
];

foreach ( $mv_create_autoload_files as $mv_create_autoload_file ) {
	require_once $mv_create_autoload_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Fixed list of plugin files above.
}

unset( $mv_create_classmap, $mv_create_autoload_files, $mv_create_autoload_file );
