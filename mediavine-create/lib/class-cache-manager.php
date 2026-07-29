<?php

namespace Mediavine;

class Cache_Manager {

	/**
	 * Hooks into WordPress to prevent caching of Create REST API responses.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'prevent_rest_api_caching' ], 10, 3 );
	}

	/**
	 * Adds no-cache headers to Create REST API responses.
	 *
	 * Prevents caching plugins (LiteSpeed Cache, etc.) from serving stale
	 * API responses, which causes edits to appear lost in the admin UI.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_REST_Server   $server   The REST server instance.
	 * @param \WP_REST_Request  $request  The request object.
	 * @return \WP_REST_Response
	 */
	public static function prevent_rest_api_caching( $response, $server, $request ) {
		$route = $request->get_route();

		// Only prevent caching on admin editing routes under /creations/.
		// Exclude public read-only endpoints that benefit from caching.
		if (
			preg_match( '#^/mv-create/v1/creations(?:/|$)#', $route )
			&& ! preg_match( '#/(published|json_?ld|print)$#', $route )
		) {
			$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
		}

		return $response;
	}

	/**
	 * Clears single post cache on a variety of caching plugins
	 * @param  int $id  Id of the post
	 * @return void
	 */
	public static function clear_single_by_id( $id ) {
		// Force ID as integer
		$id = (int) $id;

		// Cachify
		if ( class_exists( '\Cachify' ) && method_exists( '\Cachify', 'remove_page_cache_by_post_id' ) ) {
			\Cachify::remove_page_cache_by_post_id( $id );
		}

		// W3TC
		if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
			w3tc_pgcache_flush_post( $id );
		}

		// WP Fast Cache
		if ( function_exists( 'wp_fast_cache_build_url_from_file' ) && function_exists( 'wp_fast_cache_delete_cached_url' ) ) {
			$permalink = get_permalink( $id );
			if ( ! empty( $permalink ) ) {
				$url = wp_fast_cache_build_url_from_file( $permalink );
				wp_fast_cache_delete_cached_url( $url );
			}
		}

		// WP Fastest Cache
		if ( class_exists( 'WpFastestCache' ) ) {
			$wpfc = new \WpFastestCache();
			$wpfc->singleDeleteCache( false, $id );
		}

		// Comet Cache
		if ( class_exists( '\WebSharks\CometCache\Classes\ApiBase' ) ) {
			\WebSharks\CometCache\Classes\ApiBase::clearPost( $id );
		}

		// WP Super Cache
		if ( file_exists( WP_CONTENT_DIR . '/wp-cache-config.php' ) && function_exists( 'wpsc_delete_post_cache' ) ) {
			wpsc_delete_post_cache( $id );
		}

		// WP Rocket
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $id );
		}

		// LiteSpeed Cache (modern action API; no-op when the plugin is absent).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party LiteSpeed public purge API
		do_action( 'litespeed_purge_post', $id );
	}

	/**
	 * Clears post cache on a single post or an array of posts
	 * @param  int|array $post_id_or_ids  Id or an array of the post(s) to clear cache
	 * @return void
	 */
	public static function clear_by_id( $post_id_or_ids = [] ) {
		if ( empty( $post_id_or_ids ) ) {
			return;
		}

		if ( is_array( $post_id_or_ids ) ) {
			foreach ( $post_id_or_ids as $id ) {
				self::clear_single_by_id( $id );
			}
			return;
		}
		self::clear_single_by_id( $post_id_or_ids );
	}

	/**
	 * Purge full-site page caches (and derived artifacts such as UCSS / RUCSS).
	 *
	 * Shared by upgrade-time sweeps that need a domain-wide invalidation rather
	 * than a single-post purge.
	 *
	 * @param string $litespeed_reason Optional reason string for LiteSpeed purge_all.
	 * @return void
	 */
	public static function purge_full_page_caches( $litespeed_reason = 'Mediavine Create cache purge' ) {
		// WP Rocket — clears page caches and the Used CSS table along with them.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed Cache — public purge_all() also wipes the UCSS folder.
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all( $litespeed_reason );
		}

		// Perfmatters — clears locally generated Used CSS (documented API).
		if ( class_exists( '\Perfmatters\CSS' ) && method_exists( '\Perfmatters\CSS', 'clear_used_css' ) ) {
			\Perfmatters\CSS::clear_used_css();
		}

		// FlyingPress — wipe cache folder (includes used-CSS JSON artifacts).
		// Documented at https://docs.flyingpress.com/en/articles/11406092-programmatically-purge-and-preload-cache
		if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_everything' ) ) {
			\FlyingPress\Purge::purge_everything();
		}
	}
}
