<?php
namespace Mediavine\Create;

class Creations_WP_Content extends Creations {
	static $slug = 'mv_create';

	public static function register_content_types() {
		$permission_level = \Mediavine\Permissions::access_level();
		$post_type_name   = __( 'Create Card', 'mediavine' );
		$post_type_plural = __( 'Create Cards', 'mediavine' );

		$post_type_labels = [
			'name'                  => '%2$s',
			'singular_name'         => '%1$s',
			/* translators: %1$s: post type name */
			'add_new'               => __( 'Add New %1$s', 'mediavine' ),
			/* translators: %1$s: post type name */
			'add_new_item'          => __( 'Add New %1$s', 'mediavine' ),
			/* translators: %1$s: post type name */
			'edit_item'             => __( 'Edit %1$s', 'mediavine' ),
			/* translators: %1$s: post type name */
			'new_item'              => __( 'Add New %1$s', 'mediavine' ),
			/* translators: %1$s: post type name */
			'view_item'             => __( 'View %1$s', 'mediavine' ),
			/* translators: %2$s: post type name */
			'view_items'            => __( 'View %2$s', 'mediavine' ),
			/* translators: %2$s: post type name */
			'search_items'          => __( 'Search %2$s', 'mediavine' ),
			/* translators: %2$s: post type name */
			'not_found'             => __( 'No %2$s found', 'mediavine' ),
			/* translators: %2$s: post type name */
			'not_found_in_trash'    => __( 'No %2$s found in trash', 'mediavine' ),
			/* translators: %2$s: post type name */
			'parent_item_colon'     => __( 'Parent %2$s:', 'mediavine' ),
			/* translators: %2$s: post type name */
			'all_items'             => __( 'All %2$s', 'mediavine' ),
			/* translators: %1$s: post type name */
			'archives'              => __( '%1$s Archives', 'mediavine' ),
			/* translators: %1$s: post type name */
			'attributes'            => __( '%1$s Attributes', 'mediavine' ),
			/* translators: %1$s: post type name */
			'insert_into_item'      => __( 'Insert into %1$s', 'mediavine' ),
			/* translators: %1$s: post type name */
			'uploaded_to_this_item' => __( 'Uploaded to this %1$s', 'mediavine' ),
			/* translators: %2$s: post type name */
			'filter_items_list'     => __( 'Filter %2$s list', 'mediavine' ),
			/* translators: %2$s: post type name */
			'items_list_navigation' => __( '%2$s list navigation', 'mediavine' ),
			/* translators: %2$s: post type name */
			'items_list'            => __( '%2$s list', 'mediavine' ),
		];

		foreach ( $post_type_labels as $key => $value ) {
			$post_type_labels[ $key ] = sprintf( $value, $post_type_name, $post_type_plural );
		}

		$post_type_args = [
			'labels'              => $post_type_labels,
			'public'              => false,
			'hierarchical'        => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_rest'        => true,
			'menu_position'       => 5,
			'menu_icon'           => 'data:image/svg+xml;base64,' . base64_encode( '<svg viewBox="0 0 25 24" xmlns="http://www.w3.org/2000/svg"><path fill="white" d="M8.084 11.645l.265.336-.262.339c-.07.09-1.731 2.206-3.9 2.215-2.17.01-3.851-2.091-3.922-2.18L0 12.017l.262-.338c.07-.09 1.73-2.206 3.9-2.215 2.169-.01 3.851 2.09 3.922 2.18zm16.65 0l.266.336-.262.339c-.07.09-1.73 2.206-3.9 2.215-2.17.01-3.851-2.091-3.922-2.18l-.265-.337.262-.338c.07-.09 1.731-2.206 3.901-2.215 2.168-.01 3.85 2.09 3.92 2.18zM12.835.251c.093.067 2.297 1.662 2.306 3.745.01 2.082-2.177 3.697-2.27 3.764l-.35.255-.353-.251c-.094-.068-2.298-1.662-2.308-3.745C9.85 1.936 12.038.322 12.131.254L12.48 0l.353.251zm0 15.985c.093.067 2.297 1.662 2.306 3.745.01 2.082-2.177 3.697-2.27 3.764l-.35.255-.353-.251c-.094-.067-2.298-1.662-2.308-3.745-.01-2.083 2.179-3.697 2.272-3.764l.35-.255.353.251zm-3.219-1.014c.017.11.401 2.735-1.127 4.214-1.527 1.48-4.263 1.135-4.378 1.12l-.436-.058-.064-.417c-.017-.11-.4-2.735 1.128-4.214 1.526-1.479 4.262-1.135 4.378-1.12l.435.058.064.417zm7.729-9.866c-.804.777-.866 2.12-.837 2.814.723.02 2.12-.05 2.924-.83.756-.73.872-2.03.84-2.813-.723-.021-2.123.049-2.927.829zm-1.895 3.839l-.064-.417c-.018-.111-.401-2.735 1.127-4.214 1.526-1.48 4.262-1.136 4.378-1.12l.435.058.064.417c.017.11.401 2.735-1.126 4.214-1.527 1.48-4.263 1.135-4.38 1.12l-.434-.058zM8.472 4.548c1.54 1.465 1.182 4.092 1.166 4.203l-.06.418-.434.062c-.116.016-2.85.385-4.39-1.082-1.54-1.466-1.182-4.093-1.166-4.204l.06-.417.434-.062c.116-.017 2.85-.385 4.39 1.082zm11.774 11.303c1.54 1.465 1.182 4.092 1.167 4.204l-.06.417-.435.062c-.116.016-2.849.385-4.39-1.082-1.54-1.466-1.182-4.093-1.166-4.204l.06-.417.434-.062c.116-.016 2.85-.385 4.39 1.082z"></path></svg>' ),
			'capability_type'     => 'post',
			'supports'            => [ 'title', 'author' ],
			'taxonomies'          => [],
			'has_archive'         => false,
			'can_export'          => true,
			'query_var'           => false,
			'delete_with_user'    => false,
			'rewrite'             => false,
			'capabilities'        => [
				'publish_posts'       => $permission_level,
				'edit_others_posts'   => $permission_level,
				'delete_posts'        => $permission_level,
				'delete_others_posts' => $permission_level,
				'read_private_posts'  => $permission_level,
				'edit_post'           => $permission_level,
				'delete_post'         => $permission_level,
				'read_post'           => $permission_level,
			],
		];

		register_post_type( self::$slug, $post_type_args );
	}

	public static function register_taxonomies() {
		foreach ( self::$term_map as $term ) {
			$taxonomy_name   = $term;
			$taxonomy_plural = $term;

			$taxonomy_labels = [
				'name'                       => '%2$s',
				'singular_name'              => '%1$s',
				/* translators: %2$s: post type name */
				'search_items'               => __( 'Search %2$s', 'mediavine' ),
				/* translators: %2$s: post type name */
				'popular_items'              => __( 'Popular %2$s', 'mediavine' ),
				/* translators: %2$s: post type name */
				'all_items'                  => __( 'All %2$s', 'mediavine' ),
				/* translators: %2$s: post type name */
				'parent_item'                => __( 'Parent %2$s', 'mediavine' ),
				/* translators: %2$s: post type name */
				'parent_item_colon'          => __( 'Parent %2$s:', 'mediavine' ),
				/* translators: %1$s: post type name */
				'edit_item'                  => __( 'Edit %1$s', 'mediavine' ),
				/* translators: %1$s: post type name */
				'view_item'                  => __( 'View %1$s', 'mediavine' ),
				/* translators: %1$s: post type name */
				'update_item'                => __( 'Update %1$s', 'mediavine' ),
				/* translators: %1$s: post type name */
				'add_new_item'               => __( 'Add New %1$s', 'mediavine' ),
				/* translators: %1$s: post type name */
				'new_item_name'              => __( 'New %1$s Name', 'mediavine' ),
				/* translators: %2$s: post type name */
				'separate_items_with_commas' => __( 'Separate %2$s with commas', 'mediavine' ),
				/* translators: %2$s: post type name */
				'add_or_remove_items'        => __( 'Add or remove %2$s', 'mediavine' ),
				/* translators: %2$s: post type name */
				'choose_from_most_used'      => __( 'Choose from the most used %2$s', 'mediavine' ),
				/* translators: %2$s: post type name */
				'not_found'                  => __( 'No %2$s found', 'mediavine' ),
				/* translators: %2$s: post type name */
				'no_terms'                   => __( 'No %2$s', 'mediavine' ),
			];

			foreach ( $taxonomy_labels as $key => $value ) {
				$taxonomy_labels[ $key ] = sprintf( $value, $taxonomy_name, $taxonomy_plural );
			}

			$taxonomy_args = [
				'labels'             => $taxonomy_labels,
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => false,
				'show_ui'            => false,
				'show_in_rest'       => true,
				'rest_base'          => 'mv-' . $term,
				'show_admin_column'  => true,
				'rewrite'            => false,
				'query_var'          => true,
			];

			register_taxonomy( 'mv_' . $term, self::$slug, $taxonomy_args );
		}
	}
}
