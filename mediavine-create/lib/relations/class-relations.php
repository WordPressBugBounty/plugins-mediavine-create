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
	 * Shared Amazon refresh service for list-item Amazon links.
	 *
	 * @var Amazon_Refresh_Service
	 */
	public $amazon_refresh;

	/**
	 * @var Amazon
	 */
	public $amazon;

	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	function init() {
		$this->api = new Relations_API();

		$this->amazon       = Amazon_Adapter::get_instance();
		$this->amazon_queue = new Queue(
			[
				'queue_name'     => 'mv_amazon_link_queue',
				'transient_name' => 'mv_amazon_link_queue_lock',
				'lock_timeout'   => 43200, // check queue every 12 hours
				'auto_unlock'    => true,
			]
		);

		$this->amazon_refresh = new Amazon_Refresh_Service(
			[
				'queue'              => $this->amazon_queue,
				'amazon'             => $this->amazon,
				'model'              => self::$models_v2->mv_relations,
				'expiring_transient' => 'mv_amazon_expiring_amazon_links',
				'url_field'          => 'url',
				'extra_where'        => [
					[ 'content_type', '=', 'external' ],
				],
				'apply_result'       => function ( $product, $item ) {
					$product['meta']    = $item;
					$product['expires'] = $item['expires'];
					return $product;
				},
			]
		);
		$this->amazon_refresh->register();

		add_filter( 'mv_custom_schema', [ $this, 'custom_schema' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
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
				// Skip synchronous image processing during REST API requests to avoid timeouts
				if ( ! defined('REST_REQUEST') || ! REST_REQUEST ) {
					Images::check_image_size( $relation->thumbnail_id, [], $size );
				}
			}
			return \wp_get_attachment_image_url( $relation->thumbnail_id, $size );
		}
	}

	public function build_amazon_data( $id ) {
		return $this->amazon_refresh->build_amazon_data( $id );
	}

	public function refresh_amazon_links() {
		return $this->amazon_refresh->refresh_expiring();
	}

	public function step_amazon_queue() {
		return $this->amazon_refresh->step_queue();
	}

	public function get_expiring_amazon_links( $within, $limit = 50 ) {
		return $this->amazon_refresh->get_expiring( $within, $limit );
	}

	public static function get_creation_relations( $creation_id ) {
		global $wpdb;
		$table = self::$models_v2->mv_relations->table_name;
		$products_table = self::$models_v2->mv_products->table_name;
		if ( $creation_id instanceof Model ) {
			$model       = $creation_id;
			$creation_id = $model->key();
		}
		$creation_id = intval( $creation_id );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$prepared_statement = $wpdb->prepare(
			"SELECT {$table}.*,
				{$products_table}.title as product_title,
				{$products_table}.description as product_description,
				{$products_table}.link as product_link,
				{$products_table}.thumbnail_id as product_thumbnail_id,
				{$products_table}.external_thumbnail_url as product_external_thumbnail_url,
				{$products_table}.asin as product_asin
			FROM {$table}
			LEFT JOIN {$products_table} ON {$table}.relation_id = {$products_table}.id AND {$table}.content_type = 'product'
			WHERE {$table}.creation = %d
			ORDER BY {$table}.type, {$table}.position ASC",
			[ $creation_id ]
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
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
				$meta = json_decode($relation->meta ?: '{}');
				// Ensure $meta is an object (json_decode can return array if meta contains [])
				if ( is_object( $meta ) && ! empty( $meta->external_thumbnail_url ) ) {
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
				case 'product':
					$relation = static::prepare_product_item( $relation );
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
			$associated_posts = json_decode($creation->associated_posts ?: '[]');
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

	/**
	 * Prepare list items that are products.
	 *
	 * Merges product data from wp_mv_products with list-specific overrides from wp_mv_relations.
	 * List overrides (title, url, thumbnail_id) take precedence over product defaults.
	 *
	 * @param \stdClass $relation
	 * @return \stdClass $relation
	 */
	public static function prepare_product_item( $relation ) {
		// If product was deleted, return relation as-is (will be filtered out in shortcode)
		if ( empty( $relation->product_title ) && empty( $relation->product_link ) ) {
			return $relation;
		}

		// Merge product data with list overrides
		// List-specific values take precedence over product defaults
		if ( empty( $relation->title ) && ! empty( $relation->product_title ) ) {
			$relation->title = Scraped_Content_Normalizer::sanitize_title( $relation->product_title );
		} elseif ( ! empty( $relation->title ) ) {
			$relation->title = Scraped_Content_Normalizer::sanitize_title( $relation->title );
		}

		if ( empty( $relation->description ) && ! empty( $relation->product_description ) ) {
			$relation->description = $relation->product_description;
		}

		if ( empty( $relation->url ) && ! empty( $relation->product_link ) ) {
			$relation->url = $relation->product_link;
		}

		// Handle thumbnail: prioritize list override, then product external URL, then product thumbnail_id
		if ( empty( $relation->thumbnail_id ) && ! empty( $relation->product_thumbnail_id ) ) {
			$relation->thumbnail_id = $relation->product_thumbnail_id;
		}

		if ( empty( $relation->thumbnail_uri ) ) {
			if ( ! empty( $relation->product_external_thumbnail_url ) ) {
				$relation->thumbnail_uri = $relation->product_external_thumbnail_url;
			} elseif ( ! empty( $relation->product_thumbnail_id ) ) {
				$relation->thumbnail_uri = wp_get_attachment_url( $relation->product_thumbnail_id );
			}
		}

		// Set nofollow and ASIN for Amazon products
		// Also set meta field with external thumbnail URL so img() method can find it
		if ( ! empty( $relation->product_asin ) ) {
			$relation->nofollow = true;
			$relation->asin     = $relation->product_asin;

			// Set meta field with external thumbnail URL for Amazon products
			// This allows the img() method in Creations_Views to properly render Amazon images
			if ( ! empty( $relation->product_external_thumbnail_url ) ) {
				$meta = [
					'external_thumbnail_url' => $relation->product_external_thumbnail_url,
				];
				$relation->meta = wp_json_encode( $meta );
			}
		}

		// Clean up temporary product fields
		unset(
			$relation->product_title,
			$relation->product_description,
			$relation->product_link,
			$relation->product_thumbnail_id,
			$relation->product_external_thumbnail_url,
			$relation->product_asin
		);

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
				'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);
	}
}
