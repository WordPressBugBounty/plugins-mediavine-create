<?php

namespace Mediavine\Create;

use Mediavine\Create\API_Services;


// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This plugin requires WordPress' );
}

if ( class_exists( 'Mediavine\Create\Supplies' ) ) {

	class Supplies_API extends Creations {

		/**
		 * Create Supplies entry
		 *
		 * @param \WP_REST_Request $request
		 * @param \WP_REST_Response $response
		 *
		 * @return \WP_Error|\WP_REST_Response
		 */
		public function create( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$params = $request->get_params();

			$supply = self::$models_v2->mv_supplies->create( $params );

			if ( empty( $supply ) ) {
				return new \WP_Error( 409, __( 'Entry Not Created', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the Supply could not be created', 'mediavine' ) ] );
			}
			$data     = self::$api_services->prepare_item_for_response( $supply, $request );
			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 201 );

			return $response;
		}

		/**
		 * Find and return supplies for a given creation
		 *
		 * @param \WP_REST_Request $request
		 * @param \WP_REST_Response $response
		 *
		 * @return \WP_Error|\WP_REST_Response
		 */
		public function read_creation_supplies( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$params = $request->get_params();
			$data   = [];

			if ( isset( $params['id'] ) ) {
				$data = Supplies::get_creation_supplies( $params['id'], $params['type'] );
			}

			if ( ! wp_is_numeric_array( $data ) ) {
				return new \WP_Error( 404, __( 'No Entries Found', 'mediavine' ), [ 'message' => __( 'No Supplies were found for the given Creation', 'mediavine' ) ] );
			}
			foreach ( $data as &$supply ) {
				$supply = self::$api_services->prepare_item_for_response( $supply, $request );
			}
			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 200 );

			return $response;
		}

		/**
		 * Find one supply
		 *
		 * @param \WP_REST_Request $request
		 * @param \WP_REST_Response $response
		 *
		 * @return \WP_Error|\WP_REST_Response
		 */
		public function find_one( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$params = $request->get_params();
			$supply = self::$models_v2->mv_supplies->create( $params['id'] );

			if ( empty( $supply ) ) {
				return new \WP_Error( 404, __( 'Entry Not Found', 'mediavine' ), [ 'message' => __( 'The Supply could not be found', 'mediavine' ) ] );
			}

			$data     = self::$api_services->prepare_item_for_response( $supply, $request );
			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 200 );

			return $response;
		}

		/**
		 * Destroy a specific supply
		 *
		 * @param \WP_REST_Request $request
		 * @param \WP_REST_Response $response
		 *
		 * @return \WP_Error|\WP_REST_Response
		 */
		public function destroy( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$params  = $request->get_params();
			$deleted = self::$models_v2->mv_supplies->delete( $params['id'] );

			if ( ! $deleted ) {
				return new \WP_Error( 409, __( 'Entry Could Not Be Deleted', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the Supply could not be deleted', 'mediavine' ) ] );
			}
			$data     = self::$api_services->prepare_item_for_response( $deleted, $request );
			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 204 );

			return $response;
		}

		/**
		 * Set supplies for a given creation
		 *
		 * @param \WP_REST_Request $request
		 * @param \WP_REST_Response $response
		 *
		 * @return \WP_REST_Response
		 *
		 */
		public function set_supplies( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$params      = $request->get_params();
			$creation_id = $params['id'];
			$type        = $params['type'];

			$deleted_count = Supplies::delete_all_supplies( $creation_id, $type );

			$data = $params['data'];

			if ( ! wp_is_numeric_array( $data ) ) {
				return $response;
			}

			foreach ( $data as &$supply ) {
				unset( $params['id'] );
				$supply['creation'] = $creation_id;
				$supply             = self::$models_v2->mv_supplies->create( $supply );
				$supply             = self::$api_services->prepare_item_for_response( $supply, $request );
			}

			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 201 );
			return $response;
		}

	}

}
