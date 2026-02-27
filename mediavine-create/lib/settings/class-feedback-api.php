<?php
/**
 * Feedback API
 *
 * Proxies error feedback reports from the admin UI to Create Studio.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

class Feedback_API {

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		register_rest_route(
			'mv-create/v1',
			'/studio/feedback',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'submit_feedback' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	public static function submit_feedback( \WP_REST_Request $request ) {
		if ( ! Create_Studio_Client::is_site_connected() ) {
			return new \WP_Error(
				'not_connected',
				__( 'Site is not connected to Create Studio', 'mediavine' ),
				[ 'status' => 400 ]
			);
		}

		$body = [
			'error_message'   => sanitize_text_field( $request->get_param( 'error_message' ) ),
			'stack_trace'     => $request->get_param( 'stack_trace' ),
			'component_stack' => $request->get_param( 'component_stack' ),
			'user_message'    => sanitize_textarea_field( $request->get_param( 'user_message' ) ?? '' ),
			'user_email'      => sanitize_email( $request->get_param( 'user_email' ) ?? '' ),
			'current_url'     => esc_url_raw( $request->get_param( 'current_url' ) ?? '' ),
			'browser_info'    => $request->get_param( 'browser_info' ),
			'create_version'  => \Mediavine\Create\Plugin::VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
		];

		$screenshot = $request->get_param( 'screenshot_base64' );
		if ( ! empty( $screenshot ) ) {
			$body['screenshot_base64'] = $screenshot;
		}

		if ( empty( $body['error_message'] ) ) {
			return new \WP_Error(
				'missing_error_message',
				__( 'Error message is required', 'mediavine' ),
				[ 'status' => 400 ]
			);
		}

		$response = Create_Studio_Client::request( 'POST', '/sites/feedback', $body, null, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['success'] ) ) {
			return new \WP_Error(
				'studio_error',
				__( 'Failed to submit feedback to Create Studio', 'mediavine' ),
				[ 'status' => $response['status_code'] ?? 500 ]
			);
		}

		return rest_ensure_response( $response['data'] );
	}
}
