<?php
/**
 * The plugin bootstrap file
 *
 * @link              https://www.mediavine.com/
 * @since             1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       Create by Mediavine
 * Plugin URI:        https://www.mediavine.com/mediavine-create/
 * Description:       Create custom recipe cards to be displayed in posts.
 * Version:           1.9.11
 * Requires at least: 5.2
 * Requires PHP:      7.2
 *
 * Author:            Mediavine
 * Author URI:        https://www.mediavine.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       mediavine
 * Domain Path:       /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This plugin requires WordPress' );
}

// Autoload via Composer.
require_once __DIR__ . '/vendor/autoload.php';

// Environment.
define( 'MV_CREATE_URL', plugin_dir_url( __FILE__ ) );
define( 'MV_CREATE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MV_CREATE_PLUGIN_FILE', plugin_basename( __FILE__ ) );
define( 'MV_CREATE_BASENAME_DIR', plugin_basename( __DIR__ ) );

// Add hooks regardless of compatibility.
add_filter( 'plugin_action_links_' . MV_CREATE_PLUGIN_FILE, 'mv_create_add_action_links' );
add_filter( 'plugin_row_meta', 'mv_create_plugin_info_links', 10, 2 );
add_action( 'admin_notices', 'mv_create_incompatible_notice' );
add_action( 'admin_notices', 'mv_create_permalink_check' );
add_action( 'admin_head', 'mv_create_throw_warnings' );

if ( mv_create_is_compatible() ) {
	\Mediavine\Create\Plugin::get_instance();
}
