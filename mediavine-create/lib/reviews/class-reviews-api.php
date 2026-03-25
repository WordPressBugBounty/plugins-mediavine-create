<?php
namespace Mediavine\Create;

class Reviews_API extends Reviews {

	const MAX_AUTHOR_NAME_LENGTH   = 99;
	const MAX_REVIEW_TITLE_LENGTH  = 200;
	const MAX_REVIEW_CONTENT_LENGTH = 1000;
	const MAX_EMAIL_LENGTH         = 254;
	const RATE_LIMIT_PER_HOUR      = 5;

	private static $min_rating = 4;

	private static $instance = null;

	private $Reviews;

	/**
	 * Get Instance of Object
	 * @return Reviews_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Sanitize parameters
	 *
	 * @param array $params
	 * @param array $raw_params Original unsanitized params for HTML detection.
	 *
	 * @return array
	 */
	function sanitize( $params, &$raw_params = [] ) {
		$raw_params = $params;
		$cleaned    = [];
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
	 * @param array $params Sanitized parameters.
	 * @param array $rating_status Rating status from resolve_rating_status.
	 * @param array $raw_params Original unsanitized parameters for HTML detection.
	 *
	 * @return array
	 */
	function validate_review( $params, $rating_status, $raw_params = [] ) {

		$more_required = false;
		$error         = false;
		$errors        = [];
		$new_review    = [];

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
			if ( mb_strlen( $params['review_title'] ) > self::MAX_REVIEW_TITLE_LENGTH ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Title is Too Long', 'mediavine' ),
						'details' => __( 'Title must be 200 characters or fewer', 'mediavine' ),
					], 'error'
				);
			}
			$new_review['review_title'] = $params['review_title'];
		}

		// Reject URLs in content from non-authenticated users
		if ( ! \Mediavine\Permissions::is_user_authorized() && isset( $params['review_content'] ) ) {
			if ( preg_match( '/https?:\/\/|www\./i', $params['review_content'] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'URLs Not Allowed', 'mediavine' ),
						'details' => __( 'Reviews cannot contain URLs', 'mediavine' ),
					], 'error'
				);
			}
		}

		if ( isset( $params['review_content'] ) ) {
			if ( mb_strlen( $params['review_content'] ) > self::MAX_REVIEW_CONTENT_LENGTH ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Review is Too Long', 'mediavine' ),
						'details' => __( 'Review must be 1000 characters or fewer', 'mediavine' ),
					], 'error'
				);
			}
			$new_review['review_content'] = $params['review_content'];
		}

		if ( isset( $params['author_email'] ) ) {
			if ( mb_strlen( $params['author_email'] ) > self::MAX_EMAIL_LENGTH ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Email is Too Long', 'mediavine' ),
						'details' => __( 'Email must be 254 characters or fewer', 'mediavine' ),
					], 'error'
				);
			}
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
			if ( mb_strlen( $params['author_name'] ) > self::MAX_AUTHOR_NAME_LENGTH ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Name is Too Long', 'mediavine' ),
						'details' => __( 'Name must be 99 characters or fewer', 'mediavine' ),
					], 'error'
				);
			} elseif ( ! empty( $raw_params['author_name'] ) && wp_strip_all_tags( $raw_params['author_name'] ) !== $raw_params['author_name'] ) {
				// Detect HTML by stripping tags from the raw input and comparing.
				// Using wp_strip_all_tags() avoids false positives from sanitize_text_field()
				// which also normalizes whitespace, octets, and special characters.
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Name Contains Invalid Characters', 'mediavine' ),
						'details' => __( 'Name cannot contain HTML or special markup', 'mediavine' ),
					], 'error'
				);
			} elseif ( preg_match( '/https?:\/\/|www\./i', $params['author_name'] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 422, [
						'title'   => __( 'Name Contains URL', 'mediavine' ),
						'details' => __( 'Name cannot contain URLs', 'mediavine' ),
					], 'error'
				);
			}

			if ( ! $error ) {
				$new_review['author_name'] = $params['author_name'];
			}
		}

		if ( $more_required ) {

			if ( isset( $new_review['author_name'] ) && is_numeric( $new_review['author_name'] ) ) {
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

		// Check rate limit (read-only, does not increment)
		$rate_limit_check = $this->check_review_rate_limit( false );
		if ( $rate_limit_check['exceeded'] ) {
			$status_code        = 429;
			$response['errors'] = $this::$api_services->normalize_errors(
				[], $status_code, [
					'title'   => __( 'Rate Limit Exceeded', 'mediavine' ),
					'details' => __( 'You are submitting reviews too quickly. Please try again later.', 'mediavine' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		// Honeypot check — hidden field should be empty.
		// Return 201 to avoid signaling detection to bots.
		$honeypot = $request->get_param( 'create_website' );
		if ( ! empty( $honeypot ) ) {
			return new \WP_REST_Response( [ 'data' => (object) [ 'id' => 0 ] ], 201 );
		}

		$sanitized = $request->sanitize_params();
		if ( is_wp_error( $sanitized ) ) {
			$status_code        = 403;
			$response['errors'] = $this::$api_services->normalize_errors(
				$response['errors'], $status_code, [
					'title'   => __( 'Unsafe Content Submission', 'mediavine' ),
					'details' => __( 'Your submission includes unsafe characters', 'mediavine' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		$raw_params = [];
		$params     = $this::$api_services->process_inbound( $request );
		$params     = $this->sanitize( $params, $raw_params );

		// TODO: change recipe_id param to creation so we can remove this
		if ( empty( $params['creation'] ) && ! empty( $params['recipe_id'] ) ) {
			$params['creation'] = $params['recipe_id'];
		}

		$rating_status = $this->resolve_rating_status( $params );

		if ( ! $rating_status['ok'] ) {
			return new \WP_REST_Response( $rating_status['response'], $rating_status['status'] );
		}

		$result = $this->validate_review( $params, $rating_status, $raw_params );

		$new_review = $result['review'];
		$error      = $result['error'];
		$errors     = $result['errors'];

		if ( ! $error ) {
			$inserted = self::$models->reviews->insert( $new_review );

			if ( $inserted ) {
				// Increment rate limit only on successful insert
				$this->check_review_rate_limit( true );

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
		$review    = self::$models_v2->mv_reviews->select_one( (int) $params['id'] );
		$handshake = ( ! empty( $review->handshake ) ) ? (int) $review->handshake : false;

		if ( ! empty( $params['handshake'] ) && (int) $params['handshake'] === $handshake ) {
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
					'details' => __( 'Your submission includes unsafe characters', 'mediavine' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		$raw_params = [];
		$params     = $this::$api_services->process_inbound( $request );
		$params     = $this->sanitize( $params, $raw_params );

		// Is this an authorized edit request?
		if ( ! $this->is_authorized_review_update( $params ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to edit this review.', 'mediavine' ),
				[ 'status' => 401 ]
			);
		}

		// Check if admin editing is gated (admins editing on behalf of users)
		if ( \Mediavine\Permissions::is_user_authorized() && ! empty( $params['edited_by_admin'] ) ) {
			if ( ! GateKeeper::can_access( GateKeeper::FEATURE_REVIEW_EDIT ) ) {
				return new \WP_Error(
					'feature_gated',
					__( 'Editing reviews requires a Pro subscription', 'mediavine' ),
					[
						'status'      => 403,
						'upgrade_url' => GateKeeper::get_upgrade_url(),
					]
				);
			}
		}

		// This accounts for old versions of create where we used recipe_id
		if ( empty( $params['creation'] ) && ! empty( $params['recipe_id'] ) ) {
			$params['creation'] = $params['recipe_id'];
		}

		$rating_status = $this->resolve_rating_status( $params );

		if ( ! $rating_status['ok'] ) {
			return new \WP_REST_Response( $rating_status['response'], $rating_status['status'] );
		}

		// Admin bypass: authorized admins can change ratings without requiring content fields
		// This allows admins to change rating-only reviews to any star value
		if ( \Mediavine\Permissions::is_user_authorized() && ! empty( $params['edited_by_admin'] ) ) {
			$rating_status['more_required'] = false;
		}

		$result = $this->validate_review( $params, $rating_status, $raw_params );

		$new_review = $result['review'];
		$error      = $result['error'];
		$errors     = $result['errors'];

		if ( ! $error ) {
			$updated = $this->Reviews->update( $params );

			if ( $updated ) {
				$updated->updated = true;
				$this->Reviews->update_creation_rating( $updated );
				do_action( 'mv_create_review_managed', $updated );
				if ( \Mediavine\Permissions::is_user_authorized() && ! empty( $params['edited_by_admin'] ) ) {
					$this->maybe_unlock_moderator_achievement();
				}
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
					[ 'status' => 401 ]
				);
			}

			// Make sure that our creation has a least one public associated post
			$creation = self::$models_v2->mv_creations->select_one( (int) $params['creation'] );
			if ( ! $this->has_public_associated_post( $creation ) ) {
				return new \WP_Error(
					'creation_not_public',
					__( 'This creation is not associated with a public post.', 'mediavine' ),
					[ 'status' => 401 ]
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

		// Filter by has_responses (boolean: true = has responses, false = no responses)
		if ( isset( $params['has_responses'] ) ) {
			$has_responses = filter_var( $params['has_responses'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null !== $has_responses ) {
				$query_args['where']['has_responses'] = $has_responses ? 1 : 0;
			}
		}

		// Filter by has_content (boolean: true = has title or content, false = rating only)
		// Applied post-query because it checks two columns with OR logic.
		$has_content_filter = null;
		if ( isset( $params['has_content'] ) ) {
			$has_content_filter = filter_var( $params['has_content'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}

		// Rating range filters — pushed to DB via conditions.
		if ( isset( $params['min_rating'] ) && is_numeric( $params['min_rating'] ) ) {
			$query_args['conditions'][] = [ 'rating', '>=', floatval( $params['min_rating'] ) ];
		}
		if ( isset( $params['max_rating'] ) && is_numeric( $params['max_rating'] ) ) {
			$query_args['conditions'][] = [ 'rating', '<=', floatval( $params['max_rating'] ) ];
		}

		// Content length filters — pushed to DB via conditions.
		if ( isset( $params['min_content_length'] ) && is_numeric( $params['min_content_length'] ) ) {
			$query_args['conditions'][] = [ 'CHAR_LENGTH(review_content)', '>=', intval( $params['min_content_length'] ) ];
		}
		if ( isset( $params['max_content_length'] ) && is_numeric( $params['max_content_length'] ) ) {
			$query_args['conditions'][] = [ 'CHAR_LENGTH(review_content)', '<=', intval( $params['max_content_length'] ) ];
		}

		// Parse suggested_featured early so we can skip LIMIT when active.
		// Post-query filters need the full result set to find optimal reviews.
		$suggested_featured = isset( $params['suggested_featured'] )
			? filter_var( $params['suggested_featured'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE )
			: null;

		// When suggested_featured is active, skip the DB limit so
		// post-query filtering has the full result set to choose from.
		$limit = 0;
		if ( ! empty( $params['limit'] ) && true !== $suggested_featured ) {
			$limit               = intval( sanitize_text_field( $params['limit'] ) );
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
			$offset               = intval( sanitize_text_field( $params['offset'] ) );
			$query_args['offset'] = $offset;
		}

		// Allow custom sorting
		$allowed_order_by = [ 'modified', 'created', 'rating' ];
		$order_by = isset( $params['order_by'] ) && in_array( $params['order_by'], $allowed_order_by, true )
			? $params['order_by']
			: 'modified';
		$query_args['order_by'] = $order_by;

		$allowed_order = [ 'ASC', 'DESC' ];
		$order = isset( $params['order'] ) && in_array( strtoupper( $params['order'] ), $allowed_order, true )
			? strtoupper( $params['order'] )
			: 'DESC';
		$query_args['order'] = $order;

		$reviews = $this->Reviews->find( $query_args, $search );

		// Apply has_content filter post-query (OR across two columns)
		if ( is_array( $reviews ) && null !== $has_content_filter ) {
			$reviews = array_filter( $reviews, function( $review ) use ( $has_content_filter ) {
				$title   = isset( $review->review_title ) ? $review->review_title : '';
				$content = isset( $review->review_content ) ? $review->review_content : '';
				$has_content = ! empty( $title ) || ! empty( $content );
				return $has_content_filter ? $has_content : ! $has_content;
			} );
			$reviews = array_values( $reviews ); // Re-index array
		}

		// Suggested featured filter: optimal reviews for featuring (140-300 chars, highest rated, with content)
		// Falls back to any review with content if no optimal matches
		if ( is_array( $reviews ) && true === $suggested_featured ) {
			// First, filter to only reviews with content
			$reviews_with_content = array_filter( $reviews, function( $review ) {
				$content = isset( $review->review_content ) ? $review->review_content : '';
				return ! empty( $content );
			} );

			// Try to find optimal length reviews (140-300 chars)
			$optimal_reviews = array_filter( $reviews_with_content, function( $review ) {
				$content_length = isset( $review->review_content ) ? mb_strlen( $review->review_content ) : 0;
				return $content_length >= 140 && $content_length <= 300;
			} );

			// Use optimal if available, otherwise fall back to all with content
			$reviews = ! empty( $optimal_reviews ) ? array_values( $optimal_reviews ) : array_values( $reviews_with_content );

			// Sort by rating descending (highest first)
			usort( $reviews, function( $a, $b ) {
				$rating_a = isset( $a->rating ) ? floatval( $a->rating ) : 0;
				$rating_b = isset( $b->rating ) ? floatval( $b->rating ) : 0;
				return $rating_b <=> $rating_a;
			} );
		}

		// Apply limit after post-query filtering when suggested_featured bypassed the DB limit.
		if ( is_array( $reviews ) && true === $suggested_featured && ! empty( $params['limit'] ) ) {
			$reviews = array_slice( $reviews, 0, intval( $params['limit'] ) );
		}

		if ( is_array( $reviews ) ) {

			$response = [];

			$response['links'] = $this::$api_services->prepare_collection_links( $request );

			$is_authenticated  = \Mediavine\Permissions::is_user_authorized();
			$creation_cache    = [];

			$response = [];
			foreach ( $reviews as $review ) {
				$relationships = [];
				if ( $is_authenticated && isset( $review->creation ) ) {
					$creation_id = $review->creation;
					if ( ! isset( $creation_cache[ $creation_id ] ) ) {
						$full_creation = self::$models_v2->mv_creations->select_one( $creation_id );
						if ( $full_creation ) {
							$creation_cache[ $creation_id ] = (object) [
								'id'    => $full_creation->id,
								'title' => $full_creation->title,
								'type'  => $full_creation->type,
							];
						}
					}
					if ( isset( $creation_cache[ $creation_id ] ) ) {
						$relationships[] = $creation_cache[ $creation_id ];
					}
				}

				$review->review_title   = wp_strip_all_tags( $review->review_title );
				$review->review_content = wp_strip_all_tags( $review->review_content );
				$review->author_email   = wp_strip_all_tags( $review->author_email );
				$review->author_name    = wp_strip_all_tags( $review->author_name );
				$review->type           = wp_strip_all_tags( $review->type );

				// The email should never be publicly available
				if ( ! $is_authenticated ) {
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

		if ( $review ) {
			$review->review_title   = wp_strip_all_tags( $review->review_title );
			$review->review_content = wp_strip_all_tags( $review->review_content );
			$review->author_email   = wp_strip_all_tags( $review->author_email );
			$review->author_name    = wp_strip_all_tags( $review->author_name );
			$review->type           = wp_strip_all_tags( $review->type );

			// The email should never be publicly available
			if ( ! \Mediavine\Permissions::is_user_authorized() ) {
				unset( $review->author_email );
			}

			// Do not display handshake. Ever.
			unset( $review->handshake );

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
		$review    = self::$models_v2->mv_reviews->select_one_by_id( $review_id );

		if ( ! $review ) {
			return new \WP_Error( 404, __( 'Review Not Found', 'mediavine' ), [ 'status' => 404 ] );
		}

		global $wpdb;
		$deleted = $wpdb->delete( self::$models_v2->mv_reviews->table_name, [ 'id' => $review_id ] );

		if ( $deleted ) {
			$this->Reviews->update_creation_rating( $review );
			$this->maybe_unlock_moderator_achievement();
			$response    = [];
			$status_code = 204;
		}

		if ( ! $deleted ) {
			return new \WP_Error( 409, __( 'Entry Could Not Be Deleted', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the single review could not be deleted', 'mediavine' ) ] );
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	/**
	 * Unlock the moderator achievement when an admin edits or deletes a review.
	 */
	private function maybe_unlock_moderator_achievement() {
		$achievements = get_option( 'mv_create_achievements', [] );
		if ( ! is_array( $achievements ) ) {
			$achievements = json_decode( $achievements, true );
			if ( ! is_array( $achievements ) ) {
				$achievements = [];
			}
		}

		if ( ! empty( $achievements['moderator']['unlocked'] ) ) {
			return;
		}

		$achievements['moderator'] = [
			'unlocked'    => true,
			'unlocked_at' => gmdate( 'c' ),
		];
		update_option( 'mv_create_achievements', wp_json_encode( $achievements ) );
	}

	/**
	 * Check if review submission rate limit has been exceeded.
	 *
	 * @param bool $increment Whether to increment the submission count.
	 * @return array Array with 'exceeded' boolean.
	 */
	private function check_review_rate_limit( $increment = false ) {
		if ( \Mediavine\Permissions::is_user_authorized() ) {
			return [ 'exceeded' => false ];
		}

		$transient_key = 'mv_review_rate_limit_' . md5( self::get_client_ip() );
		$submissions   = get_transient( $transient_key );

		if ( ! $submissions ) {
			$submissions = [];
		}

		$current_time = time();
		$hour_ago     = $current_time - 3600;

		$submissions = array_filter( $submissions, function( $timestamp ) use ( $hour_ago ) {
			return $timestamp > $hour_ago;
		} );

		if ( count( $submissions ) >= self::RATE_LIMIT_PER_HOUR ) {
			return [ 'exceeded' => true ];
		}

		if ( $increment ) {
			$submissions[] = $current_time;
			set_transient( $transient_key, $submissions, 3600 );
		}

		return [ 'exceeded' => false ];
	}

	/**
	 * Get the client IP address, checking proxy headers when available.
	 *
	 * Prefers HTTP_X_FORWARDED_FOR and HTTP_X_REAL_IP (common behind load
	 * balancers and reverse proxies like Cloudflare, Nginx, AWS ALB) before
	 * falling back to REMOTE_ADDR.
	 *
	 * @return string Client IP address.
	 */
	private static function get_client_ip() {
		// X-Forwarded-For may contain a chain: "client, proxy1, proxy2".
		// The first (leftmost) IP is the original client.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$ip  = trim( $ips[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = trim( $_SERVER['HTTP_X_REAL_IP'] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	}

	function init() {
		$this->Reviews = new Reviews_Models();
	}
}
