<?php
namespace Mediavine\Create;

class Reviews_API extends Reviews {

	private static $min_rating = 4;

	private static $instance = null;

	/**
	 * Get Instance of Object
	 * @return Reviews_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Sanitize parameters
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	function sanitize( $params ) {
		$cleaned = [];
		foreach ( $params as $key => $value ) {
			if ( 'review_content' === $key ) {
				$cleaned[ $key ] = sanitize_textarea_field( $value );
				continue;
			}
			$cleaned[ $key ] = sanitize_text_field( $value );
		}

		return $cleaned;
	}

	/**
	 * Validate reviews to check for missing fields
	 *
	 * @param array $params
	 * @param array $rating_status
	 *
	 * @return array
	 */
	function validate_review( $params, $rating_status ) {

		$more_required = false;
		$error         = false;
		$errors        = [];

		$more_required = $rating_status['more_required'];

		$new_review['rating'] = intval( $params['rating'] * 2 ) / 2;

		if ( isset( $params['creation'] ) ) {
			$new_review['creation'] = $params['creation'];
		} else {
			$error         = true;
			$more_required = true;
			$errors        = $this::$api_services->normalize_errors(
				$errors, 403, [
					'title'   => __( 'Missing required fields', 'mediavine' ),
					'details' => __( 'Through no fault of yours, something is wrong', 'mediavine' ),
				], 'error'
			);
		}

		if ( isset( $params['handshake'] ) ) {
			$new_review['handshake'] = $params['handshake'];
		}

		if ( isset( $params['review_title'] ) ) {
			$new_review['review_title'] = $params['review_title'];
		}

		if ( isset( $params['review_content'] ) ) {
			$new_review['review_content'] = $params['review_content'];
		}

		if ( isset( $params['author_email'] ) ) {
			$is_email = is_email( $params['author_email'] );
			if ( $is_email || $params['rating'] >= self::$min_rating ) {
				$new_review['author_email'] = $is_email;
			} else {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 403, [
						'title'   => __( 'Email is Invalid', 'mediavine' ),
						'details' => __( 'Email address provided is invalid', 'mediavine' ),
					], 'error'
				);
			}
		}

		if ( isset( $params['author_name'] ) ) {
			$new_review['author_name'] = $params['author_name'];
		}

		if ( $more_required ) {

			if ( is_numeric( $new_review['author_name'] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Name cannot be a number', 'mediavine' ),
						'details' => __( 'Name of author cannot be a number', 'mediavine' ),
					], 'error'
				);
			}

			if ( ! isset( $new_review['author_name'] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Name is Required', 'mediavine' ),
						'details' => __( 'Name is required for reviews less than 4 Stars', 'mediavine' ),
					], 'error'
				);
			}

			if ( ! isset( $new_review['author_email'] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Email is Required', 'mediavine' ),
						'details' => __( 'Email is required for reviews less than 4 Stars', 'mediavine' ),
					], 'error'
				);
			}

			if ( ! isset( $new_review['review_title'] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Title is Required', 'mediavine' ),
						'details' => __( 'Title is required for reviews less than 4 Stars', 'mediavine' ),
					], 'error'
				);
			}

			if ( ! isset( $new_review['review_content'] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Message is Required', 'mediavine' ),
						'details' => __( 'Message is required for reviews less than 4 Stars', 'mediavine' ),
					], 'error'
				);
			}
		}

		return [
			'review' => $new_review,
			'error'  => $error,
			'errors' => $errors,
		];

	}

	/**
	 * Check rating status, and return appropriate message if not valid
	 * @param $params
	 *
	 * @return array|bool[]
	 */
	function resolve_rating_status( $params ) {
		if ( ! isset( $params['rating'] ) ) {
			return [
				'ok'            => false,
				'response'      => [
					'error' => 'Missing Required Rating',
				],
				'status'        => 400,
				'more_required' => false,
			];
		}

		if ( ! is_numeric( $params['rating'] ) ) {
			return [
				'ok'            => false,
				'response'      => [
					'error' => 'Rating Must Be A Number',
				],
				'status'        => 400,
				'more_required' => false,
			];
		}

		$rating = intval( $params['rating'] * 2 ) / 2;

		// If rating outside allowed ratings
		if ( ( 0.5 > $rating ) || ( 5 < $rating ) ) {
			return [
				'ok'            => false,
				'response'      => [
					'error' => 'Invalid Value for Rating',
				],
				'status'        => 400,
				'more_required' => false,
			];
		}

		// Set ratings autosubmit threshold
		$ratings_submit_threshold = apply_filters( 'mv_create_ratings_submit_threshold', 4 );

		// If prompt threshold is less than autosubmit, make sure we use the prompt instead
		$ratings_prompt_threshold = apply_filters( 'mv_create_ratings_prompt_threshold', 4 );
		if ( $ratings_prompt_threshold < $ratings_submit_threshold ) {
			$ratings_submit_threshold = $ratings_prompt_threshold;
		}

		if ( ( $ratings_submit_threshold <= $rating ) && ( 5.5 > $rating ) ) {
			return [
				'ok'            => true,
				'more_required' => false,
			];
		}

		if ( ( 0 < $rating ) && ( $ratings_submit_threshold > $rating ) ) {
			return [
				'ok'            => true,
				'more_required' => true,
			];
		}

	}

	/**
	 * Submit a review for content through the API
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	function create_reviews( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$sanitized = $request->sanitize_params();
		if ( is_wp_error( $sanitized ) ) {
			$status_code        = 403;
			$response['errors'] = $this::$api_services->normalize_errors(
				$response['errors'], $status_code, [
					'title'   => __( 'Unsafe Content Submission', 'mediavine' ),
					'details' => __( 'You\'re submission includes unsafe characters', 'mediavine' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		$params = $this::$api_services->process_inbound( $request );
		$params = $this->sanitize( $params );

		// TODO: change recipe_id param to creation so we can remove this
		if ( empty( $params['creation'] ) && ! empty( $params['recipe_id'] ) ) {
			$params['creation'] = $params['recipe_id'];
		}

		$rating_status = $this->resolve_rating_status( $params );

		if ( ! $rating_status['ok'] ) {
			return new \WP_REST_Response( $rating_status['response'], $rating_status['status'] );
		}

		$result = $this->validate_review( $params, $rating_status );

		$new_review = $result['review'];
		$error      = $result['error'];
		$errors     = $result['errors'];

		if ( ! $error ) {
			$inserted = self::$models->reviews->insert( $new_review );

			if ( $inserted ) {

				$this->Reviews->update_creation_rating( $inserted );
				$response    = [];
				$response    = $this::$api_services->prepare_item_for_response( $inserted, $request );
				$status_code = 201;
			}
		}

		if ( $error ) {
			$status_code        = 403;
			$response['errors'] = $errors;
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	function is_authorized_review_update( $params ) {
		// Any authorized users are safe to edit
		if ( \Mediavine\Permissions::is_user_authorized() ) {
			return true;
		}

		// Get current review to check handshake
		$review = self::$models_v2->mv_reviews->select_one( (int) $params['id'] );
		$handshake = ( ! empty( $review->handshake ) ) ? (int) $review->handshake : false;

		if ( !empty( $params['handshake'] ) && (int) $params['handshake'] === $handshake ) {
			return true;
		}

		return false;
	}

	/**
	 * Update a single review
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	function update_single_review( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$sanitized = $request->sanitize_params();
		if ( is_wp_error( $sanitized ) ) {
			$status_code        = 403;
			$response['errors'] = $this::$api_services->normalize_errors(
				$response['errors'], $status_code, [
					'title'   => __( 'Unsafe Content Submission', 'mediavine' ),
					'details' => __( 'You\'re submission includes unsafe characters', 'mediavine' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		$params = $this::$api_services->process_inbound( $request );
		$params = $this->sanitize( $params );

		// Is this an authorized edit request?
		if ( ! $this->is_authorized_review_update( $params ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to edit this review.', 'mediavine' ),
				[ 'status'  => 401 ]
			);
		}

		// This accounts for old versions of create where we used recipe_id
		if ( empty( $params['creation'] ) && ! empty( $params['recipe_id'] ) ) {
			$params['creation'] = $params['recipe_id'];
		}

		$rating_status = $this->resolve_rating_status( $params );

		if ( ! $rating_status['ok'] ) {
			return new \WP_REST_Response( $rating_status['response'], $rating_status['status'] );
		}

		$result = $this->validate_review( $params, $rating_status );

		$new_review = $result['review'];
		$error      = $result['error'];
		$errors     = $result['errors'];

		if ( ! $error ) {
			$updated = $this->Reviews->update( $params );

			if ( $updated ) {
				$updated->updated = true;
				$this->Reviews->update_creation_rating( $updated );
				$response    = [];
				$response    = $this::$api_services->prepare_item_for_response( $updated, $request );
				$status_code = 200;
			}
		}

		if ( $error ) {
			$status_code        = 403;
			$response['errors'] = $errors;
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	/**
	 * Checks if a creation has a publicly viewable post associated with it.
	 *
	 * @param object Creation data
	 * @return boolean
	 */
	public function has_public_associated_post( $creation ) {
		if ( empty( $creation->associated_posts ) ) {
			return false;
		}

		$associated_posts = json_decode( $creation->associated_posts );
		if ( ! is_iterable( $associated_posts ) ) {
			return false;
		}

		foreach ( $associated_posts as $associated_post ) {
			if ( is_post_publicly_viewable( $associated_post ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get reviews for a given card
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	function read_reviews( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params = $request->get_params();

		// Perform checks for public REST API requests
		if ( ! \Mediavine\Permissions::is_user_authorized() ) {
			// All reviews should never be publicly available
			if ( empty( floatval( $params['creation'] ) ) ) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'You are not allowed to view all reviews.', 'mediavine' ),
					[ 'status'  => 401 ]
				);
			}

			// Make sure that our creation has a least one public associated post
			$creation = self::$models_v2->mv_creations->select_one( (int) $params['creation'] );
			if ( ! $this->has_public_associated_post( $creation ) ) {
				return new \WP_Error(
					'creation_not_public',
					__( 'This creation is not associated with a public post.', 'mediavine' ),
					[ 'status'  => 401 ]
				);
			}
		}

		$search = null;
		if ( ! empty( $params['search'] ) ) {
			$search = $params['search'];
		}

		$query_args = [];

		$allowed_params = [
			'creation',
			'rating',
		];

		foreach ( $params as $param => $value ) {
			if ( in_array( $param, $allowed_params, true ) ) {
				$query_args['where'][ $param ] = floatval( $value );
			}
		}

		if ( ! empty( $params['limit'] ) ) {
			$limit               = sanitize_text_field( $params['limit'] );
			$query_args['limit'] = $limit;
		}

		if ( isset( $params['page'] ) ) {
			$page = intval( sanitize_text_field( $params['page'] ) );
			if ( empty( $limit ) ) {
				$limit = get_option( 'posts_per_page' );
			}
			if ( 1 < $page ) {
				$page_offset          = $limit * ( $page - 1 );
				$query_args['offset'] = $page_offset;
			}
		}

		if ( isset( $params['offset'] ) ) {
			$offset               = sanitize_text_field( $params['offset'] );
			$query_args['offset'] = $offset;
		}

		$query_args['order_by'] = 'modified';
		$query_args['order']    = 'DESC';

		$reviews = $this->Reviews->find( $query_args, $search );

		if ( is_array( $reviews ) ) {

			$response = [];

			$response['links'] = $this::$api_services->prepare_collection_links( $request );

			$response = [];
			foreach ( $reviews as $review ) {
				$relationships = [];
				if ( isset( $review->creation ) ) {
					$relationships[] = self::$models_v2->mv_creations->select_one( $review->creation );
				}

				$review->review_title   = wp_kses( $review->review_title, [] );
				$review->review_content = wp_kses( $review->review_content, [] );
				$review->author_email   = wp_kses( $review->author_email, [] );
				$review->author_name    = wp_kses( $review->author_name, [] );
				$review->type           = wp_kses( $review->type, [] );

				// The email should never be publicly available
				if ( ! \Mediavine\Permissions::is_user_authorized() ) {
					unset( $review->author_email );
				}

				// Do not display handshake. Ever.
				unset( $review->handshake );

				$response[] = $this::$api_services->prepare_item_for_response( $review, $request, $relationships );
			}

			$status_code = 200;

		}

		$response = new \WP_REST_Response( $response, $status_code );
		$response->header( 'X-Total-Items', $this->Reviews->get_count( $query_args, $search ) );
		return $response;
	}

	/**
	 * Get a single review
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	function read_single_review( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params = $request->get_params();

		$review = self::$models->reviews->select_one_by_id( $params['id'] );

		$review->review_title   = wp_kses( $review->review_title, [] );
		$review->review_content = wp_kses( $review->review_content, [] );
		$review->author_email   = wp_kses( $review->author_email, [] );
		$review->author_name    = wp_kses( $review->author_name, [] );
		$review->type           = wp_kses( $review->type, [] );

		// The email should never be publicly available
		if ( ! \Mediavine\Permissions::is_user_authorized() ) {
			unset( $review->author_email );
		}

		// Do not display handshake. Ever.
		unset( $review->handshake );

		if ( $review ) {
			$response    = [];
			$response    = $this::$api_services->prepare_item_for_response( $review, $request );
			$status_code = 200;
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	/**
	 * Delete a single review
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	function delete_single_review( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params    = $request->get_params();
		$review_id = intval( $params['id'] );
		$review    = self::$models->reviews->select_one_by_id( $review_id );
		$deleted   = self::$models->reviews->delete_by_id( $review_id );

		if ( $deleted ) {
			$this->Reviews->update_creation_rating( $review );
			$response    = [];
			$status_code = 204;
		}

		if ( ! $deleted ) {
			return new \WP_Error( 409, __( 'Entry Could Not Be Deleted', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the single review could not be deleted', 'mediavine' ) ] );
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	function init() {
		$this->Reviews = new Reviews_Models();
	}

}

