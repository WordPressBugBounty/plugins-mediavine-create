<?php
/**
 * User Verification REST API Endpoints
 *
 * This class registers and handles REST API endpoints for user-level
 * verification with Create Studio.
 *
 * @package Create
 */

namespace Mediavine\Create;

/**
 * User Verification class
 *
 * Provides REST API endpoints for multi-user verification with Create Studio.
 */
class User_Verification {

	/**
	 * REST API namespace
	 */
	const REST_NAMESPACE = 'mv-create/v1';

	/**
	 * REST API route base
	 */
	const REST_BASE = 'studio/user-verify';

	/**
	 * admin-post action that opens a Create Studio page signed in (SSO).
	 */
	const OPEN_STUDIO_ACTION = 'mv_create_open_studio';

	/**
	 * Initialize the class
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_post_' . self::OPEN_STUDIO_ACTION, [ $this, 'open_studio' ] );
	}

	/**
	 * A plain link that opens a Create Studio page, signed in when this user
	 * is linked. For places with no admin JS to call sso-url (the admin bar,
	 * the payment-failed and cancel-pending notices).
	 *
	 * @param string $path Studio path, e.g. '/admin/billing?site_url=...'.
	 * @return string
	 */
	public static function open_studio_url( $path ) {
		return wp_nonce_url(
			add_query_arg(
				[
					'action' => self::OPEN_STUDIO_ACTION,
					'path'   => rawurlencode( $path ),
				],
				admin_url( 'admin-post.php' )
			),
			self::OPEN_STUDIO_ACTION
		);
	}

	/**
	 * Open Studio's billing page, signed in when this user is linked.
	 *
	 * Same destination as GateKeeper::get_billing_url(), via open_studio_url()
	 * so a linked admin is signed in instead of hitting the login wall.
	 *
	 * @return string
	 */
	public static function open_billing_url() {
		return self::open_studio_url( '/admin/billing?site_url=' . rawurlencode( home_url() ) );
	}

	/**
	 * admin-post handler for open_studio_url(): redirect to a fresh SSO URL,
	 * or to the plain Studio page (login prefilled with this user's email
	 * hint) when the user isn't linked or SSO fails.
	 */
	public function open_studio() {
		check_admin_referer( self::OPEN_STUDIO_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'mediavine-create' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by sanitize_return_path().
		$path = self::sanitize_return_path( isset( $_GET['path'] ) ? wp_unslash( $_GET['path'] ) : '' );
		if ( '' === $path ) {
			$path = '/admin';
		}

		wp_redirect( self::resolve_studio_url( get_current_user_id(), $path ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Studio is off-site; only Studio URLs are returned.
		exit;
	}

	/**
	 * Studio's SSO URL, moved onto the Studio address this plugin uses.
	 *
	 * Studio builds the URL on its own root URL, which can differ from the
	 * address the publisher signs in on (a proxy or local hostname vs. the
	 * app's internal origin). Following it as-is starts a session on the
	 * other host, so the user looks signed out on theirs. Only Studio's
	 * /auth/sso path is accepted, so this can never point anywhere else.
	 *
	 * @param mixed $sso_url URL from POST /auth/sso.
	 * @return string The URL on Create Studio's base, or '' if it isn't an SSO URL.
	 */
	public static function studio_sso_url( $sso_url ) {
		if ( ! is_string( $sso_url ) || '' === $sso_url ) {
			return '';
		}

		$parts = wp_parse_url( $sso_url );
		if ( empty( $parts['path'] ) || '/auth/sso' !== $parts['path'] || empty( $parts['query'] ) ) {
			return '';
		}

		return untrailingslashit( Create_Studio_Client::get_studio_base_url() ) . '/auth/sso?' . $parts['query'];
	}

	/**
	 * Where open_studio() sends a user: an SSO URL when the user's token works,
	 * otherwise the plain Studio page with the email hint.
	 *
	 * @param int    $user_id WP user.
	 * @param string $path    Sanitized Studio path.
	 * @return string
	 */
	public static function resolve_studio_url( $user_id, $path ) {
		$base  = Create_Studio_Client::get_studio_base_url();
		$token = User_Verification_Meta::get_token( $user_id );

		if ( ! empty( $token ) ) {
			$response = Create_Studio_Client::generate_sso_url( $token, $path );
			$sso_url  = ! is_wp_error( $response ) && ! empty( $response['success'] ) ? self::studio_sso_url( $response['data']['sso_url'] ?? '' ) : '';

			if ( '' !== $sso_url ) {
				return $sso_url;
			}
		}

		$url   = $base . $path;
		$email = Site_Verification::get_studio_email_hint( $user_id );
		if ( '' !== $email && false === strpos( $path, 'email=' ) ) {
			$url = add_query_arg( 'email', rawurlencode( $email ), $url );
		}

		return $url;
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// POST /mv-create/v1/studio/user-verify/initiate
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/initiate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'initiate_verification' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		// GET /mv-create/v1/studio/user-verify/status
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'check_status' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		// POST /mv-create/v1/studio/user-verify/complete
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/complete',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'complete_verification' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		// DELETE /mv-create/v1/studio/user-verify
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE,
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'disconnect' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		// POST /mv-create/v1/studio/user-verify/resend-email
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/resend-email',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'resend_email_verification' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		// GET /mv-create/v1/studio/user-verify/sso-url
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/sso-url',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_sso_url' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				'args'                => [
					'return_url' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => [ self::class, 'sanitize_return_path' ],
					],
				],
			]
		);
	}

	/**
	 * Sanitize a Studio return path without disturbing percent-encoding.
	 *
	 * sanitize_text_field() strips %XX octets, which corrupts encoded query
	 * values. Accept only a same-site path (one leading slash) verbatim.
	 *
	 * @param mixed $value Raw value.
	 * @return string Path, or '' when invalid.
	 */
	public static function sanitize_return_path( $value ) {
		if ( ! is_string( $value ) || '' === $value || '/' !== $value[0] ) {
			return '';
		}
		if ( isset( $value[1] ) && ( '/' === $value[1] || '\\' === $value[1] ) ) {
			return '';
		}
		if ( preg_match( '/[\s\x00-\x1F\x7F<>"\\\\]/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * POST /mv-create/v1/studio/user-verify/initiate
	 *
	 * Initiates the user verification process by creating a link session
	 * and returning a redirect URL to Create Studio.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function initiate_verification( \WP_REST_Request $request ) {
		// Check if site is connected first
		if ( ! Create_Studio_Client::is_site_connected() ) {
			return new \WP_Error(
				'site_not_connected',
				__( 'Site must be connected to Create Studio before user verification', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
		}

		$user_id = get_current_user_id();

		// Check if user is already verified locally
		if ( User_Verification_Meta::is_verified( $user_id ) ) {
			return new \WP_REST_Response(
				[
					'success' => true,
					'status'  => 'already_verified',
					'email'   => User_Verification_Meta::get_email( $user_id ),
				],
				200
			);
		}

		// Build return URL — settings page is under the mv_create CPT menu
		$return_url = admin_url( 'edit.php?post_type=mv_create&page=settings#create-studio' );

		// Call Create Studio API to create link session
		$response = Create_Studio_Client::create_link_session( $return_url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'] ?? [];

		if ( ! $response['success'] ) {
			$error_msg = $data['message'] ?? $data['error'] ?? __( 'Failed to create link session', 'mediavine-create' );
			return new \WP_Error(
				$data['error'] ?? 'api_error',
				$error_msg,
				[ 'status' => $response['status_code'] ]
			);
		}

		// Store session ID in user meta
		$session_id = $data['session_id'] ?? '';
		if ( ! empty( $session_id ) ) {
			User_Verification_Meta::set_link_session( $session_id, $user_id );
		}

		// Build the account-link redirect from the plugin's configured Studio base
		// URL — the same "site-connect redirection method" used by the site
		// connection flow (see Site_Verification::initiate_connection). Studio's own
		// link_url is derived from its internal app URL (rootUrl), which is not the
		// browser-facing host when the plugin is pointed at a routed/proxied Studio
		// origin, so we construct the URL ourselves to keep both flows on the same host.
		$studio_base_url = Create_Studio_Client::get_studio_base_url();
		if ( ! empty( $session_id ) && ! empty( $studio_base_url ) ) {
			$link_url = add_query_arg(
				[ 'session' => $session_id ],
				$studio_base_url . '/auth/link'
			);
		} else {
			// Fallback to Studio's link_url if we cannot construct one locally.
			$link_url = $data['link_url'] ?? '';
		}

		// Tell Studio which account this is probably for, so its login and
		// set-password forms start filled in. A hint only: the person can type
		// another.
		$email_hint = Site_Verification::get_studio_email_hint( $user_id );
		if ( ! empty( $link_url ) && is_email( $email_hint ) ) {
			$link_url = add_query_arg( 'email', rawurlencode( $email_hint ), $link_url );
		}

		return new \WP_REST_Response(
			[
				'success'  => true,
				'status'   => 'redirect',
				'link_url' => $link_url,
			],
			200
		);
	}

	/**
	 * GET /mv-create/v1/studio/user-verify/status
	 *
	 * Checks the current verification status for the user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_status( \WP_REST_Request $request ) {
		if ( ! Create_Studio_Client::is_site_connected() ) {
			return new \WP_Error(
				'site_not_connected',
				__( 'Site must be connected to Create Studio', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
		}

		$user_id = get_current_user_id();

		// Check if already verified locally
		if ( User_Verification_Meta::is_verified( $user_id ) ) {
			$email_verified = User_Verification_Meta::get_email_verified( $user_id );

			// Refresh email_verified from Studio if not yet verified locally
			if ( ! $email_verified ) {
				$user_token = User_Verification_Meta::get_token( $user_id );
				$response   = Create_Studio_Client::check_email_verification_status( $user_token );

				if ( ! is_wp_error( $response ) && ! empty( $response['success'] ) && ! empty( $response['data']['email_verified'] ) ) {
					$email_verified = true;
					User_Verification_Meta::set_email_verified( true, $user_id );
				}
			}

			return new \WP_REST_Response(
				[
					'status'         => 'verified',
					'email'          => User_Verification_Meta::get_email( $user_id ),
					'verified_at'    => User_Verification_Meta::get_verified_at( $user_id ),
					'email_verified' => $email_verified,
				],
				200
			);
		}

		// Auto-claim pending user verification from site connection flow.
		// During site connection, Studio sends the user token via server-to-server
		// callback. It's stored in a transient for the first admin to claim.
		$pending = get_transient( 'mv_create_pending_user_verification' );

		if ( ! empty( $pending ) && ! empty( $pending['token'] ) ) {
			$token          = $pending['token'];
			$email          = $pending['email'] ?? '';
			$verified_at    = gmdate( 'c' );
			$email_verified = ! empty( $pending['email_verified'] );

			User_Verification_Meta::store_verification( $token, $email, $verified_at, $user_id, $email_verified );

			// One-time use — delete so other admins don't claim it.
			delete_transient( 'mv_create_pending_user_verification' );

			return new \WP_REST_Response(
				[
					'status'         => 'verified',
					'email'          => $email,
					'verified_at'    => $verified_at,
					'email_verified' => $email_verified,
				],
				200
			);
		}

		return new \WP_REST_Response(
			[
				'status' => 'unverified',
			],
			200
		);
	}

	/**
	 * POST /mv-create/v1/studio/user-verify/complete
	 *
	 * Completes the user verification by exchanging a link session for a token.
	 * Called by the frontend after the user is redirected back from Studio.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function complete_verification( \WP_REST_Request $request ) {
		// Check if site is connected first
		if ( ! Create_Studio_Client::is_site_connected() ) {
			return new \WP_Error(
				'site_not_connected',
				__( 'Site must be connected to Create Studio', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
		}

		$user_id    = get_current_user_id();
		$session_id = $request->get_param( 'session_id' );

		if ( empty( $session_id ) ) {
			return new \WP_Error(
				'missing_session_id',
				__( 'Session ID is required', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
		}

		// Exchange the session for a token via Studio API
		$response = Create_Studio_Client::exchange_link_session( $session_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $response['success'] ) {
			$data      = $response['data'] ?? [];
			$error_msg = $data['message'] ?? $data['error'] ?? __( 'Failed to complete verification', 'mediavine-create' );
			return new \WP_Error(
				$data['error'] ?? 'api_error',
				$error_msg,
				[ 'status' => $response['status_code'] ]
			);
		}

		$data           = $response['data'] ?? [];
		$token          = $data['user_token'] ?? '';
		$email          = $data['email'] ?? '';
		$avatar         = $data['avatarUrl'] ?? null;
		$verified_at    = $data['verified_at'] ?? gmdate( 'c' );
		$email_verified = ! empty( $data['email_verified'] );

		if ( empty( $token ) ) {
			return new \WP_Error(
				'missing_token',
				__( 'Verification token missing from response', 'mediavine-create' ),
				[ 'status' => 500 ]
			);
		}

		// Store verification data
		User_Verification_Meta::store_verification( $token, $email, $verified_at, $user_id, $email_verified );

		return new \WP_REST_Response(
			[
				'status'         => 'verified',
				'email'          => $email,
				'avatarUrl'      => $avatar,
				'verified_at'    => $verified_at,
				'email_verified' => $email_verified,
			],
			200
		);
	}

	/**
	 * DELETE /mv-create/v1/studio/user-verify
	 *
	 * Disconnects the user from Create Studio.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function disconnect( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		// Check if user is verified
		$user_token = User_Verification_Meta::get_token( $user_id );

		if ( empty( $user_token ) ) {
			// User is not verified, but still clear any pending verification
			User_Verification_Meta::clear_all( $user_id );

			return new \WP_REST_Response(
				[
					'success' => true,
					'message' => __( 'User was not verified', 'mediavine-create' ),
				],
				200
			);
		}

		// Call Create Studio API to disconnect
		$response = Create_Studio_Client::disconnect_user( $user_token );

		// Log any API errors but don't block the disconnect
		if ( is_wp_error( $response ) ) {
			Help::log( 'Create Studio user disconnect API error: ' . $response->get_error_message() );
		} elseif ( ! $response['success'] ) {
			Help::log( 'Create Studio user disconnect API error: ' . wp_json_encode( $response['data'] ) );
		}

		// Clear all user verification data regardless of API response
		User_Verification_Meta::clear_all( $user_id );

		return new \WP_REST_Response(
			[
				'success' => true,
			],
			200
		);
	}

	/**
	 * POST /mv-create/v1/studio/user-verify/resend-email
	 *
	 * Resends the Studio email verification for the current user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resend_email_verification( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$user_token = User_Verification_Meta::get_token( $user_id );

		if ( empty( $user_token ) ) {
			return new \WP_Error(
				'not_verified',
				__( 'User is not linked with Create Studio', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
		}

		$response = Create_Studio_Client::resend_email_verification( $user_token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $response['success'] ) {
			$data = $response['data'] ?? [];

			return new \WP_Error(
				$data['error'] ?? 'api_error',
				$data['message'] ?? $data['error'] ?? __( 'Failed to resend verification email', 'mediavine-create' ),
				[ 'status' => $response['status_code'] ]
			);
		}

		return new \WP_REST_Response(
			[
				'success' => true,
			],
			200
		);
	}

	/**
	 * GET /mv-create/v1/studio/user-verify/sso-url
	 *
	 * Generates an SSO URL for the verified user to access Create Studio.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_sso_url( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$user_token = User_Verification_Meta::get_token( $user_id );

		if ( empty( $user_token ) ) {
			return new \WP_Error(
				'not_verified',
				__( 'User is not verified with Create Studio', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
		}

		$return_url = $request->get_param( 'return_url' );

		// Call Create Studio API to generate SSO URL
		$response = Create_Studio_Client::generate_sso_url( $user_token, $return_url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $response['success'] ) {
			$data = $response['data'] ?? [];

			// Check for token expiration or invalid token
			if ( in_array( $response['status_code'], [ 401, 403 ], true ) ) {
				// Token may be invalid - clear user verification
				User_Verification_Meta::clear_all( $user_id );

				return new \WP_Error(
					'token_invalid',
					__( 'Your verification has expired. Please verify again.', 'mediavine-create' ),
					[ 'status' => 401 ]
				);
			}

			return new \WP_Error(
				$data['error'] ?? 'api_error',
				$data['message'] ?? __( 'Failed to generate SSO URL', 'mediavine-create' ),
				[ 'status' => $response['status_code'] ]
			);
		}

		$data = $response['data'] ?? [];

		$sso_url = self::studio_sso_url( $data['sso_url'] ?? '' );
		if ( '' === $sso_url ) {
			return new \WP_Error( 'api_error', __( 'Failed to generate SSO URL', 'mediavine-create' ), [ 'status' => 502 ] );
		}

		return new \WP_REST_Response(
			[
				'sso_url'    => $sso_url,
				'expires_at' => $data['expires_at'] ?? '',
			],
			200
		);
	}
}
