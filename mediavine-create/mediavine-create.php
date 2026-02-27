<?php
/**
 * The plugin bootstrap file
 *
 * @link              https://create.studio/
 * @since             1.9.16
 *
 * @wordpress-plugin
 * Plugin Name:       Create
 * Plugin URI:        https://create.studio/plugin
 * Description:       Create custom recipe and how to cards to be displayed in posts.
 * Version:           2.0.12
 * Requires at least: 6.5
 * Requires PHP:      7.4
 *
 * Author:            Mischief Marmot
 * Author URI:        https://create.studio/
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
