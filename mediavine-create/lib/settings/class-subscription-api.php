<?php
/**
 * Subscription API — REST endpoint for manually syncing subscription state from Create Studio.
 *
 * Provides a user-invoked alternative to the scheduled sync triggered by
 * GateKeeper::maybe_sync_subscription(). Because this path makes an outbound
 * HTTP call to Create Studio, it bypasses Wordfence rules that can block
 * inbound webhooks from Studio.
 *
 * @package Create
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

		register_rest_route(
			'mv-create/v1',
			'/subscription/tier',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_set_tier' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				'args'                => [
					'tier' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $value ) {
							return in_array( $value, [ GateKeeper::TIER_FREE, GateKeeper::TIER_FREE_PLUS ], true );
						},
					],
				],
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
				'payment_failed'       => GateKeeper::is_payment_failed(),
				'grace_days_remaining' => GateKeeper::get_grace_days_remaining(),
				'cancel_pending'       => GateKeeper::is_cancel_pending(),
				'cancel_plan_name'     => GateKeeper::tier_display_name( GateKeeper::get_effective_tier() ),
				'cancel_date'          => GateKeeper::get_cancel_date_display(),
			],
			200
		);
	}

	/**
	 * Self-serve switch between the two free ad tiers (free <-> free-plus).
	 *
	 * Backs both the connection page's "ads on / off" toggle AND the Create 3.0
	 * plan-choice gate (Plan_Choice): free-plus is the ad-supported plan that
	 * unlocks the recipe engine; free turns ads off (and gives up the premium
	 * recipe features). Either direction is an explicit choice, so a success
	 * response always clears Plan_Choice::OPTION_CHOICE_REQUIRED.
	 *
	 * Unconnected sites may choose EITHER plan locally — no connection needed
	 * either way (Create 3.0 replaced the CRE-296 automatic free-plus floor
	 * with this explicit choice, so there is no longer a one-way gate on which
	 * direction an unconnected site may move).
	 *
	 * Connected sites round-trip through Create Studio (source of truth) first,
	 * then re-sync (retrying once) so local gating reflects the new tier
	 * immediately. Once Create Studio confirms the switch, the response always
	 * reports the REQUESTED tier as authoritative — never the stale pre-switch
	 * tier. If both re-sync attempts fail, the response carries
	 * `sync_pending: true` and a near-term background sync is scheduled to
	 * settle local state; sync_subscription()'s mirror step keeps the recorded
	 * choice in step with whatever Studio ultimately reports.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public static function handle_set_tier( $request ) {
		$tier = $request->get_param( 'tier' );

		if ( ! Create_Studio_Client::is_site_connected() ) {
			$resolved = GateKeeper::set_free_plan_choice( $tier );

			// Report the SAVED choice, not the effective tier: while the site is
			// disconnected Free+ is paused (effective tier is free) but the
			// choice is what the publisher picked and what resumes on connect.
			return new \WP_REST_Response(
				[
					'success'           => true,
					'subscription_tier' => false === $resolved ? $resolved : GateKeeper::get_free_plan_choice(),
					'local_only'        => true,
				],
				200
			);
		}

		$result = Create_Studio_Client::set_subscription_tier( $tier );

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$status = is_array( $result ) && ! empty( $result['status_code'] ) ? (int) $result['status_code'] : 502;
			$error  = is_wp_error( $result )
				? $result->get_error_message()
				: ( isset( $result['data']['error'] ) ? $result['data']['error'] : 'tier_switch_failed' );

			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => $error,
				],
				$status >= 400 ? $status : 502
			);
		}

		// Create Studio has confirmed the switch, so this IS the publisher's
		// choice regardless of whether the re-sync below succeeds. Record it
		// (which also clears the plan-choice gate) so a later disconnect falls
		// back to the same plan; sync_subscription()'s mirror keeps it current.
		GateKeeper::set_free_plan_choice( $tier );

		// Pull authoritative state so local gating + the localized feature_access
		// map reflect the switch on the next paint. Retry once before giving up:
		// Create Studio has already switched the plan at this point, so the
		// response must never fall back to reporting the stale pre-switch tier.
		$synced = GateKeeper::sync_subscription();

		if ( ! $synced ) {
			$synced = GateKeeper::sync_subscription();
		}

		if ( $synced ) {
			return new \WP_REST_Response(
				[
					'success'           => true,
					'subscription_tier' => GateKeeper::get_effective_tier(),
				],
				200
			);
		}

		// Local re-sync still hasn't caught up, but Studio already confirmed the
		// switch — report the tier we KNOW is now correct upstream (not the
		// stale cached one) and flag it as pending. A near-term background sync
		// settles local state instead of waiting out the full subscription TTL.
		if ( ! wp_next_scheduled( 'mv_create_sync_subscription' ) ) {
			wp_schedule_single_event( time(), 'mv_create_sync_subscription' );
		}

		return new \WP_REST_Response(
			[
				'success'           => true,
				'subscription_tier' => $tier,
				'sync_pending'      => true,
			],
			200
		);
	}
}
