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
 * ---------------------------------------------------------------------------
 * wp_redirect audit (CRE-154, 2.6.x)
 * ---------------------------------------------------------------------------
 * The namespace-wide match was audited against every route Create registers.
 * All 76 `register_rest_route()` calls in the `mv-create/v1` namespace were
 * reviewed; **no route handler redirects**. Every callback returns a
 * `WP_REST_Response` or `WP_Error`, and there is not a single `wp_redirect()`,
 * `wp_safe_redirect()`, or raw `Location:` header anywhere under `lib/`.
 *
 * The redirect-shaped flows are all server-to-server JSON, not HTTP redirects:
 *   - `/site-connect/callback` and `/studio/site-connect/initiate` — Studio
 *     posts the JWT back to us and we answer with JSON; the browser redirect in
 *     that flow is issued by Studio, not by WordPress.
 *   - `/user-verification/*` (including `/sso-url`) — returns the URL in the
 *     response body for the client to navigate to.
 *   - `/webhooks/studio`, `/studio/feedback` — plain JSON responses.
 *
 * The plugin's only redirects live in admin page context (`admin_init` /
 * notice dismissal): `Admin_Init::maybe_redirect_default_admin_page()`,
 * `Admin_Init::maybe_repair_creation_object_id()`, `Welcome_Notice`,
 * `Admin_Notice_Helper::redirect_after_dismiss()`, and
 * `Creations_Views::handle_apply_theme()`. None of those run during a REST
 * request, so the guard cannot reach them.
 *
 * Decision: keep the guard namespace-wide. Narrowing it to specific routes
 * would only shrink coverage of the bug it was written for without protecting
 * anything. If a future route *does* need to redirect, return false from the
 * `mv_create_block_rest_redirect` filter for that case rather than
 * unregistering the guard.
 *
 * Known coverage gap: this guard only sees redirects that go through
 * `wp_redirect()`. Feedback report #34 (ovenspot.com, Create 2.5.5) caught a
 * 301 that appended a trailing slash to `/creations/{id}` with no
 * `x-redirect-by` header and with `_locale=user` already on the URL — it
 * cleared both defenses because it never touched `wp_redirect()` at all
 * (server/host-level canonicalization, or a plugin rolling its own redirect).
 * That one was harmless: `rest_api_loaded()` untrailingslashits the route, so
 * the same handler answered and the payload was intact. Redirects at that layer
 * are simply out of PHP's reach; the admin UI's response-shape checks are the
 * backstop.
 *
 * Attribution: for the redirects we *can* see, a falsy `wp_redirect` filter
 * return short-circuits before core applies `x_redirect_by`, so a blocked
 * redirect never sends the `x-redirect-by` header the editor's diagnostic
 * reports look for. When we block, we therefore name the culprit ourselves — an
 * `X-MV-Create-Redirect-Blocked` response header plus a
 * `mv_create_rest_redirect_blocked` action — which keeps the diagnostic trail
 * alive even though the defense worked.
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
		if ( ! self::is_protected_rest_request() ) {
			return $location;
		}

		$source = self::identify_redirect_source();

		/**
		 * Filters whether to cancel a redirect issued during a Create REST request.
		 *
		 * No Create route relies on redirecting (see the class docblock's audit),
		 * so this defaults to true. Return false to let a redirect through — the
		 * supported escape hatch if a future route or integration legitimately
		 * needs one.
		 *
		 * @param bool   $block    Whether to cancel the redirect.
		 * @param string $location The redirect URL a third party asked for.
		 * @param int    $status   The redirect HTTP status.
		 * @param string $source   Best-effort name of the code that requested it.
		 */
		$block = apply_filters( 'mv_create_block_rest_redirect', true, $location, $status, $source );

		if ( ! $block ) {
			return $location;
		}

		self::record_blocked_redirect( $location, $status, $source );

		return false;
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path used only for substring matching; sanitize_text_field strips %XX and breaks the encoded rest_route check below.
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

	/**
	 * Leaves a trail for whoever picks up the next interference report: a
	 * response header the admin UI's diagnostic reporter can read back, and an
	 * action for sites that want to log it.
	 *
	 * @param string $location The redirect URL that was cancelled.
	 * @param int    $status   The redirect HTTP status.
	 * @param string $source   Best-effort name of the code that requested it.
	 * @return void
	 */
	private static function record_blocked_redirect( $location, $status, $source ) {
		if ( ! headers_sent() ) {
			header( 'X-MV-Create-Redirect-Blocked: ' . self::sanitize_header_value( $source ) );
		}

		/**
		 * Fires when a third-party redirect is cancelled on a Create REST request.
		 *
		 * @param string $source   Best-effort name of the code that requested it.
		 * @param string $location The redirect URL that was cancelled.
		 * @param int    $status   The redirect HTTP status.
		 */
		do_action( 'mv_create_rest_redirect_blocked', $source, $location, $status );
	}

	/**
	 * Walks the call stack to name the plugin, theme, or mu-plugin that asked
	 * for the redirect. Only runs when we're actually cancelling one, which is
	 * rare enough that the backtrace cost doesn't matter.
	 *
	 * @return string Plugin/theme slug, `core`, or `unknown`.
	 */
	private static function identify_redirect_source() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Diagnostic attribution; only runs on a cancelled redirect.

		$roots = [
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ),
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ),
			'theme'     => wp_normalize_path( get_theme_root() ),
		];
		$ours  = wp_normalize_path( dirname( __DIR__ ) );

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );

			// Our own frames and core's plugin API say nothing useful.
			if ( 0 === strpos( $file, $ours ) || false !== strpos( $file, '/wp-includes/' ) ) {
				continue;
			}

			foreach ( $roots as $label => $root ) {
				if ( 0 === strpos( $file, trailingslashit( $root ) ) ) {
					$relative = ltrim( substr( $file, strlen( $root ) ), '/' );
					$slug     = strtok( $relative, '/' );
					return $label . ':' . ( $slug ? $slug : $relative );
				}
			}

			return 'core';
		}

		return 'unknown';
	}

	/**
	 * Keeps CR/LF and other junk out of the response header.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_header_value( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9 .:\/_-]/', '', (string) $value );
		return '' === $value ? 'unknown' : substr( $value, 0, 128 );
	}
}
