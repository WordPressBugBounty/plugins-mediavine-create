<?php
namespace Mediavine\Create;

use Mediavine\MV_DBI;
use Mediavine\WordPress\Support\Arr;
use Mediavine\WordPress\Support\Str;

class Publish extends Plugin {

	public static function publish( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$creation_id = $request->get_param( 'id' );

		if ( ! $creation_id ) {
			return new \WP_Error(
				404, __( 'Entry Not Found', 'mediavine' ), [
					'message' => __( 'The Creation could not be found', 'mediavine' ),
					'class'   => 'Mediavine\Create\Publish',
					'method'  => 'publish',
				]
			);
		}

		$creation                = Creations::publish_creation( (int) $creation_id );
		$creation->thumbnail_uri = wp_get_attachment_url( $creation->thumbnail_id );
		$response                = API_Services::set_response_data( $creation, $response );
		return $response;
	}

	/**
	 * Conditionally Republish Creation at Run Time
	 * @param object $creation Creation DB object
	 * @return object $creation full creation object whether new or original
	 */
	public static function maybe_republish( $creation ) {
		self::do_actions( $creation->id );
		$should_republish = false;

		// $should_republish bool passed on to return prior result, preventing false negatives
		$should_republish = self::list_link_repair( $creation, $should_republish );
		$should_republish = self::remove_associated_post_revisions( $creation, $should_republish );
		$should_republish = self::fix_associated_posts_column( $creation, $should_republish );
		$should_republish = self::fix_imported_ratings_dates( $creation, $should_republish );
		$should_republish = self::fix_canonical_post_id( $creation, $should_republish );
		$should_republish = self::fix_create_slug( $creation, $should_republish );

		$publish_queue        = [];
		$publish_queue_option = get_option( 'mv_publish_queue' );

		if ( ! empty( $publish_queue_option ) ) {
			$publish_queue = json_decode( $publish_queue_option, true );
		}

		if ( in_array( $creation->id, $publish_queue, true ) ) {
			$should_republish = true;
			$publish_queue    = array_values(
				array_filter(
					$publish_queue, function( $item ) use ( $creation ) {
					return $item !== $creation->id;
					}
				)
			);
			update_option( 'mv_publish_queue', wp_json_encode( $publish_queue ) );
		}

		if ( empty( $creation->published ) || ! is_array( json_decode( $creation->published, true ) ) ) {
			$should_republish = true;
		}

		if ( $should_republish ) {
			$creation = \Mediavine\Create\Creations::publish_creation( $creation->id, false );
		}

		return $creation;
	}

	/**
	 * Repairs list links on a Create list
	 *
	 * @param object $creation Create card data
	 * @param bool $should_republish Current $should_republish value to potentially be passed on
	 * @return bool True if data updated and republish should happen, false if it doesn't need to happen
	 */
	private static function list_link_repair( $creation, $should_republish ) {

		if ( 'list' !== $creation->type ) {
			return $should_republish;
		}

		$metadata = [];
		if ( ! empty( $creation->metadata ) ) {
			$metadata = json_decode( $creation->metadata, true );
		}

		if ( ! empty( $metadata['list_link_repaired'] ) ) {
			return $should_republish;
		}

		$items = self::$models_v2->mv_relations->find(
			[
				'where' => [
					'creation' => $creation->id,
				],
			]
		);

		foreach ( $items as &$item ) {
			if ( 'card' !== $item->content_type ) {
				continue;
			}

			if ( $item->relation_id !== $item->canonical_post_id ) {
				continue;
			}

			$found_creation = self::$models_v2->mv_creations->find_one_by_id( $item->relation_id );

			if ( ! empty( $found_creation->canonical_post_id ) ) {
				$item->url = get_permalink( $found_creation->canonical_post_id );

				$updated_item = self::$models_v2->mv_relations->update(
					[
						'id'                => $item->id,
						'url'               => $item->url,
						'canonical_post_id' => $found_creation->canonical_post_id,
					]
				);
			}
		}

		$metadata['list_link_repaired'] = true;
		$updated_creation               = self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'       => $creation->id,
				'metadata' => wp_json_encode( $metadata ),
			]
		);

		return true;
	}


	private static function fix_associated_posts_column( $creation, $should_republish ) {
		if (
			empty( $creation->associated_posts ) ||
			Str::contains( 'fixed_associated_posts_column', $creation->metadata ) ||
			Str::contains( '""', $creation->associated_posts )
		) {
			return $should_republish;
		}
		$associated_posts = json_decode( $creation->associated_posts, true );
		$associated_posts = wp_json_encode( array_map( 'strval', $associated_posts ) );

		$updated_creation = self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'               => $creation->id,
				'associated_posts' => $associated_posts,
			]
		);
		if ( is_wp_error( $updated_creation ) ) {
			return $should_republish;
		}

		$metadata                                  = json_decode( $creation->metadata, true );
		$metadata['fixed_associated_posts_column'] = true;
		self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'       => $creation->id,
				'metadata' => wp_json_encode( $metadata ),
			]
		);
		return true;
	}


	private static function fix_canonical_post_id( $creation, $should_republish ) {
		// return $should_republish;
		if ( empty( $creation->canonical_post_id ) ) {
			return $should_republish;
		}

		$associated_posts = ! empty( $creation->associated_posts ) ? json_decode( $creation->associated_posts, true ) : [];
		if ( empty( $associated_posts ) || in_array( $creation->canonical_post_id, $associated_posts, true ) ) {
			return $should_republish;
		}

		// Use first associated post if found
		$canonical_post_id = Arr::first( $associated_posts );

		// Use original post ID if available and in associated posts
		if (
			! empty( $creation->original_post_id ) &&
			in_array( $creation->original_post_id, $associated_posts, true )
		) {
			$canonical_post_id = $creation->original_post_id;
		}

		if ( empty( $canonical_post_id ) ) {
			return $should_republish;
		}
		// Update creation with new metadata and associated posts
		self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'                => $creation->id,
				'canonical_post_id' => $canonical_post_id,
			]
		);

		return true;
	}

	/**
	 * Fix the slug for a Create post type if incorrect
	 *
	 * We check for '-creation' at the end of the slug. If it's not there then we will
	 * update the slug to include it. This will prevent Yoast SEO Premium from causing
	 * bad page redirects.
	 *
	 * @param object $creation Full creation data
	 * @param bool $should_republish Previous republish value
	 * @return bool True if we should republish or the previous value if no changes are to be made
	 */
	static public function fix_create_slug( $creation, $should_republish ) {
		$metadata = [];
		if ( ! empty( $creation->metadata ) ) {
			$metadata = json_decode( $creation->metadata, true );
		}

		if ( ! empty( $metadata['slug_repaired'] ) ) {
			return $should_republish;
		}

		$post_slug   = get_post_field( 'post_name', $creation->object_id );
		$update_slug = false;
		if ( ! empty( $post_slug ) ) {
			$end_of_slug = substr( $post_slug, -9 );
			if ( '-creation' !== $end_of_slug ) {
				$update_slug = true;
			}
		}
		if ( $update_slug ) {
			$update_post = wp_update_post(
				[
					'ID'        => $creation->object_id,
					'post_name' => $post_slug . '-creation',
				]
			);
			if ( is_wp_error( $update_post ) ) {
				return $should_republish;
			}

			// Legacy support for old Create post types, and old WP revision support
			global $wpdb;

			// Remove any trailing digits
			// SECURITY CHECKED: This query is properly sanitized. Custom LIKE doesn't work with preparation.
			$trimmed_slug   = preg_replace( '/-[0-9]*$/', '', $post_slug );
			$statement      = "SELECT * FROM {$wpdb->prefix}posts
				WHERE post_name LIKE '{$trimmed_slug}%'
				AND ( post_type = 'mv_create'
					OR post_type = 'mv_creations'
					OR post_type = 'mv_products'
					OR post_type = 'mv_recipes'
				)
			";
			$prepared       = $wpdb->prepare( $statement, [] );
			$matching_posts = $wpdb->get_results( $prepared );
			foreach ( $matching_posts as $matching_post ) {
				if ( ! empty( $matching_post->post_name ) ) {
					$end_of_slug = substr( $matching_post->post_name, -9 );
					if ( '-creation' !== $end_of_slug ) {
						$update_post = wp_update_post(
							[
								'ID'        => $matching_post->ID,
								'post_name' => $matching_post->post_name . '-creation',
							]
						);
						if ( is_wp_error( $update_post ) ) {
							return $should_republish;
						}
					}
				}
			}
		}

		$metadata['slug_repaired'] = true;
		self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'       => $creation->id,
				'metadata' => wp_json_encode( $metadata ),
			]
		);
		return true;
	}

	/**
	 * Removes previously associated revisions from a Create card
	 *
	 * @param object $creation Create card data
	 * @param bool $should_republish Current $should_republish value to potentially be passed on
	 * @return bool True if data updated and republish should happen, false if it doesn't need to happen
	 */
	private static function remove_associated_post_revisions( $creation, $should_republish ) {
		$metadata = [];
		if ( ! empty( $creation->metadata ) ) {
			$metadata = json_decode( $creation->metadata, true );
		}

		if ( ! empty( $metadata['revisions_removed'] ) ) {
			return $should_republish;
		}

		$associated_posts = [];
		if ( ! empty( $creation->associated_posts ) ) {
			$associated_posts = json_decode( $creation->associated_posts );
		}

		foreach ( $associated_posts as $key => $associated_post ) {
			$post_status      = get_post_status( $associated_post );
			$allowed_statuses = [
				'publish',
				'future',
				'draft',
				'pending',
				'private',
			];

			if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
				unset( $associated_posts[ $key ] );
			}
		}

		$metadata['revisions_removed'] = true;

		// Update creation with new metadata and associated posts
		self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'               => $creation->id,
				'metadata'         => wp_json_encode( $metadata ),
				'associated_posts' => wp_json_encode( array_values( array_unique( $associated_posts ) ) ),
			]
		);

		return true;
	}

	/**
	 * Fixes review creation dates for imported ratings/reviews.
	 *
	 * Recipe ratings imported previously were assigned a created date on import,
	 * which means all ratings imported for a given recipe had the same date. This function
	 * reassigns any rating creation dates possible.
	 *
	 * Remove December 2019
	 *
	 * @since 1.4.10
	 *
	 * @return void
	 */
	public static function fix_imported_ratings_dates( $creation, $should_republish ) {
		global $wpdb;

		if (
			'recipe' !== $creation->type ||
			! Str::contains( $creation->metadata, 'import' ) ||
			Str::contains( $creation->metadata, 'fixed_ratings_dates' ) ||
			empty( $creation->original_post_id )
		) {
			return $should_republish;
		}

		$dbi       = self::$models_v2->mv_reviews;
		$statement = "SELECT
			r.id as id,
			comment_date AS created,
			comment_date AS modified
			FROM {$wpdb->commentmeta} AS cm
			JOIN {$wpdb->comments} AS c ON (c.comment_ID = cm.comment_id)
			JOIN {$dbi->table_name} AS r ON (c.comment_author_email=r.author_email)
			WHERE c.comment_approved = 1
			AND cm.meta_key IN ('ERRating', 'cookbook_comment_rating', 'recipe_rating', 'wprm-comment-rating')
			AND cm.meta_value != 0
			AND c.comment_post_ID = %d";

		if ( Str::contains( $creation->metadata, [ 'meal_planner', 'recipe_maker' ] ) ) {
			if ( Str::contains( $creation->metadata, 'meal_planner' ) ) {
				$table_name = 'mpprecipe_ratings';
			}
			if ( Str::contains( $creation->metadata, 'recipe_maker' ) ) {
				$table_name = 'wprm_ratings';
			}
			$statement = "SELECT
				r.id as id,
				comment_date AS created,
				comment_date AS modified
				FROM {$wpdb->prefix}{$table_name} AS ir
				JOIN {$wpdb->comments} AS c ON (c.comment_ID = ir.comment_id)
				JOIN {$dbi->table_name} AS r ON (c.comment_author_email=r.author_email)
				WHERE c.comment_approved = 1
				AND c.comment_post_ID = %d";
		}

		// SECURITY CHECKED: This query is properly prepared.
		$prepared = $wpdb->prepare( $statement, [ $creation->original_post_id ] );
		$ratings  = $wpdb->get_results( $prepared, ARRAY_A );

		foreach ( $ratings as $data ) {
			$date             = date( 'Y-m-d H:i:s' );
			$data['created']  = isset( $data['created'] ) ? $data['created'] : $date;
			$data['modified'] = isset( $data['modified'] ) ? $data['modified'] : $date;

			if ( is_wp_error( $data ) ) {
				continue;
			}

			// because our insert, upsert, and update methods overwrite `created` and `modified` dates,
			// we have to manually perform an update here
			$normalized_data = $dbi->normalize_data( $data );
			add_filter( 'query', [ $dbi, 'allow_null' ] );
			$wpdb->update( $dbi->table_name, $normalized_data, [ 'id' => $normalized_data['id'] ] );
			remove_filter( 'query', [ $dbi, 'allow_null' ] );
		}

		$metadata                        = json_decode( $creation->metadata, true );
		$metadata['fixed_ratings_dates'] = true;

		// Update creation with new metadata and associated posts
		self::$models_v2->mv_creations->update_without_modified_date(
			[
				'id'       => $creation->id,
				'metadata' => wp_json_encode( $metadata ),
			]
		);

		return true;
	}

	/**
	 * Add Creations to republish queue.
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 *
	 * @return void|bool|array|\WP_REST_Response
	 */
	public static function republish_creations( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();

		if ( 'pubeng' !== $params['auth'] ) {
			return false;
		}

		if ( empty( $params['type'] ) ) {
			return static::add_all_to_publish_queue();
		}

		$query_args   = [
			'where'  => [
				'type' => $params['type'],
			],
			'select' => [
				'id',
			],
			'limit'  => 9999,
		];
		$model        = new MV_DBI( 'mv_creations' );
		$creations    = $model->find( $query_args );
		$creation_ids = [];
		foreach ( $creations as $creation ) {
			$creation_ids[] = $creation->id;
		}
		$publish_queue = static::update_publish_queue( $creation_ids );
		$response      = API_Services::set_response_data( $publish_queue, $response );
		return $response;
	}

	public static function do_actions( $id ) {
		$action_queues = get_option( 'mv_queues', [] );
		if ( empty( $action_queues ) ) {
			return;
		}
		$action_queues = json_decode( $action_queues, true );
		foreach ( $action_queues as $key => $name ) {
			$queue = get_option( 'mv_' . $name . '_queue' );
			if ( false === $queue || empty( $queue ) || 'null' === $queue ) {
				unset( $action_queues[ $key ] );
				continue;
			}
			$queued_ids = json_decode( $queue, true );
			if ( ! in_array( (string) $id, $queued_ids, true ) ) {
				continue;
			}
			do_action( 'mv_' . $name . '_queue_action', $id );
			$queued_ids = array_values(
				array_filter(
					$queued_ids, function( $item ) use ( $id ) {
						return $item !== $id;
					}
				)
			);
			if ( empty( $queued_ids ) ) {
				delete_option( 'mv_' . $name . '_queue' );
				unset( $action_queues[ $key ] );
				continue;
			}
			update_option( 'mv_' . $name . '_queue', wp_json_encode( $queued_ids ) );
		}
		update_option( 'mv_queues', wp_json_encode( $action_queues ) );
	}

	public static function selective_update_queue( $creation_ids = [], $name = '' ) {
		$queue        = [];
		$option       = 'mv_' . $name . '_queue';
		$queue_option = get_option( $option );

		if ( ! empty( $queue_option ) ) {
			$queue = json_decode( $queue_option, true );
		}

		foreach ( $creation_ids as $id ) {
		$queue[] = (string) $id;
		}

		$queue = array_values( array_unique( $queue ) );

		update_option( $option, wp_json_encode( $queue ) );
		static::add_to_queues( $name );

		return $queue;
	}

	public static function add_to_queues( $name ) {
		$queue_option = get_option( 'mv_queues' );
		$queue        = [];

		if ( ! empty( $queue_option ) ) {
			$queue = json_decode( $queue_option, true );
		}
		$queue[] = $name;

		$queue = array_values( array_unique( $queue ) );

		update_option( 'mv_queues', wp_json_encode( $queue ) );
	}

	/**
	 * Manage queue of items that need to be republished
	 * @param array $creation_ids Numeric Array of Creation IDs
	 * @return array $publish_queue Numeric Array of Creation IDs that need republish
	 */
	public static function update_publish_queue( $creation_ids = [] ) {
		$publish_queue        = [];
		$publish_queue_option = get_option( 'mv_publish_queue' );

		if ( ! empty( $publish_queue_option ) ) {
			$publish_queue = json_decode( $publish_queue_option, true );
		}

		foreach ( $creation_ids as $an_id ) {
			$publish_queue[] = (string) $an_id;
		}

		$publish_queue = array_values( array_unique( $publish_queue ) );

		update_option( 'mv_publish_queue', wp_json_encode( $publish_queue ) );
		return $publish_queue;
	}

	public static function add_all_to_publish_queue() {
		$model        = new MV_DBI( 'mv_creations' );
		$creations    = $model->find();
		$creation_ids = [];
		foreach ( $creations as $creation ) {
			$creation_ids[] = $creation->id;
		}
		return static::update_publish_queue( $creation_ids );
	}

	public static function prepare_creation( $creation ) {
		if ( empty( $creation->id ) ) {
			return new \WP_Error( 404, __( 'Entry Not Found', 'mediavine' ), [ 'message' => __( 'The Creation could not be found', 'mediavine' ) ] );
		}

		unset( $creation->published );
		return $creation;
	}

	public static function prepare_jsonld( $creation ) {
		if ( ! $creation->schema_display ) {
			return $creation;
		}

		$JSON_LD = JSON_LD::get_instance();

		if ( ! $creation->author ) {
			$creation->author = \Mediavine\Settings::get_setting( self::$settings_group . '_copyright_attribution' );
		}

		// Pinterest image should not be included in google schema
		if ( empty( $creation->images ) ) {
			$json_ld = $JSON_LD->build_json_ld( (array) $creation, $creation->type );

			if ( ! empty( $json_ld ) ) {
				$creation->json_ld = wp_json_encode( $json_ld );
			}
			return $creation;
		}

		// Clone used because shallow copy was made, so $cleaned_image_creation passing a
		// referenced object of $creation (http://php.net/manual/en/language.oop5.cloning.php)
		$cleaned_image_creation = clone( $creation );
		$cleaned                = [];

		foreach ( $cleaned_image_creation->images as $image ) {
			if ( 'mv_create_vert' === $image['image_size'] ) {
				continue;
			}
			$cleaned[] = $image;
		}

		$cleaned_image_creation->images = $cleaned;
		$json_ld                        = $JSON_LD->build_json_ld( (array) $cleaned_image_creation, $cleaned_image_creation->type );

		if ( ! empty( $json_ld ) ) {
			$creation->json_ld = wp_json_encode( $json_ld );
		}

		return $creation;
	}

	public static function prepare_create_settings( $creation ) {
		$create_settings = [];

		$create_settings = apply_filters( 'mv_publish_create_settings', $create_settings );

		$creation->create_settings = $create_settings;
		return $creation;
	}

	public static function prepare_supplies( $creation ) {

		if ( empty( $creation->supplies ) ) {
			return $creation;
		}

		$supplies = $creation->supplies;
		usort( $supplies, [ 'Mediavine\Create\Supplies', 'sort_supply' ] );

		if ( 'recipe' === $creation->type ) {
			$ingredients = array_filter(
				$supplies, function( $supply ) {
					return 'ingredients' === $supply->type;
				}
			);
			if ( $ingredients ) {
				$creation->ingredients = Supplies::put_supplies_in_groups_array( $ingredients );
			}
		}

		if ( 'diy' === $creation->type ) {
			$materials = array_filter(
				$supplies, function( $supply ) {
					return 'materials' === $supply->type;
				}
			);
			if ( $materials ) {
				$creation->materials = Supplies::put_supplies_in_groups_array( $materials );
			}
			$tools = array_filter(
				$supplies, function( $supply ) {
					return 'tools' === $supply->type;
				}
			);
			if ( $tools ) {
				$creation->tools = Supplies::put_supplies_in_groups_array( $tools );
			}
		}

		unset( $creation->supplies );

		return $creation;
	}

	public static function prepare_relations( $creation ) {
		if ( 'list' === $creation->type ) {
			$relations  = $creation->relations;
			$list_items = array_filter(
				$relations, function( $item ) {
					return 'listItems' === $item->type;
				}
			);
			if ( $list_items ) {
				$creation->list_items = array_values( $list_items );
			}
		}

		unset( $creation->relations );
		return $creation;
	}

	public static function prepare_instructions( $creation ) {
		$creation->instructions = html_entity_decode( $creation->instructions );

		if ( ( 'diy' === $creation->type ) || ( 'recipe' === $creation->type ) ) {
			$dom = new \DOMDocument;
			if ( function_exists( 'libxml_use_internal_errors' ) ) {
				libxml_use_internal_errors( true );
			}
			$load = $dom->loadHTML( mb_convert_encoding( $creation->instructions, 'HTML-ENTITIES', 'UTF-8' ) );
			if ( function_exists( 'libxml_use_internal_errors' ) ) {
				libxml_use_internal_errors( false );
			}
			if ( ! $load ) {
				return $creation;
			}

			$lis = $dom->getElementsByTagName( 'li' );
			$i   = 1; // start at 1 because it's outputting HTML for instructions, so step 1 should be `mv_create_123_1`

			foreach ( $lis as $li ) {
				// the schema outputs the anchor as `mv_create_123_1`, so we do the same here
				$li->setAttribute( 'id', "mv_create_{$creation->id}_{$i}" );
				++$i;
			}

			// Remove the doctype when saving
			$creation->instructions = preg_replace( '~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $dom->saveHTML() );
		}

		return $creation;
	}

	public static function prepare_ratings( $creation ) {
		$rating       = Reviews_Models::get_creation_rating( $creation->id );
		$rating_count = Reviews_Models::get_creation_rating_count( $creation->id );

		if ( $rating && $rating_count ) {
			$creation->rating       = $rating;
			$creation->rating_count = $rating_count;
		}

		return $creation;
	}

	public static function prepare_posts( $creation ) {
		$associated_posts = [];
		if ( ! empty( $creation->associated_posts ) ) {
			$associated_posts = json_decode( $creation->associated_posts );
			foreach ( $associated_posts as &$post ) {
				$post = [
					'id'    => $post,
					'title' => get_the_title( $post ),
				];
			}
		}

		$creation->posts = $associated_posts;
		return $creation;
	}

	/**
	 * Prepares the image data for publishing
	 *
	 * @param object $creation Creation data
	 * @return object Updated creation data
	 */
	public static function prepare_images( $creation ) {
		if ( empty( $creation->thumbnail_id ) && empty( $creation->pinterest_img_id ) ) {
			return $creation;
		}
		if ( empty( $creation->thumbnail_id ) ) {
			$creation->thumbnail_id = 0;
		}
		if ( empty( $creation->pinterest_img_id ) ) {
			$creation->pinterest_img_id = 0;
		}
		$images = Creations::add_images_to_creation( $creation, $creation->thumbnail_id, $creation->pinterest_img_id, $creation->type );

		if ( ! empty( $images ) ) {
			// Make sure we generate base image sizes for published data
			$images           = Images::get_available_image_sizes( $images );
			$creation->images = $images;
		}

		return $creation;
	}

	public static function prepare_times( $creation ) {
		$times_to_parse = [
			'prep_time',
			'active_time',
			'additional_time',
			'perform_time',
			'total_time',
		];

		// similar to how juration parses time units
		$units_to_parse = apply_filters(
			'mv_time_units_to_parse', [
				'years'   => 31536000,
				'months'  => 2628000,
				'days'    => 86400,
				'hours'   => 3600,
				'minutes' => 60,
				'seconds' => 1,
			]
		);

		$times_to_parse = apply_filters( 'mv_times_to_parse', $times_to_parse );

		foreach ( $times_to_parse as $time_to_parse ) {
			if ( empty( $creation->{$time_to_parse} ) ) {
				$creation->{$time_to_parse} = null;
				continue;
			}

			$seconds = (int) floor( $creation->{$time_to_parse} );

			$time_array['original'] = $seconds;
			$remaining              = $seconds;
			foreach ( $units_to_parse as $unit => $unit_value ) {
				$value = (int) floor( round( $remaining * 1000 ) / 1000 / $unit_value );

				if ( 0 !== $value ) {
					$time_array[ $unit ] = $value;
					$remaining           = $remaining % $unit_value;
				} else {
					$time_array[ $unit ] = 0;
				}
			}

			$creation->{$time_to_parse} = $time_array;
		}

		return $creation;
	}

	private static function format_supplies( $supplies ) {

		$formatted = [];
		foreach ( $supplies as $supply ) {
			$formatted[ $supply->type ][] = $supply;
		}

		return $formatted;
	}

}
