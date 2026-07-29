<?php
/**
 * User Verification REST API Endpoints
 *
 * This class registers and handles REST API endpoints for user-level
 * verification with Create Studio.
 *
 * @package Mediavine\Create
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
	 * Initialize the class
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
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
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
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

		return new \WP_REST_Response(
			[
				'success'  => true,
				'status'   => 'redirect',
				'link_url' => $data['link_url'] ?? '',
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

		return new \WP_REST_Response(
			[
				'sso_url'    => $data['sso_url'] ?? '',
				'expires_at' => $data['expires_at'] ?? '',
			],
			200
		);
	}
}
