<?php
namespace Mediavine\Create;

class Images_API extends Images {

	public function init() {
		$this->images_models = new Images_Models();
	}

	function validate_image( $params ) {
		$error  = false;
		$errors = [];

		$required_fields = [
			'object_id'       => 'Object ID',
			'associated_id'   => 'Associated ID',
			'associated_type' => 'Associated Type',
		];

		foreach ( $required_fields as $key => $name ) {
			if ( empty( $params[ $key ] ) ) {
				$error  = true;
				$errors = $this::$api_services->normalize_errors(
					$errors, 400, [
						/* translators: %s: field name */
						'title'   => sprintf( __( 'Missing Required %s', 'mediavine' ), $name ),
						/* translators: %s: field name */
						'details' => sprintf( __( '%s is a required field.', 'mediavine' ), $key ),
					], 'error'
				);
			}
		}

		$params = self::prep_image( $params );

		return [
			'image'  => $params,
			'error'  => $error,
			'errors' => $errors,
		];
	}

	public function fetch_media_urls( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params = $request->get_params();

		if ( ! isset( $params['ids'] ) ) {
			return new \WP_REST_Response(
				[
					'data' => [],
				], $status_code
			);
		}

		$ids = $params['ids'];

		$ids  = explode( ',', $ids );
		$data = [];

		foreach ( $ids as $id ) {
			$data[ $id ] = wp_get_attachment_url( $id );
		}

		$response = [
			'data' => $data,
		];

		return new \WP_REST_Response( $response, 200 );
	}

	public function create_image( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$sanitized = $request->sanitize_params();
		$params    = $this::$api_services->process_inbound( $request );
		$result    = $this->validate_image( $params );

		$new_image          = $result['image'];
		$response['errors'] = $result['errors'];

		if ( ! $result['error'] ) {
			$inserted = self::$models->images->insert( $new_image );

			if ( $inserted ) {
				$response    = [
					'data' => $this::$api_services->prepare_item_for_response( $inserted, $request ),
				];
				$status_code = 201;
			}
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	public function update_single_image( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$sanitized = $request->sanitize_params();
		$params    = $this::$api_services->process_inbound( $request );

		$result = $this->validate_image( $params );

		$new_image          = $result['image'];
		$response['errors'] = $result['errors'];

		if ( ! $result['error'] ) {
			$updated = self::$models->images->update( $new_image, $new_image['id'], true );

			// If error
			if ( empty( $updated ) || ( is_array( $updated ) && ! empty( $updated['error'] ) ) ) {
				$status_code = 304;
				$errors      = [
					'title'   => __( 'Nothing to Update', 'mediavine' ),
					'details' => __( 'Nothing in the current request has changed.', 'mediavine' ),
				];

				if ( ! empty( $updated['error'] ) ) {
					$status_code = 500;
					$errors      = [
						'title'   => __( 'Database Update Error', 'mediavine' ),
						'details' => $updated['error'],
					];
				}

				$response['errors'] = $this::$api_services->normalize_errors( [], 304, $errors, 'error' );
				return new \WP_REST_Response( $response, $status_code );
			}

			$response         = [];
			$response['data'] = [];
			$response['data'] = $this::$api_services->prepare_item_for_response( $updated, $request );
			$status_code      = 200;
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	public function read_images( \WP_REST_Request $request ) {
		$params      = $request->get_params();
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$query_args = [];

		if ( isset( $params['limit'] ) ) {
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

		$images = self::$models->images->find( $query_args );

		if ( is_array( $images ) ) {
			$response = [
				'links' => $this::$api_services->prepare_collection_links( $request ),
				'data'  => [],
			];

			foreach ( $images as $image ) {
				$response['data'][] = $this::$api_services->prepare_item_for_response( $image, $request );
			}

			$status_code = 200;
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	public function read_single_image( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params   = $request->get_params();
		$image_id = intval( $params['id'] );
		$image    = self::$models->images->select_one_by_id( $image_id );

		if ( empty( $image ) ) {
			$response['errors'] = $this::$api_services->normalize_errors(
				[], $status_code, [
					'title'   => __( 'Resource Not Found', 'mediavine' ),
					'details' => __( 'No image with that ID.', 'mediavine' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		$response         = [];
		$response['data'] = $this::$api_services->prepare_item_for_response( $image, $request );
		$status_code      = 200;

		return new \WP_REST_Response( $response, $status_code );
	}

	public function delete_single_image( \WP_REST_Request $request ) {
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		$params   = $request->get_params();
		$image_id = intval( $params['id'] );
		$deleted  = self::$models->images->delete_by_id( $image_id );

		if ( empty( $deleted ) ) {
			$response['errors'] = $this::$api_services->normalize_errors(
				[], $status_code, [
					'title'   => __( 'Resource Not Found', 'mediavine' ),
					'details' => __( 'No image with that ID.', 'mediavine' ),
				], 'error'
			);
			return new \WP_REST_Response( $response, $status_code );
		}

		if ( $deleted ) {
			$response    = [];
			$status_code = 204;
		}

		if ( ! $deleted ) {
			$response    = [];
			$status_code = 409;
			$response    = [
				'error' => 'Image could not be deleted',
			];
		}

		return new \WP_REST_Response( $response, $status_code );
	}

	public function verify_integrity( \WP_REST_Request $request ) {
		global $wpdb;
		$response    = $this::$api_services->default_response;
		$status_code = $this::$api_services->default_status;

		// Get image URL
		$params = $request->get_params();
		$url    = $params['uri'];

		// Look for URL in post meta
		// SECURITY CHECKED: This query is properly prepared.
		$statement = $wpdb->prepare( "SELECT meta_value as url, post_id as id FROM {$wpdb->prefix}postmeta WHERE meta_value = %s", $url );
		$img_data  = $wpdb->get_results( $statement, ARRAY_A );

		// If image exists, return true
		if ( count( $img_data ) ) {
			$response = array_merge( $img_data[0], [ 'exists' => true ] );
		} else {
			$response = [
				'url'    => $url,
				'id'     => null,
				'exists' => false,
			];
		}

		// If image does not exist, return false
		return new \WP_REST_Response( $response, 200 );
	}

}
