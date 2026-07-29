<?php
namespace Mediavine\Create;

use Mediavine\MV_DBI;

class Publish extends Plugin {

	public static function publish( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$creation_id = $request->get_param( 'id' );

		if ( ! $creation_id ) {
			return new \WP_Error(
				404, __( 'Entry Not Found', 'mediavine-create' ), [
					'message' => __( 'The Creation could not be found', 'mediavine-create' ),
					'class'   => 'Mediavine\Create\Publish',
					'method'  => 'publish',
				]
			);
		}

		$creation = Creations::publish_creation( (int) $creation_id );
		if ( empty( $creation ) ) {
			return new \WP_Error(
				404, __( 'Entry Not Found', 'mediavine-create' ), [
					'message' => __( 'The Creation could not be found', 'mediavine-create' ),
					'class'   => 'Mediavine\Create\Publish',
					'method'  => 'publish',
				]
			);
		}
		$creation->thumbnail_uri = wp_get_attachment_url( $creation->thumbnail_id );
		$response                = API_Services::set_response_data( $creation, $response );
		return $response;
	}

	/**
	 * Whether a render-time republish may run for the current request.
	 *
	 * A render-time republish is a write (it drains queues, regenerates the
	 * published snapshot and can flip a draft to published). Front-end renders
	 * keep that self-healing behaviour, since a card only reaches them through
	 * a post its author placed it in. REST reads are public surface where the
	 * caller picks the card id, so an anonymous caller must never be able to
	 * trigger the write by addressing an id directly.
	 *
	 * @return bool True when the current request may perform the write.
	 */
	public static function can_republish_at_render() {
		$is_rest_request = function_exists( 'wp_is_rest_endpoint' )
			? wp_is_rest_endpoint()
			: ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		if ( ! $is_rest_request ) {
			return true;
		}

		return \Mediavine\Permissions::is_user_authorized();
	}

	/**
	 * Conditionally Republish Creation at Run Time
	 * @param object $creation Creation DB object
	 * @return object $creation full creation object whether new or original
	 */
	public static function maybe_republish( $creation ) {
		// CVE-2026-16992: leave the row (and the queues) untouched rather than
		// half-processing them, so an anonymous REST read never mutates state.
		if ( ! self::can_republish_at_render() ) {
			return $creation;
		}

		self::do_actions( $creation->id );
		$should_republish = false;

		$publish_queue        = [];
		$publish_queue_option = get_option( 'mv_publish_queue' );

		if ( ! empty( $publish_queue_option ) ) {
			$publish_queue = json_decode( $publish_queue_option ?: '[]', true );
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

		if ( empty( $creation->published ) || ! is_array( json_decode( $creation->published ?: '{}', true ) ) ) {
			$should_republish = true;
		}

		if ( $should_republish ) {
			$creation = \Mediavine\Create\Creations::publish_creation( $creation->id, false );
		}

		return $creation;
	}

	/**
	 * Add Creations to republish queue.
	 *
	 * Capability is enforced by the route `permission_callback`
	 * (`API_Services::permitted`). An explicit `confirm` query/body param is
	 * also required so a casually authenticated GET cannot enqueue a full-site
	 * republish.
	 *
	 * @param \WP_REST_Request  $request  Incoming REST request.
	 * @param \WP_REST_Response $response Response being built.
	 * @return void|bool|array|\WP_REST_Response|\WP_Error
	 */
	public static function republish_creations( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();

		if ( empty( $params['confirm'] ) ) {
			return new \WP_Error(
				'mv_create_republish_unconfirmed',
				__( 'Republish requires an explicit confirm parameter.', 'mediavine-create' ),
				[ 'status' => 400 ]
			);
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
		$action_queues = json_decode( $action_queues ?: '[]', true );
		foreach ( $action_queues as $key => $name ) {
			$queue = get_option( 'mv_' . $name . '_queue' );
			if ( false === $queue || empty( $queue ) || 'null' === $queue ) {
				unset( $action_queues[ $key ] );
				continue;
			}
			$queued_ids = json_decode( $queue ?: '[]', true );
			if ( ! in_array( (string) $id, $queued_ids, true ) ) {
				continue;
			}
			do_action( 'mv_' . $name . '_queue_action', $id );
			$queued_ids = array_values(
				array_filter(
					$queued_ids, function( $item ) use ( $id ) {
						// Cast both sides: queue ids are stored as strings, but
						// callers may pass an int (membership check already casts).
						return (string) $item !== (string) $id;
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
			$queue = json_decode( $queue_option ?: '[]', true );
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
			$queue = json_decode( $queue_option ?: '[]', true );
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
			$publish_queue = json_decode( $publish_queue_option ?: '[]', true );
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
			return new \WP_Error( 404, __( 'Entry Not Found', 'mediavine-create' ), [ 'message' => __( 'The Creation could not be found', 'mediavine-create' ) ] );
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
		// Clean up empty tags from Slate editor
		$cleaned_instructions = \Mediavine\Create\Helpers\Instructions_Cleaner::clean_instructions( $creation->instructions, $creation->id );
		
		if ( null === $cleaned_instructions ) {
			$creation->instructions = null;
			return $creation;
		}
		
		// Apply HTML entity decoding
		$creation->instructions = html_entity_decode( $cleaned_instructions );
		
		// Add schema IDs for recipe and DIY cards only
		if ( in_array( $creation->type, [ 'recipe', 'diy' ] ) ) {
			$creation->instructions = \Mediavine\Create\Helpers\Schema_Id_Injector::add_schema_ids( 
				$creation->instructions, 
				$creation->id 
			);
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
			$associated_posts = json_decode( $creation->associated_posts ?: '[]', true );
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
		$images = Creations::add_images_to_creation( $creation, $creation->thumbnail_id, $creation->pinterest_img_id );

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
