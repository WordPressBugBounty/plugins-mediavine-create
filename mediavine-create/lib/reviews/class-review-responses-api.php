<?php
namespace Mediavine\Create;

class Review_Responses_API extends Review_Responses {

	private static $instance = null;

	private $Review_Responses;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function sanitize( $params ) {
		$cleaned = [];
		foreach ( $params as $key => $value ) {
			if ( 'content' === $key ) {
				$cleaned[ $key ] = wp_kses( $value, [
					'p'      => [],
					'br'     => [],
					'strong' => [],
					'em'     => [],
					'b'      => [],
					'i'      => [],
				] );
				continue;
			}
			$cleaned[ $key ] = sanitize_text_field( $value );
		}

		return $cleaned;
	}

	public function validate_response( $params ) {
		$error  = false;
		$errors = [];

		if ( empty( $params['review_id'] ) || ! is_numeric( $params['review_id'] ) ) {
			$error  = true;
			$errors = $this::$api_services->normalize_errors(
				$errors, 422, [
					'title'   => __( 'Review ID Required', 'mediavine-create' ),
					'details' => __( 'A valid review ID is required', 'mediavine-create' ),
				], 'error'
			);
		}

		if ( empty( $params['content'] ) ) {
			$error  = true;
			$errors = $this::$api_services->normalize_errors(
				$errors, 422, [
					'title'   => __( 'Content Required', 'mediavine-create' ),
					'details' => __( 'Response content is required', 'mediavine-create' ),
				], 'error'
			);
		}

		if ( empty( $params['author_name'] ) && empty( $params['author_id'] ) ) {
			$error  = true;
			$errors = $this::$api_services->normalize_errors(
				$errors, 422, [
					'title'   => __( 'Author Required', 'mediavine-create' ),
					'details' => __( 'Author name or user ID is required', 'mediavine-create' ),
				], 'error'
			);
		}

		if ( ! empty( $params['author_email'] ) && ! is_email( $params['author_email'] ) ) {
			$error  = true;
			$errors = $this::$api_services->normalize_errors(
				$errors, 422, [
					'title'   => __( 'Invalid Email', 'mediavine-create' ),
					'details' => __( 'Email address provided is invalid', 'mediavine-create' ),
				], 'error'
			);
		}

		return [
			'error'  => $error,
			'errors' => $errors,
		];
	}

	public function is_authorized_response_action( $response_id = null ) {
		if ( \Mediavine\Permissions::is_user_authorized() ) {
			return true;
		}

		if ( $response_id ) {
			$response = self::$models->review_responses->select_one_by_id( $response_id );
			$current_user_id = get_current_user_id();
			
			if ( $response && $response->author_id && $current_user_id === (int) $response->author_id ) {
				return true;
			}
		}

		return false;
	}

	public function get_review_responses( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params = $request->get_params();
		$review_id = intval( $params['review_id'] );

		if ( ! \Mediavine\Permissions::is_user_authorized() ) {
			$review = self::$models_v2->mv_reviews->select_one( $review_id );
			if ( ! $review ) {
				return new \WP_Error(
					'review_not_found',
					__( 'Review not found.', 'mediavine-create' ),
					[ 'status' => 404 ]
				);
			}

			$creation = self::$models_v2->mv_creations->select_one( $review->creation );
			if ( ! $this->has_public_associated_post( $creation ) ) {
				return new \WP_Error(
					'review_not_public',
					__( 'This review is not associated with a public post.', 'mediavine-create' ),
					[ 'status' => 401 ]
				);
			}
		}

		$query_args = [
			'where' => [
				'review_id' => $review_id,
				'status'    => 'approved',
			],
			// `id` breaks ties on the second-granularity `created` column so
			// limit/offset paging stays stable across queries (see CRE-290).
			'order_by' => 'created ASC, id',
			'order'    => 'ASC',
		];

		if ( ! empty( $params['limit'] ) ) {
			$query_args['limit'] = intval( sanitize_text_field( $params['limit'] ) );
		}

		if ( isset( $params['offset'] ) ) {
			$query_args['offset'] = intval( sanitize_text_field( $params['offset'] ) );
		}

		$responses = $this->Review_Responses->find( $query_args );

		// Check if user can see admin responses (Pro feature)
		$can_see_admin_responses = GateKeeper::can_access( GateKeeper::FEATURE_REVIEW_RESPOND );

		if ( is_array( $responses ) ) {
			$response = [];

			foreach ( $responses as $resp ) {
				// Filter out admin responses for non-Pro users on the frontend
				if ( ! $can_see_admin_responses && ! empty( $resp->is_admin_response ) ) {
					continue;
				}

				$resp->content     = wp_kses( $resp->content, [
					'p'      => [],
					'br'     => [],
					'strong' => [],
					'em'     => [],
					'b'      => [],
					'i'      => [],
				] );
				$resp->author_name = wp_kses( $resp->author_name, [] );

				if ( ! \Mediavine\Permissions::is_user_authorized() ) {
					unset( $resp->author_email );
					unset( $resp->author_id );
				}

				$response[] = $this::$api_services->prepare_item_for_response( $resp, $request );
			}

			$status_code = 200;
		}

		$response = new \WP_REST_Response( $response, $status_code );
		$response->header( 'X-Total-Items', $this->Review_Responses->get_count( $query_args ) );
		return $response;
	}

	public function create_response_api( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		// Check if responding to reviews is gated (Pro feature)
		if ( ! GateKeeper::can_access( GateKeeper::FEATURE_REVIEW_RESPOND ) ) {
			return new \WP_Error(
				'feature_gated',
				__( 'Responding to reviews requires a Pro subscription', 'mediavine-create' ),
				[
					'status'      => 403,
					'upgrade_url' => GateKeeper::get_upgrade_url(),
				]
			);
		}

		$sanitized = $request->sanitize_params();
		if ( is_wp_error( $sanitized ) ) {
			$status_code        = 403;
			$response['errors'] = $this::$api_services->normalize_errors(
				$response['errors'], $status_code, [
					'title'   => __( 'Unsafe Content Submission', 'mediavine-create' ),
					'details' => __( 'Your submission includes unsafe characters', 'mediavine-create' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		$params = $this::$api_services->process_inbound( $request );
		$params = $this->sanitize( $params );

		$current_user_id = get_current_user_id();
		if ( $current_user_id ) {
			$params['author_id'] = $current_user_id;
		}

		$validation = $this->validate_response( $params );

		if ( $validation['error'] ) {
			$status_code        = 422;
			$response['errors'] = $validation['errors'];
			return new \WP_REST_Response( $response, $status_code );
		}

		$rate_limit_check = $this->check_rate_limit( $params );
		if ( $rate_limit_check['exceeded'] ) {
			$status_code        = 429;
			$response['errors'] = $this::$api_services->normalize_errors(
				[], $status_code, [
					'title'   => __( 'Rate Limit Exceeded', 'mediavine-create' ),
					'details' => __( 'You are submitting responses too quickly. Please wait before trying again.', 'mediavine-create' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		$inserted = $this->Review_Responses->create_response( $params );

		if ( $inserted ) {
			$response    = $this::$api_services->prepare_item_for_response( $inserted, $request );
			$status_code = 201;
		} else {
			$status_code        = 500;
			$response['errors'] = $this::$api_services->normalize_errors(
				[], $status_code, [
					'title'   => __( 'Response Creation Failed', 'mediavine-create' ),
					'details' => __( 'Unable to create response', 'mediavine-create' ),
				], 'error'
			);
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	public function update_response( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params = $this::$api_services->process_inbound( $request );
		$params = $this->sanitize( $params );

		if ( ! $this->is_authorized_response_action( $params['id'] ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to edit this response.', 'mediavine-create' ),
				[ 'status' => 401 ]
			);
		}

		$validation = $this->validate_response( $params );

		if ( $validation['error'] ) {
			$status_code        = 422;
			$response['errors'] = $validation['errors'];
			return new \WP_REST_Response( $response, $status_code );
		}

		$updated = $this->Review_Responses->update( $params );

		if ( $updated ) {
			$response    = $this::$api_services->prepare_item_for_response( $updated, $request );
			$status_code = 200;
		} else {
			$status_code        = 500;
			$response['errors'] = $this::$api_services->normalize_errors(
				[], $status_code, [
					'title'   => __( 'Response Update Failed', 'mediavine-create' ),
					'details' => __( 'Unable to update response', 'mediavine-create' ),
				], 'error'
			);
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	public function delete_response_api( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params = $request->get_params();
		$response_id = intval( $params['id'] );

		if ( ! $this->is_authorized_response_action( $response_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to delete this response.', 'mediavine-create' ),
				[ 'status' => 401 ]
			);
		}

		$deleted = $this->Review_Responses->delete_response( $response_id );

		if ( $deleted ) {
			$response    = [];
			$status_code = 204;
		} else {
			return new \WP_Error( 
				409, 
				__( 'Response Could Not Be Deleted', 'mediavine-create' ), 
				[ 'message' => __( 'A conflict occurred and the response could not be deleted', 'mediavine-create' ) ] 
			);
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	private function check_rate_limit( $params = [] ) {
		if ( \Mediavine\Permissions::is_user_authorized() ) {
			return [ 'exceeded' => false ];
		}

		$remote_addr   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$transient_key = 'mv_response_rate_limit_' . md5( $remote_addr );
		$submissions = get_transient( $transient_key );

		if ( ! $submissions ) {
			$submissions = [];
		}

		$current_time = time();
		$hour_ago = $current_time - 3600;

		$submissions = array_filter( $submissions, function( $timestamp ) use ( $hour_ago ) {
			return $timestamp > $hour_ago;
		});

		if ( count( $submissions ) >= 5 ) {
			return [ 'exceeded' => true ];
		}

		$submissions[] = $current_time;
		set_transient( $transient_key, $submissions, 3600 );

		return [ 'exceeded' => false ];
	}

	private function has_public_associated_post( $creation ) {
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

	public function init() {
		$this->Review_Responses = new Review_Responses();
		$this->Review_Responses->init();
	}
}