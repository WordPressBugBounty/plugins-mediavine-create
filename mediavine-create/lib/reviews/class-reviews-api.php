<?php
namespace Mediavine\Create;

class Reviews_API extends Reviews {

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

		$result = $this->validate_review( $params, $rating_status );

		$new_review = $result['review'];
		$error      = $result['error'];
		$errors     = $result['errors'];

		if ( ! $error ) {
			$updated = $this->Reviews->update( $params );

			if ( $updated ) {
				$updated->updated = true;
				$this->Reviews->update_creation_rating( $updated );
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
		$has_content_filter = null;
		if ( isset( $params['has_content'] ) ) {
			$has_content_filter = filter_var( $params['has_content'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}

		// Min rating filter will be applied post-query (for >= comparison)
		$min_rating = isset( $params['min_rating'] ) && is_numeric( $params['min_rating'] )
			? floatval( $params['min_rating'] )
			: null;

		// Max rating filter will be applied post-query
		$max_rating = isset( $params['max_rating'] ) && is_numeric( $params['max_rating'] )
			? floatval( $params['max_rating'] )
			: null;

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

		// Apply min_rating filter post-query (for >= comparison not supported by DBI)
		if ( is_array( $reviews ) && null !== $min_rating ) {
			$reviews = array_filter( $reviews, function( $review ) use ( $min_rating ) {
				$rating = isset( $review->rating ) ? floatval( $review->rating ) : 0;
				return $rating >= $min_rating;
			} );
			$reviews = array_values( $reviews ); // Re-index array
		}

		// Apply max_rating filter post-query (for <= comparison not supported by DBI)
		if ( is_array( $reviews ) && null !== $max_rating ) {
			$reviews = array_filter( $reviews, function( $review ) use ( $max_rating ) {
				$rating = isset( $review->rating ) ? floatval( $review->rating ) : 0;
				return $rating <= $max_rating;
			} );
			$reviews = array_values( $reviews ); // Re-index array
		}

		// Apply has_content filter post-query
		if ( is_array( $reviews ) && null !== $has_content_filter ) {
			$reviews = array_filter( $reviews, function( $review ) use ( $has_content_filter ) {
				$title   = isset( $review->review_title ) ? $review->review_title : '';
				$content = isset( $review->review_content ) ? $review->review_content : '';
				$has_content = ! empty( $title ) || ! empty( $content );
				return $has_content_filter ? $has_content : ! $has_content;
			} );
			$reviews = array_values( $reviews ); // Re-index array
		}

		// Content length filters (for review_content specifically)
		$min_content_length = isset( $params['min_content_length'] ) && is_numeric( $params['min_content_length'] )
			? intval( $params['min_content_length'] )
			: null;
		$max_content_length = isset( $params['max_content_length'] ) && is_numeric( $params['max_content_length'] )
			? intval( $params['max_content_length'] )
			: null;

		if ( is_array( $reviews ) && ( null !== $min_content_length || null !== $max_content_length ) ) {
			$reviews = array_filter( $reviews, function( $review ) use ( $min_content_length, $max_content_length ) {
				$content_length = isset( $review->review_content ) ? mb_strlen( $review->review_content ) : 0;
				if ( null !== $min_content_length && $content_length < $min_content_length ) {
					return false;
				}
				if ( null !== $max_content_length && $content_length > $max_content_length ) {
					return false;
				}
				return true;
			} );
			$reviews = array_values( $reviews );
		}

		// Suggested featured filter: optimal reviews for featuring (140-300 chars, highest rated, with content)
		// Falls back to any review with content if no optimal matches
		$suggested_featured = isset( $params['suggested_featured'] )
			? filter_var( $params['suggested_featured'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE )
			: null;

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

	function init() {
		$this->Reviews = new Reviews_Models();
	}
}
