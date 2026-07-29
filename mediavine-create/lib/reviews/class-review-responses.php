<?php
namespace Mediavine\Create;

class Review_Responses extends Plugin {

	public $response_table = 'mv_reviews_responses';

	/**
	 * Send email notification to the original reviewer when a response is added.
	 *
	 * @param object $response The response that was created.
	 */
	public function notify_reviewer_of_response( $response ) {
		// Get the original review
		$review = self::$models->reviews->select_one_by_id( $response->review_id );

		if ( ! $review ) {
			return;
		}

		// Check if the reviewer wants notifications
		if ( empty( $review->notify_responses ) || '0' === $review->notify_responses ) {
			return;
		}

		// Check if we have the reviewer's email
		if ( empty( $review->author_email ) ) {
			return;
		}

		// Don't notify if the reviewer is responding to their own review
		if ( ! empty( $response->author_email ) && $response->author_email === $review->author_email ) {
			return;
		}

		// Get the creation/card to find the associated post
		$creation = self::$models_v2->mv_creations->select_one( $review->creation );
		if ( ! $creation ) {
			return;
		}

		// Get the post title and URL
		$post_id = $creation->canonical_post_id ?? $creation->original_post_id ?? null;
		$post_title = $post_id ? get_the_title( $post_id ) : $creation->title;
		$post_url = $post_id ? get_permalink( $post_id ) : home_url();

		// Add hash to scroll directly to the review
		$review_url = $post_url . '#review-' . $review->id;

		// Build the email
		$to = $review->author_email;
		$reviewer_name = ! empty( $review->author_name ) ? $review->author_name : __( 'Reviewer', 'mediavine-create' );
		$responder_name = ! empty( $response->author_name ) ? $response->author_name : __( 'Someone', 'mediavine-create' );

		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		/* translators: %s: Post title */
		$subject = sprintf( __( '[%1$s] New response to your review on "%2$s"', 'mediavine-create' ), $blogname, $post_title );

		$message = sprintf(
			/* translators: 1: Reviewer name, 2: Responder name, 3: Post title */
			__( 'Hi %1$s,

%2$s has responded to your review on "%3$s".

Their response:
%4$s

View the full conversation:
%5$s

---
You are receiving this email because you left a review and opted to receive notifications.
', 'mediavine-create' ),
			$reviewer_name,
			$responder_name,
			$post_title,
			wp_strip_all_tags( $response->content ),
			$review_url
		);

		/**
		 * Filter the review response notification email recipient.
		 *
		 * @param string $to      The recipient email address.
		 * @param object $review  The original review.
		 * @param object $response The new response.
		 */
		$to = apply_filters( 'mv_review_response_notification_to', $to, $review, $response );

		/**
		 * Filter the review response notification email subject.
		 *
		 * @param string $subject  The email subject.
		 * @param object $review   The original review.
		 * @param object $response The new response.
		 */
		$subject = apply_filters( 'mv_review_response_notification_subject', $subject, $review, $response );

		/**
		 * Filter the review response notification email message.
		 *
		 * @param string $message  The email message.
		 * @param object $review   The original review.
		 * @param object $response The new response.
		 */
		$message = apply_filters( 'mv_review_response_notification_message', $message, $review, $response );

		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
		];

		/**
		 * Filter the review response notification email headers.
		 *
		 * @param array  $headers  The email headers.
		 * @param object $review   The original review.
		 * @param object $response The new response.
		 */
		$headers = apply_filters( 'mv_review_response_notification_headers', $headers, $review, $response );

		wp_mail( $to, $subject, $message, $headers );
	}

	public static function get_responses_for_review( $review_id, $args = [] ) {
		if ( ! isset( $review_id ) ) {
			return new \WP_Error( 'no_value', __( 'Review ID was not set in function call', 'mediavine-create' ), [ 'message' => __( 'A Review ID was not included in the request', 'mediavine-create' ) ] );
		}

		if ( ! is_numeric( $review_id ) ) {
			return new \WP_Error( 'non_numeric', __( 'Review ID value was not a number', 'mediavine-create' ), [ 'message' => __( 'A Review ID variable was included but was non-numeric', 'mediavine-create' ) ] );
		}

		$limit  = 50;
		$offset = 0;

		if ( isset( $args['limit'] ) ) {
			$limit = $args['limit'];
		}

		if ( isset( $args['offset'] ) ) {
			$offset = $args['offset'];
		}

		$responses = self::$models_v2->mv_reviews_responses->find(
			[
				'limit'    => $limit,
				'offset'   => $offset,
				'where'    => [
					'review_id' => $review_id,
				],
				'order_by' => 'created',
				'order'    => 'ASC',
			]
		);

		return $responses;
	}

	/**
	 * Batch-fetch approved responses for many reviews in one query.
	 *
	 * Returns a map of review_id => array of response objects (created ASC).
	 * Reviews with no responses are omitted (callers should default to []).
	 *
	 * @param int[] $review_ids Review IDs to fetch responses for.
	 * @return array<int, array<int, object>>
	 */
	public static function get_responses_for_reviews( $review_ids ) {
		$review_ids = array_values( array_filter( array_map( 'intval', (array) $review_ids ) ) );
		if ( empty( $review_ids ) ) {
			return [];
		}

		$responses = self::$models_v2->mv_reviews_responses->find(
			[
				'where'      => [
					'review_id' => [ 'IN' => $review_ids ],
				],
				'conditions' => [
					[ 'status', '=', 'approved' ],
				],
				'order_by'   => 'created',
				'order'      => 'ASC',
			]
		);

		if ( ! is_array( $responses ) ) {
			return [];
		}

		$by_review = [];
		foreach ( $responses as $response ) {
			$rid = (int) $response->review_id;
			if ( ! isset( $by_review[ $rid ] ) ) {
				$by_review[ $rid ] = [];
			}
			$by_review[ $rid ][] = $response;
		}

		return $by_review;
	}

	public function init() {
		self::$models->{'review_responses'} = new \Mediavine\MV_DBI( $this->response_table );

		// Send email notification when a response is created
		add_action( 'mv_review_response_created', [ $this, 'notify_reviewer_of_response' ], 10, 2 );
	}

	public function find( $args = null, $search = null ) {
		$search_params = null;

		if ( $search ) {
			$search_params = [
				'author_name' => $search,
				'content'     => $search,
			];
		}

		return self::$models->review_responses->find( $args, $search_params );
	}

	public function get_count( $args = null, $search = null ) {
		$search_params = null;

		if ( $search ) {
			$search_params = [
				'author_name' => $search,
				'content'     => $search,
			];
		}

		$total = self::$models->review_responses->get_count( $args, $search_params );

		return $total;
	}

	public function create_response( $response ) {
		if (
			empty( $response['review_id'] ) ||
			empty( $response['content'] ) ||
			( empty( $response['author_name'] ) && empty( $response['author_id'] ) )
		) {
			return false;
		}

		if ( ! empty( $response['author_id'] ) ) {
			$user = get_user_by( 'id', $response['author_id'] );
			if ( $user ) {
				$response['author_name'] = $user->display_name;
				$response['author_email'] = $user->user_email;
				
				if ( \Mediavine\Permissions::is_user_authorized( $response['author_id'] ) ) {
					$response['is_admin_response'] = 1;
				}
			}
		}

		if ( empty( $response['status'] ) ) {
			$response['status'] = 'approved';
		}

		$inserted = self::$models->review_responses->insert( $response );

		if ( $inserted ) {
			$this->update_review_response_counts( $inserted );
			return $inserted;
		}

		return false;
	}

	public function update( $data ) {
		$updated = self::$models->review_responses->update( $data );
		if ( $updated ) {
			$response = self::$models->review_responses->select_one_by_id( $data['id'] );
			return $response;
		}
		return false;
	}

	public function update_review_response_counts( $response, $is_delete = false ) {
		$review_id = $response->review_id;
		$response_count = $this->get_count( [ 'where' => [ 'review_id' => $review_id ] ] );

		$has_responses = $response_count > 0 ? 1 : 0;

		$review_update = [
			'id'             => $review_id,
			'has_responses'  => $has_responses,
			'response_count' => $response_count,
		];

		// Only update last_response_date when creating a response, not when deleting
		if ( ! $is_delete ) {
			$review_update['last_response_date'] = gmdate( 'Y-m-d H:i:s' );
		}

		if ( isset( self::$models->reviews ) ) {
			self::$models->reviews->update( $review_update );
		}

		if ( ! $is_delete ) {
			do_action( 'mv_review_response_created', $response, $response_count );
		} else {
			do_action( 'mv_review_response_deleted', $response, $response_count );
		}
	}

	public function delete_response( $response_id ) {
		$response = self::$models->review_responses->select_one_by_id( $response_id );
		if ( ! $response ) {
			return false;
		}

		$deleted = self::$models->review_responses->delete_by_id( $response_id );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		// Delete was successful (returns rows affected or falsy if no error)
		$this->update_review_response_counts( $response, true );
		return true;
	}

}
