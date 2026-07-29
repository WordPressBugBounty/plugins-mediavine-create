<?php
namespace Mediavine;

/**
 * Create REST permission levels and authorization helpers.
 *
 * All `mv-create/v1` route `permission_callback`s should use one of the named
 * levels below (`viewer`, `editor`, `admin`) or `allow_public` for endpoints
 * listed in {@see Permissions::PUBLIC_ENDPOINTS}. Custom auth callbacks that
 * cannot be expressed as a single capability (e.g. review handshake) must be
 * listed in {@see Permissions::CUSTOM_AUTH_CALLBACKS}.
 *
 * Failure shape: every named level returns `true` or a `WP_Error` with a
 * `status` (via `rest_authorization_required_code()`).
 *
 * @package Mediavine
 * @see docs/rest-permission-levels.md
 */
class Permissions {

	/**
	 * Named level: WordPress `edit_posts`.
	 *
	 * @var string
	 */
	const LEVEL_VIEWER = 'viewer';

	/**
	 * Named level: settings-overridable Create access (default `publish_posts`).
	 *
	 * @var string
	 */
	const LEVEL_EDITOR = 'editor';

	/**
	 * Named level: WordPress `manage_options`.
	 *
	 * @var string
	 */
	const LEVEL_ADMIN = 'admin';

	/**
	 * Named level: intentionally public / token-authenticated in the handler.
	 *
	 * @var string
	 */
	const LEVEL_PUBLIC = 'public';

	/**
	 * Documented public or token/signature-authenticated `mv-create/v1` endpoints.
	 *
	 * Keys are full route paths as registered with `register_rest_route` (same
	 * keys returned by `rest_get_server()->get_routes()`). Values are short
	 * rationales. Auth for these routes is enforced in the callback (token,
	 * webhook signature, handshake, etc.) or the data is intentionally public.
	 *
	 * @var array<string, string>
	 */
	const PUBLIC_ENDPOINTS = [
		'/mv-create/v1/verify-site-code'                 => 'Token-authenticated site verification from Create Studio',
		'/mv-create/v1/site-connect/callback'            => 'Token-authenticated site connect callback from Create Studio',
		'/mv-create/v1/webhooks/studio'                  => 'Signature-authenticated Create Studio webhook',
		'/mv-create/v1/render'                           => 'Public card render for embeds/previews',
		'/mv-create/v1/creations/(?P<id>\\d+)'           => 'Public GET of a published creation (mutations use editor)',
		'/mv-create/v1/creations/(?P<id>\\d+)/published' => 'Public published creation payload',
		'/mv-create/v1/creations/(?P<id>\\d+)/json_ld'   => 'Public JSON-LD for a published creation',
		'/mv-create/v1/creations/(?P<id>\\d+)/print'     => 'Public print view of a published creation',
		'/mv-create/v1/creations/(?P<id>\\d+)/jsonld'    => 'Public HTML JSON-LD for crawlers',
		'/mv-create/v1/creations/(?P<id>\\d+)/featured-review' => 'Public featured review for a published creation',
		'/mv-create/v1/products/debug-amazon-error'      => 'WP_DEBUG-only mock Amazon error (registered only when WP_DEBUG)',
		'/mv-create/v1/reviews'                          => 'Public review create/list (validated and rate-limited in handlers)',
		'/mv-create/v1/reviews/(?P<review_id>\\d+)/responses' => 'Public GET of responses on a review (mutations use editor)',
	];

	/**
	 * Custom permission callbacks that are not a single named level.
	 *
	 * Each entry is `ClassName::method` (no leading backslash). These still
	 * enforce auth; they combine a level with additional checks (handshake,
	 * feature gates, etc.).
	 *
	 * @var string[]
	 */
	const CUSTOM_AUTH_CALLBACKS = [
		'Mediavine\\Create\\Reviews_API::can_update_single_review',
	];

	/**
	 * Named level method names accepted as `permission_callback` targets.
	 *
	 * @var string[]
	 */
	const NAMED_LEVEL_METHODS = [
		self::LEVEL_VIEWER,
		self::LEVEL_EDITOR,
		self::LEVEL_ADMIN,
		'allow_public',
	];

	/**
	 * Whether the current user may use Create (settings-overridable).
	 *
	 * Checks per-user then site-wide `mv_create_default_access_role`, falling
	 * back to `publish_posts`.
	 *
	 * @param mixed $api Unused; retained for call-site compatibility.
	 * @return bool
	 */
	public static function is_user_authorized( $api = null ) {
		unset( $api );

		$user = wp_get_current_user();

		$user_role_setting = \Mediavine\Settings::get_settings( 'mv_create_default_access_role' . $user->ID );

		if ( ! empty( $user_role_setting ) ) {
			return current_user_can( $user_role_setting->value );
		}

		$user_role_setting = \Mediavine\Settings::get_settings( 'mv_create_default_access_role' );

		if ( ! empty( $user_role_setting ) ) {
			return current_user_can( $user_role_setting->value );
		}

		return current_user_can( 'publish_posts' );
	}

	/**
	 * Effective Create access capability slug (for UI / role pickers).
	 *
	 * @return string
	 */
	public static function access_level() {
		if ( mv_create_table_exists( 'mv_settings' ) ) {
			$settings = new MV_DBI( 'mv_settings' );

			$user_role_setting = $settings->find_one(
				[
					'col' => 'slug',
					'key' => 'mv_create_default_access_role',
				]
			);

			if ( $user_role_setting ) {
				return $user_role_setting->value;
			}
		}

		return 'publish_posts';
	}

	/**
	 * Viewer level: requires `edit_posts`.
	 *
	 * @param \WP_REST_Request|null $request Unused; accepted for REST callback signature.
	 * @return bool|\WP_Error
	 */
	public static function viewer( $request = null ) {
		unset( $request );
		return self::require_capability( 'edit_posts' );
	}

	/**
	 * Editor level: settings-overridable Create authorization.
	 *
	 * @param \WP_REST_Request|null $request Unused; accepted for REST callback signature.
	 * @return bool|\WP_Error
	 */
	public static function editor( $request = null ) {
		unset( $request );
		if ( self::is_user_authorized() ) {
			return true;
		}
		return self::forbidden_error();
	}

	/**
	 * Admin level: requires `manage_options`.
	 *
	 * @param \WP_REST_Request|null $request Unused; accepted for REST callback signature.
	 * @return bool|\WP_Error
	 */
	public static function admin( $request = null ) {
		unset( $request );
		return self::require_capability( 'manage_options' );
	}

	/**
	 * Public level: always allows the request through.
	 *
	 * Only use for routes documented in {@see Permissions::PUBLIC_ENDPOINTS}.
	 *
	 * @param \WP_REST_Request|null $request Unused; accepted for REST callback signature.
	 * @return bool
	 */
	public static function allow_public( $request = null ) {
		unset( $request );
		return true;
	}

	/**
	 * Whether a callable is a recognized named Permissions level.
	 *
	 * @param mixed $callback Route permission_callback.
	 * @return bool
	 */
	public static function is_named_level_callback( $callback ) {
		if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] ) ) {
			return false;
		}

		$class  = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		$method = (string) $callback[1];

		if ( __CLASS__ !== ltrim( $class, '\\' ) && 'Mediavine\\Permissions' !== ltrim( $class, '\\' ) ) {
			return false;
		}

		return in_array( $method, self::NAMED_LEVEL_METHODS, true );
	}

	/**
	 * Whether a callable is a documented custom auth callback.
	 *
	 * @param mixed $callback Route permission_callback.
	 * @return bool
	 */
	public static function is_custom_auth_callback( $callback ) {
		if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] ) ) {
			return false;
		}

		$class  = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		$method = (string) $callback[1];
		$key    = ltrim( $class, '\\' ) . '::' . $method;

		return in_array( $key, self::CUSTOM_AUTH_CALLBACKS, true );
	}

	/**
	 * Whether a route path is on the public endpoint allowlist.
	 *
	 * @param string $route Full route path from `get_routes()` keys.
	 * @return bool
	 */
	public static function is_public_endpoint( $route ) {
		return isset( self::PUBLIC_ENDPOINTS[ $route ] );
	}

	/**
	 * Whether a permission_callback is allowed for an `mv-create/v1` route.
	 *
	 * @param mixed  $callback permission_callback value.
	 * @param string $route    Full route path.
	 * @return bool
	 */
	public static function is_allowed_permission_callback( $callback, $route ) {
		if ( self::is_custom_auth_callback( $callback ) ) {
			return true;
		}

		if ( ! self::is_named_level_callback( $callback ) ) {
			return false;
		}

		$method = is_array( $callback ) ? (string) $callback[1] : '';
		if ( 'allow_public' === $method ) {
			return self::is_public_endpoint( $route );
		}

		return true;
	}

	/**
	 * Require a WordPress capability, returning a standardized WP_Error on failure.
	 *
	 * @param string $capability Capability slug.
	 * @return bool|\WP_Error
	 */
	private static function require_capability( $capability ) {
		if ( current_user_can( $capability ) ) {
			return true;
		}
		return self::forbidden_error();
	}

	/**
	 * Standardized REST forbidden error.
	 *
	 * @return \WP_Error
	 */
	private static function forbidden_error() {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'mediavine-create' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
