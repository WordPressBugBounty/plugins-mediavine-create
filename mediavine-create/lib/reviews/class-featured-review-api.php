<?php
namespace Mediavine\Create;

/**
 * Featured Review REST API
 *
 * Provides REST endpoints for managing featured reviews:
 * - PUT /mv-create/v1/reviews/{id}/featured - Toggle featured status
 * - GET /mv-create/v1/creations/{id}/featured-review - Get featured review for a card
 */
class Featured_Review_API extends Plugin {

	/**
	 * @var Featured_Review_API
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Featured_Review_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		$route_namespace = $this->api_route . '/' . $this->api_version;

		// PUT /reviews/{id}/featured - Toggle featured status
		register_rest_route(
			$route_namespace,
			'/reviews/(?P<id>\d+)/featured',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_featured_status' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => function( $param ) {
								return is_numeric( $param );
							},
						],
						'is_featured' => [
							'required'          => true,
							'validate_callback' => function( $param ) {
								return is_bool( $param ) || in_array( $param, [ 'true', 'false', '1', '0', 1, 0 ], true );
							},
							'sanitize_callback' => function( $param ) {
								if ( is_bool( $param ) ) {
									return $param;
								}
								return in_array( $param, [ 'true', '1', 1 ], true );
							},
						],
					],
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		// GET /creations/{id}/featured-review - Get featured review for a card
		register_rest_route(
			$route_namespace,
			'/creations/(?P<id>\d+)/featured-review',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_featured_review' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => function( $param ) {
								return is_numeric( $param );
							},
						],
					],
					// Public read of the featured review for a card.
					'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ],
				],
			]
		);
	}

	/**
	 * Handle PUT request to update featured status.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_featured_status( \WP_REST_Request $request ) {
		$review_id   = absint( $request->get_param( 'id' ) );
		$is_featured = $request->get_param( 'is_featured' );

		// Check if featured reviews feature is gated (Pro feature)
		if ( ! GateKeeper::can_access( GateKeeper::FEATURE_REVIEW_FEATURED ) ) {
			return new \WP_Error(
				'feature_gated',
				__( 'Featured reviews require a Pro subscription', 'mediavine-create' ),
				[
					'status'      => 403,
					'upgrade_url' => GateKeeper::get_upgrade_url(),
				]
			);
		}

		// Check if review exists
		if ( ! Featured_Review::review_exists( $review_id ) ) {
			return new \WP_Error(
				'rest_review_not_found',
				__( 'Review not found.', 'mediavine-create' ),
				[ 'status' => 404 ]
			);
		}

		// Get the review to find its card
		$review = self::$models_v2->mv_reviews->select_one( $review_id );

		// Get current featured review ID (for auto-replace response)
		$previous_featured_id = null;
		if ( $is_featured ) {
			$previous_featured_id = Featured_Review::get_current_featured_id( $review->creation );
			// Don't report previous if it's the same review
			if ( $previous_featured_id === $review_id ) {
				$previous_featured_id = null;
			}
		}

		// Update featured status
		if ( $is_featured ) {
			$result = Featured_Review::mark_as_featured( $review_id );
		} else {
			$result = Featured_Review::unfeature_review( $review_id );
		}

		if ( ! $result ) {
			return new \WP_Error(
				'rest_update_failed',
				__( 'Failed to update featured status.', 'mediavine-create' ),
				[ 'status' => 500 ]
			);
		}

		$response_data = [
			'success' => true,
			'data'    => [
				'review_id'   => $review_id,
				'is_featured' => $is_featured,
			],
		];

		// Include previous featured ID if there was one
		if ( $previous_featured_id ) {
			$response_data['data']['previous_featured_id'] = $previous_featured_id;
		}

		return new \WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Handle GET request for featured review.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_featured_review( \WP_REST_Request $request ) {
		$card_id = absint( $request->get_param( 'id' ) );

		// CVE-2026-16992: a featured review is only public once its parent card is.
		// An empty body is the same shape as "this card has no featured
		// review", so an anonymous caller learns nothing about the draft.
		$creation = self::$models_v2->mv_creations->find_one( $card_id );
		if ( empty( $creation ) || ( empty( $creation->published ) && ! \Mediavine\Permissions::is_user_authorized() ) ) {
			return new \WP_REST_Response( null, 200 );
		}

		// Get featured review
		$review = Featured_Review::get_featured_for_card( $card_id );

		if ( ! $review ) {
			return new \WP_REST_Response( null, 200 );
		}

		// Prepare response data
		$response_data = [
			'id'             => $review->id,
			'creation'       => $review->creation,
			'author_name'    => wp_kses( $review->author_name, [] ),
			'review_title'   => wp_kses( $review->review_title, [] ),
			'review_content' => wp_kses( $review->review_content, [] ),
			'rating'         => floatval( $review->rating ),
			'is_featured'    => (bool) $review->is_featured,
			'created'        => $review->created,
			'modified'       => $review->modified,
		];

		// Only include email for authorized users
		if ( \Mediavine\Permissions::is_user_authorized() ) {
			$response_data['author_email'] = wp_kses( $review->author_email, [] );
		}

		return new \WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Initialize the API.
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}
}
