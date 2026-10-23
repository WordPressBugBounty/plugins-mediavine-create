<?php
/**
 * Site Verification Code functionality for Create Studio connection
 *
 * This class handles the generation and verification of site verification codes
 * used to connect WordPress sites to Create Studio.
 *
 * @package Create
 */

namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * Site Verification class
 */
class Site_Verification {

	/**
	 * The setting key for storing the verification code
	 */
	const SETTING_KEY = 'site_verification_code';

	/**
	 * Option holding the email of the Create Studio account this site was
	 * connected under, so the admin can be told which account to sign in to.
	 */
	const OPTION_ACCOUNT_EMAIL = 'mv_create_studio_account_email';

	/**
	 * Option holding the ID of the WP user who connected the site. Sites
	 * connected before this was recorded have none.
	 */
	const OPTION_CONNECTED_BY = 'mv_create_studio_connected_by';

	/**
	 * Transient remembering who started the pending connect, because Create
	 * Studio's callback arrives with no WP user (it is a server-to-server call).
	 */
	const TRANSIENT_CONNECT_USER = 'mv_create_connect_user';

	/**
	 * Set when the Create Studio account a connect named declined the request
	 * (which disconnects the site). Cleared by the next successful connect.
	 */
	const OPTION_CONNECTION_DECLINED = 'mv_create_studio_connection_declined';

	/**
	 * Initialize the class
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'wp_ajax_mv_create_generate_verification_code', [ $this, 'ajax_generate_code' ] );
		add_action( 'wp_ajax_mv_create_get_verification_code', [ $this, 'ajax_get_code' ] );
		add_action( 'wp_ajax_mv_create_disconnect', [ $this, 'ajax_disconnect' ] );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		register_rest_route(
			'mv-create/v1',
			'/verify-site-code',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'verify_site_code' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ], // Public endpoint - security via code verification
				'args'                => [
					'code'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'token'   => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'user_id' => [
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'mv-create/v1',
			'/studio/site-status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_site_status' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		// Site Connection V2: Initiate redirect-based connection
		register_rest_route(
			'mv-create/v1',
			'/studio/site-connect/initiate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'initiate_site_connect' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		// Site Connection V2: Connect in the background, no trip to Studio
		register_rest_route(
			'mv-create/v1',
			'/studio/site-connect/auto',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'auto_site_connect' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				'args'                => [
					'tos_accepted' => [
						'type'    => 'boolean',
						'default' => false,
					],
					'email'        => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'return_url'   => [
						'type'     => 'string',
						'required' => false,
					],
					'upgrade'      => [
						'type'    => 'boolean',
						'default' => false,
					],
					'upgrade_tier' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					],
					'refresh'      => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		// Site Connection V2: Callback from Studio (public, token-authenticated)
		register_rest_route(
			'mv-create/v1',
			'/site-connect/callback',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'site_connect_callback' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ],
				'args'                => [
					'connect_token' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'jwt'           => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'site_id'       => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'user_token'    => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'user_email'    => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);
	}

	/**
	 * REST API callback to get site connection status from Create Studio.
	 *
	 * Proxies the request to Create Studio's /sites/status endpoint.
	 * Fail-open: if API is unreachable but local token exists, reports connected
	 * with cached subscription tier.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_site_status( \WP_REST_Request $request ) {
		// Check if site has a token at all.
		$has_token = Create_Studio_Client::is_site_connected();

		if ( ! $has_token ) {
			return new \WP_REST_Response(
				[
					'connected' => false,
					'reason'    => 'no_token',
				],
				200
			);
		}

		// Call Create Studio API.
		$status = Create_Studio_Client::check_site_status();

		if ( false === $status ) {
			// API unreachable — fail open with cached tier.
			$cached_tier = GateKeeper::get_subscription_tier();

			return new \WP_REST_Response(
				[
					'connected'         => true,
					'subscription_tier' => $cached_tier,
					'reason'            => 'api_unreachable',
					'account_email'     => self::get_account_email(),
				],
				200
			);
		}

		$status['account_email'] = self::get_account_email();

		return new \WP_REST_Response( $status, 200 );
	}

	/**
	 * The email of the Create Studio account this site was connected under.
	 *
	 * @return string The email, or '' when unknown (connected before this was recorded).
	 */
	public static function get_account_email() {
		return (string) get_option( self::OPTION_ACCOUNT_EMAIL, '' );
	}

	/**
	 * Which email to prefill on Create Studio's login for a WP user.
	 *
	 * 1. Their own linked Create Studio email.
	 * 2. Else, if they connected the site: the site's Create Studio account email.
	 * 3. Else their own WP user email.
	 *
	 * Sites connected before the connecting user was recorded have no
	 * connected-by option, so rule 2 can't be known: those users get rule 3
	 * unless linked. A hint only; the person can type another.
	 *
	 * @param int|null $user_id WP user ID. Defaults to the current user.
	 * @return string The email, or '' when none is known.
	 */
	public static function get_studio_email_hint( $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$linked = (string) User_Verification_Meta::get_email( $user_id );
		if ( '' !== $linked ) {
			return $linked;
		}

		$connected_by = (int) get_option( self::OPTION_CONNECTED_BY, 0 );
		if ( $connected_by && $connected_by === (int) $user_id ) {
			$account = self::get_account_email();
			if ( '' !== $account ) {
				return $account;
			}
		}

		$wp_user = get_userdata( $user_id );
		return $wp_user ? (string) $wp_user->user_email : '';
	}

	/**
	 * Start a connection attempt: mint the one-time token Create Studio must
	 * echo back to /site-connect/callback. Replaces any earlier pending token.
	 *
	 * @param bool $refresh Refreshing an existing connection: the site stays
	 *                      connected by its original user, so no "connected by"
	 *                      user is recorded for the callback.
	 * @return string The connect token.
	 */
	private static function issue_connect_token( $refresh = false ) {
		$token = wp_generate_password( 64, false, false );

		// Store in transient with 10-minute TTL
		set_transient( 'mv_create_connect_token', $token, 600 );
		if ( $refresh ) {
			delete_transient( self::TRANSIENT_CONNECT_USER );
		} else {
			set_transient( self::TRANSIENT_CONNECT_USER, get_current_user_id(), 600 );
		}

		return $token;
	}

	/**
	 * Build the Studio URL for the redirect (manual) connect flow.
	 *
	 * @param string      $token      The connect token.
	 * @param string|null $return_url Where Studio should send the admin back to.
	 * @param string|null $email      Email to prefill on Studio's login. Defaults to the current user's hint.
	 * @return string
	 */
	private static function build_connect_url( $token, $return_url, $email = null ) {
		if ( empty( $return_url ) || strpos( $return_url, admin_url() ) !== 0 ) {
			$return_url = admin_url( 'edit.php?post_type=mv_create&page=settings#create-studio' );
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			$email = self::get_studio_email_hint();
		}

		$args = [
			'site_url'   => rawurlencode( home_url() ),
			'token'      => rawurlencode( $token ),
			'return_url' => rawurlencode( $return_url ),
		];
		if ( is_email( $email ) ) {
			$args['email'] = rawurlencode( $email );
		}

		return add_query_arg( $args, Create_Studio_Client::get_studio_base_url() . '/auth/connect' );
	}

	/**
	 * REST API callback to initiate a site connection via redirect flow.
	 *
	 * Generates a one-time connect token and returns a Studio redirect URL.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function initiate_site_connect( \WP_REST_Request $request ) {
		// Check if already connected
		if ( Create_Studio_Client::is_site_connected() ) {
			return new \WP_REST_Response(
				[ 'status' => 'already_connected' ],
				200
			);
		}

		$connect_url = self::build_connect_url( self::issue_connect_token(), $request->get_param( 'return_url' ), $request->get_param( 'email' ) );

		return new \WP_REST_Response(
			[
				'status'      => 'redirect',
				'connect_url' => $connect_url,
			],
			200
		);
	}

	/**
	 * REST API callback to connect the site in the background.
	 *
	 * Sends the connect token and the admin's email to Create Studio, which
	 * proves the site by calling /site-connect/callback (storing the JWT)
	 * before it answers. No account or login is needed up front: Create Studio
	 * creates one for the email if there isn't one, to be claimed later.
	 *
	 * Sites Create Studio can't reach (local, firewalled, password-protected)
	 * fail here. That is reported as `status: failed` with a reason and a
	 * ready-to-use link for the redirect flow, rather than as an HTTP error,
	 * so the admin always has a way forward.
	 *
	 * With `refresh`, the same handshake runs for a site that is ALREADY
	 * connected, so Create Studio issues a fresh site token — the fix for a
	 * token that has stopped working, without disconnecting (which drops the
	 * plan, the account links and the tier state). It stays under the account
	 * that made the connection: no new consent, no email field, because
	 * neither is being changed. A failed refresh leaves the old token in place.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function auto_site_connect( \WP_REST_Request $request ) {
		$refresh   = true === $request->get_param( 'refresh' );
		$connected = Create_Studio_Client::is_site_connected();

		if ( $connected && ! $refresh ) {
			return new \WP_REST_Response(
				[
					'status' => 'already_connected',
					'email'  => self::get_account_email(),
				],
				200
			);
		}

		if ( $refresh ) {
			if ( ! $connected ) {
				return new \WP_REST_Response(
					[
						'status'  => 'failed',
						'code'    => 'not_connected',
						'message' => __( 'This site isn\'t connected to Create Studio yet, so there is nothing to refresh. Connect it instead.', 'mediavine-create' ),
					],
					400
				);
			}

			// Connections made before the account email was recorded can't be
			// refreshed under the right account; reconnecting records it.
			$email = self::get_account_email();
			if ( empty( $email ) ) {
				return new \WP_REST_Response(
					[
						'status'  => 'failed',
						'code'    => 'no_account_email',
						'message' => __( 'We don\'t know which Create Studio account this site is connected to. Disconnect and connect again to refresh it.', 'mediavine-create' ),
					],
					400
				);
			}
		} else {
			// An account may be created for this email, so consent comes first.
			if ( true !== $request->get_param( 'tos_accepted' ) ) {
				return new \WP_REST_Response(
					[
						'status'  => 'failed',
						'code'    => 'tos_required',
						'message' => __( 'Please accept the Create Studio Terms of Service to connect.', 'mediavine-create' ),
					],
					400
				);
			}

			$email = $request->get_param( 'email' );
			$email = empty( $email ) ? wp_get_current_user()->user_email : $email;

			if ( ! is_email( $email ) ) {
				return new \WP_REST_Response(
					[
						'status'  => 'failed',
						'code'    => 'invalid_email',
						'message' => __( 'Please enter a valid email address.', 'mediavine-create' ),
					],
					400
				);
			}
		}

		$user = wp_get_current_user();
		$body = [
			'site_url'      => home_url(),
			'connect_token' => self::issue_connect_token( $refresh ),
			'email'         => $email,
			'firstname'     => $user->first_name,
			'lastname'      => $user->last_name,
			'tos_accepted'  => true,
		];

		// Connecting on the way to a paid plan: Create Studio points the
		// set-password email at this site's upgrade page instead of the dashboard.
		if ( true === $request->get_param( 'upgrade' ) ) {
			$body['upgrade'] = true;

			$upgrade_tier = $request->get_param( 'upgrade_tier' );
			if ( ! empty( $upgrade_tier ) ) {
				$body['upgrade_tier'] = $upgrade_tier;
			}
		}

		// A refresh succeeds only if the callback stored a NEW token: the old one
		// is still there after a refused refresh, and that is a failure.
		$previous_token = $refresh ? (string) Settings::get_setting( 'mv_create_api_token' ) : '';

		$response = Create_Studio_Client::auto_connect( $body );

		// The callback ran in another request; read what it stored.
		Settings::reset_settings();

		// The stored token is the truth. If the callback landed but Create
		// Studio's answer was lost on the way back, the site is still connected.
		if ( Create_Studio_Client::is_site_connected() && (string) Settings::get_setting( 'mv_create_api_token' ) !== $previous_token ) {
			$data = ! is_wp_error( $response ) && is_array( $response['data'] ) ? $response['data'] : [];

			// A refresh keeps the original "connected by" user.
			if ( ! $refresh && get_current_user_id() ) {
				update_option( self::OPTION_CONNECTED_BY, get_current_user_id(), false );
			}

			// Create Studio also signs the admin in when it can: the same
			// per-user token the "Link account" flow stores. Stored for the
			// current user only, and never sent to the browser.
			$signed_in = self::store_auto_connect_user_token( $data, $refresh );

			return new \WP_REST_Response(
				[
					'status'              => 'connected',
					'email'               => ! empty( $data['email'] ) ? $data['email'] : self::get_account_email(),
					'account'             => isset( $data['account'] ) ? $data['account'] : '',
					'needs_password'      => ! empty( $data['needs_password'] ),
					'claim_email_sent'    => ! empty( $data['claim_email_sent'] ),
					// A still-valid claim link was emailed for an earlier site.
					'claim_email_pending' => ! empty( $data['claim_email_pending'] ),
					'subscription_tier'   => GateKeeper::get_effective_tier(),
					'refreshed'           => $refresh,
					'signed_in'           => $signed_in,
				],
				200
			);
		}

		if ( is_wp_error( $response ) ) {
			Help::log( 'Create Studio auto-connect error: ' . $response->get_error_message() );
			$code    = 'studio_unreachable';
			$message = __( 'Your site could not reach Create Studio.', 'mediavine-create' );
		} elseif ( ! is_array( $response['data'] ) ) {
			// Not Create Studio's own answer: a gateway or outage page.
			$code    = 'studio_unavailable';
			$message = __( 'Create Studio is not responding right now. Please try again in a few minutes.', 'mediavine-create' );
		} else {
			$data    = $response['data'];
			$code    = ! empty( $data['code'] ) ? sanitize_key( $data['code'] ) : 'connect_failed';
			$message = ! empty( $data['error'] ) && is_string( $data['error'] )
				? sanitize_text_field( $data['error'] )
				: __( 'Please try again, or connect manually.', 'mediavine-create' );
		}

		// A fresh token for the manual flow: the one above may already be spent.
		return new \WP_REST_Response(
			[
				'status'      => 'failed',
				'code'        => $code,
				'message'     => $message,
				'connect_url' => self::build_connect_url( self::issue_connect_token( $refresh ), $request->get_param( 'return_url' ), ! $refresh ? $email : null ),
			],
			200
		);
	}

	/**
	 * Link the current WP user from an auto-connect response.
	 *
	 * Mirrors User_Verification::complete_verification(): when Create Studio
	 * answers `signed_in: true` with a `user_token`, store it with the account
	 * email and verified state so SSO works for this user from now on. Older
	 * Create Studio versions omit both fields, which counts as not signed in.
	 *
	 * @param array $data    Decoded /sites/auto-connect response.
	 * @param bool  $refresh Whether this was a token refresh (no user is linked then).
	 * @return bool Whether the current user is now linked.
	 */
	private static function store_auto_connect_user_token( $data, $refresh ) {
		$user_id = get_current_user_id();
		$token   = isset( $data['user_token'] ) && is_string( $data['user_token'] ) ? $data['user_token'] : '';

		if ( $refresh || empty( $data['signed_in'] ) || '' === $token || ! $user_id ) {
			return false;
		}

		$email = ! empty( $data['email'] ) ? $data['email'] : self::get_account_email();

		// Signed in on the site's authority, not the email's: nobody has proven
		// the address yet, so Settings keeps nudging them to verify it (which is
		// also how a new account gets a password).
		User_Verification_Meta::store_verification( $token, (string) $email, gmdate( 'c' ), $user_id, false );

		return User_Verification_Meta::is_verified( $user_id );
	}

	/**
	 * REST API callback to receive JWT from Studio after site connection.
	 *
	 * This public endpoint is called by Create Studio's server during the
	 * redirect-based site connection flow. Authentication is via the
	 * connect_token that was generated by initiate_site_connect().
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function site_connect_callback( \WP_REST_Request $request ) {
		$submitted_token = $request->get_param( 'connect_token' );
		$jwt             = $request->get_param( 'jwt' );
		$site_id         = $request->get_param( 'site_id' );

		// Retrieve stored token
		$stored_token = get_transient( 'mv_create_connect_token' );

		if ( empty( $stored_token ) ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => 'No pending connection or token expired',
				],
				401
			);
		}

		// Timing-safe comparison
		if ( ! hash_equals( $stored_token, $submitted_token ) ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => 'Invalid connection token',
				],
				401
			);
		}

		// Token valid — store the JWT
		Settings::create_settings(
			[
				'slug'  => 'mv_create_api_token',
				'value' => $jwt,
				'group' => 'mv_create_api',
			]
		);

		// If Studio also sent user verification data, store in transient for
		// the redirecting user to claim on next status check (5 min TTL).
		$user_token = $request->get_param( 'user_token' );
		$user_email = $request->get_param( 'user_email' );

		// Remember which account the site is under. The background flow sends
		// no user token here (Create Studio returns one in the /sites/auto-connect
		// response instead, see store_auto_connect_user_token()), so this is the
		// only record of the account on the site.
		if ( ! empty( $user_email ) ) {
			update_option( self::OPTION_ACCOUNT_EMAIL, sanitize_email( $user_email ), false );
		}

		// Remember who started this connect (see issue_connect_token()).
		$connected_by = (int) get_transient( self::TRANSIENT_CONNECT_USER );
		if ( $connected_by ) {
			update_option( self::OPTION_CONNECTED_BY, $connected_by, false );
		}
		delete_transient( self::TRANSIENT_CONNECT_USER );

		// Connected again, so an earlier declined request is history.
		delete_option( self::OPTION_CONNECTION_DECLINED );

		if ( ! empty( $user_token ) ) {
			set_transient(
				'mv_create_pending_user_verification',
				[
					'token' => sanitize_text_field( $user_token ),
					'email' => sanitize_email( $user_email ),
				],
				300
			);
		}

		// Clear the connect token (one-time use)
		delete_transient( 'mv_create_connect_token' );

		// Reset cached settings
		Settings::reset_settings();

		// Pull the subscription tier inline so the grant Create Studio just made on
		// connect (free-plus) is reflected the moment the browser redirects back —
		// the scheduled admin_init sync only fires on a LATER request, which would
		// otherwise leave the settings page showing the free floor until then. Fail-open:
		// sync_subscription() returns false on error and never throws.
		GateKeeper::sync_subscription();

		// Return site metadata
		return new \WP_REST_Response(
			[
				'success'        => true,
				'site_name'      => get_bloginfo( 'name' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => phpversion(),
				'create_version' => Plugin::VERSION,
			],
			200
		);
	}

	/**
	 * Get the current site verification code
	 *
	 * @return string|null The verification code or null if not generated
	 */
	public static function get_verification_code() {
		$setting = Settings::get_settings( self::SETTING_KEY );

		if ( $setting && isset( $setting->value ) ) {
			return $setting->value;
		}

		return null;
	}

	/**
	 * Generate a new site verification code
	 *
	 * @return string The newly generated code
	 */
	public static function generate_verification_code() {
		// Generate a 32-character alphanumeric code
		$code = wp_generate_password( 32, false, false );

		// Store in settings
		Settings::create_settings(
			[
				'slug'  => self::SETTING_KEY,
				'value' => $code,
				'group' => 'hidden',
			]
		);

		// Reset cached settings to ensure the new value is available
		Settings::reset_settings();

		return $code;
	}

	/**
	 * AJAX handler for generating a new verification code
	 */
	public function ajax_generate_code() {
		// Security check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		// Verify nonce - check both possible nonce names
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mv_create_admin' ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		$code = self::generate_verification_code();
		wp_send_json_success( [ 'code' => $code ] );
	}

	/**
	 * AJAX handler for getting the current verification code
	 */
	public function ajax_get_code() {
		// Security check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		// Verify nonce - check both possible nonce names
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mv_create_admin' ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		$code = self::get_verification_code();
		wp_send_json_success( [ 'code' => $code ] );
	}

	/**
	 * AJAX handler for disconnecting from Create Studio
	 *
	 * Calls Create Studio API to unverify the site-user association (returns it
	 * to a pending state so the user can easily reconnect later), then clears
	 * the API token, user ID, and email confirmation settings locally, along
	 * with every WordPress user's own Studio link.
	 */
	public function ajax_disconnect() {
		// Security check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		// Verify nonce - check both possible nonce names
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mv_create_admin' ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
		}

		// Get the current token before clearing it
		$token_setting = Settings::get_settings( 'mv_create_api_token' );
		$token         = $token_setting ? $token_setting->value : '';

		// Call Create Studio API to disconnect (token contains site_id)
		if ( ! empty( $token ) ) {
			$api_url  = Plugin::$services_api_url . '/sites/disconnect';
			$response = wp_remote_post(
				$api_url,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					],
					'timeout' => 15,
				]
			);

			// Log any errors but don't block the disconnect
			if ( is_wp_error( $response ) ) {
				Help::log( 'Create Studio disconnect API error: ' . $response->get_error_message() );
			}
		}

		self::disconnect_locally();

		wp_send_json_success( [ 'message' => 'Disconnected from Create Studio' ] );
	}

	/**
	 * Everything a disconnect does on this site, without calling Create Studio.
	 *
	 * Shared by the admin's own disconnect (which tells Studio first) and by
	 * Studio telling the site its connection request was declined.
	 *
	 * @return void
	 */
	public static function disconnect_locally() {
		// Clear API token (preserve the data type so it renders correctly on the Settings page)
		Settings::create_settings(
			[
				'slug'  => 'mv_create_api_token',
				'value' => '',
				'group' => 'mv_create_api',
				'order' => 105,
				'data'  => [
					'type'         => 'api_authentication',
					'label'        => __( 'Product Registration', 'mediavine-create' ),
					'instructions' => __( 'In order to use services like nutrition calculation or link scraping, you must register an account. This is a free, one-time action that will grant access to all of our external APIs.', 'mediavine-create' ),
				],
			]
		);

		delete_option( self::OPTION_ACCOUNT_EMAIL );
		delete_option( self::OPTION_CONNECTED_BY );

		// Clear user ID
		Settings::create_settings(
			[
				'slug'  => 'mv_create_api_user_id',
				'value' => '',
				'group' => 'hidden',
			]
		);

		// Clear any verification code
		Settings::create_settings(
			[
				'slug'  => self::SETTING_KEY,
				'value' => '',
				'group' => 'hidden',
			]
		);

		// Studio put every linked user back to pending when the site
		// disconnected, so each user's local link is now a lie: it would show
		// the Account card as linked while the SSO token it holds is rejected.
		self::clear_user_links();

		// Reset ALL subscription + trial state (tier, is_trialing, trial_end,
		// sync timestamp, ...) to the disconnected free floor BEFORE enforcing
		// fallbacks. The token was just deleted, so no future sync can correct
		// stale state — a stale is_trialing flag in particular would keep the
		// effective tier at Pro Pass (skipping the free-floor settings
		// reset, and every gate) forever. The helper resets the settings cache
		// after each write, so enforce_feature_fallbacks() reads fresh values.
		GateKeeper::reset_subscription_state();

		// Enforce feature fallbacks now that the effective tier is free
		GateKeeper::enforce_feature_fallbacks();

		// Clear transients
		delete_transient( 'mv_create_needs_password_reset' );
		delete_transient( 'mv_create_pending_user_verification' );

		// Reset cached settings (again, to pick up the fallback writes)
		Settings::reset_settings();
	}

	/**
	 * Record that the account named in the last connect declined it, and
	 * disconnect: Studio has no account left on this site.
	 *
	 * @param string $email The account that declined, when Studio says.
	 * @return void
	 */
	public static function handle_connection_declined( $email = '' ) {
		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			$email = self::get_account_email();
		}

		// Recorded first: the teardown below rewrites settings and can be slow,
		// and the reason has to survive it being cut short.
		update_option(
			self::OPTION_CONNECTION_DECLINED,
			[
				'email' => $email,
				'at'    => time(),
			],
			false
		);

		self::disconnect_locally();
	}

	/**
	 * The declined request to tell the admin about, if the last connect ended that way.
	 *
	 * @return array|null { email: string, at: int }
	 */
	public static function get_declined_connection() {
		$declined = get_option( self::OPTION_CONNECTION_DECLINED, null );

		if ( ! is_array( $declined ) || ! isset( $declined['email'] ) ) {
			return null;
		}

		return [
			'email' => (string) $declined['email'],
			'at'    => isset( $declined['at'] ) ? (int) $declined['at'] : 0,
		];
	}

	/**
	 * Forget every WordPress user's Create Studio link.
	 *
	 * Per-user links live in user meta, not in the site's settings, so clearing
	 * the site token leaves them behind. Reuses the same per-user clear as
	 * "Unlink your account".
	 *
	 * @return void
	 */
	public static function clear_user_links() {
		$linked_user_ids = get_users(
			[
				'fields'     => 'ID',
				'meta_query' => [
					'relation' => 'OR',
					[
						'key'     => User_Verification_Meta::TOKEN_KEY,
						'compare' => 'EXISTS',
					],
					[
						'key'     => User_Verification_Meta::EMAIL_KEY,
						'compare' => 'EXISTS',
					],
					[
						'key'     => User_Verification_Meta::VERIFIED_AT_KEY,
						'compare' => 'EXISTS',
					],
					[
						'key'     => User_Verification_Meta::EMAIL_VERIFIED_KEY,
						'compare' => 'EXISTS',
					],
					[
						'key'     => User_Verification_Meta::LINK_SESSION_KEY,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		foreach ( $linked_user_ids as $user_id ) {
			User_Verification_Meta::clear_all( (int) $user_id );
		}
	}

	/**
	 * REST API callback to verify a site code
	 *
	 * This endpoint is called by Create Studio to verify that a user has
	 * access to this WordPress site's plugin settings.
	 *
	 * When verification succeeds, the JWT token sent by Create Studio is stored
	 * for future API calls (nutrition, scraping, etc.).
	 *
	 * @param \WP_REST_Request $request The request object
	 * @return \WP_REST_Response
	 */
	public function verify_site_code( \WP_REST_Request $request ) {
		$submitted_code = $request->get_param( 'code' );
		$token          = $request->get_param( 'token' );
		$user_id        = $request->get_param( 'user_id' );
		$stored_code    = self::get_verification_code();

		// No code configured
		if ( empty( $stored_code ) ) {
			return new \WP_REST_Response(
				[
					'valid' => false,
					'error' => 'Site verification code not configured. Please generate a code in the Create plugin settings.',
				],
				400
			);
		}

		// Verify code using timing-safe comparison
		if ( ! hash_equals( $stored_code, $submitted_code ) ) {
			return new \WP_REST_Response(
				[
					'valid' => false,
					'error' => 'Invalid verification code',
				],
				401
			);
		}

		// Code is valid - store the JWT token from Create Studio
		Settings::create_settings(
			[
				'slug'  => 'mv_create_api_token',
				'value' => $token,
				'group' => 'mv_create_api',
			]
		);

		// Store user ID if provided
		if ( $user_id ) {
			Settings::create_settings(
				[
					'slug'  => 'mv_create_api_user_id',
					'value' => $user_id,
					'group' => 'hidden',
				]
			);
		}

		// Clear the verification code so it can't be reused
		Settings::create_settings(
			[
				'slug'  => self::SETTING_KEY,
				'value' => '',
				'group' => 'hidden',
			]
		);

		// Clear the password reset transient since verification via Create Studio
		// means the user has already set up their account properly
		delete_transient( 'mv_create_needs_password_reset' );

		// Reset cached settings
		Settings::reset_settings();

		// Return site info
		return new \WP_REST_Response(
			[
				'valid'          => true,
				'site_url'       => home_url(),
				'site_name'      => get_bloginfo( 'name' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => phpversion(),
				'create_version' => Plugin::VERSION,
			],
			200
		);
	}
}
