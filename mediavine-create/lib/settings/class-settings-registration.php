<?php

namespace Mediavine\Create;

use Mediavine\Create\Settings\Advanced;
use Mediavine\Create\Settings\Affiliates;
use Mediavine\Create\Settings\Control_Panel;
use Mediavine\Create\Settings\Dev;
use Mediavine\Create\Settings\Appearance;
use Mediavine\Create\Settings\List_Ads;
use Mediavine\Create\Settings\Lists;
use Mediavine\Create\Settings\Reader_Experience;
use Mediavine\Create\Settings\Recipes;

/**
 * Code-declared settings registrations and the admin-payload helpers built on
 * them. get_settings() is the single source of truth for every setting's slug,
 * group, and `data`; the DB only owns values.
 */
class Settings_Registration {

	/**
	 * Handle registration of all settings classes and pass complete array
	 * @return array
	 */
	public static function get_settings() {
		// Settings classes divided by groups
		$settings = [
			[
				'slug'  => Plugin::$settings_group . '_secondary_color',
				'value' => null,
				'group' => 'mv_create_hidden',
				'order' => '0',
				'data'  => [],
			],
			[
				'slug'  => Plugin::$settings_group . '_api_token',
				'value' => null,
				'group' => Plugin::$settings_group . '_api',
				'order' => 105,
				'data'  => [
					'type'         => 'api_authentication',
					'label'        => __( 'Product Registration', 'mediavine-create' ),
					'instructions' => __( 'In order to use services like nutrition calculation or link scraping, you must register an account. This is a free, one-time action that will grant access to all of our external APIs.', 'mediavine-create' ),
				],
			],
			[
				'slug'  => Plugin::$settings_group . '_api_user_id',
				'value' => false,
				'group' => 'hidden',
				'order' => 105,
				'data'  => [],
			],
		];

		$settings = array_merge(
			Advanced::settings(),
			Dev::settings(),
			Appearance::settings(),
			Recipes::settings(),
			Lists::settings(),
			List_Ads::settings(),
			Reader_Experience::settings(),
			Affiliates::settings(),
			Control_Panel::settings(),
			$settings
		);

		return apply_filters( 'mv_create_init_settings', $settings );
	}

	/**
	 * Presentation metadata for every registered setting, keyed by slug.
	 *
	 * The `data` blob stored in mv_settings is only rewritten when the plugin
	 * version bumps, so between releases (and on any site whose upgrade routine
	 * hasn't run) the stored copy can lag the PHP declarations. Everything the
	 * admin derives from `data` — subgroup, gating, labels, options — must come
	 * from the code, so callers shipping settings to the UI overlay this map on
	 * top of the DB rows.
	 *
	 * @return array<string, array> Slug => registered `data` array.
	 */
	public static function get_registered_setting_data() {
		static $map = null;

		if ( null === $map ) {
			$map = [];
			foreach ( self::get_settings() as $setting ) {
				if ( empty( $setting['slug'] ) || ! isset( $setting['data'] ) ) {
					continue;
				}
				$map[ $setting['slug'] ] = $setting['data'];
			}
		}

		return $map;
	}

	/**
	 * Overlay the registered `data` metadata onto settings rows from the DB.
	 *
	 * Values, groups, and ordering stay as stored (publishers own those); only
	 * the presentation/gating metadata is refreshed. Rows with no registered
	 * counterpart (settings added by a filter, or removed from the code) keep
	 * whatever the DB holds.
	 *
	 * @param array $settings Settings rows (objects or arrays) with extracted `data`.
	 * @return array The same rows with refreshed `data`.
	 */
	public static function apply_registered_setting_data( $settings ) {
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return $settings;
		}

		$registered = self::get_registered_setting_data();

		foreach ( $settings as $index => $setting ) {
			$is_object = is_object( $setting );
			$slug      = $is_object
				? ( isset( $setting->slug ) ? $setting->slug : null )
				: ( isset( $setting['slug'] ) ? $setting['slug'] : null );

			// Empty registered metadata means an intentionally bare setting
			// (hidden flags); don't clobber whatever the DB has for those.
			if ( ! $slug || empty( $registered[ $slug ] ) ) {
				continue;
			}

			if ( $is_object ) {
				$settings[ $index ]->data = $registered[ $slug ];
			} else {
				$settings[ $index ]['data'] = $registered[ $slug ];
			}
		}

		return $settings;
	}

	/**
	 * Return image size value and label
	 *
	 * @return array
	 */
	public static function get_image_size_values() {
		$image_sizes                   = Creations::get_image_sizes();
		$image_sizes_values_and_labels = [];

		// @TODO add a filter for adding size names to exclude from disable list?
		$exclude = [
			'mv_create_no_ratio',
			'mv_create_vert',
		];

		foreach ( $image_sizes as $size => $size_data ) {
			if ( strpos( $size, '_high_res' ) || in_array( $size, $exclude, true ) ) {
				continue;
			}

			$image_sizes_values_and_labels[ $size ] = $size_data['name'];
		}

		return $image_sizes_values_and_labels;
	}

	/**
	 * Modify setting array to include custom post types
	 *
	 * @param array $settings array of current settings to be modified with addition of custom post types
	 *
	 * @return array
	 */
	public static function set_custom_post_type_option_value( $settings ) {
		$allowed_post_types = self::get_custom_post_types();

		$cpt_field = array_search( Plugin::$settings_group . '_allowed_cpt_types', wp_list_pluck( $settings, 'slug' ), true );

		if ( empty( $allowed_post_types ) ) {
			$settings[ $cpt_field ]->value = []; // reset value
			return $settings;
		}

		if ( ! empty( $settings[ $cpt_field ] ) ) {
			// two things need to happen:
			// 1. CPTs that no longer exist need to be removed as a saved value
			// 2. New CPTs need to be added to options but NOT to saved values — this should already be handled by self::get_custom_post_types()

			$values        = array_keys( $allowed_post_types );
			$stored_values = json_decode($settings[ $cpt_field ]->value ?: '{}');

			if ( ! is_array( $stored_values ) ) {
				return $settings;
			}

			$keys_to_remove = array_diff( $stored_values, $values ); // CPTs that were removed

			// remove invalid CPTs from saved values
			$new_values = array_filter(
				$stored_values, function ( $i ) use ( $keys_to_remove ) {
				return ! in_array( $i, $keys_to_remove, true );
				}
			);

			$settings[ $cpt_field ]->data['options'] = $allowed_post_types;
			$settings[ $cpt_field ]->value           = json_encode( array_values( $new_values ) );
		}

		return $settings;
	}

	/**
	 * Retrieve an array of custom post-types that are public and not built-in
	 *
	 * @return array
	 */
	private static function get_custom_post_types() {
		/**
		 * @var \WP_Post_Type[]
		 */
		$post_types = get_post_types(
			[
				'public'   => true,
				'_builtin' => false,
			], 'objects'
		);

		$allowed_post_types = [];
		foreach ( $post_types as $post_type ) {
			$post_type_label = $post_type->label;
			if ( ! empty( $post_type->labels->singular_name ) ) {
				$post_type_label = $post_type->labels->singular_name;
			}

			$allowed_post_types[ $post_type->name ] = $post_type_label;
		}

		return $allowed_post_types;
	}
}
