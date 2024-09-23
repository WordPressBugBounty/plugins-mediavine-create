<?php
namespace Mediavine\Create;

use Mediavine\MV_DBI;
use Mediavine\Settings;
use \WP_REST_Request as Request;
use \WP_REST_Response as Response;
use \WP_Error as Error;
use Mediavine\WordPress\Support\Str;

/**
 * Endpoints for our v1 Creations API
 */
class Creations_API extends Creations {

	public function valid_creation( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();
		$error  = [
			'status'  => 400,
			'message' => 'Missing required field: [%s]',
			'data'    => $params,
		];
		if ( empty( $params['title'] ) ) {
			$error['message'] = sprintf( $error['message'], 'title' );
			return new \WP_Error( 'mv-missing-required-fields', __( 'Missing required field: [title]', 'mediavine' ), $error );
		}

		if ( empty( $params['type'] ) ) {
			$error['message'] = sprintf( $error['message'], 'type' );
			return new \WP_Error( 'Missing Required Field', __( 'Missing required field: [type]', 'mediavine' ), $error );
		}
		return $response;
	}

	public function computed_properties( \WP_REST_Request $request, \WP_REST_Response $response ) {
		return $response;
	}

	public function create( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();
		do_action( 'mv_pre_create_card', $params );
		if ( ! empty( $params['type'] ) ) {
			do_action( 'mv_pre_create_' . $params['type'] . '_card', $params );
		}
		$creation = self::$models_v2->mv_creations->create( $params );

		if ( empty( $creation ) ) {
			return new \WP_Error( 409, __( 'Entry Not Created', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the Creation could not be created', 'mediavine' ) ] );
		}
		do_action( 'mv_post_create_card', $creation );
		if ( ! empty( $params->type ) ) {
			do_action( 'mv_post_create_' . $params->type . '_card', $creation );
		}
		$data     = self::$api_services->prepare_item_for_response( $creation, $request );
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update a single creation
	 *
	 * @param Request  $request    Request that was sent
	 * @param Response $response   Response that was recieved
	 * @return Response $response
	 */
	public function update( Request $request, Response $response ) {
		$data = $request->get_params();

		$remove_original_object_id = apply_filters( 'mv_create_dbi_update_remove_original_object_id', true );
		if ( $remove_original_object_id ) {
			unset( $data['original_object_id'] );
		}
		unset( $data['published'] );

		// if custom_fields is not set, just set it to an empty array
		$data['custom_fields'] = $data['custom_fields'] ?? '[]';
		// Content with relations (like Lists) doesn't have thumbnails, but our UI
		// depends on having them, so we fake it.
		if ( ! empty( $data['type'] ) && 'list' === $data['type'] && count( $data['relations'] ) ) {
			// cycle through relations until we find one with a thumbnail
			foreach ( $data['relations'] as $relation ) {
				if ( empty( $relation['thumbnail_id'] ) ) {
					continue;
				}
				$data['thumbnail_id'] = $relation['thumbnail_id'];
				break;
			}
		}

		// if this is a list with a new Layout type, and Trellis is active, purge the Critical CSS for any post this list is on
		if ( ! empty( $data['type'] ) && 'list' === $data['type'] && Theme_Checker::is_trellis() && function_exists( 'mv_trellis_purge_page_critical_css' ) ) {
			$card = self::$models_v2->mv_creations->find_one( $data['id'] );
			if ( $card->layout !== $data['layout'] ) {
				$associated_posts = '[]' !== $data['associated_posts'] && ! empty( $data['associated_posts'] )
					? array_map( 'intval', json_decode( $data['associated_posts'] ) )
					: [];
				$associated_posts = ! empty( $associated_posts ) ? \get_posts( [ 'include' => $associated_posts ] ) : [];
				foreach ( $associated_posts as $post ) {
					/**
					 * Purge the critical CSS of posts associated with the given list when the list layout is updated.
					 *
					 * @function: mv_trellis_purge_page_critical_css
					 *
					 * @since 1.8.0
					 */
					mv_trellis_purge_page_critical_css( $post->ID );
				}
			}
		}

		do_action( 'mv_pre_update_card', $data );
		if ( ! empty( $data['type'] ) ) {
			do_action( 'mv_pre_update_' . $data['type'] . '_card', $data );
		}

		$updated = self::$models_v2->mv_creations->upsert(
			$data,
			[ 'id' => intval( $data['id'] ) ]
		);
		if ( empty( $updated ) ) {
			return new \WP_Error( 409, __( 'Entry Not Updated', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the Creation could not be updated', 'mediavine' ) ] );
		}
		do_action( 'mv_post_update_card', $updated );
		if ( ! empty( $updated->type ) ) {
			do_action( 'mv_post_update_' . $updated->type . '_card', $updated );
		}
		unset( $updated->published );
		unset( $updated->json_ld );

		$data                  = self::$api_services->prepare_item_for_response( $updated, $request );
		$data['custom_fields'] = json_decode( $data['custom_fields'] );
		$response              = API_Services::set_response_data( $data, $response );

		$response->set_status( 200 );

		return $response;
	}

	public function find( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$allowed_params = [
			'author',
			'category',
			'cuisine',
			'type',
		];

		$params = $request->get_params();

		$query_args = [];
		if ( isset( $response->query_args ) ) {
			$query_args = $response->query_args;
		}

		$query_args['where'] = [];

		if ( empty( $params['order_by'] ) ) {
			$query_args['order_by'] = 'created';
		}

		if ( isset( $params['search'] ) ) {
			$query_args['where']['published'] = $params['search'];
		}

		if ( ! empty( $params ) ) {
			foreach ( $params as $param => $value ) {
				if ( in_array( $param, $allowed_params, true ) ) {
					$query_args['where'][ $param ] = $value;
				}
			}
		}

		if ( ! empty( $params['show_trash'] ) ) {
			$show_trash               = (bool) $params['show_trash'];
			$query_args['show_trash'] = $show_trash;
		}

		$creations = self::$models_v2->mv_creations->find( $query_args );

		$response->data = [];
		$data           = [];
		if ( wp_is_numeric_array( $creations ) ) {
			foreach ( $creations as $creation ) {
				$creation                = static::bind_creation_relationships( $creation );
				$creation->id            = intval( $creation->id );
				$creation->thumbnail_uri = \wp_get_attachment_url( $creation->thumbnail_id );
				if ( isset( $creation->canonical_post_id ) ) {
					$creation->canonical_post_permalink = get_permalink( $creation->canonical_post_id );
				}

				unset( $creation->published );
				$data[] = $creation;
			}
			$response->set_status( 200 );
		}

		if ( ! empty( $response->data->custom_fields ) ) {
			$response->data->custom_fields = json_decode( $response->data->custom_fields );
		}

		// Send a total count of results based on the search params
		$response = API_Services::set_response_data( $data, $response );
		$response->header( 'X-Total-Items', self::$models_v2->mv_creations->get_count( $query_args ) );

		// Send a total count of a specific card type, regardless of search params.
		// If no card type is specified, get the count of all cards.
		$count_where = [];
		$type        = ! empty( $params['type'] ) ? $params['type'] : '';
		if ( $type ) {
			$count_where['where'] = compact( 'type' );
		}
		$response->header( 'X-Card-Type-Count', self::$models_v2->mv_creations->get_count( $count_where ) );
		return $response;
	}

	public static function bind_creation_relationships( $creation ) {
		$creation->supplies  = Supplies::get_creation_supplies( $creation->id );
		$creation->nutrition = Nutrition::get_creation_nutrition( $creation->id );
		$creation->products  = Products_Map::get_creation_products_map( $creation->id );
		$creation->relations = Relations::get_creation_relations( $creation->id );

		foreach ( $creation->nutrition as $property => $value ) {
			if ( ! is_null( $value ) ) {
				continue;
			}

			$creation->nutrition->{$property} = (string) $value;
		}

		// Set proper thumbnail image
		foreach ( $creation->products as &$product ) {
			$product->thumbnail_uri = Products_Map::get_correct_thumbnail_src( $product );
		}

		$creation->thumbnail_uri       = \wp_get_attachment_url( $creation->thumbnail_id );
		$creation->category_name       = self::$api_services->get_term_name( $creation->category );
		$creation->secondary_term_name = self::$api_services->get_term_name( $creation->secondary_term );

		$posts = json_decode( $creation->associated_posts );
		if ( $posts && count( $posts ) ) {
			$posts           = array_values( array_unique( $posts ) );
			$creation->posts = array_map(
				function( $id ) {
				return [
					'id'    => $id,
					'title' => html_entity_decode( get_the_title( $id ) ),
				];
				}, $posts
			);
		}

		return $creation;
	}

	public function find_one_by_object_id( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$creation = self::$models_v2->mv_creations->find_one(
			[
				'where' => [
					'object_id' => (int) $request['id'],
				],
			]
		);
		$request->set_param( 'id', $creation->id );
		return $this->find_one( $request, $response );
	}

	/**
	 * Get pagination details for create cards neighboring given card.
	 *
	 * @param Request $request
	 * @param Response $response
	 * @return Response $response
	 */
	public function get_pagination_links( Request $request, Response $response ) {
		$creation = $response->get_data();

		// Apparently some methods still return the response data incorrectly, so we have ot check for the second `data` key
		// on the response object
		if ( array_key_exists( 'data', $creation ) ) {
			$creation = $creation['data'];
		}

		$args = [
			'table'  => 'mv_creations',
			'fields' => [ 'id', 'object_id', 'title', 'type' ],
		];

		if ( is_array( $creation ) ) {
			$args['id']        = $creation['id'];
			$args['type']      = $creation['type'];
			$creation['links'] = Paginator::make_links( $args );
		}

		if ( is_object( $creation ) ) {
			$args['id']   = $creation->id;
			$args['type'] = $creation->type;

			$creation->links = Paginator::make_links( $args );
		}

		return API_Services::set_response_data( $creation, $response );
	}

	public function find_one_by_original_object_id( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$creation = self::$models_v2->mv_creations->find_one(
			[
				'where' => [
					'original_object_id' => (int) $request['id'],
				],
			]
		);
		$request->set_param( 'id', $creation->id );
		return $this->find_one( $request, $response );
	}

	public function find_one( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params   = $request->get_params();
		$creation = self::$models_v2->mv_creations->find_one( (int) $params['id'] );
		if ( empty( $creation ) ) {
			return new \WP_Error( 404, __( 'The Create Card could not be found', 'mediavine' ), [ 'request_params' => $params ] );
		}

		$creation = \Mediavine\Create\Creations::restore_video_data( $creation );
		$creation = \Mediavine\Create\Products::restore_product_images( $creation );
		$creation = \Mediavine\Create\Publish::maybe_republish( $creation );

		$creation->relations = Relations::get_creation_relations( $params['id'] );

		$associated_posts = [];
		if ( ! empty( $creation->associated_posts ) ) {
			$associated_posts = json_decode( $creation->associated_posts );
			foreach ( $associated_posts as &$post ) {
				$post = [
					'id'    => $post,
					'title' => htmlentities( get_the_title( $post ) ),
				];
			}
		}

		if ( ! empty( $creation->pinterest_img_id ) ) {
			$creation->pinterest_img_uri = wp_get_attachment_url( $creation->pinterest_img_id );
		}

		if ( isset( $creation->canonical_post_id ) ) {
			$creation->canonical_post_permalink = get_permalink( $creation->canonical_post_id );
		}

		if ( ! $creation ) {
			$response->set_status( 404 );
			$data     = [
				'code'    => 404,
				'message' => __( 'Creation not found', 'mediavine' ),
				'data'    => [],
			];
			$response = API_Services::set_response_data( $data, $response );
			return $response->as_error();
		}

		$creation = static::bind_creation_relationships( $creation );

		if ( ! empty( $creation->custom_fields ) ) {
			$creation->custom_fields = json_decode( $creation->custom_fields );
		}

		unset( $creation->published );
		unset( $creation->json_ld );

		// Ensure that creation is an integer. We can't use prepare_item_for_response because it coerces
		// empty arrays into empty strings, which breaks the UI.
		$creation->id = intval( $creation->id );
		$response     = API_Services::set_response_data( $creation, $response );
		$response->set_status( 200 );
		return $response;
	}

	public function destroy( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params   = $request->get_params();
		$creation = ( new MV_DBI( 'mv_creations' ) )->find_one( (int) $params['id'] );
		$deleted  = self::$models_v2->mv_creations->delete( (int) $params['id'] );

		if ( ! $deleted ) {
			return new \WP_Error( 409, __( 'Entry Could Not Be Deleted', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the Creation could not be deleted', 'mediavine' ) ] );
		}
		$this->destroy_related_content( $creation );
		$data     = self::$api_services->prepare_item_for_response( $deleted, $request );
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 204 );

		return $response;
	}

	public function delete( \WP_REST_Request $request, \WP_REST_Response $response ) {
		return $this->destroy( $request, $response );
	}

	/**
	 * Duplicate a given creation by id.
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 * @return \WP_REST_Response $response
	 */
	public function duplicate_create_card( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$creation_id = (int) $request->get_param( 'id' );
		$model       = new MV_DBI( 'mv_creations' );

		// get the creation to duplicate
		$creation = $model->find_one( $creation_id );
		if ( ! $creation ) {
			$response->set_status( 404 );
			$data     = [
				'code'    => 404,
				'message' => __( 'Creation not found', 'mediavine' ),
				'data'    => [],
			];
			$response = API_Services::set_response_data( $data, $response );
			return $response->as_error();
		}
		// unset irrelevant data
		// this creation will not be attached to any posts immediately, so remove associated posts
		unset(
			$creation->id,
			$creation->object_id,
			$creation->original_post_id,
			$creation->original_object_id,
			$creation->canonical_post_id,
			$creation->associated_posts,
			$creation->created,
			$creation->modified,
			$creation->rating,
			$creation->rating_count,
			$creation->json_ld
		);
		$metadata = json_decode( $creation->metadata, true );
		// set some historical data on the duplicated card
		$metadata['history']['duplicated'] = [
			'from_creation' => $creation_id,
			'on'            => date( 'Y-m-d H:i:s' ),
		];
		$creation->metadata                = wp_json_encode( $metadata );
		$creation->title                   = "{$creation->title} -- CLONED";
		$new                               = $model->create( (array) $creation );
		// duplicate related content (nutrition, products_map, supplies, images, relations)
		// we specifically don't duplicate reviews because that doesn't make sense
		$this->duplicate_related_content( $creation_id, $new->id );

		Creations::publish_creation( $new->id );

		$response = API_Services::set_response_data( $new, $response );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Duplicates content related to a create card
	 * Deletes related content to keep the DB clean.
	 *
	 * @param int $creation_id
	 * @return void
	 */
	public function duplicate_related_content( $original_creation_id, $new_creation_id ) {
		$related = [
			'mv_nutrition'    => 'creation',
			'mv_products_map' => 'creation',
			'mv_supplies'     => 'creation',
			'mv_images'       => 'associated_id',
			'mv_relations'    => 'creation',
		];
		// grab the models
		$dbi = MV_DBI::get_models( array_keys( $related ) );
		foreach ( $related as $table => $col ) {
			// find each related object for each model
			$res = $dbi->{$table}->find(
				[
					'where' => [
						$col => $original_creation_id,
					],
					'limit' => 10000,
				]
			);
			if ( empty( $res ) ) {
				continue;
			}
			foreach ( $res as $item ) {
				// remove relation to old card and reset timestamps
				unset(
					$item->id,
					$item->created,
					$item->modified
				);
				// set the relation via the column name `$col`
				$item->{$col} = $new_creation_id;
				// duplicate!
				$new = $dbi->{$table}->create( (array) $item );
			}
		}
	}

	/**
	 * Deletes related content to keep the DB clean.
	 *
	 * @param int $creation_id
	 * @return void
	 */
	public function destroy_related_content( $creation ) {
		$related = [
			'mv_nutrition'    => 'creation',
			'mv_products_map' => 'creation',
			'mv_reviews'      => 'creation',
			'mv_supplies'     => 'creation',
			'mv_images'       => 'associated_id',
			'mv_relations'    => 'relation_id',
		];
		$dbi     = MV_DBI::get_models( array_keys( $related ) );
		foreach ( $related as $table => $col ) {
			$dbi->{$table}->delete(
				[
					'col' => $col,
					'key' => $creation->id,
				]
			);
		}
		$this->remove_from_previous_import( $creation );
	}

	public function remove_from_previous_import( $creation ) {
		$metadata = json_decode( $creation->metadata, true );
		// If the creation wasn't imported, get outta here, ya punk!
		if ( empty( $metadata['import'] ) || empty( $metadata['import']['importer'] ) ) {
			return;
		}
		// Grab the importer metadata.
		$import = $metadata['import'];
		// Grab the imported recipes setting and convert it from json
		$json    = Settings::get_setting( 'mv_recipe_imported_recipes' );
		$decoded = json_decode( $json, true );
		// Filter the previously imported recipes and get rid of the creation
		// with the same original id and importer type.
		// We have to check the importer type because original_id is _not_ unique.
		$imported = array_filter(
			$decoded, function( $item ) use ( $import, $creation ) {
				return $creation->original_post_id !== $item['original_id'] && $item['type'] !== $import['importer'];
			}
		);
		// If there was no change, no need to query the DB again. Get outta here.
		if ( count( $decoded ) === count( $imported ) ) {
			return;
		}
		// Otherwise, let's re-encode the new array of previously imported recipes and update the DB.
		$previous = wp_json_encode( array_values( $imported ) );
		$settings = [
			'slug'  => 'mv_recipe_imported_recipes',
			'value' => $previous,
		];
		Settings::create_settings( $settings );
	}

	public function publish( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();

		$result = Creations::publish_creation( (int) $params['id'] );

		if ( ! empty( $result ) ) {
			$data     = [ 'published' => true ];
			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 201 );
		}

		return $response;
	}

	/**
	 * Index of a particular Creation type.
	 *
	 * Lists all Creations of the given type. Optionally search published data for text.
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 * @return array index of Creations
	 */
	public function index( \WP_REST_Request $request, \WP_REST_Response $response ) {
		global $wpdb;

		$default_params = [
			'search'         => '',
			'page'           => 1,
			'limit'          => 12,
			'category'       => null,
			'secondary_term' => null,
			'type'           => 'recipe', // change to `all` for a cumulative search
		];
		$params         = array_merge( $default_params, $request->get_params() );

		$search         = sanitize_text_field( $params['search'] );
		$limit          = intval( $params['limit'] );
		$offset         = ( intval( $params['page'] ) - 1 ) * $limit;
		$type           = $params['type'];
		$category       = $params['category'];
		$secondary_term = $params['secondary_term'];
		$total          = 0;

		$prepare_values = [];

		// We only want Create cards that are in posts and are type `$type`
		$where = 'canonical_post_id IS NOT NULL';
		// Filter by type unless we're looking at all types
		if ( 'all' !== $type ) {
			$where           .= " AND type='%s'";
			$prepare_values[] = $type;
		}
		// Filter by search query in the published data
		if ( ! empty( $search ) ) {
			$where           .= " AND published LIKE '%%%s%%'";
			$prepare_values[] = $search;
		}
		if ( ! is_null( $category ) ) {
			$term = get_term_by( 'name', $category, 'category', ARRAY_A );
			if ( ! empty( $term['term_id'] ) ) {
				$category_id = $term['term_id'];
				$where      .= " AND category={$category_id}";
			}
		}
		if ( ! is_null( $secondary_term ) ) {
			$term_type = 'recipe' === $type ? 'mv_cuisine' : 'mv_project_types';
			$term      = get_term_by( 'name', $secondary_term, $term_type, ARRAY_A );
			if ( ! empty( $term['term_id'] ) ) {
				$secondary_term_id = $term['term_id'];
				$where            .= " AND secondary_term={$secondary_term_id}";
			}
		}

		// This statement is prepared if there's any varialbes used in the where clause. If not, then we are safe.
		$statement = "SELECT id, title, thumbnail_id, canonical_post_id, category, secondary_term FROM {$wpdb->prefix}mv_creations WHERE {$where} LIMIT {$offset}, {$limit} ";
		$prepared  = $statement;
		if ( ! empty( $prepare_values ) ) {
			$prepared = $wpdb->prepare( $statement, $prepare_values );
		}

		// This statement is prepared if there's any varialbes used in the where clause. If not, then we are safe.
		$total_statement = "SELECT COUNT(*) as total FROM {$wpdb->prefix}mv_creations WHERE $where";
		$total_prepared  = $total_statement;
		if ( ! empty( $prepare_values ) ) {
			$total_prepared = $wpdb->prepare( $total_statement, $prepare_values );
		}

		// SECURITY CHECKED: These queries are properly prepared.
		$results       = $wpdb->get_results( $prepared );
		$total_results = $wpdb->get_results( $total_prepared );
		if ( ! empty( $total_results ) ) {
			$total = $total_results[0]->total;
		}
		if ( ! empty( $results ) ) {
			foreach ( $results as &$result ) {
				$result->thumbnail_uri = '';
				if ( ! empty( $result->thumbnail_id ) ) {
					$result->thumbnail_uri = \wp_get_attachment_url( $result->thumbnail_id );
				}
				$result->canonical_post_permalink = get_permalink( $result->canonical_post_id );
				$result->category_name            = self::$api_services->get_term_name( $result->category );
				$result->secondary_term_name      = self::$api_services->get_term_name( $result->secondary_term );
				unset( $result->thumbnail_id, $result->canonical_post_id, $result->category, $result->secondary_term );
			}
		}
		$response->set_headers( [ 'X-Total-Items' => $total ] );
		return API_Services::set_response_data( $results, $response );
	}
}
