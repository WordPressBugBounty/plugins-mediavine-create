<?php
namespace Mediavine\Create;

use Mediavine\MV_DBI;
use Mediavine\Settings;
use \WP_REST_Request as Request;
use \WP_REST_Response as Response;

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
				$associated_posts = array_map('intval', json_decode($data['associated_posts'] ?: '[]'))
					? array_map( 'intval', json_decode($data['associated_posts'] ?: '[]') )
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
		// Set is_published flag before unsetting the published data
		$updated->is_published = ! empty( $updated->published );
		unset( $updated->published );
		unset( $updated->json_ld );

		$data                  = self::$api_services->prepare_item_for_response( $updated, $request );
		$data['custom_fields'] = json_decode($data['custom_fields'] ?: '{}');
		$response              = API_Services::set_response_data( $data, $response );

		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get unique authors from all creations.
	 *
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Response $response
	 * @return \WP_REST_Response
	 */
	public function get_authors( \WP_REST_Request $request, \WP_REST_Response $response ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mv_creations';

		// SECURITY CHECKED: No user input in this query
		$results = $wpdb->get_results(
			"SELECT author, COUNT(*) as count FROM {$table_name} WHERE author IS NOT NULL AND author != '' GROUP BY author ORDER BY author ASC"
		);

		$authors = [];
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$authors[] = [
					'name'  => $row->author,
					'count' => (int) $row->count,
				];
			}
		}

		return API_Services::set_response_data( $authors, $response );
	}

	public function find( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$allowed_params = [
			'author',
			'category',
			'cuisine',
			'type',
			'missing_fields',
		];

		$params = $request->get_params();

		$query_args = [];
		if ( isset( $response->query_args ) ) {
			$query_args = $response->query_args;
		}

		$query_args['where'] = [];

		if ( empty( $params['order_by'] ) ) {
			$query_args['order_by'] = 'created';
		} elseif ( 'rating' === $params['order_by'] ) {
			// Use weighted rating (Bayesian average) that considers both rating value and count
			// Formula: (v/(v+m)) * R + (m/(v+m)) * C
			// where v = rating_count, m = minimum votes (5), R = rating, C = mean rating (4.0)
			// This ensures that a 5-star rating with 1 review ranks lower than a 4.9-star with 50 reviews
			// CASE statement ensures cards with NULL ratings are pushed to the bottom (0 weighted score)
			$query_args['order_by'] = 'CASE WHEN rating IS NULL OR rating_count IS NULL OR rating_count = 0 THEN 0 ELSE ((rating_count / (rating_count + 5)) * rating + (5 / (rating_count + 5)) * 4.0) END';
		}

		if ( ! empty( $params['search'] ) ) {
			$query_args['where']['published'] = $params['search'];
		}

		if ( ! empty( $params ) ) {
			foreach ( $params as $param => $value ) {
				if ( in_array( $param, $allowed_params, true ) ) {
					// Skip missing_fields as it requires special handling
					if ( 'missing_fields' === $param ) {
						continue;
					}
					// Support comma-separated type values (e.g. "recipe,diy")
					if ( 'type' === $param && false !== strpos( $value, ',' ) ) {
						$query_args['type_in'] = array_map( 'trim', explode( ',', $value ) );
						continue;
					}
					$query_args['where'][ $param ] = $value;
				}
			}
		}

		if ( ! empty( $params['show_trash'] ) ) {
			$show_trash               = (bool) $params['show_trash'];
			$query_args['show_trash'] = $show_trash;
		}

		// Parse exclude_type (comma-separated) so callers can hide card types
		// from the results, e.g. the reviews card picker hides List cards.
		$exclude_type_in = [];
		if ( ! empty( $params['exclude_type'] ) ) {
			$exclude_type_in = array_map( 'trim', explode( ',', $params['exclude_type'] ) );
		}

		// Check if we need to use custom SQL for advanced filters
		$has_linked_posts_filter = ! empty( $params['linked_posts'] ) && in_array( $params['linked_posts'], [ 'has', 'none' ], true );
		$has_created_after       = ! empty( $params['created_after'] );
		$has_created_before      = ! empty( $params['created_before'] );
		$has_missing_fields      = ! empty( $params['missing_fields'] ) && filter_var( $params['missing_fields'], FILTER_VALIDATE_BOOLEAN );
		$has_post_id             = ! empty( $params['post_id'] ) && is_numeric( $params['post_id'] );
		$has_type_in             = ! empty( $query_args['type_in'] );
		$has_exclude_type        = ! empty( $exclude_type_in );

		// If any advanced filter is set, use custom SQL
		if ( $has_linked_posts_filter || $has_created_after || $has_created_before || $has_missing_fields || $has_post_id || $has_type_in || $has_exclude_type ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'mv_creations';

			// Build base query with existing where conditions
			$where_conditions = [];
			$prepare_params   = [];

			// Columns that use LIKE instead of exact match (mirrors MV_DBI::find logic)
			$like_columns = [ 'published', 'title', 'post_title', 'associated_posts' ];

			if ( ! empty( $query_args['where'] ) ) {
				foreach ( $query_args['where'] as $col => $val ) {
					if ( in_array( $col, $like_columns, true ) ) {
						$where_conditions[] = "$col LIKE %s";
						$prepare_params[]   = '%' . $wpdb->esc_like( $val ) . '%';
					} else {
						$where_conditions[] = "$col = %s";
						$prepare_params[]   = $val;
					}
				}
			}

			// Handle linked_posts filter
			// Note: We use %s placeholders for string literals because wpdb->prepare()
			// converts the bare string 'null' to SQL NULL, breaking the comparison.
			if ( $has_linked_posts_filter ) {
				if ( 'has' === $params['linked_posts'] ) {
					$where_conditions[] = "(associated_posts IS NOT NULL AND associated_posts != %s AND associated_posts != %s)";
					$prepare_params[]   = '';
					$prepare_params[]   = '[]';
				} else {
					$where_conditions[] = "(associated_posts IS NULL OR associated_posts = %s OR associated_posts = %s)";
					$prepare_params[]   = '';
					$prepare_params[]   = '[]';
				}
			}

			// Handle date filters
			if ( $has_created_after ) {
				$date               = sanitize_text_field( $params['created_after'] );
				$where_conditions[] = "created >= %s";
				$prepare_params[]   = $date;
			}

			if ( $has_created_before ) {
				$date               = sanitize_text_field( $params['created_before'] );
				$where_conditions[] = "created <= %s";
				$prepare_params[]   = $date . ' 23:59:59';
			}

			// Handle missing_fields filter
			if ( $has_missing_fields ) {
				$where_conditions[] = "(thumbnail_id IS NULL OR thumbnail_id = '' OR description IS NULL OR description = '')";
			}

			// Handle post_id filter - matches cards linked to this post via canonical_post_id or original_post_id
			if ( $has_post_id ) {
				$post_id            = absint( $params['post_id'] );
				$where_conditions[] = "(canonical_post_id = %d OR original_post_id = %d)";
				$prepare_params[]   = $post_id;
				$prepare_params[]   = $post_id;
			}

			// Handle comma-separated type filter (e.g. "recipe,diy")
			if ( $has_type_in ) {
				$type_placeholders = implode( ', ', array_fill( 0, count( $query_args['type_in'] ), '%s' ) );
				$where_conditions[] = "type IN ($type_placeholders)";
				foreach ( $query_args['type_in'] as $type_val ) {
					$prepare_params[] = sanitize_text_field( $type_val );
				}
			}

			// Handle exclude_type filter (e.g. "list") — used by the reviews card
			// picker so List cards (which have no reviews) never appear there.
			if ( $has_exclude_type ) {
				$exclude_placeholders = implode( ', ', array_fill( 0, count( $exclude_type_in ), '%s' ) );
				$where_conditions[]   = "type NOT IN ($exclude_placeholders)";
				foreach ( $exclude_type_in as $type_val ) {
					$prepare_params[] = sanitize_text_field( $type_val );
				}
			}

			$where_clause = ! empty( $where_conditions ) ? implode( ' AND ', $where_conditions ) : '1=1';

			// Build complete SQL query
			$order_by = ! empty( $query_args['order_by'] ) ? $query_args['order_by'] : 'created';
			$order    = ! empty( $params['order'] ) ? strtoupper( $params['order'] ) : 'DESC';
			$limit    = ! empty( $params['limit'] ) ? intval( $params['limit'] ) : 50;
			$offset   = ! empty( $params['offset'] ) ? intval( $params['offset'] ) : 0;

			// SECURITY CHECKED: This query uses properly prepared statements
			$sql              = "SELECT * FROM $table_name WHERE $where_clause ORDER BY $order_by $order LIMIT %d, %d";
			$prepare_params[] = $offset;
			$prepare_params[] = $limit;

			$query_args['sql']    = $sql;
			$query_args['params'] = $prepare_params;

			// Clear the where array since we're using custom SQL
			unset( $query_args['where'] );
		}

		$creations = self::$models_v2->mv_creations->find( $query_args );

		$response->data = [];
		$data           = [];
		if ( wp_is_numeric_array( $creations ) ) {
			foreach ( $creations as $creation ) {
				$creation                = static::bind_creation_relationships( $creation );
				$creation->id            = intval( $creation->id );
				// thumbnail_uri is already set in bind_creation_relationships, but ensure it's set for backwards compatibility
				if ( ! isset( $creation->thumbnail_uri ) ) {
					$creation->thumbnail_uri = ! empty( $creation->thumbnail_id ) ? \wp_get_attachment_url( $creation->thumbnail_id ) : '';
				}
				if ( isset( $creation->canonical_post_id ) ) {
					$creation->canonical_post_permalink = get_permalink( $creation->canonical_post_id );
				}

				unset( $creation->published );
				$data[] = $creation;
			}
			$response->set_status( 200 );
		}

		if ( ! empty( $response->data->custom_fields ) ) {
			$response->data->custom_fields = json_decode($response->data->custom_fields ?: '{}');
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

		$creation->thumbnail_uri       = ! empty( $creation->thumbnail_id ) ? \wp_get_attachment_url( $creation->thumbnail_id ) : '';
		$creation->category_name       = self::$api_services->get_term_name( $creation->category ?? null );
		$creation->secondary_term_name = self::$api_services->get_term_name( $creation->secondary_term ?? null );

		$posts = json_decode( $creation->associated_posts ?? '[]' );
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
	 * @param Request  $request
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
			$associated_posts = json_decode( $creation->associated_posts ?: '[]' );
			foreach ( $associated_posts as &$post ) {
				$post = [
					'id'    => $post,
					'title' => htmlentities( get_the_title( $post ) ?: '' ),
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
			$creation->custom_fields = json_decode($creation->custom_fields ?: '{}');
		}

		// Set is_published flag before unsetting the published data
		$creation->is_published = ! empty( $creation->published );
		unset( $creation->published );
		unset( $creation->json_ld );

		// Add unit conversion data if feature is enabled
		if (
			'recipe' === $creation->type &&
			GateKeeper::can_access( GateKeeper::FEATURE_UNIT_CONVERSION ) &&
			Settings::get_setting( 'mv_create_enable_unit_conversion', false )
		) {
			$conversion_data = Creations_Views::get_unit_conversion_data( intval( $creation->id ) );
			if ( ! empty( $conversion_data ) ) {
				$creation->unit_conversions = $conversion_data;
			}
		}

		// Ensure that creation is an integer. We can't use prepare_item_for_response because it coerces
		// empty arrays into empty strings, which breaks the UI.
		$creation->id = intval( $creation->id );
		$response     = API_Services::set_response_data( $creation, $response );
		$response->set_status( 200 );
		return $response;
	}

	public function find_published( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params   = $request->get_params();
		$creation = self::$models_v2->mv_creations->find_one( (int) $params['id'] );

		if ( empty( $creation ) ) {
			return new \WP_Error( 404, __( 'The Create Card could not be found', 'mediavine' ), [ 'request_params' => $params ] );
		}

		if ( empty( $creation->published ) ) {
			return new \WP_Error( 404, __( 'The Create Card has not been published', 'mediavine' ), [ 'request_params' => $params ] );
		}

		$published = json_decode( $creation->published );

		$response->set_data( $published );
		$response->set_status( 200 );
		return $response;
	}

	public function find_json_ld( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params   = $request->get_params();
		$creation = self::$models_v2->mv_creations->find_one( (int) $params['id'] );

		if ( empty( $creation ) ) {
			return new \WP_Error( 404, __( 'The Create Card could not be found', 'mediavine' ), [ 'request_params' => $params ] );
		}

		if ( empty( $creation->json_ld ) ) {
			return new \WP_Error( 404, __( 'The Create Card has no JSON-LD', 'mediavine' ), [ 'request_params' => $params ] );
		}

		$json_ld = json_decode( $creation->json_ld );

		$response->set_data( $json_ld );
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
	 * @param \WP_REST_Request  $request
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
		$metadata = json_decode($creation->metadata ?: '{}', true);
		// set some historical data on the duplicated card
		$metadata['history']['duplicated'] = [
			'from_creation' => $creation_id,
			'on'            => date( 'Y-m-d H:i:s' ),
		];
		$creation->metadata                = wp_json_encode( $metadata );
		$creation->title                   = "Copy of {$creation->title}";
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
		$metadata = json_decode($creation->metadata ?: '{}', true);
		// If the creation wasn't imported, get outta here, ya punk!
		if ( empty( $metadata['import'] ) || empty( $metadata['import']['importer'] ) ) {
			return;
		}
		// Grab the importer metadata.
		$import = $metadata['import'];
		// Grab the imported recipes setting and convert it from json
		$json    = Settings::get_setting( 'mv_recipe_imported_recipes' );
		$decoded = json_decode($json ?: '{}', true);
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
	 * @param \WP_REST_Request  $request
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
