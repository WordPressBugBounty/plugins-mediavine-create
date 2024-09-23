<?php
namespace Mediavine\Create;

class Relations extends Plugin {

	public $api_root = 'mv-create';

	public $api_version = 'v1';

	public $api = null;

	private $table_name = 'mv_relations';

	public $schema = [
		'type'              => 'varchar(20)',
		'content_type'      => 'varchar(20)',
		'secondary_type'    => 'varchar(20)',
		'creation'          => 'bigint(20)',
		'relation_id'       => 'bigint(20)',
		'title'             => 'longtext',
		'description'       => 'longtext',
		'canonical_post_id' => 'bigint(20)',
		'thumbnail_id'      => 'bigint(20)',
		'url'               => 'longtext',
		'thumbnail_credit'  => 'longtext',
		'position'          => 'mediumint(9)',
		'meta'              => 'longtext',
		'nofollow'          => 'tinyint(1)',
		'link_text'         => 'longtext',
		'asin'              => 'varchar(10)',
		'expires'           => 'datetime',
	];

	/**
	 * @var Queue
	 */
	public $amazon_queue;

	/**
	 * @var Amazon
	 */
	public $amazon;

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	function init() {
		$this->api = new Relations_API();

		$this->amazon       = Amazon::get_instance();
		$this->amazon_queue = new Queue(
			[
				'queue_name'     => 'mv_amazon_link_queue',
				'transient_name' => 'mv_amazon_link_queue_lock',
				'lock_timeout'   => 43200, // check queue every 12 hours
				'auto_unlock'    => true,
			]
		);

		add_filter( 'mv_custom_schema', [ $this, 'custom_schema' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'init', [ $this, 'step_amazon_queue' ] );
		add_action( 'init', [ $this, 'refresh_amazon_links' ] );
	}

	public function custom_schema( $tables ) {
		$tables[] = [
			'version'    => self::DB_VERSION,
			'table_name' => $this->table_name,
			'schema'     => $this->schema,
		];
		return $tables;
	}

	public static function get_index_item_image( $relation, $index ) {
		$layout = ! empty( $index->layout ) ? $index->layout : 'magazine';
		switch ( $layout ) {
			case 'arrow':
				$size = 'mv_create_1x1';
				break;
			case 'book':
			default:
				$size = 'mv_create_4x3';
				break;
			case 'gallery':
				$size = 'mv_create_3x4';
				break;
			case 'magazine':
				$size = 'mv_create_vert';
				break;
			case 'polaroid':
				$size = 'mv_create_1x1_medium_res';
				break;
		}
		// Everything needs a thumbnail
		if ( ! empty( $relation->thumbnail_id ) ) {
			if ( 'gallery' === $layout ) {
				Images::check_image_size( $relation->thumbnail_id, [], $size );
			}
			return \wp_get_attachment_image_url( $relation->thumbnail_id, $size );
		}
	}

	public function build_amazon_data( $id ) {
		$product = (array) self::$models_v2->mv_relations->select_one( $id );
		if ( empty( $product ) ) {
			return false;
		}

		if ( is_wp_error( $product ) ) {
			return false;
		}

		if ( empty( $product['asin'] ) ) {
			$product['asin'] = $this->amazon->get_asin_from_link( $product['url'] );
		}

		$result = $this->amazon->get_products_by_asin( $product['asin'] );

		// Move on if error
		if ( is_wp_error( $result ) ) {
			return false;
		}

		// move on if empty
		if ( empty( $result[ $product['asin'] ] ) ) {
			return false;
		}

		$product['meta']    = $result[ $product['asin'] ];
		$product['expires'] = $result[ $product['asin'] ]['expires'];

		self::$models_v2->mv_relations->update( $product );
	}

	public function refresh_amazon_links() {
		$transient = 'mv_amazon_expiring_amazon_links';
		if ( get_transient( $transient ) ) {
			return false;
		}

		$THREE_HOURS       = 3 * 60 * 60;
		$amazon_rate_limit = apply_filters( 'mv_create_amazon_rate_limit', $THREE_HOURS );
		$expiring          = $this->get_expiring_amazon_links( $amazon_rate_limit );
		if ( empty( $expiring ) ) {
			return false;
		}

		$expiring = array_column( $expiring, 'id' );
		$this->amazon_queue->push_many( $expiring );

		set_transient( $transient, time(), $amazon_rate_limit );
	}

	public function step_amazon_queue() {
		// Only run the queue if Amazon is setup
		if ( $this->amazon->amazon_affiliates_setup() ) {
			return $this->amazon_queue->step(
				function ( $item ) {
					$this->build_amazon_data( $item );
				}
			);
		}
	}

	public function get_expiring_amazon_links( $within, $limit = 50 ) {
		$timestamp = date( 'Y-m-d H:i:s', strtotime( "+{$within} seconds" ) );
		$model     = self::$models_v2->mv_relations;
		$model->set_select( '*' )
			->set_order_by( 'expires' )
			->set_order( 'ASC' )
			->set_limit( $limit );
		$links = $model->where(
			[
				// make sure the product is an Amazon link and has an expiration
				[ 'asin', 'IS NOT', 'NULL' ],
				[ 'expires', 'IS NOT', 'NULL' ],
				// and that the expiration is $within the $timestamp
				[ 'expires', '<', $timestamp ],
				[ 'content_type', '=', 'external' ],
			]
		);

		return $links;
	}

	public static function get_creation_relations( $creation_id ) {
		global $wpdb;
		$table = self::$models_v2->mv_relations->table_name;
		if ( $creation_id instanceof Model ) {
			$model       = $creation_id;
			$creation_id = $model->key();
		}
		$creation_id = intval( $creation_id );
		// SECURITY CHECKED: This query is properly prepared.
		$prepared_statement = $wpdb->prepare( "SELECT * FROM {$table} WHERE creation = %d ORDER BY type, position ASC", [ $creation_id ] );

		$relations = $wpdb->get_results( $prepared_statement );
		if ( empty( $relations ) ) {
			return $relations;
		}

		foreach ( $relations as &$relation ) {
			// Everything needs a thumbnail
			if ( ! empty( $relation->thumbnail_id ) ) {
				$relation->thumbnail_uri = wp_get_attachment_url( $relation->thumbnail_id );
			}

			if ( ! empty( ( $relation->asin ) ) ) {
				$meta = json_decode( $relation->meta );
				if ( $meta ) {
					$relation->thumbnail_uri = $meta->external_thumbnail_url;
				}
			}

			$relation->nofollow = API_Services::to_bool( $relation->nofollow );

			switch ( $relation->content_type ) {
				case 'card':
					$relation = static::prepare_card_item( $relation );
					break;
				case 'revision':
					$relation = static::fix_revision_item( $relation );
					break;
				default:
					break;
			}
		}

		return $relations;
	}

	/**
	 * Fixes items with the content_type `revision`.
	 *
	 * Revision is not an acceptable post type, so here we repair any accidentally
	 * allowed revision items by replacing the details with their parent post data.
	 *
	 * @param stdObj $relation
	 * @return stdObj $relation
	 */
	public static function fix_revision_item( $relation ) {
		$parent_post = get_post( wp_get_post_parent_id( $relation->relation_id ) );
		if (
			is_wp_error( $parent_post ) ||
			empty( $parent_post ) ||
			empty( $parent_post->post_status ) ||
			'publish' !== $parent_post->post_status
		) {
			return $relation;
		}
		$relation->content_type      = 'post';
		$relation->relation_id       = $parent_post->ID;
		$relation->canonical_post_id = $parent_post->ID;
		$relation                    = static::update_single_relation( $relation );

		return $relation;
	}

	/**
	 * Update a single relation.
	 *
	 * @param stdObj|array $relation
	 * @return mixed $relation
	 */
	public static function update_single_relation( $relation ) {
		return static::$models_v2->mv_relations->upsert( (array) $relation );
	}

	/**
	 * Prepare list items that are cards.
	 *
	 * @param \stdClass $relation
	 * @return \stdClass $relation
	 */
	public static function prepare_card_item( $relation ) {
		// Get the published version of the Create card
		$creation = \mv_create_get_creation( $relation->relation_id, true );
		if ( empty( $creation ) ) {
			return $relation;
		}
		$isRecipe = 'recipe' === $creation->type;

		// Set universal fields
		$relation->active_time     = Creations_Views::prep_creation_time( $creation->active_time, '', $creation->active_time_label );
		$relation->prep_time       = Creations_Views::prep_creation_time( $creation->prep_time, '', $creation->prep_time_label );
		$relation->additional_time = Creations_Views::prep_creation_time( $creation->additional_time, '', $creation->additional_time_label );
		$relation->total_time      = Creations_Views::prep_creation_time( $creation->total_time, '', 'Total Time' );
		$relation->yield           = $creation->yield;

		$category           = \get_term( $creation->category );
		$relation->category = ! empty( $category->name ) ? $category->name : '';

		$secondary_term_taxonomy         = $isRecipe ? 'mv_cuisine' : 'mv_project_types';
		$secondary_term_key              = $isRecipe ? 'cuisine' : 'project_type';
		$secondary_term                  = \get_term( $creation->secondary_term, $secondary_term_taxonomy );
		$relation->{$secondary_term_key} = ! empty( $secondary_term->name ) ? $secondary_term->name : '';

		if ( $isRecipe && ! empty( $creation->nutrition ) ) {
			$relation->calories = $creation->nutrition->calories;
		} elseif ( ! $isRecipe ) {
			$relation->difficulty = $creation->difficulty;
			$relation->cost       = $creation->estimated_cost;
		}

		if ( ! empty( $creation->associated_posts ) ) {
			$associated_posts = json_decode( $creation->associated_posts );
			$relation->posts  = [];

			if ( $associated_posts ) {
				foreach ( $associated_posts as &$post ) {
					$post = [
						'id'    => $post,
						'title' => get_the_title( $post ),
					];
				}
				$relation->posts = $associated_posts;
			}
		}
		return $relation;
	}

	public static function delete_all_relations( $creation_id, $type ) {
		return self::$models_v2->mv_relations->delete(
			[
				'where' => [
					'creation' => $creation_id,
					'type'     => $type,
				],
			]
		);
	}

	function routes() {
		$namespace = $this->api_root . '/' . $this->api_version;

		register_rest_route(
			$namespace, '/list/search', [
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function( \WP_REST_Request $request ) {
					return \Mediavine\Create\API_Services::middleware(
						[
							[ $this->api, 'content_search' ],
						],
						$request
					);
				},
				'permission_callback' => [ self::$api_services, 'permitted' ],
			]
		);

		register_rest_route(
			$namespace, '/creations/(?P<id>\d+)/relations', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'read_creation_relations' ],
							],
							$request
						);
					},
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'set_relations' ],
							],
							$request
						);
					},
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
			]
		);
	}
}
