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
		add_filter( 'allowed_http_origin', '__return_true' );
		add_action( 'rest_api_init', [ $this, 'reviews_routes' ] );
		add_action( 'rest_api_init', [ $this, 'review_responses_routes' ] );
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
			return new \WP_Error( 'no_value', __( 'Creation ID was not set in function call', 'mediavine' ), [ 'message' => __( 'A Creation ID was not included in the request', 'mediavine' ) ] );
		}

		if ( ! is_numeric( $creation_id ) ) {
			return new \WP_Error( 'non_numeric', __( 'Creation ID value was not a number', 'mediavine' ), [ 'message' => __( 'A Creation ID variable was included but was non-numeric', 'mediavine' ) ] );
		}

		$limit  = 50;
		$offset = 0;

		if ( isset( $args['limit'] ) ) {
			$limit = $args['limit'];
		}

		if ( isset( $args['offset'] ) ) {
			$limit = $args['offset'];
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
					'methods'             => 'POST',
					'callback'            => [ $this->reviews_api, 'create_reviews' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this->reviews_api, 'read_reviews' ],
					'permission_callback' => '__return_true',
				],
			]
		);

		register_rest_route(
			$route_namespace, '/reviews/(?P<id>\d+)', [
				[
					'methods'             => 'GET',
					'callback'            => [ $this->reviews_api, 'read_single_review' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this->reviews_api, 'update_single_review' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this->reviews_api, 'delete_single_review' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

	}

	function review_responses_routes() {

		$route_namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$route_namespace, '/reviews/(?P<review_id>\d+)/responses', [
				[
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
					'permission_callback' => '__return_true',
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
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/responses/(?P<id>\d+)', [
				[
					'methods'             => 'POST',
					'callback'            => [ $this->review_responses_api, 'update_response' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this->review_responses_api, 'delete_response_api' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return \Mediavine\Permissions::is_user_authorized();
					},
				],
			]
		);

	}

}
