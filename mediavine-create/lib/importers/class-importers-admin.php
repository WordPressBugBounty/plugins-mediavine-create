<?php

namespace Mediavine\Create\Importers;

use Mediavine\Create\Plugin;
use Mediavine\Create\Admin_Init;
use Mediavine\Settings;

/**
 * Admin initialization for Recipe Importers
 */
class Importers_Admin {

	/**
	 * Enqueue admin scripts for the import page
	 *
	 * @param string $hook The current admin page.
	 */
	public function admin_enqueue_scripts( $hook ) {
		// Only load on import page
		global $current_screen;
		if ( $current_screen && 'mv_create_page_import' !== $current_screen->id ) {
			return;
		}

		$settings = Settings::get_settings();
		$shapes   = \Mediavine\Create\Shapes::get_shapes();

		$version    = Plugin::VERSION;
		$assets_url = Plugin::assets_url() . 'admin/ui/build/';

		// CSS is optional - only enqueue if file exists
		$css_path = plugin_dir_path( dirname( __DIR__ ) ) . 'admin/ui/build/importers.build.' . $version . '.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style( 'mv_create/importers.css', $assets_url . 'importers.build.' . $version . '.css', [], $version );
		}

		$script_url = $assets_url . 'importers.build.' . $version . '.js';

		if ( apply_filters( 'mv_create_dev_mode', false ) ) {
			$dev_port   = apply_filters( 'mv_create_dev_port', defined( 'MV_CREATE_DEV_PORT' ) ? MV_CREATE_DEV_PORT : 3000 );
			$script_url = 'http://localhost:' . $dev_port . '/importers.build.' . $version . '.js';
			wp_dequeue_style( 'mv_create/importers.css' );
		}

		wp_register_script( 'mv_create/importers.js', $script_url, [ Plugin::PLUGIN_DOMAIN . '-script' ], $version, true );

		wp_localize_script(
			'mv_create/importers.js',
			'MV_IMPORTER',
			[
				'__URL__'       => esc_url_raw( rest_url() ),
				'__NONCE__'     => wp_create_nonce( 'wp_rest' ),
				'__ADMIN_URL__' => esc_url_raw( admin_url() ),
				'imported'      => json_decode( MV_Recipe_Importer::get_imported_recipes() ?: '[]' ),
				'replaced'      => json_decode( MV_Recipe_Importer::get_replaced_recipes() ?: '[]' ),
			]
		);
		wp_enqueue_script( 'mv_create/importers.js' );
	}

	/**
	 * Add media button for single recipe import in classic editor
	 *
	 * @param string $editor_id The editor ID.
	 */
	public function media_buttons( $editor_id ) {
		if ( 'content' !== $editor_id ) {
			return;
		}
		?>
			<div id="MVSingleImporter" class="mv-ui-root"></div>
		<?php
	}

	/**
	 * Render the submenu page content
	 */
	public function submenu_page() {
		?>
		<div id="mv-recipe-importer-app" class="mv-ui-root"></div>
		<?php
	}

	/**
	 * Add the Import Recipes submenu
	 */
	public function update_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=mv_create',
			__( 'Import Recipes', 'mediavine-create' ),
			__( 'Import Recipes', 'mediavine-create' ),
			'manage_options',
			'import',
			[ $this, 'submenu_page' ]
		);
	}

	/**
	 * Enqueue block editor assets for Gutenberg integration
	 *
	 * This loads the importers script in the block editor so the mv/inter block
	 * can be registered for transforming legacy recipe shortcodes.
	 */
	public function enqueue_block_editor_assets() {
		$version    = Plugin::VERSION;
		$assets_url = Plugin::assets_url() . 'admin/ui/build/';
		$script_url = $assets_url . 'importers.build.' . $version . '.js';

		if ( apply_filters( 'mv_create_dev_mode', false ) ) {
			$dev_port   = apply_filters( 'mv_create_dev_port', defined( 'MV_CREATE_DEV_PORT' ) ? MV_CREATE_DEV_PORT : 3000 );
			$script_url = 'http://localhost:' . $dev_port . '/importers.build.' . $version . '.js';
		}

		// Depend on the slim blocks script so MV_SHARED_COMPONENTS is available
		// without shipping the full Create admin SPA on post-edit screens.
		wp_enqueue_script(
			'mv_create/importers-block.js',
			$script_url,
			[ 'wp-blocks', 'wp-element', 'wp-i18n', Admin_Init::blocks_script_handle() ],
			$version,
			true
		);

		// Localize with importer data
		wp_localize_script(
			'mv_create/importers-block.js',
			'MV_IMPORTER',
			[
				'__URL__'       => esc_url_raw( rest_url() ),
				'__NONCE__'     => wp_create_nonce( 'wp_rest' ),
				'__ADMIN_URL__' => esc_url_raw( admin_url() ),
				'imported'      => json_decode( MV_Recipe_Importer::get_imported_recipes() ?: '[]' ),
				'replaced'      => json_decode( MV_Recipe_Importer::get_replaced_recipes() ?: '[]' ),
			]
		);
	}

	/**
	 * Initialize admin hooks
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'update_admin_menu' ] );
		add_action( 'media_buttons', [ $this, 'media_buttons' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
	}
}
