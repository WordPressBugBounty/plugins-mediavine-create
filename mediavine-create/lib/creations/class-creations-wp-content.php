<?php
namespace Mediavine\Create;

class Creations_WP_Content extends Creations {
	static $slug = 'mv_create';

	public static function register_content_types() {
		$permission_level = \Mediavine\Permissions::access_level();
		$post_type_name   = 'Create Card';
		$post_type_plural = 'Create Cards';

		$post_type_labels = [
			'menu_name'             => 'Create',
			'name'                  => $post_type_plural,
			'singular_name'         => $post_type_name,
			'add_new'               => 'Add New ' . $post_type_name,
			'add_new_item'          => 'Add New ' . $post_type_name,
			'edit_item'             => 'Edit ' . $post_type_name,
			'new_item'              => 'Add New ' . $post_type_name,
			'view_item'             => 'View ' . $post_type_name,
			'view_items'            => 'View ' . $post_type_plural,
			'search_items'          => 'Search ' . $post_type_plural,
			'not_found'             => 'No ' . $post_type_plural . ' found',
			'not_found_in_trash'    => 'No ' . $post_type_plural . ' found in trash',
			'parent_item_colon'     => 'Parent ' . $post_type_plural . ':',
			'all_items'             => 'All ' . $post_type_plural,
			'archives'              => $post_type_name . ' Archives',
			'attributes'            => $post_type_name . ' Attributes',
			'insert_into_item'      => 'Insert into ' . $post_type_name,
			'uploaded_to_this_item' => 'Uploaded to this ' . $post_type_name,
			'filter_items_list'     => 'Filter ' . $post_type_plural . ' list',
			'items_list_navigation' => $post_type_plural . ' list navigation',
			'items_list'            => $post_type_plural . ' list',
		];

		$post_type_args = [
			'labels'              => $post_type_labels,
			'public'              => false,
			'hierarchical'        => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_rest'        => true,
			'menu_position'       => 5,
			'menu_icon'           => 'data:image/svg+xml;base64,' . base64_encode( '<svg viewBox="0 0 25 24" xmlns="http://www.w3.org/2000/svg"><path d="M8.084 11.645L8.349 11.981L8.087 12.32C8.017 12.41 6.356 14.526 4.187 14.535C2.017 14.545 0.336 12.444 0.265 12.355L0 12.017L0.262 11.679C0.332 11.589 1.992 9.473 4.162 9.464C6.331 9.454 8.013 11.554 8.084 11.644V11.645ZM24.734 11.645L25 11.981L24.738 12.32C24.668 12.41 23.008 14.526 20.838 14.535C18.668 14.545 16.987 12.444 16.916 12.355L16.651 12.018L16.913 11.68C16.983 11.59 18.644 9.474 20.814 9.465C22.982 9.455 24.664 11.555 24.734 11.645ZM12.835 0.251C12.928 0.318 15.132 1.913 15.141 3.996C15.151 6.078 12.964 7.693 12.871 7.76L12.521 8.015L12.168 7.764C12.074 7.696 9.87 6.102 9.86 4.019C9.85 1.936 12.038 0.322 12.131 0.254L12.48 0L12.833 0.251H12.835ZM12.835 16.236C12.928 16.303 15.132 17.898 15.141 19.981C15.151 22.063 12.964 23.678 12.871 23.745L12.521 24L12.168 23.749C12.074 23.682 9.87 22.087 9.86 20.004C9.85 17.921 12.039 16.307 12.132 16.24L12.482 15.985L12.835 16.236ZM9.616 15.222C9.633 15.332 10.017 17.957 8.489 19.436C6.962 20.916 4.226 20.571 4.111 20.556L3.675 20.498L3.611 20.081C3.594 19.971 3.211 17.346 4.739 15.867C6.265 14.388 9.001 14.732 9.117 14.747L9.552 14.805L9.616 15.222ZM15.45 9.195L15.386 8.778C15.368 8.667 14.985 6.043 16.513 4.564C18.039 3.084 20.775 3.428 20.891 3.444L21.326 3.502L21.39 3.919C21.407 4.029 21.791 6.654 20.264 8.133C18.737 9.613 16.001 9.268 15.884 9.253L15.45 9.195ZM8.472 4.548C10.012 6.013 9.654 8.64 9.638 8.751L9.578 9.169L9.144 9.231C9.028 9.247 6.294 9.616 4.754 8.149C3.214 6.683 3.572 4.056 3.588 3.945L3.648 3.528L4.082 3.466C4.198 3.449 6.932 3.081 8.472 4.548ZM20.246 15.851C21.786 17.316 21.428 19.943 21.413 20.055L21.353 20.472L20.918 20.534C20.802 20.55 18.069 20.919 16.528 19.452C14.988 17.986 15.346 15.359 15.362 15.248L15.422 14.831L15.856 14.769C15.972 14.753 18.706 14.384 20.246 15.851Z" fill="white"/></svg>' ),
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
				'name'                       => $taxonomy_plural,
				'singular_name'              => $taxonomy_name,
				'search_items'               => 'Search ' . $taxonomy_plural,
				'popular_items'              => 'Popular ' . $taxonomy_plural,
				'all_items'                  => 'All ' . $taxonomy_plural,
				'parent_item'                => 'Parent ' . $taxonomy_plural,
				'parent_item_colon'          => 'Parent ' . $taxonomy_plural . ':',
				'edit_item'                  => 'Edit ' . $taxonomy_name,
				'view_item'                  => 'View ' . $taxonomy_name,
				'update_item'                => 'Update ' . $taxonomy_name,
				'add_new_item'               => 'Add New ' . $taxonomy_name,
				'new_item_name'              => 'New ' . $taxonomy_name . ' Name',
				'separate_items_with_commas' => 'Separate ' . $taxonomy_plural . ' with commas',
				'add_or_remove_items'        => 'Add or remove ' . $taxonomy_plural,
				'choose_from_most_used'      => 'Choose from the most used ' . $taxonomy_plural,
				'not_found'                  => 'No ' . $taxonomy_plural . ' found',
				'no_terms'                   => 'No ' . $taxonomy_plural,
			];

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
