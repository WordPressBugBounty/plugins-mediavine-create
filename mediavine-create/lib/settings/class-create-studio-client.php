<?php
/**
 * Create Studio API Client
 *
 * This class handles HTTP communication with the Create Studio API
 * for user verification endpoints.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * Create Studio API Client
 *
 * Provides methods for communicating with Create Studio API endpoints
 * related to user verification.
 */
class Create_Studio_Client {

	/**
	 * Default request timeout in seconds
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Get the site API token from settings.
	 *
	 * @return string|null The site API token or null if not set.
	 */
	public static function get_site_token() {
		$token_setting = Settings::get_settings( 'mv_create_api_token' );

		if ( $token_setting && ! empty( $token_setting->value ) ) {
			return $token_setting->value;
		}

		return null;
	}

	/**
	 * Extract the site ID from the JWT token.
	 *
	 * @param string|null $token The JWT token. Defaults to site token.
	 * @return string|null The site ID or null if not found.
	 */
	public static function get_site_id( $token = null ) {
		$token = $token ?? self::get_site_token();

		if ( empty( $token ) ) {
			return null;
		}

		$token_parts = explode( '.', $token );

		if ( empty( $token_parts[1] ) ) {
			return null;
		}

		$payload = json_decode( base64_decode( $token_parts[1] ), true );

		return $payload['site_id'] ?? null;
	}

	/**
	 * Get the base API URL for Create Studio.
	 *
	 * @return string The API base URL.
	 */
	public static function get_api_url() {
		return Plugin::$services_api_url;
	}

	/**
	 * Make an HTTP request to Create Studio API.
	 *
	 * @param string $method   HTTP method (GET, POST, DELETE).
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body data.
	 * @param string $token    Authorization token. Defaults to site token.
	 * @param array  $options  Optional request options. Supports 'timeout' key.
	 * @return array|\WP_Error Response array with 'success', 'data', 'status_code' keys, or \WP_Error.
	 */
	public static function request( $method, $endpoint, $body = [], $token = null, $options = [] ) {
		$token = $token ?? self::get_site_token();

		if ( empty( $token ) ) {
			return new \WP_Error(
				'no_token',
				__( 'Site is not connected to Create Studio', 'mediavine' ),
				[ 'status' => 401 ]
			);
		}

		$url = self::get_api_url() . $endpoint;

		$args = [
			'method'  => strtoupper( $method ),
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'timeout' => ! empty( $options['timeout'] ) ? $options['timeout'] : self::DEFAULT_TIMEOUT,
		];

		// Disable SSL verification for local .test domains (Caddy local CA).
		if ( str_contains( $url, '.test/' ) ) {
			$args['sslverify'] = false;
		}

		if ( ! empty( $body ) && in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		return [
			'success'     => $status_code >= 200 && $status_code < 300,
			'data'        => $data,
			'status_code' => $status_code,
		];
	}

	/**
	 * Disconnect user from Create Studio.
	 *
	 * DELETE /api/sites/{site_id}/users/verify
	 * Uses user token for authentication.
	 *
	 * @param string $user_token The user-specific API token.
	 * @return array|\WP_Error Response data or \WP_Error.
	 */
	public static function disconnect_user( $user_token ) {
		$site_id = self::get_site_id();

		if ( empty( $site_id ) ) {
			return new \WP_Error(
				'no_site_id',
				__( 'Could not determine site ID from token', 'mediavine' ),
				[ 'status' => 400 ]
			);
		}

		$endpoint = '/sites/' . $site_id . '/users/verify';

		return self::request( 'DELETE', $endpoint, [], $user_token );
	}

	/**
	 * Create a link session for redirect-based user verification.
	 *
	 * POST /sites/{site_id}/auth/link
	 *
	 * @param string $return_url The WP admin URL to redirect back to.
	 * @return array|\WP_Error Response data or \WP_Error.
	 */
	public static function create_link_session( $return_url ) {
		$site_id = self::get_site_id();

		if ( empty( $site_id ) ) {
			return new \WP_Error(
				'no_site_id',
				__( 'Could not determine site ID from token', 'mediavine' ),
				[ 'status' => 400 ]
			);
		}

		$endpoint = '/sites/' . $site_id . '/auth/link';

		return self::request(
			'POST',
			$endpoint,
			[
				'return_url' => $return_url,
			]
		);
	}

	/**
	 * Exchange a completed link session for the user token.
	 *
	 * POST /sites/{site_id}/auth/link/exchange
	 *
	 * @param string $session_id The link session ID.
	 * @return array|\WP_Error Response data or \WP_Error.
	 */
	public static function exchange_link_session( $session_id ) {
		$site_id = self::get_site_id();

		if ( empty( $site_id ) ) {
			return new \WP_Error(
				'no_site_id',
				__( 'Could not determine site ID from token', 'mediavine' ),
				[ 'status' => 400 ]
			);
		}

		$endpoint = '/sites/' . $site_id . '/auth/link/exchange';

		return self::request(
			'POST',
			$endpoint,
			[
				'session_id' => $session_id,
			]
		);
	}

	/**
	 * Generate SSO URL for user to access Create Studio.
	 *
	 * POST /api/auth/sso
	 * Uses user token for authentication.
	 *
	 * @param string      $user_token The user-specific API token.
	 * @param string|null $return_url Optional return URL path within Create Studio.
	 * @return array|\WP_Error Response data or \WP_Error.
	 */
	public static function generate_sso_url( $user_token, $return_url = null ) {
		// Build default return URL if not provided
		if ( empty( $return_url ) ) {
			$return_url = '/admin';
		}

		$body = [];
		if ( ! empty( $return_url ) ) {
			$body['return_url'] = $return_url;
		}

		return self::request( 'POST', '/auth/sso', $body, $user_token );
	}

	/**
	 * Resend Studio email verification for a linked user.
	 *
	 * POST /auth/resend-site-user-verification
	 * Uses user token for authentication.
	 *
	 * @param string $user_token The user-specific API token.
	 * @return array|\WP_Error Response data or \WP_Error.
	 */
	public static function resend_email_verification( $user_token ) {
		return self::request( 'POST', '/auth/resend-site-user-verification', [], $user_token );
	}

	/**
	 * Check the current email verification status from Studio.
	 *
	 * GET /auth/resend-site-user-verification
	 * Uses user token for authentication.
	 *
	 * @param string $user_token The user-specific API token.
	 * @return array|\WP_Error Response data or \WP_Error.
	 */
	public static function check_email_verification_status( $user_token ) {
		return self::request( 'GET', '/auth/resend-site-user-verification', [], $user_token );
	}

	/**
	 * Check site connection status with Create Studio.
	 *
	 * GET /sites/status
	 * Uses the site JWT token for authentication.
	 *
	 * @return array|false Array with 'connected', 'subscription_tier', 'site_id' keys, or false on failure.
	 */
	public static function check_site_status() {
		$response = self::request( 'GET', '/sites/status' );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( empty( $response['success'] ) || empty( $response['data'] ) ) {
			return false;
		}

		$data = $response['data'];

		return [
			'connected'              => ! empty( $data['connected'] ),
			'subscription_tier'      => isset( $data['subscription_tier'] ) ? $data['subscription_tier'] : 'free',
			'site_id'                => isset( $data['site_id'] ) ? (int) $data['site_id'] : 0,
			'site_url'               => isset( $data['site_url'] ) ? $data['site_url'] : '',
			'site_name'              => isset( $data['site_name'] ) ? $data['site_name'] : '',
			'active_paid_count'      => isset( $data['active_paid_count'] ) ? (int) $data['active_paid_count'] : 0,
			'total_site_count'       => isset( $data['total_site_count'] ) ? (int) $data['total_site_count'] : 1,
			'is_trialing'            => ! empty( $data['is_trialing'] ),
			'trial_days_remaining'   => isset( $data['trial_days_remaining'] ) ? (int) $data['trial_days_remaining'] : 0,
			'trial_end'              => isset( $data['trial_end'] ) ? $data['trial_end'] : '',
			'trial_extensions'       => isset( $data['trial_extensions'] ) ? $data['trial_extensions'] : [],
			'trial_eligible'         => ! empty( $data['trial_eligible'] ),
		];
	}

	/**
	 * Extend the trial by completing an onboarding step.
	 *
	 * POST /subscriptions/trial-extend
	 *
	 * @param string $step The onboarding step identifier.
	 * @return array|\WP_Error Response data or \WP_Error.
	 */
	public static function extend_trial( $step ) {
		$site_id = self::get_site_id();
		if ( empty( $site_id ) ) {
			return new \WP_Error( 'no_site_id', 'Site is not connected' );
		}

		return self::request( 'POST', '/subscriptions/trial-extend', [
			'siteId' => $site_id,
			'step'   => $step,
		] );
	}

	/**
	 * Check if the site is connected to Create Studio.
	 *
	 * @return bool True if site has a valid API token.
	 */
	public static function is_site_connected() {
		return ! empty( self::get_site_token() );
	}

	/**
	 * Get Create Studio base URL for user-facing links.
	 *
	 * @return string The Create Studio base URL.
	 */
	public static function get_studio_base_url() {
		return Plugin::$create_studio_base_url;
	}

}
