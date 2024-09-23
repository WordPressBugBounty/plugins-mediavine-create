<?php

/**
 *  Load i18n.
 */
function mv_create_load_plugin_textdomain() {
	load_plugin_textdomain( 'mediavine', false, mv_create_plugin_basename_dir( 'languages' ) );
}

/**
 * Determine whether or not a table exists.
 *
 * @param string $table_name
 * @param string $prefix
 * @return bool
 */
function mv_create_table_exists( $table_name, $prefix = '' ) {
	global $wpdb;
	$table_name = ( $prefix ? $prefix : $wpdb->prefix ) . $table_name;
	$table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name );
	$statement  = "SHOW TABLES LIKE '%{$table_name}%'";

	// SECURITY CHECKED: Nothing in this query can be sanitized.
	return ! empty( $wpdb->get_results( $statement ) );
}

/**
 * Manually log an error in Sentry.
 *
 * @param string $message the message we want to log (can be formatted with `print_f` style placeholders)
 * @param array $message_params if `$message` is formatted, this is an array of values to replace format markers
 * @param array $data an array of contextual data -- must be associative, not numeric
 * @param string $level the log level (debug, info, warning, error, fatal)
 *
 * Example: mv_create_log( 'this is a %', ['serious problem'], ['someVar' => 'had an issue'], $level = 'error');
 * This will produce a sentry report with the message "this is a serious problem" and a full stack trace.
 *
 * @return string uuid of Sentry event
 */
function mv_create_log( $message, $message_params = [], $data = [], $level = 'info' ) {
	return '';
}

/**
 * Manually log an exception in Sentry.
 *
 * @param \Exception $e
 * @param array $data contextual data -- must be associative, not numeric
 * @return string uuid of Sentry event
 */
function mv_create_log_exception( \Exception $e, $data = [] ) {
	// ensure this function can be used anywhere in the plugin
	if ( ! class_exists( 'Mediavine\Create\MV_Sentry' ) ) {
		require_once MV_CREATE_DIR . 'vendor/autoload.php';
	}

	return \Mediavine\Create\MV_Sentry::log_exception( $e, $data );
}

/**
 * Gets a list of reviews by creation_id
 * @param integer $creation_id ID of Creation from which you want reviews.
 * @param array $args limit and offset to get max number or paginate (default 50, 0)
 * @return array Returns an array of objects
 */
function mv_create_get_reviews( $creation_id, $args = [] ) {
	return \Mediavine\Create\Reviews::get_reviews( $creation_id, $args );
}

/**
 * Gets a list of Creation IDs associated with a Post ID.
 *
 * Can be filtered by card type.
 *
 * @param integer $post_id ID of WP Post from which you want a list of Associated Creations.
 * @param array $filter_types Array of card types to filter by. And empty array will display all card types.
 * @return array Returns an array of objects
 */
function mv_get_post_creations( $post_id, $filter_types = [] ) {
	return \Mediavine\Create\Creations::get_creation_ids_by_post( $post_id, $filter_types );
}

/**
 * Gets a single creation by ID
 * @param  {number}  $id        Creation ID
 * @param  {boolean} $published Return published data
 * @return {object}             Card data
 */
function mv_create_get_creation( $id, $published = false ) {
	$creations_dbi = new \Mediavine\MV_DBI( 'mv_creations' );
	$creation      = $creations_dbi->find_one_by_id( $id );
	if ( $published ) {
		$published_content = '[]';
		if ( is_array( $creation ) && isset( $creation['published'] ) ) {
			$published_content = $creation['published'];
		}
		if ( is_object( $creation ) && isset( $creation->published ) ) {
			$published_content = $creation->published;
		}
		return json_decode( $published_content );
	}
	return $creation;
}

/**
 * Get a custom field registered to a creation
 *
 * @since 1.1.0
 * @param {string} $slug   Custom field slug
 * @param {number} $id     Creation ID
 * @param {mixed}          Value of field
 */
function mv_create_get_field( $id, $slug ) {
	$creation      = mv_create_get_creation( $id );
	$custom_fields = $creation->custom_fields;
	$parsed_data   = json_decode( $custom_fields );
	if ( empty( $parsed_data ) || empty( $parsed_data[ $slug ] ) ) {
		return null;
	}
	return $parsed_data[ $slug ];
}

/**
 * Declares that a theme supports integration with a particular version of Create skins.
 * (For now, if a theme integrates, just pass 'v1')
 *
 * If this is _not_ called in the theme's functions.php file, custom skins will _not_ override defaults.
 *
 * @since 1.1.0
 * @param  {string} $version  Compatible version
 * @return {void}
 */
function mv_create_theme_support( $version ) {
	add_filter(
		'mv_create_style_version',
		function() use ( $version ) {
			return $version;
		}
	);
}


/**
 * Display a JTR button
 *
 * @param integer $id Creation ID. Optional
 * @param string $type Creation Type. Optional
 */
function mv_create_jtr_button( $id = null, $type = null ) {
	global $post;

	if ( empty( $id ) || empty( $type ) ) {
		$atts = \Mediavine\Create\Creations_Jump_To_Recipe::get_jtr_atts( get_post_field( 'post_content', $post ) );
		$id   = $atts['id'];
		$type = $atts['type'];
	}

	echo do_shortcode( vsprintf( '[mv_create_jtr id="%d" type="%s"]', [ $id, $type ] ) );
}

/**
 * Register a custom field.
 *
 * A helper function to quickly register a custom field to the Custom Fields section of Create Cards.
 *
 * @since 1.1.0
 * @param array $field Refer to CustomFields.md for acceptable params
 * @return void
 */
function mv_create_register_custom_field( $field ) {
	add_filter(
		'mv_create_fields', function( $arr ) use ( $field ) {
			$arr[] = $field;
			return $arr;
		}
	);
}
