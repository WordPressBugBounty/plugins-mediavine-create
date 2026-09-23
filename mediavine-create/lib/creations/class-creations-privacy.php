<?php
namespace Mediavine\Create;

/**
 * Keeps Create Cards (the `mv_create` post type) private.
 *
 * Every card is stored as a published `mv_create` post. The post type is registered
 * as private, but themes and plugins can still surface cards: a `register_post_type_args`
 * filter can flip the flags, and a query built from `get_post_types()` (the common
 * "add my post types to the RSS feed" snippet) asks for private types explicitly.
 * Publishers then see duplicate posts with broken permalinks in their feed or listings.
 *
 * This class re-applies the private flags after everyone else has had a turn, removes
 * cards from front-end queries that mix them with other post types, and limits the
 * `wp/v2/mv_create` REST route to users who can manage Create.
 */
class Creations_Privacy {

	/**
	 * @var Creations_Privacy|null
	 */
	public static $instance = null;

	/**
	 * Registration args that must hold for `mv_create`, whatever other code filters in.
	 *
	 * @var array
	 */
	public static $private_args = [
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_in_nav_menus'   => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
	];

	/**
	 * @return Creations_Privacy
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * @return void
	 */
	public function init() {
		add_filter( 'register_post_type_args', [ $this, 'enforce_private_args' ], PHP_INT_MAX, 2 );
		add_action( 'wp_loaded', [ $this, 'enforce_private_object' ] );
		add_action( 'pre_get_posts', [ $this, 'remove_from_mixed_queries' ], PHP_INT_MAX );
	}

	/**
	 * Re-applies the private flags after every other `register_post_type_args` filter.
	 *
	 * @param array  $args      Post type registration args.
	 * @param string $post_type Post type being registered.
	 * @return array
	 */
	public function enforce_private_args( $args, $post_type ) {
		if ( Creations_WP_Content::$slug !== $post_type ) {
			return $args;
		}
		return array_merge( (array) $args, self::$private_args );
	}

	/**
	 * Catches code that edits the registered post type object directly (after the
	 * registration filter has run) instead of going through `register_post_type_args`.
	 *
	 * @return void
	 */
	public function enforce_private_object() {
		$post_type = get_post_type_object( Creations_WP_Content::$slug );
		if ( ! $post_type ) {
			return;
		}
		foreach ( self::$private_args as $key => $value ) {
			$post_type->$key = $value;
		}
	}

	/**
	 * Removes `mv_create` from front-end queries that ask for it alongside other post types.
	 *
	 * A query for cards alone is deliberate and left untouched. A query for cards plus other
	 * types comes from code listing every registered post type, which is how cards end up
	 * in feeds, blog listings and "recent posts" widgets.
	 *
	 * @param \WP_Query $query The query about to run.
	 * @return void
	 */
	public function remove_from_mixed_queries( $query ) {
		// Admin screens may list every post type on purpose. Admin-ajax still counts as
		// front end, since themes use it for "load more" and infinite scroll.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$post_types = $query->get( 'post_type' );
		if ( ! is_array( $post_types ) || count( $post_types ) < 2 || ! in_array( Creations_WP_Content::$slug, $post_types, true ) ) {
			return;
		}

		/**
		 * Filters whether Create Cards are removed from a front-end query that mixes them
		 * with other post types.
		 *
		 * @param bool      $remove Whether to remove `mv_create` from the query. Default true.
		 * @param \WP_Query $query  The query about to run.
		 */
		if ( ! apply_filters( 'mv_create_remove_cards_from_mixed_queries', true, $query ) ) {
			return;
		}

		// Every query on purpose, not just the main one: widgets and page builders run their own.
		$query->set( 'post_type', array_values( array_diff( $post_types, [ Creations_WP_Content::$slug ] ) ) ); // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts
	}
}
