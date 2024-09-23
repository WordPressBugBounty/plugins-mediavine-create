<?php
namespace Mediavine\Create;

use Mediavine\Settings;

class Creations_Meta_Blocks extends Creations {

	public static $instance;

	/**
	 * Makes sure class is only instantiated once and runs init
	 *
	 * @return object Instantiated class
	 */
	static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Retrieves list of the slugs of hooked meta blocks
	 *
	 * @return array List of meta block slugs
	 */
	public static function get_meta_block_slugs() {
		$namespace = 'mv-create';

		$meta_blocks = apply_filters( $namespace . '_meta_fields', [] );
		$meta_blocks = wp_list_pluck( $meta_blocks, 'slug' );

		return $meta_blocks;
	}

	/**
	 * Hooks to be run on class instantiation
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'mv-create_meta_fields', [ $this, 'add_post_meta_fields' ] );
	}

	/**
	 * Adds post meta fields to Gutenberg and TinyMCE editors
	 *
	 * @param array $blocks Current list of blocks
	 * @return array Updated list of blocks
	 */
	public function add_post_meta_fields( $blocks ) {
		if ( ! empty( Settings::get_setting( self::$settings_group . '_enable_jump_to_recipe', false ) ) ) {
			$blocks[] = [
				'slug'  => 'disable-jtr',
				'type'  => 'boolean',
				'title' => __( 'Disable Jump-to-Recipe', 'mediavine' ),
			];
		}

		return $blocks;
	}
}
