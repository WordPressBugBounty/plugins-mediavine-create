<?php
/**
 * Subscription API — REST endpoint for manually syncing subscription state from Create Studio.
 *
 * Provides a user-invoked alternative to the scheduled sync triggered by
 * GateKeeper::maybe_sync_subscription(). Because this path makes an outbound
 * HTTP call to Create Studio, it bypasses Wordfence rules that can block
 * inbound webhooks from Studio.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

/**
 * Registers the /subscription/sync REST route.
 */
class Subscription_API {

	/**
	 * Initialize by registering the REST route.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the subscription sync REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			'mv-create/v1',
			'/subscription/sync',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_sync' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);
	}

	/**
	 * Run GateKeeper::sync_subscription() synchronously and return the result.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public static function handle_sync( $request ) {
		if ( ! Create_Studio_Client::is_site_connected() ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => 'not_connected',
				],
				400
			);
		}

		$synced = GateKeeper::sync_subscription();

		if ( ! $synced ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => 'sync_failed',
				],
				502
			);
		}

		return new \WP_REST_Response(
			[
				'success'              => true,
				'subscription_tier'    => GateKeeper::get_subscription_tier(),
				'is_trialing'          => GateKeeper::is_trialing(),
				'trial_days_remaining' => GateKeeper::get_trial_days_remaining(),
			],
			200
		);
	}
}
