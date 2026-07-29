<?php
/**
 * Site Verification Code functionality for Create Studio connection
 *
 * This class handles the generation and verification of site verification codes
 * used to connect WordPress sites to Create Studio.
 *
 * @package Mediavine\Create
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
					'code'  => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'token' => [
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
				],
				200
			);
		}

		return new \WP_REST_Response( $status, 200 );
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

		// Generate a one-time connect token
		$token = wp_generate_password( 64, false, false );

		// Store in transient with 10-minute TTL
		set_transient( 'mv_create_connect_token', $token, 600 );

		// Build the Studio connect URL
		$studio_base_url = Create_Studio_Client::get_studio_base_url();
		$site_url        = home_url();
		$return_url      = $request->get_param( 'return_url' );
		if ( empty( $return_url ) || strpos( $return_url, admin_url() ) !== 0 ) {
			$return_url = admin_url( 'edit.php?post_type=mv_create&page=settings#create-studio' );
		}

		$connect_url = add_query_arg(
			[
				'site_url'   => rawurlencode( $site_url ),
				'token'      => rawurlencode( $token ),
				'return_url' => rawurlencode( $return_url ),
			],
			$studio_base_url . '/auth/connect'
		);

		return new \WP_REST_Response(
			[
				'status'      => 'redirect',
				'connect_url' => $connect_url,
			],
			200
		);
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
	 * the API token, user ID, and email confirmation settings locally.
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

		// Reset subscription tier to free (prevents stale tier from keeping features unlocked)
		Settings::create_settings(
			[
				'slug'  => GateKeeper::SETTING_SUBSCRIPTION_TIER,
				'value' => GateKeeper::TIER_FREE,
				'group' => 'mv_create_subscription',
			]
		);

		// Clear subscription sync timestamp
		Settings::create_settings(
			[
				'slug'  => GateKeeper::SETTING_SUBSCRIPTION_SYNCED_AT,
				'value' => '',
				'group' => 'mv_create_subscription',
			]
		);

		// Enforce feature fallbacks now that tier is free
		GateKeeper::enforce_feature_fallbacks();

		// Clear transients
		delete_transient( 'mv_create_needs_password_reset' );

		// Reset cached settings
		Settings::reset_settings();

		wp_send_json_success( [ 'message' => 'Disconnected from Create Studio' ] );
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
