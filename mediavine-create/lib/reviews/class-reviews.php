<?php
namespace Mediavine\Create;

/**
 * DBI function and Routes for Reviews class
 */
class Reviews extends Plugin {

	public $review_table = 'mv_reviews';

	public $reviews_api = null;

	public $review_responses_api = null;

	function init() {
		$this->reviews_api = Reviews_API::get_instance();
		$this->reviews_api->init();
		$this->review_responses_api = Review_Responses_API::get_instance();
		$this->review_responses_api->init();
		add_filter( 'rest_pre_serve_request', [ $this, 'send_review_cors_headers' ], 10, 3 );
		add_action( 'rest_api_init', [ $this, 'reviews_routes' ] );
		add_action( 'rest_api_init', [ $this, 'review_responses_routes' ] );
	}

	/**
	 * Send narrowly-scoped CORS headers for the public review routes only.
	 *
	 * Replaces the former site-wide `add_filter( 'allowed_http_origin', '__return_true' )`
	 * override, which forced WordPress's origin allowlist to pass for *every* REST
	 * route and — combined with `Access-Control-Allow-Credentials: true` — let any
	 * third-party page make credentialed cross-origin reads against endpoints that
	 * don't independently check nonces.
	 *
	 * Review submission and reading is public and unauthenticated, so a review left
	 * from a cached/AMP page served on a different origin still works. Crucially we
	 * do NOT emit `Access-Control-Allow-Credentials`, so this only ever exposes the
	 * already-public review data, and only for the review routes.
	 *
	 * @param bool                       $served  Whether the request has already been served.
	 * @param \WP_HTTP_Response|mixed    $result  Result to send to the client.
	 * @param \WP_REST_Request|mixed     $request Request used to generate the response.
	 * @return bool
	 */
	function send_review_cors_headers( $served, $result, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $served;
		}

		$reviews_prefix = '/' . $this->api_route . '/' . $this->api_version . '/reviews';
		if ( 0 !== strpos( (string) $request->get_route(), $reviews_prefix ) ) {
			return $served;
		}

		$origin = get_http_origin();
		if ( empty( $origin ) ) {
			return $served;
		}

		// Public data only — reflect the origin but never allow credentials.
		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
		header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-WP-Nonce' );
		header( 'Vary: Origin', false );

		return $served;
	}

	/**
	 * Get the reviews for a given creation.
	 *
	 * @param int   $creation_id the ID of the Create card
	 * @param array $args optional limit and offset values for the query
	 * @return array $reviews the results of the reviews query
	 */
	public static function get_reviews( $creation_id, $args = [] ) {
		if ( ! isset( $creation_id ) ) {
			return new \WP_Error( 'no_value', __( 'Creation ID was not set in function call', 'mediavine-create' ), [ 'message' => __( 'A Creation ID was not included in the request', 'mediavine-create' ) ] );
		}

		if ( ! is_numeric( $creation_id ) ) {
			return new \WP_Error( 'non_numeric', __( 'Creation ID value was not a number', 'mediavine-create' ), [ 'message' => __( 'A Creation ID variable was included but was non-numeric', 'mediavine-create' ) ] );
		}

		$limit  = 50;
		$offset = 0;

		if ( isset( $args['limit'] ) ) {
			$limit = $args['limit'];
		}

		if ( isset( $args['offset'] ) ) {
			$offset = $args['offset'];
		}

		$reviews = self::$models_v2->mv_reviews->find(
			[
				'limit'  => $limit,
				'offset' => $offset,
				'where'  => [
					'creation' => $creation_id,
				],
			]
		);

		return $reviews;
	}

	/** Doc block for function review_routes */
	function reviews_routes() {

		$route_namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$route_namespace, '/reviews', [
				[
					// Public by design: visitor ratings/reviews. Rate-limited + validated in create_reviews.
					'methods'             => 'POST',
					'callback'            => [ $this->reviews_api, 'create_reviews' ],
					'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ],
				],
				[
					// Public read; read_reviews still restricts listing without a public creation.
					'methods'             => 'GET',
					'callback'            => [ $this->reviews_api, 'read_reviews' ],
					'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ],
				],
			]
		);

		register_rest_route(
			$route_namespace, '/reviews/(?P<id>\d+)', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this->reviews_api, 'read_single_review' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
				[
					// Mutating: require Create capability or matching per-review handshake token.
					'methods'             => 'POST',
					'callback'            => [ $this->reviews_api, 'update_single_review' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ $this->reviews_api, 'can_update_single_review' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this->reviews_api, 'delete_single_review' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

	}

	function review_responses_routes() {

		$route_namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$route_namespace, '/reviews/(?P<review_id>\d+)/responses', [
				[
					// Public read of responses attached to a review.
					'methods'             => 'GET',
					'callback'            => [ $this->review_responses_api, 'get_review_responses' ],
					'args'                => [
						'review_id' => [
							'required'          => true,
							'validate_callback' => function( $param ) {
								return is_numeric( $param );
							},
						],
					],
					'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this->review_responses_api, 'create_response_api' ],
					'args'                => [
						'review_id' => [
							'required'          => true,
							'validate_callback' => function( $param ) {
								return is_numeric( $param );
							},
						],
					],
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		register_rest_route(
			$route_namespace, '/responses/(?P<id>\d+)', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this->review_responses_api, 'update_response' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this->review_responses_api, 'delete_response_api' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

	}

}
