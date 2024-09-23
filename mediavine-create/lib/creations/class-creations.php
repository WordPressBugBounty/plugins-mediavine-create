<?php
namespace Mediavine\Create;

use Mediavine\Create\API\V1\CreationsSchema;
use Mediavine\Create\API\V1\CreationsArgs;
use Mediavine\WordPress\Support\Str;

/**
 * DBI functions for Creations
 */
class Creations extends Plugin {

	public static $instance = null;

	public $api_root = 'mv-create';

	public $api = null;

	public $api_version = 'v1';

	public $creations_views = null;

	public $card_style_version = 'v1';

	public $cached_data = []; // For use in passing arbitrary data to various middlewares

	private $table_name = 'mv_creations';

	public $schema = [
		'object_id'             => [
			'type'   => 'bigint(20)',
			'unique' => true,
		],
		'type'                  => 'varchar(20)',
		'title'                 => 'longtext',
		'author'                => 'longtext',
		'description'           => 'longtext',
		'instructions'          => 'longtext',
		'instructions_with_ads' => 'longtext',
		'notes'                 => 'longtext',
		'schema_display'        => [
			'type'    => 'tinyint(1)',
			'default' => 1,
		],
		'published'             => 'longtext',
		'suitable_for_diet'     => 'varchar(100)',
		'difficulty'            => 'longtext',
		'estimated_cost'        => 'longtext',
		'original_post_id'      => [
			'type' => 'bigint(20)',
			'key'  => true,
		],
		'canonical_post_id'     => [
			'type' => 'bigint(20)',
			'key'  => true,
		],
		'category'              => 'bigint(20)',
		'secondary_term'        => 'bigint(20)',
		'tags'                  => 'longtext',
		'thumbnail_id'          => 'bigint(20)',
		'prep_time'             => 'bigint(20)',
		'active_time'           => 'bigint(20)',
		'additional_time'       => 'bigint(20)',
		'total_time'            => 'bigint(20)',
		'prep_time_label'       => [
			'type'    => 'varchar(255)',
			'default' => "'Prep Time'",
		],
		'active_time_label'     => [
			'type'    => 'varchar(255)',
			'default' => "'Active Time'",
		],
		'additional_time_label' => [
			'type'    => 'varchar(255)',
			'default' => "'Additional Time'",
		],
		'time_display'          => 'longtext',
		'yield'                 => 'tinytext',
		'pinterest_url'         => 'longtext',
		'pinterest_description' => 'longtext',
		'pinterest_img_id'      => 'bigint(20)',
		'video'                 => 'longtext',
		'external_video'        => 'longtext',
		'keywords'              => 'longtext',
		'associated_posts'      => 'longtext',
		'rating'                => 'float(2,1)',
		'rating_count'          => 'int',
		'json_ld'               => 'longtext',
		'metadata'              => 'longtext',
		'custom_fields'         => 'longtext',
		'original_object_id'    => [
			'type'   => 'bigint(20)',
			'unique' => true,
		],
		'layout'                => 'varchar(20)',
		'title_hide'            => [
			'type'    => 'tinyint(1)',
			'default' => 0,
		],
		'description_hide'      => [
			'type'    => 'tinyint(1)',
			'default' => 0,
		],
	];

	// Key only used for secondary terms
	// Value used for taxonomy creation
	public static $term_map = [
		'all'            => 'authors',
		'recipe'         => 'cuisine',
		'diy'            => 'project_types',
		'diy_difficulty' => 'difficulty',
		'diy_cost'       => 'estimated_cost',
		'button_text'    => 'link_text',
	];

	public static $img_sizes = [];

	public $singular = 'creation';

	public $plural = 'creations';

	/**
	 * Makes sure class is only instantiated once
	 *
	 * @return object Instantiated class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Hooks to be run on class instantiation
	 *
	 * @return void
	 */
	function init() {
		// Size display names are set in add_image_size_names()
		// Order must always go in progressing form smaller to larger
		self::get_image_sizes();

		$this->api                   = new Creations_API();
		$this->creations_views       = Creations_Views::get_instance();
		$this->creations_plugins     = Creations_Plugins::get_instance();
		$this->creations_meta_blocks = Creations_Meta_Blocks::get_instance();

		add_filter( 'mv_custom_schema', [ $this, 'custom_schema' ] );
		add_filter( 'mv_dbi_before_create_' . $this->table_name, [ $this, 'before_create' ] );
		add_filter( 'mv_dbi_before_update_' . $this->table_name, [ $this, 'before_update' ] );
		add_filter( 'mv_dbi_after_update_' . $this->table_name, [ $this, 'after_update' ] );
		add_filter( 'mv_dbi_after_delete_' . $this->table_name, [ $this, 'after_delete' ] );
		add_action( 'setup_theme', '\Mediavine\Create\Creations_WP_Content::register_content_types', 1 );
		add_action( 'setup_theme', '\Mediavine\Create\Creations_WP_Content::register_taxonomies', 1 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );

		// Update creation when rating added/updated
		add_action( 'mv_rating_updated', [ $this, 'update_creation_rating' ], 10, 3 );

		// Handle associated posts with creations
		add_action( 'wp_trash_post', [ $this, 'unassociate_post_with_creations' ] );
		add_action( 'wp_trash_post', [ $this, 'adjust_canonical_posts' ], 20 );
		add_action( 'untrash_post', [ $this, 'handle_save_post' ] );
		add_action( 'post_updated', [ $this, 'handle_save_post' ], 10, 2 );
		add_action( 'publish_future_post', [ $this, 'handle_save_post' ] );

		// Filter creations prior to publishing
		add_filter( 'mv_publish_prepare_creation', [ $this, 'remove_empty_list_wysiwyg' ] );

		// Filter JSON LD of creation types
		add_action( 'mv_create_before_json_ld_build_diy', [ $this, 'diy_json_ld_filters' ] );
		add_action( 'mv_create_json_ld_output_list', [ $this, 'list_json_ld_output' ] );
		add_action( 'mv_create_before_json_ld_build_recipe', [ $this, 'recipe_json_ld_filters' ] );
		add_action( 'mv_create_json_ld_build_creation_recipe', [ $this, 'recipe_json_ld_prep_creation' ] );

		add_filter( 'posts_search', [ $this, 'add_creations_to_search' ], 10, 2 );
	}

	/**
	 * Set and return image sizes
	 *
	 * @return array
	 */
	public static function get_image_sizes() {
		self::$img_sizes = [
			'mv_create_1x1'                 => [
				'name'   => __( 'Create Card Square (Small)', 'mediavine' ),
				'width'  => 200,
				'height' => 200,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_1x1_medium_res'      => [
				'name'   => __( 'Create Card Square', 'mediavine' ),
				'width'  => 320,
				'height' => 320,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_1x1_medium_high_res' => [
				'name'   => __( 'Create Card Square (Medium High)', 'mediavine' ),
				'width'  => 480,
				'height' => 480,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_1x1_high_res'        => [
				'name'   => __( 'Create Card Square (High Res)', 'mediavine' ),
				'width'  => 720,
				'height' => 720,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_4x3'                 => [
				'name'   => __( 'Create Card 4:3 (Small)', 'mediavine' ),
				'width'  => 320,
				'height' => 240,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_4x3_medium_res'      => [
				'name'   => __( 'Create Card 4:3', 'mediavine' ),
				'width'  => 480,
				'height' => 360,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_4x3_high_res'        => [
				'name'   => __( 'Create Card 4:3 (High Res)', 'mediavine' ),
				'width'  => 720,
				'height' => 540,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_3x4'                 => [
				'name'   => __( 'Create Card 3:4 (Small)', 'mediavine' ),
				'height' => 320,
				'width'  => 240,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_3x4_medium_res'      => [
				'name'   => __( 'Create Card 3:4', 'mediavine' ),
				'height' => 480,
				'width'  => 360,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_3x4_high_res'        => [
				'name'   => __( 'Create Card 3:4 (High Res)', 'mediavine' ),
				'height' => 720,
				'width'  => 540,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_16x9'                => [
				'name'   => __( 'Create Card 16:9 (Small)', 'mediavine' ),
				'width'  => 320,
				'height' => 180,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_16x9_medium_res'     => [
				'name'   => __( 'Create Card 16:9', 'mediavine' ),
				'width'  => 480,
				'height' => 270,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			'mv_create_16x9_high_res'       => [
				'name'   => __( 'Create Card 16:9 (High Res)', 'mediavine' ),
				'width'  => 720,
				'height' => 405,
				'crop'   => true,
				'class'  => 'mv-create-image no_pin ggnoads',
			],
			// Same as mv_create_vert. No additional images will be created
				'mv_create_no_ratio'        => [
					'name'   => __( 'Create Card No Ratio', 'mediavine' ),
					'width'  => 735,
					'height' => 9999,
					'crop'   => false,
					'class'  => 'mv-create-image no_pin ggnoads',
				],
			'mv_create_vert'                => [
				'name'   => __( 'Create Card Vertical Pin', 'mediavine' ),
				'width'  => 735,
				'height' => 9999,
				'crop'   => false,
				'class'  => 'mv-create-pinterest no_pin ggnoads',
			],
		];

		return self::$img_sizes;
	}

	/**
	 * Restore any missing video data in a creation
	 *
	 * @param object $creation  creation data
	 * @return object creation data with restored video data
	 */
	public static function restore_video_data( $creation ) {
		if ( ! empty( $creation->video ) || ! isset( $creation->original_object_id ) || Str::contains( 'video_data_restored', $creation->metadata ) ) {
			return $creation;
		}

		$metadata = json_decode( $creation->metadata );
		// Fallback to prevent PHP errors if metadata was empty
		if ( ! is_object( $metadata ) ) {
			$metadata = new \stdClass();
		}
		$metadata->video_data_restored = true;
		$creation->metadata            = wp_json_encode( $metadata );

		$models = \Mediavine\MV_DBI::get_models( null, 'mv_recipes' );

		if ( ! isset( $models->mv_recipes ) ) {
			return $creation;
		}
		$recipe_model = $models->mv_recipes;

		$recipe = $recipe_model->find_one(
				[
					'col' => 'object_id',
					'key' => $creation->original_object_id,
				]
		);

		if ( ! isset( $recipe->id ) || empty( $recipe->video_data ) ) {
			return $creation;
		}

		$creation->video = $recipe->video_data;

		$creation = self::$models_v2->mv_creations->update_without_modified_date( (array) $creation );

		\Mediavine\Create\Publish::update_publish_queue(
				[
					$creation->id,
				]
		);

		return $creation;
	}

	/**
	 * Get the `type` of a given creation.
	 *
	 * @param int $creation_id  the ID of the Create card
	 * @return string the type of Create card
	 */
	public static function get_creation_type( $creation_id ) {
		global $wpdb;

		$creation_type = '';

		// SECURITY CHECKED: This query is properly prepared.
		$statement = "SELECT type FROM {$wpdb->prefix}mv_creations WHERE id = %d";
		$prepared  = $wpdb->prepare( $statement, [ $creation_id ] );
		$result    = $wpdb->get_row( $prepared );

		if ( ! empty( $result->type ) ) {
			$creation_type = $result->type;
		}

		return $creation_type;
	}

	/**
	 * Add our Creations posts to WP search
	 *
	 * @param string   $search  term being searched
	 * @param WP_Query $query   standard WP search query
	 * @return string new custom search query
	 */
	function add_creations_to_search( $search, $query ) {
		global $wpdb;

		if ( ! \Mediavine\Settings::get_setting( self::$settings_group . '_enhanced_search' ) ) {
			return $search;
		}

		// Main Query will always come up false in tests. Add a filter specifically for testing
		// ignore 'Hook names invoked by a theme/plugin should start with the theme/plugin prefix' for now
		// phpcs:ignore
		$is_main_query = apply_filters( 'mv_test_is_main_query', $query->is_main_query() );

		if ( empty( $search ) || is_admin() || ! $is_main_query ) {
			return $search;
		}

		$search_term = $query->query['s'];
		$creations   = $wpdb->prefix . 'mv_creations';

		$search = "AND
			(
				({$wpdb->posts}.post_title LIKE '%$search_term%') OR
				({$wpdb->posts}.post_excerpt LIKE '%$search_term%') OR
				({$wpdb->posts}.post_content LIKE '%$search_term%') OR
				({$wpdb->posts}.ID IN (
					SELECT DISTINCT {$wpdb->posts}.ID
					FROM {$creations}
					JOIN {$wpdb->posts}
					ON {$creations}.canonical_post_id = {$wpdb->posts}.ID
					WHERE {$creations}.published LIKE '%$search_term%'
				))
			)
		";

		return $search;
	}

	/**
	 * Preps the creation object to be published
	 *
	 * @param int $creation_id  ID of the creation
	 * @return object|null Creation to be published
	 */
	public static function prep_publish_creation( $creation_id ) {
		$creation = self::$models_v2->mv_creations->find_one( $creation_id );
		if ( ! is_object( $creation ) ) {
			return null;
		}

		$creation_for_publish = Creations_API::bind_creation_relationships( $creation );
		$creation_for_publish = Publish::prepare_creation( $creation_for_publish );
		$creation_for_publish = Publish::prepare_supplies( $creation_for_publish );
		$creation_for_publish = Publish::prepare_posts( $creation_for_publish );
		$creation_for_publish = Publish::prepare_images( $creation_for_publish );
		$creation_for_publish = Publish::prepare_times( $creation_for_publish );
		$creation_for_publish = Publish::prepare_instructions( $creation_for_publish );
		$creation_for_publish = Publish::prepare_ratings( $creation_for_publish );
		$creation_for_publish = Publish::prepare_create_settings( $creation_for_publish );
		$creation_for_publish = Publish::prepare_relations( $creation_for_publish );

		// Filter creation before JSON-LD and publishing
		$creation_for_publish = apply_filters( 'mv_publish_prepare_creation', $creation_for_publish );

		$creation_for_publish = Publish::prepare_jsonld( $creation_for_publish );

		return $creation_for_publish;
	}

	/**
	 * Publish a creation with all data for faster card renders
	 *
	 * @param int  $creation_id  ID of creation
	 * @param bool $clear_cache  Clears the cache on associated posts. e.g. A post that in the republish
	 *                           queue won't be republished unless the cache is no longer valid, so
	 *                           there's no reason to flush it again
	 * @return object All creation card data
	 */
	public static function publish_creation( $creation_id, $clear_cache = true ) {
		$creation = self::prep_publish_creation( $creation_id );
		if ( is_null( $creation ) ) {
			return null;
		}

		/**
		 * Filters the Create card data prior to publish
		 *
		 * @param object $creation  Create card data
		 */
		$creation = apply_filters( 'mv_create_card_pre_publish_data', $creation );

		$published = wp_json_encode( $creation );

		/**
		 * Fires immediately before Create card is published
		 *
		 * @param int    $creation_id  ID of the Create card
		 * @param object $creation     Create card data
		 */
		do_action( 'mv_create_card_pre_publish', $creation, $published );

		$result = self::$models_v2->mv_creations->update_without_modified_date(
				[
					'id'        => $creation_id,
					'published' => $published,
					'json_ld'   => $creation->json_ld,
				]
		);

		/**
		 * Fires immediately after Create card is published
		 *
		 * @param int    $creation_id  ID of the Create card
		 * @param object $creation     Create card data
		 * @param string $published    Published data
		 */
		do_action( 'mv_create_card_post_publish', $creation, $published );

		if ( $clear_cache && class_exists( '\Mediavine\Cache_Manager' ) ) {
			\Mediavine\Cache_Manager::clear_by_id( json_decode( $result->associated_posts ) );
		}

		return $result;
	}

	/**
	 * @param object   $creation          Creation data
	 * @param int      $thumbnail_id      ID of the image we want to add
	 * @param int|null $pinterest_img_id  ID of pinterest image we want to add
	 * @return array images added to the creation
	 */
	public static function add_images_to_creation( $creation, $thumbnail_id, $pinterest_img_id = null ) {
		$images = [];

		// Get image sizes to generate
		$img_sizes = apply_filters( 'mv_create_image_sizes', self::$img_sizes, __FUNCTION__ );

		// Generate images if they don't exist
		Images::generate_intermediate_sizes_deferred( $thumbnail_id, $img_sizes );
		Images::generate_intermediate_sizes_deferred( $pinterest_img_id, $img_sizes );

		// TODO: This is done in the old way and needs to be refactored
		$Images_API = new Images_API();

		foreach ( $img_sizes as $img_size => $img_meta ) {
			$params = [
				'image_size'      => $img_size,
				'associated_id'   => $creation->id,
				'associated_type' => $creation->type,
			];
			if ( ! empty( $thumbnail_id ) ) {
				$params['object_id'] = $thumbnail_id;
			}

			// Check for pinterest image
			if ( 'mv_create_vert' === $img_size && ! empty( $pinterest_img_id ) ) {
				$params['object_id'] = $pinterest_img_id;
			}

			// Only upload if added object_id is set
			if ( isset( $params['object_id'] ) ) {
				$thumbnail   = $Images_API->validate_image( $params );
				$where_array = [
					'image_size'    => $img_size,
					'associated_id' => $creation->id,
				];
				$image       = (array) self::$models_v2->mv_images->upsert( $thumbnail['image'], $where_array );

				$images[] = $image;
			}
		}

		return $images;
	}

	/**
	 * Gets a list of Creation IDs associated with a Post ID.
	 *
	 * Can be filtered by card type.
	 *
	 * @param integer $post_id       ID of WP Post from which you want a list of Associated Creations.
	 * @param array   $filter_types  Array of card types to filter by. And empty array will display all card types.
	 * @return array Returns an array of objects
	 */
	public static function get_creation_ids_by_post( $post_id, $filter_types = [] ) {
		if ( ! isset( $post_id ) ) {
			return new \WP_Error( 'no_value', __( 'Post ID was not set in function call', 'mediavine' ), [ 'message' => __( 'A Post ID was not included in the request', 'mediavine' ) ] );
		}

		if ( ! is_numeric( $post_id ) ) {
			return new \WP_Error( 'non_numeric', __( 'Post ID value was not a number', 'mediavine' ), [ 'message' => __( 'A Post ID varable was included but was non-numeric', 'mediavine' ) ] );
		}

		$creations = self::$models_v2->mv_creations->where(
				[
					[ 'associated_posts', 'LIKE', '%"' . $post_id . '"%', 'or' ],
					[ 'associated_posts', 'LIKE', '%[' . $post_id . ',%', 'or' ],
					[ 'associated_posts', 'LIKE', '%,' . $post_id . ']%', 'or' ],
					[ 'associated_posts', 'LIKE', '%[' . $post_id . ']%' ],
				]
		);
		$ids       = [];
		foreach ( $creations as $item ) {
			if ( ! empty( $filter_types ) && ! in_array( $item->type, $filter_types, true ) ) {
				continue;
			}
			$ids[] = $item->id;
		}
		return $ids;
	}

	/**
	 * Gets imported images
	 *
	 * @param  array  $value        Current image URL array
	 * @param  string $schema_type  'image'
	 * @param  string $schema_prop  'image'
	 * @param  array  $json_ld      Current JSON-LD
	 * @param  array  $creation     Published creation
	 * @return array                 Array of image urls
	 */
	public function get_imported_images( $value, $schema_type, $schema_prop, $json_ld, $creation ) {
		if ( ! empty( $creation['metadata'] ) ) {
			$metadata = json_decode( $creation['metadata'], true );
			if ( ! empty( $metadata['import']['imported_images'] ) ) {
				$value = array_merge( $value, $metadata['import']['imported_images'] );
			}
		}

		return $value;
	}

	/**
	 * Checks instructions for list and changes schema type to step if there's only 1 list
	 *
	 * @param array  $schema_types  Schema types to be added to JSON LD
	 * @param string $type          Type of card to generate schema
	 * @param array  $creation      All creation data of current card
	 * @return array  Updated schema types
	 */
	public function check_for_list_steps( $schema_types, $type, $creation ) {
		if ( 'diy' === $type ) {
			if ( ! empty( $creation['instructions'] ) ) {
				// Checking for closing tags in case a class is ever added to the list
				$ordered_lists   = substr_count( $creation['instructions'], '</ol>' );
				$unordered_lists = substr_count( $creation['instructions'], '</ul>' );
				if ( 1 === $ordered_lists + $unordered_lists ) {
					$schema_types['diy']['properties']['step']['type']                = 'step';
					$schema_types['diy']['properties']['step']['flags']['parse_list'] = true;
				}
			}
		}

		return $schema_types;
	}

	/**
	 * Adds additional time to perform time
	 *
	 * @param number $value        value of current duration time
	 * @param string $schema_type  'recipe'
	 * @param string $schema_prop  'recipe'
	 * @param string $json_ld      Current JSON LD
	 * @param object $creation     Creation data
	 */
	public function additional_perform_time( $value, $schema_type, $schema_prop, $json_ld, $creation ) {
		// Only run on duration array, not final value
		if ( 'duration_arrays' === $schema_type && ! empty( $creation['additional_time'] ) ) {
			$value = [ $creation['additional_time'] ];
		}

		// performTime is optional for recipes, so return null if duration is 0
		if ( 'PT0S' === $value && 'recipe' === $creation['type'] ) {
			return null;
		}

		return $value;
	}

	/**
	 * Add JSON LD filters for DIY Creation
	 *
	 * @param object $creation  Creation data
	 */
	public function diy_json_ld_filters( $creation ) {
		add_filter( 'mv_schema_types', [ $this, 'check_for_list_steps' ], 10, 3 );
		add_filter( 'mv_json_ld_value_prop_performTime', [ $this, 'additional_perform_time' ], 10, 5 );
	}

	/**
	 * Removes JSON LD if no list items are available on list card type
	 *
	 * @param array $json_ld  Array of JSON LD output
	 * @return array|null Array of JSON LD output, null if no list items
	 */
	public function list_json_ld_output( $json_ld ) {
		if ( empty( $json_ld['itemListElement'] ) ) {
			return null;
		}
		return $json_ld;
	}

	/**
	 * Add JSON LD filters for recipe Creation
	 *
	 * @param object $creation  Creation data
	 */
	public function recipe_json_ld_filters( $creation ) {
		add_filter( 'mv_json_ld_value_prop_image', [ $this, 'get_imported_images' ], 10, 5 );
		add_filter( 'mv_json_ld_value_prop_performTime', [ $this, 'additional_perform_time' ], 10, 5 );
	}

	/**
	 * Build the JSON LD for recipe Creation
	 *
	 * @param object $creation  Creation data
	 * @return object Creation data with updated json ld
	 */
	public function recipe_json_ld_prep_creation( $creation ) {
		// Add ingredients to $creation
		if ( empty( $creation['supplies'] ) ) {
			$supplies = Supplies::get_creation_supplies( $creation['id'] );
			if ( ! empty( $supplies ) ) {
				$ingredients = array_filter(
						$supplies, function( $supply ) {
					return 'ingredients' === $supply->type;
				}
				);

				$creation['ingredients'] = Supplies::put_supplies_in_groups_array( $ingredients );
			}
		}

		$creation['nutrition'] = (array) self::$models_v2->mv_nutrition->find_one(
				[
					'col' => 'creation',
					'key' => $creation['id'],
				]
		);

		return $creation;
	}

	/**
	 * Remove any leftover junk tags in a list's blank WYSIWYG
	 *
	 * @param object $creation  Creation data
	 * @return object Creation data with truly updated WYSIWYG
	 */
	public function remove_empty_list_wysiwyg( $creation ) {
		if ( ! empty( $creation->instructions ) && '<ol><li><br></li></ol>' === $creation->instructions ) {
			$creation->instructions = null;
		}

		return $creation;
	}

	/**
	 * Lifecycle hook to manage pre DB write Operations
	 *
	 * @param object $data  Associative Array with data to be stored
	 * @return object Associative Array that includes the changes made in the hook
	 */
	function before_create( $data ) {
		$original_data = $data;
		$user          = \wp_get_current_user();
		$object_id     = \wp_insert_post(
				[
					'post_title'  => $data['title'] . ' Creation',
					'post_type'   => 'mv_create',
					'post_author' => $user->ID,
					'post_status' => 'publish',
				], true
		);

		$data['prep_time_label']       = ! empty( $original_data['prep_time_label'] ) ? $original_data['prep_time_label'] : __( 'Prep Time', 'mediavine' );
		$data['active_time_label']     = ! empty( $original_data['active_time_label'] ) ? $original_data['active_time_label'] : __( 'Active Time', 'mediavine' );
		$data['additional_time_label'] = ! empty( $original_data['additional_time_label'] ) ? $original_data['additional_time_label'] : __( 'Additional Time', 'mediavine' );

		$data['object_id'] = $object_id;

		if ( isset( $data['type'] ) && ( 'recipe' === $data['type'] ) ) {
			$data['active_time_label'] = ! empty( $original_data['active_time_label'] ) ? $original_data['active_time_label'] : __( 'Cook Time', 'mediavine' );
		}

		if ( empty( $data['instructions'] ) ) {
			$data['instructions'] = '<ol><li></li></ol>';
		}

		if ( empty( $data['author'] ) ) {
			$data['author'] = \Mediavine\Settings::get_setting( self::$settings_group . '_copyright_attribution' );
		}

		if ( isset( $data['type'] ) && ( 'list' === $data['type'] ) ) {
			$data['layout'] = 'hero';
		}

		return $data;
	}

	/**
	 * Actions to run before a creation is updated in the database.
	 *
	 * @param object $data  Retrieved Creation data
	 * @return object Update Creation data
	 */
	function before_update( $data ) {
		// If there is no object id, there's nothing we can do!
		if ( ! empty( $data['object_id'] ) ) {
			// We'll check to see if any category was previously associated
			// with this card's object id. If we find a category, we'll assign that to the card
			// and update the card.
			// This should prevent loss of categories.
			if ( empty( $data['category'] ) ) {
				$terms = wp_get_object_terms( [ $data['object_id'] ], 'category' );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					$data['category'] = $terms[0]->term_id;
					self::$models_v2->mv_creations->update_without_modified_date(
							[
								'id'       => $data['id'],
								'category' => $data['category'],
							]
					);
				}
			}
			// Same goes for secondary terms.
			if ( empty( $data['secondary_term'] ) && ! empty( self::$term_map[ $data['type'] ] ) ) {
				$terms = wp_get_object_terms( [ $data['object_id'] ], 'mv_' . self::$term_map[ $data['type'] ] );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					$data['secondary_term'] = $terms[0]->term_id;
					self::$models_v2->mv_creations->update_without_modified_date(
							[
								'id'             => $data['id'],
								'secondary_term' => $data['secondary_term'],
							]
					);
				}
			}
		}

		return $data;
	}

	/**
	 * Checks if the object terms should be reset.
	 *
	 * @return boolean
	 */
	public function should_set_object_terms() {
		$should_set_object_terms = ! $this->object_terms_set;

		/**
		 * Filters whether the object terms should be reset.
		 *
		 * @param bool $max maximum should_set_object_terms of revisions
		 */
		$should_set_object_terms = apply_filters( 'mv_create_should_set_object_terms', true );

		return $should_set_object_terms;
	}

	/**
	 * Resets a specific term association between a term and an object id.
	 *
	 * Locks the resetting of the term ID with 60 second transient, or until process completed.
	 *
	 * @param int    $object_id  Post object ID, typically a Create card
	 * @param int    $term_id    Term ID
	 * @param string $taxonomy   Type of taxonomy to target
	 * @return void
	 */
	public function reset_term_association( $object_id, $term_id, $taxonomy = 'category' ) {
		// Avoid all race conditions by locking with transient
		if ( false !== ( get_transient( 'mv_create_reset_term_association_locked' ) ) ) {
			return;
		}
		set_transient( 'mv_create_reset_term_association_locked_' . $object_id, true, MINUTE_IN_SECONDS );

		// Delete previous term associations and update with the new term
		wp_delete_object_term_relationships( $object_id, $taxonomy );
		wp_set_object_terms( $object_id, $term_id, $taxonomy );

		// Remove lock
		delete_transient( 'mv_create_reset_term_association_locked_' . $object_id );
	}

	/**
	 * Resets the primary and secondary term associations if available
	 *
	 * @param object $data  Retrieved Creation data
	 * @return void
	 */
	function reset_term_associations( $data ) {
		// Make sure we should be (re)setting the terms
		if ( ! $this->should_set_object_terms() ) {
			return;
		}

		// Delete previous category associations and update with the new category.
		if ( ! empty( $data->category ) ) {
			$this->reset_term_association( $data->object_id, (int) $data->category );
		}
		// Do the same checks for the secondary term
		if ( ! empty( $data->secondary_term ) && isset( self::$term_map[ $data->type ] ) ) {
			$this->reset_term_association( $data->object_id, (int) $data->secondary_term, 'mv_' . self::$term_map[ $data->type ] );
		}

		// Prevent reset from running multiple times
		$this->object_terms_set = true;
	}

	/**
	 * Actions to run after a creation is updated in the database.
	 *
	 * @param object $data  Retrieved Creation data
	 * @return object Update Creation data
	 */
	function after_update( $data ) {
		// If there is no object id, there's nothing we can do!
		if ( ! empty( $data->object_id ) ) {
			// Otherwise, delete previous category associations and update with the new category.
			if ( ! empty( $data->category ) ) {
				wp_delete_object_term_relationships( $data->object_id, 'category' );
				wp_set_object_terms( $data->object_id, (int) $data->category, 'category' );
			}
			// do the same checks for the secondary term
			if ( ! empty( $data->secondary_term ) && isset( self::$term_map[ $data->type ] ) ) {
				wp_delete_object_term_relationships( $data->object_id, 'mv_' . self::$term_map[ $data->type ] );
				wp_set_object_terms( $data->object_id, (int) $data->secondary_term, 'mv_' . self::$term_map[ $data->type ] );
			}
		}

		return $data;
	}

	/**
	 * Actions to run after a creation is deleted from the database.
	 *
	 * @param object $data  Retrieved Creation data
	 * @return object Deleted Creation data
	 */
	function after_delete( $data ) {
		if ( isset( $data->object_id ) ) {
			// WPRM does a no-no and destructively changes the post type on import to `wprm_recipe`,
			// making it harder to switch back to Create. They asked us to add in this check so
			// deleting a Create card doesn't also delete the WP recipe at the same time ¯\_(ツ)_/¯
			if ( 'mv_create' === get_post_type( $data->object_id ) ) {
				wp_delete_post( $data->object_id, true );
			}
		}
		return $data;
	}

	/**
	 * Renders the preview of the card along with theme styles
	 *
	 * @param \WP_REST_Request $request  API Request
	 */
	function render_view( \WP_REST_Request $request ) {
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		?>
		<html>

		<head>
			<?php
			/**
			 * Fires before the card preview's wp_head() is rendered
			 */
			do_action( 'mv_create_card_preview_render_head' );
			wp_head();
			// @todo: per phpcs, this stylesheet needs to be registered/enqueued via wp_enqueue_style'
			// phpcs:disable
			?>
			<link rel="stylesheet"
				  href="<?php echo esc_attr( Plugin::assets_url() . 'client/build/card-all.' . Plugin::VERSION . '.css' ); ?>">
			<?php
			// phpcs:enable
			?>
		</head>
		<body>
		<div id="mediavine-settings" data-blacklist-adhesion-mobile="1" data-blacklist-adhesion-tablet="1"
			 data-blacklist-adhesion-desktop="1"></div>
		<?php
		/**
		 * Fires before the card preview's wp_footer() is rendered
		 */
		do_action( 'mv_create_card_preview_render_footer' );
		wp_footer();
		?>
		</body>

		</html>
		<?php
		exit();
	}

	/**
	 * Allows users to filter the schema array used for building tables.
	 *
	 * @param array $tables  Array of table schemas to modify
	 * @return array Array of modified table schemas
	 */
	public function custom_schema( $tables ) {
		$tables[] = [
			'version'    => self::DB_VERSION,
			'table_name' => $this->table_name,
			'schema'     => $this->schema,
		];
		return $tables;
	}

	/**
	 * Function to add a post to a creation's associated_posts
	 *
	 * @param int $creation_id  the id of the creation we want updated with a new associated_post
	 * @param int $post_id      the id of the post we want added
	 */
	public static function associate_post_with_creation( $creation_id, $post_id ) {
		// It's now safe to set terms again
		$creation = self::$models_v2->mv_creations->find_one( $creation_id );

		if ( ! $creation ) {
			return;
		}

		$update = [ 'id' => $creation->id ];

		// Update post ids IF they don't exist
		$republish = false;
		if ( ! $creation->original_post_id || 0 === $creation->original_post_id ) {
			$update['original_post_id']  = $post_id;
			$update['canonical_post_id'] = $post_id;

			$republish = true;
			add_filter( 'mv_create_dbi_update_remove_original_object_id', '__return_false' );
		}

		// There may be cases where the canonical was removed, but not the original
		if ( ! $creation->canonical_post_id || 0 === $creation->canonical_post_id ) {
			$update['canonical_post_id'] = $post_id;

			$republish = true;
		}

		// Updating the canonical requires a republish of the card
		if ( $republish ) {
			Publish::update_publish_queue( [ $creation->id ] );
		}

		$associated = [];
		if ( $creation->associated_posts ) {
			$associated = json_decode( $creation->associated_posts );
		}
		$associated[]               = (string) $post_id;
		$update['associated_posts'] = wp_json_encode( array_values( array_unique( $associated ) ) );

		// If changes to associated posts or we had to republish because we changed the canonical
		if ( $creation->associated_posts !== $update['associated_posts'] || $republish ) {
			self::$models_v2->mv_creations->update_without_modified_date(
					$update
			);
			add_filter( 'mv_create_dbi_update_remove_original_object_id', '__return_true' );
		}
	}

	/**
	 * Function to remove a post from any creations associated_posts
	 *
	 * @param int $post_id  the id of the post we want removed
	 */
	public static function unassociate_post_with_creations( $post_id ) {
		// We don't want to set terms until we associate
		add_filter( 'mv_create_should_set_object_terms', '__return_false' );

		// get creations with associated_posts LIKE '%"{$post_id}"%'
		$creations = self::$models_v2->mv_creations->find(
				[
					'where' => [
						'associated_posts' => "\"{$post_id}\"",
					],
				]
		);

		if ( ! $creations ) {
			return;
		}
		foreach ( $creations as $creation ) {
			$associated = $creation->associated_posts;
			if ( ! $associated ) {
				continue;
			}
			$associated = json_decode( $associated );

			if ( count( $associated ) === 1 ) {
				self::$models_v2->mv_creations->update_without_modified_date(
						[
							'id'                => $creation->id,
							'associated_posts'  => 'NULL',
							'canonical_post_id' => 'NULL',
						]
				);
				continue;
			}

			$new_associated = array_filter(
					$associated, function( $associated_post_id ) use ( $post_id ) {
				return (int) $associated_post_id !== (int) $post_id;
			}
			);
			$new_associated = array_values( $new_associated );

			$new_associated = wp_json_encode( $new_associated );
			self::$models_v2->mv_creations->upsert_without_modified_date(
					[
						'associated_posts' => $new_associated,
					],
					[
						'id' => $creation->id,
					]
			);
		}
	}

	/**
	 * Function to handle a Creation's associated post being saved, untrashed, or scheduled
	 *
	 * @param int                $post_id  id of the post we are saving
	 * @param WP_Post|array|null $post     data we want to save to the post
	 */
	public function handle_save_post( $post_id, $post = null ) {
		if ( ! $post ) {
			$post = get_post( $post_id );
		}

		// Sanity check
		$allowed_statuses = [
			'publish',
			'future',
			'draft',
			'pending',
			'private',
		];
		if ( $post && ! in_array( $post->post_status, $allowed_statuses, true ) ) {
			return;
		}
		// Get content but DON'T apply filter with get_content(), since we want raw shortcodes
		$content = $post->post_content;

		self::unassociate_post_with_creations( $post_id );

		// Get all shortcodes
		preg_match_all( '/\[mv_create[^]]+key=\"(\d+)\"/', $content, $matches );

		// Make sure we have matches, including digit
		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $creation_id ) {
				self::associate_post_with_creation( $creation_id, $post_id );
			}
		}

		// Adjust any associated canonical posts that shouldn't have associations
		$this->maybe_adjust_canonical_posts( $post_id, $matches[1] );
	}

	/**
	 * Function to update the canonical post of a creation if it needs to be updated
	 *
	 * @param int        $post_id        the id of the post with creations
	 * @param array|null $cards_on_page  the creations on the post
	 */
	public function maybe_adjust_canonical_posts( $post_id, $cards_on_page = null ) {
		if ( is_null( $cards_on_page ) ) {
			$post = get_post( $post_id );

			// Get content but DON'T apply filter with get_content(), since we want raw shortcodes
			$content = $post->post_content;

			// Get all shortcodes
			preg_match_all( '/\[mv_create[^]]+key=\"(\d+)\"/', $content, $matches );

			$cards_on_page = $matches[1];
		}

		// Get all matching canonical id cards
		$creations = self::$models_v2->mv_creations->find(
				[
					'select' => [
						'id',
						'original_post_id',
						'canonical_post_id',
						'associated_posts',
					],
					'where'  => [
						'canonical_post_id' => $post_id,
					],
				]
		);

		if ( ! empty( $creations ) ) {
			foreach ( $creations as $creation ) {
				if ( ! in_array( (string) $creation->id, $cards_on_page, true ) ) {
					// Prep associated_posts
					$associated_posts = json_decode( $creation->associated_posts );

					// Default to null
					$creation->canonical_post_id = 'NULL';

					// Use first associated post if found
					if ( ! empty( $associated_posts[0] ) ) {
						$creation->canonical_post_id = $associated_posts[0];
					}

					// Use original post ID if available and in associated posts
					if (
							! empty( $creation->original_post_id ) &&
							is_array( $associated_posts ) &&
							in_array( $creation->original_post_id, $associated_posts, true )
					) {
						$creation->canonical_post_id = $creation->original_post_id;
					}

					// Update canonical post id
					self::$models_v2->mv_creations->update_without_modified_date(
							[
								'id'                => $creation->id,
								'canonical_post_id' => $creation->canonical_post_id,
							]
					);
				}
			}
		}
	}

	/**
	 * Function that calls maybe_adjust_canonical_posts function
	 *
	 * @param int $post_id  the id of the post with creations
	 */
	public function adjust_canonical_posts( $post_id ) {
		$this->maybe_adjust_canonical_posts( $post_id, [] );
	}

	/**
	 * Action to update the rating of a creation
	 *
	 * @param float  $avg_rating    the average rating of the creation after a new review
	 * @param object $review        the new review data
	 * @param int    $rating_count  the number of ratings the creation has after a new review
	 *
	 * @return object|false The updated creation
	 */
	public function update_creation_rating( $avg_rating, $review, $rating_count ) {
		// Return if no creation ID
		if ( empty( $review->creation ) ) {
			return false;
		}

		$creation = self::$models_v2->mv_creations->find_one( $review->creation );

		if ( empty( $creation->published ) ) {
			return false;
		}

		$creation = json_decode( $creation->published );

		// Round to nearest decimal before storing in database
		$rating_value = round( $avg_rating, 1 );
		$rating_count = intval( $rating_count );

		$creation->rating       = $rating_value;
		$creation->rating_count = $rating_count;
		$creation->modified     = time();

		if ( ! empty( $creation->json_ld ) ) {
			$json_ld                  = json_decode( $creation->json_ld );
			$json_ld->aggregateRating = [
				'@type'           => 'AggregateRating',
				// Add $rating_value as string because json_encode
				// uses the floated number, which isn't rounded
					'ratingValue' => strval( $rating_value ),
				'reviewCount'     => $rating_count,
			];

			$creation->json_ld = wp_json_encode( $json_ld );
		}

		$updated_creation = [
			'id'           => $creation->id,
			'rating'       => $rating_value,
			'rating_count' => $rating_count,
			'published'    => wp_json_encode( $creation ),
		];

		if ( ! empty( $creation->json_ld ) ) {
			$updated_creation['json_ld'] = $creation->json_ld;
		}

		$updated = self::$models_v2->mv_creations->update_without_modified_date(
				$updated_creation
		);

		return $updated;
	}

	/**
	 * Register routes with WordPress.
	 */
	function routes() {
		$namespace = $this->api_root . '/' . $this->api_version;

		register_rest_route(
				$namespace, '/print/(?P<id>\d+)', [
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => [ $this, 'print_view' ],
						'permission_callback' => '__return_true',
						'args'                => CreationsArgs\validate_id(),
					],
				]
		);

		register_rest_route(
				$namespace, '/render', [
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => [ $this, 'render_view' ],
						'permission_callback' => '__return_true',
					],
				]
		);

		register_rest_route(
				$namespace,
				'/creations',
				[
					[
						'methods'             => \WP_REST_Server::EDITABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										[ $this->api, 'valid_creation' ],
										[ $this->api, 'create' ],
									],
									$request
							);
						},
						'permission_callback' => [ self::$api_services, 'permitted' ],
					],
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										[ self::$api_services, 'process_pagination' ],
										[ $this->api, 'find' ],
									],
									$request
							);
						},
						'permission_callback' => [ self::$api_services, 'permitted' ],
					],
				]
		);

		register_rest_route(
				$namespace, '/creations/(?P<id>\d+)', [
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										[ $this->api, 'find_one' ],
										[ $this->api, 'get_pagination_links' ],
									], $request
							);
						},
						'permission_callback' => '__return_true',
						'args'                => CreationsArgs\validate_id(),
					],
					[
						'methods'             => \WP_REST_Server::DELETABLE,
						'callback'            => function( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										[ $this->api, 'destroy' ],
									], $request
							);
						},
						'permission_callback' => [ self::$api_services, 'permitted' ],
						'args'                => CreationsArgs\validate_id(),
					],
					[
						'methods'             => \WP_REST_Server::EDITABLE,
						'callback'            => function( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										[ $this->api, 'find_one' ],
										[ $this->api, 'update' ],
										[ $this->api, 'get_pagination_links' ],
									], $request
							);
						},
						'permission_callback' => [ self::$api_services, 'permitted' ],
						'args'                => CreationsArgs\validate_id(),
					],
					'schema' => function () {
						return API_Services::build_schema( 'creations/<id>', CreationsSchema\get_single() );
					},
				]
		);

		register_rest_route(
				$namespace,
				'/creations/object/(?P<id>\d+)',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										[ $this->api, 'find_one_by_original_object_id' ],
									], $request
							);
						},
						'args'                => CreationsArgs\validate_id(),
						'permission_callback' => [ self::$api_services, 'permitted' ],
					],
				]
		);

		register_rest_route(
				$namespace,
				'/creations/(?P<id>\d+)/publish',
				[
					[
						'methods'             => \WP_REST_Server::EDITABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										'Mediavine\Create\Publish::publish',
									],
									$request
							);
						},
						'args'                => CreationsArgs\validate_id(),
						'permission_callback' => [ self::$api_services, 'permitted' ],
					],
				]
		);

		register_rest_route(
				$namespace,
				'/creations/(?P<id>\d+)/print',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[ [ $this->creations_views, 'print_view' ] ],
									$request
							);
						},
						'args'                => CreationsArgs\validate_id(),
						'permission_callback' => '__return_true',
					],
				]
		);

		register_rest_route(
				$namespace,
				'/creations/(?P<id>\d+)/duplicate',
				[
					[
						'methods'             => \WP_REST_Server::EDITABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[ [ $this->api, 'duplicate_create_card' ] ],
									$request
							);
						},
						'args'                => CreationsArgs\validate_id(),
						'permission_callback' => '__return_true',
					],
				]
		);

		register_rest_route(
				$namespace,
				'/creations/(?P<id>\d+)/jsonld',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
							$creation = self::prep_publish_creation( (int) $request['id'] );

							$allowed_html = [ 'script' => [ 'type' => [] ] ];
							if ( ! empty( $creation->json_ld ) ) {
								print wp_kses( '<script type="application/ld+json">' . $creation->json_ld . '</script>', $allowed_html );
							}

							exit;
						},
						'args'                => CreationsArgs\validate_id(),
						'permission_callback' => '__return_true',
					],
				]
		);

		register_rest_route(
				$namespace,
				'/creations/republish',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										'Mediavine\Create\Publish::republish_creations',
									],
									$request
							);
						},
						'permission_callback' => [ self::$api_services, 'permitted' ],
					],
				]
		);

		register_rest_route(
				$namespace,
				'/creations/(?P<id>\d+)/restore',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return API_Services::middleware(
									[
										'Mediavine\Create\Restore::from_published',
										[ $this->api, 'find_one' ],
									],
									$request
							);
						},
						'permission_callback' => [ self::$api_services, 'permitted' ],
					],
				]
		);
	}
}
