<?php
/**
 * Trial Extension API — REST proxy for extending Pro trial via Create Studio.
 *
 * @package Mediavine\Create
 * @since 2.0.0
 */

namespace Mediavine\Create;

/**
 * Registers REST route for trial extension and proxies to Create Studio.
 */
class Trial_API {

	/**
	 * Allowed trial extension steps.
	 *
	 * @var array
	 */
	const ALLOWED_STEPS = [
		'servings_adjustment',
		'unit_conversion',
		'checklists',
		'toolbar_layout',
		'bulk_import',
		'review_management',
		'premium_theme',
	];

	/**
	 * Initialize the trial API by registering REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the trial extension REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			'mv-create/v1',
			'/trial/extend',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_extend' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				'args'                => [
					'step' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return in_array( $value, self::ALLOWED_STEPS, true );
						},
					],
				],
			]
		);
	}

	/**
	 * Handle the trial extension request by proxying to Create Studio.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response The response.
	 */
	public static function handle_extend( $request ) {
		$step = $request->get_param( 'step' );

		if ( ! GateKeeper::is_trialing() ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'error' => 'No active trial' ],
				400
			);
		}

		$response = Create_Studio_Client::extend_trial( $step );

		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'error' => $response->get_error_message() ],
				500
			);
		}

		if ( ! empty( $response['success'] ) && ! empty( $response['data'] ) ) {
			$data = $response['data'];

			// Defer sync to avoid blocking the REST response with an outbound HTTP call.
			if ( isset( $data['days_remaining'] ) && ! wp_next_scheduled( 'mv_create_sync_subscription' ) ) {
				wp_schedule_single_event( time(), 'mv_create_sync_subscription' );
			}

			return new \WP_REST_Response( [
				'success'          => true,
				'new_trial_end'    => isset( $data['new_trial_end'] ) ? $data['new_trial_end'] : '',
				'days_remaining'   => isset( $data['days_remaining'] ) ? (int) $data['days_remaining'] : 0,
				'extensions_used'  => isset( $data['extensions_used'] ) ? (int) $data['extensions_used'] : 0,
			], 200 );
		}

		$error = 'Unknown error';
		if ( ! empty( $response['data']['error'] ) ) {
			$error = $response['data']['error'];
		}

		return new \WP_REST_Response(
			[ 'success' => false, 'error' => $error ],
			400
		);
	}

	/**
	 * Programmatically extend trial for a step (called from GateKeeper hooks).
	 *
	 * @param string $step One of the ALLOWED_STEPS.
	 * @return bool True on success.
	 */
	public static function handle_extend_step( $step ) {
		if ( ! in_array( $step, self::ALLOWED_STEPS, true ) ) {
			return false;
		}

		if ( ! GateKeeper::is_trialing() ) {
			return false;
		}

		$response = Create_Studio_Client::extend_trial( $step );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Always sync — even on 409 "already redeemed" the extension exists
		// on Studio and we need it reflected locally.
		if ( ! wp_next_scheduled( 'mv_create_sync_subscription' ) ) {
			wp_schedule_single_event( time(), 'mv_create_sync_subscription' );
		}

		return ! empty( $response['success'] ) || ! empty( $response['data']['success'] );
	}
}
