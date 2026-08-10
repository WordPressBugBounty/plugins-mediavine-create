<?php
namespace Mediavine\Create;

/**
 * Restore a Create card to its last-published snapshot.
 */
class Restore extends Plugin {

	/**
	 * Creation ID being restored.
	 *
	 * @var int
	 */
	public $creation_id;

	/**
	 * @param int $creation_id Creation ID.
	 */
	public function __construct( $creation_id ) {
		$this->creation_id = $creation_id;
	}

	/**
	 * Restore card fields from the published blob via REST.
	 *
	 * @param \WP_REST_Request  $request  Incoming request.
	 * @param \WP_REST_Response $response Response being built.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function from_published( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$creation_id = intval( $request->get_param( 'id' ) );

		$restore = new Restore( $creation_id );

		$published = $restore->get_creation_from_published();
		if ( empty( $published ) ) {
			return new \WP_Error(
				404,
				__( 'The Create Card has not been published', 'mediavine-create' ),
				[ 'request_params' => [ 'id' => $creation_id ] ]
			);
		}

		$new = $published;

		unset(
			$new['nutrition'],
			$new['products'],
			$new['images'],
			$new['ingredients'],
			$new['posts'],
			$new['create_settings']
		);
		$new['prep_time']       = $restore->time_from_array( 'prep_time', $new );
		$new['active_time']     = $restore->time_from_array( 'active_time', $new );
		$new['additional_time'] = $restore->time_from_array( 'additional_time', $new );
		$new['total_time']      = $restore->time_from_array( 'total_time', $new );

		$failures = [];

		$result = $restore->creation( $new );
		if ( is_wp_error( $result ) ) {
			$failures['creation'] = $result->get_error_message();
		}

		if ( isset( $published['nutrition'] ) ) {
			$result = $restore->nutrition( $published['nutrition'] );
			if ( is_wp_error( $result ) ) {
				$failures['nutrition'] = $result->get_error_message();
			}
		}
		if ( isset( $published['products'] ) ) {
			$result = $restore->products( $published['products'] );
			if ( is_wp_error( $result ) ) {
				$failures['products'] = $result->get_error_message();
			}
		}
		if ( isset( $published['images'] ) ) {
			$result = $restore->images( $published['images'] );
			if ( is_wp_error( $result ) ) {
				$failures['images'] = $result->get_error_message();
			}
		}
		if ( isset( $published['ingredients'] ) ) {
			Supplies::delete_all_supplies( $creation_id, 'ingredients' );
			$result = $restore->ingredients( $published['ingredients'] );
			if ( is_wp_error( $result ) ) {
				$failures['ingredients'] = $result->get_error_message();
			}
		}
		if ( isset( $published['materials'] ) ) {
			Supplies::delete_all_supplies( $creation_id, 'materials' );
			$result = $restore->materials( $published['materials'] );
			if ( is_wp_error( $result ) ) {
				$failures['materials'] = $result->get_error_message();
			}
		}
		if ( isset( $published['tools'] ) ) {
			Supplies::delete_all_supplies( $creation_id, 'tools' );
			$result = $restore->tools( $published['tools'] );
			if ( is_wp_error( $result ) ) {
				$failures['tools'] = $result->get_error_message();
			}
		}

		if ( ! empty( $failures ) ) {
			return new \WP_Error(
				409,
				__( 'Restore Incomplete', 'mediavine-create' ),
				[
					'message'  => __( 'One or more restore operations failed', 'mediavine-create' ),
					'failures' => $failures,
				]
			);
		}

		return $response;
	}

	/**
	 * Decode the published blob for this creation.
	 *
	 * @return array|null Published card data, or null when missing/corrupt.
	 */
	private function get_creation_from_published() {
		$creation = self::$models_v2->mv_creations->find_one( $this->creation_id );

		if ( ! is_object( $creation ) || empty( $creation->published ) ) {
			return null;
		}

		$published = json_decode( $creation->published, true );

		if ( ! is_array( $published ) || empty( $published ) ) {
			return null;
		}

		return $published;
	}

	/**
	 * Persist restored creation fields.
	 *
	 * @param array $creation Creation row data.
	 * @return object|\WP_Error|null
	 */
	private function creation( $creation ) {
		return self::$models_v2->mv_creations->update( $creation );
	}

	/**
	 * Restore nutrition rows from the published snapshot.
	 *
	 * @param array $nutrition Nutrition data.
	 * @return true|\WP_Error
	 */
	private function nutrition( $nutrition ) {
		if ( empty( $nutrition ) ) {
			return true;
		}

		$result = self::$models_v2->mv_nutrition->upsert(
			$nutrition,
			[ 'creation' => $this->creation_id ]
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Restore product map rows from the published snapshot.
	 *
	 * @param array $products Product map rows.
	 * @return true|\WP_Error
	 */
	private function products( $products ) {
		if ( empty( $products ) ) {
			return true;
		}

		foreach ( $products as $product ) {
			$result = self::$models_v2->mv_products_map->upsert( $product );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Restore image rows from the published snapshot.
	 *
	 * @param array $images Image rows.
	 * @return true|\WP_Error
	 */
	private function images( $images ) {
		if ( empty( $images ) ) {
			return true;
		}

		foreach ( $images as $image ) {
			$result = self::$models_v2->mv_images->upsert(
				$image,
				[
					'associated_id' => $this->creation_id,
					'image_size'    => $image['image_size'],
				]
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Restore ingredient supplies from the published snapshot.
	 *
	 * @param array $ingredients Grouped ingredients.
	 * @return true|\WP_Error
	 */
	private function ingredients( $ingredients ) {
		return $this->restore_supplies( $ingredients );
	}

	/**
	 * Restore material supplies from the published snapshot.
	 *
	 * @param array $materials Grouped materials.
	 * @return true|\WP_Error
	 */
	private function materials( $materials ) {
		return $this->restore_supplies( $materials );
	}

	/**
	 * Restore tool supplies from the published snapshot.
	 *
	 * @param array $tools Grouped tools.
	 * @return true|\WP_Error
	 */
	private function tools( $tools ) {
		return $this->restore_supplies( $tools );
	}

	/**
	 * Recreate grouped supply rows.
	 *
	 * @param array $groups Grouped supply rows from the published blob.
	 * @return true|\WP_Error
	 */
	private function restore_supplies( $groups ) {
		if ( empty( $groups ) ) {
			return true;
		}

		foreach ( $groups as $group ) {
			foreach ( $group as $supply ) {
				unset( $supply['id'] );
				unset( $supply['created'] );
				unset( $supply['modified'] );
				$result = self::$models_v2->mv_supplies->create( $supply );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	/**
	 * Flatten a published time array back to its original seconds value.
	 *
	 * @param string $time_key Time field key.
	 * @param array  $creation Creation data.
	 * @return int|string|null
	 */
	private function time_from_array( $time_key, array $creation ) {
		$time = $creation[ $time_key ];
		if ( empty( $time ) ) {
			return;
		}

		if ( ! is_array( $time ) ) {
			return $time;
		}
		return $time['original'];
	}

}
