<?php
namespace Mediavine\Create;

class Nutrition_API extends Nutrition {

	/**
	 * Validate nutrition info. Stub method for now
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 *
	 * @return \WP_REST_Response
	 */
	public function valid_nutrition( \WP_REST_Request $request, \WP_REST_Response $response ) {
		return $response;
	}

	/**
	 * Add nutrition data to a recipe card
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function upsert( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();

		$nutrition = [];

		$nutrition['creation'] = $params['id'];

		if ( 'recipe' !== Creations::get_creation_type( $params['id'] ) ) {

			$this->delete_creation_nutrition( $params['id'] );

			$response = API_Services::set_response_data( [], $response );
			$response->set_status( 200 );

			return $response;
		}

		unset( $params['id'] );

		foreach ( $params as $key => $value ) {
			if ( ! isset( $param ) || ! str_len( $param ) ) {
				$nutrition[ $key ] = $value;
				continue;
			}
			$nutrition[ $key ] = $value;
		}

		// For legacy sites that had early Create betas
		$nutrition['recipe_id'] = $nutrition['creation'];

		$nutrition = self::$models_v2->mv_nutrition->upsert(
			$nutrition,
			[ 'creation' => $nutrition['creation'] ]
		);

		if ( empty( $nutrition ) ) {
			return new \WP_Error( 404, __( 'Entry Not Found', 'mediavine' ), [ 'message' => __( 'The Nutrition could not be found', 'mediavine' ) ] );
		}
		$data     = self::$api_services->prepare_item_for_response( $nutrition, $request );
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Find nutrition data for recipe card
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function find_one( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params   = $request->get_params();
		$creation = self::$models_v2->mv_creations->find_one( $params['id'] );

		if ( 'recipe' !== Creations::get_creation_type( $params['id'] ) ) {

			$this->delete_creation_nutrition( $params['id'] );

			$response = API_Services::set_response_data( [], $response );
			$response->set_status( 200 );

			return $response;
		}

		$nutrition = Nutrition::get_creation_nutrition( $creation->id );

		if ( empty( $nutrition ) ) {
			return new \WP_Error( 404, __( 'Entry Not Found', 'mediavine' ), [ 'message' => __( 'The Nutrition could not be found', 'mediavine' ) ] );
		}
		$data     = $nutrition;
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Remove nutrition data from recipe card
	 *
	 * @param $creation_id
	 *
	 * @return mixed
	 */
	public function delete_creation_nutrition( $creation_id ) {
		return self::$models_v2->mv_nutrition->delete(
			[
				'col' => 'creation',
				'key' => $creation_id,
			]
		);
	}

}
