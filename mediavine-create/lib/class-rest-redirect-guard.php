<?php

namespace Mediavine\Create;

/**
 * Prevents third-party plugins (Yoast SEO Premium, Redirection, 301 Redirects,
 * etc.) from issuing wp_redirect() responses for Create's REST API endpoints.
 *
 * Background: feedback reports #20/#23/#24 from a customer running Yoast SEO
 * Premium showed her editor crashing on every existing recipe card. Yoast's
 * URL-pattern matcher was 301-redirecting `/wp-json/mv-create/v1/creations/{id}`
 * to `/wp-json/mv-create/v1/creations/`, which sends the editor a collection
 * wrapper instead of a single card and silently empties the form.
 *
 * The JS layer now defends against the bad shape and bypasses redirect plugins
 * by appending `_locale=user` to every REST URL. This class is the
 * defense-in-depth layer for the rare case where a redirect plugin matches
 * even our query-bearing URLs, or for direct cURL/wget consumers of the API.
 *
 * @package Mediavine\Create
 */
class REST_Redirect_Guard {

	/**
	 * REST namespace this guard protects. Match is namespace-anchored so a
	 * future namespace bump still requires explicit opt-in.
	 *
	 * @var string
	 */
	const PROTECTED_NAMESPACE = 'mv-create/';

	/**
	 * Register the wp_redirect filter at priority 1 so we run before
	 * Yoast/Redirection/etc. (which typically register at the default 10).
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_redirect', [ __CLASS__, 'block_redirect_for_rest' ], 1, 2 );
	}

	/**
	 * Returns false (cancelling the redirect) when the current request is for
	 * one of our REST endpoints. Returning a falsy value from `wp_redirect`
	 * filter is the documented way to suppress the Location: header.
	 *
	 * @param string $location The redirect URL.
	 * @param int    $status   The redirect HTTP status.
	 * @return string|false
	 */
	public static function block_redirect_for_rest( $location, $status = 302 ) {
		if ( self::is_protected_rest_request() ) {
			return false;
		}
		return $location;
	}

	/**
	 * Detects whether the current incoming request targets one of our REST
	 * endpoints. Checks both pretty-permalink (`/wp-json/mv-create/...`) and
	 * plain-permalink (`?rest_route=/mv-create/...`) forms.
	 *
	 * Public so it can be reused by other guards or unit-tested directly.
	 *
	 * @return bool
	 */
	public static function is_protected_rest_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );

		// Pretty-permalink form: /wp-json/<namespace>/...
		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		if ( false !== strpos( $uri, $rest_prefix . self::PROTECTED_NAMESPACE ) ) {
			return true;
		}

		// Plain-permalink form: ?rest_route=/<namespace>/...
		if ( false !== strpos( $uri, 'rest_route=/' . self::PROTECTED_NAMESPACE ) ) {
			return true;
		}
		// URL-encoded variant.
		if ( false !== strpos( $uri, 'rest_route=%2F' . str_replace( '/', '%2F', self::PROTECTED_NAMESPACE ) ) ) {
			return true;
		}

		return false;
	}
}
