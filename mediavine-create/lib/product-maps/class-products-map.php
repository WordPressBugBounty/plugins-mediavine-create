<?php
namespace Mediavine\Create;

use Mediavine\WordPress\Support\Arr;

/**
 * DBI functions for Products Map
 */
class Products_Map extends Plugin {

	public static $instance = null;

	public $api_root = 'mv-create';

	public $api = null;

	public $api_version = 'v1';

	private $table_name = 'mv_products_map';

	public $schema = [
		'type'         => [
			'type'    => 'varchar(20)',
			'default' => "'product_map'",
		],
		'creation'     => 'bigint(20)',
		'product_id'   => 'bigint(20)',
		'recipe_id'    => 'bigint(20)',
		'title'        => 'text',
		'link'         => 'text',
		'thumbnail_id' => 'bigint(20)',
		'position'     => 'tinyint(3)',
	];

	public $singular = 'product_map';

	public $plural = 'product_map';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	public static function sort_product_map( $a, $b ) {
		$a = (array) $a;
		$b = (array) $b;

		if ( is_null( $a['position'] ) || is_null( $b['position'] ) ) {
			return 0;
		}
		if ( $a['position'] < $b['position'] ) {
			return -1;
		}
		if ( $a['position'] > $b['position'] ) {
			return 1;
		}
		return 0;
	}

	public static function get_creation_products_map( $creation_id ) {
		global $wpdb;
		$table       = self::$models_v2->mv_products_map->table_name;
		$creation_id = intval( $creation_id );

		if ( 'list' === Creations::get_creation_type( $creation_id ) ) {
			static::delete_all_products_maps( $creation_id );
			return [];
		}

		// SECURITY CHECKED: This query is properly prepared.
		$prepared_statement = $wpdb->prepare( "SELECT * FROM {$table} WHERE creation = %d ORDER BY %s ASC", [ $creation_id, 'position' ] );
		$products           = $wpdb->get_results( $prepared_statement );

		foreach ( $products as &$product ) {
			$product->thumbnail_uri = self::get_correct_thumbnail_src( $product );
		}

		usort( $products, [ '\Mediavine\Create\Products_Map', 'sort_product_map' ] );

		return $products;
	}

	public static function delete_all_products_maps( $creation_id ) {
		return self::$models_v2->mv_products_map->delete(
			[
				'col' => 'creation',
				'key' => $creation_id,
			]
		);
	}

	public static function get_correct_thumbnail_src( $product ) {
		// Make sure we are using an array
		$product = (array) $product;

		$thumbnail_src = null;

		// Check for local thumbnail first
		if ( ! empty( $product['thumbnail_id'] ) ) {
			$img_src_prep = wp_get_attachment_image_src( $product['thumbnail_id'], 'mv_create_1x1' );
			if ( ! empty( $img_src_prep[0] ) ) {
				$thumbnail_src = $img_src_prep[0];
			}
		}
		// Use external thumbnail next
		if ( empty( $thumbnail_src ) && ! empty( $product['product_id'] ) ) {
			$external_url = self::$models_v2->mv_products->find(
				[
					'select' => [ 'external_thumbnail_url' ],
					'where'  => [ 'id' => $product['product_id'] ],
				]
			);

			if ( is_wp_error( $external_url ) ) {
				return $thumbnail_src;
			}

			if ( ! empty( $external_url[0]->external_thumbnail_url ) ) {
				$thumbnail_src = $external_url[0]->external_thumbnail_url;
			}
		}

		return $thumbnail_src;
	}


	/**
	 * Get existing ASINs in Products table based on creation and products map table
	 *
	 * @param int|string $creation_id The ID of the creation we're mapping
	 * @param array      $products    Pass the request payload of products to grab ASINs from
	 *
	 * @return array Array of asins, otherwise empty
	 */
	public static function get_existing_asins_for_creation( $creation_id, $products ) {
		$amazon = Amazon::get_instance();
		// retrieve original product maps for this creation
		$original_product_maps = self::$models_v2->mv_products_map->where_many( [
			[ 'creation', '=', $creation_id ],
		] );

		// even if product_ids is empty, that doesn't necessarily mean we have no pre-existing ASINs
		// this can happen if the Card is new, but pre-existing products are being added
		$product_ids = Arr::pluck( $original_product_maps, 'product_id' );
		$column      = 'id';
		if ( empty( $product_ids ) ) {
			// getting the ASIN from the link is the most certain method
			foreach ( $products as $product ) {
				$product_ids[] = $amazon->get_asin_from_link( $product['link'] );
			}

			$column = 'asin';
		}

		// retrieve products from mv_products
		$original_products = (array) self::$models_v2->mv_products->find( [
			'select' => [
				'id as product_id',
				'asin',
			],
			'where'  => [
				$column => [
					'in' => $product_ids,
				],
			],
		] );

		$existing_asins = Arr::pluck( $original_products, 'asin' );
		$existing_asins = array_values( array_filter( $existing_asins, function ( $v ) {
			return ! empty( $v );
		} ) );

		return $existing_asins;
	}

	function init() {
		$this->api = new Products_Map_API();
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

	function routes() {
		$namespace = $this->api_root . '/' . $this->api_version;

		register_rest_route(
			$namespace, '/creations/(?P<id>\d+)/products', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'find' ],
							], $request
						);
					},
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'destroy' ],
							], $request
						);
					},
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'upsert' ],
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
