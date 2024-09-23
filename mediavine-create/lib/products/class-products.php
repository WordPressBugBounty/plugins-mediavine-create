<?php
namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * Products class
 */
class Products extends Plugin {

	/**
	 * Instance of Products class
	 * @var null|Products
	 */
	public static $instance = null;

	/**
	 * API root
	 * @var string
	 */
	public $api_root = 'mv-create';

	/**
	 * Instance of Products API class
	 * @var Products_API
	 */
	public $api = null;

	/**
	 * API version
	 * @var string
	 */
	public $api_version = 'v1';

	/**
	 * DB table
	 * @var string
	 */
	private $table_name = 'mv_products';

	/**
	 * Queue class reference
	 * @var Queue
	 */
	public $amazon_queue;

	/**
	 * DB table schema structure
	 * @var string[]
	 */
	public $schema = [
		'title'                  => 'text',
		'link'                   => 'text',
		'thumbnail_id'           => 'bigint(20)',
		'asin'                   => 'varchar(10)',
		'external_thumbnail_url' => 'text',
		'expires'                => 'datetime',
	];

	/**
	 * Singular post-type name
	 * @deprecated ?
	 * @var string
	 */
	public $singular = 'product';

	/**
	 * Plural post-type name
	 * @deprecated ?
	 * @var string
	 */
	public $plural = 'product';

	/**
	 * Instance of Amazon object
	 * @var Amazon
	 */
	private $amazon;

	/**
	 * Get instance of class
	 * @return Products
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Prepare product thumbnails. Download one if it doesn't exist. Should not process Amazon images
	 *
	 * @param \stdClass $product Product object to process
	 *
	 * @return \stdClass
	 */
	public static function prepare_product_thumbnail( $product ) {
		if ( empty( $product['thumbnail_id'] ) && ! empty( $product['external_thumbnail_url'] ) ) {
			return $product;
		}

		// If the type is the product map, we need to get the true product's asin
		if ( ! empty( $product['type'] ) && 'product_map' === $product['type'] ) {
			$true_product = self::$models_v2->mv_products->select_one( $product['product_id'] );
			if ( ! empty( $true_product ) && property_exists( $true_product, 'asin' ) ) {
				$product['asin'] = $true_product->asin;
			}
		}

		// Attempt to create a new thumbnail, but only if no ASIN
		$has_asin = Amazon::get_instance()->get_asin_from_link( $product['link'] );
		if ( ! empty( $product['remote_thumbnail_uri'] ) && empty( $product['asin'] ) && ! $has_asin ) {
			// Some results won't include protocol -or- use relative URLs, so we coerce these to absolute URLs.
			if ( strpos( $product['remote_thumbnail_uri'], 'http' ) === false ) {
				if ( strpos( $product['remote_thumbnail_uri'], '//' ) === 0 ) { // Only catch at beginning
					$product['remote_thumbnail_uri'] = 'http:' . $product['remote_thumbnail_uri'];
				} else {
					$parsed_url                      = parse_url( $product['remote_thumbnail_uri'] );
					$product['remote_thumbnail_uri'] = 'http://' . $parsed_url['host'] . $product['remote_thumbnail_uri'];
				}
			}

			$thumbnail_id            = Images::get_attachment_id_from_url( $product['remote_thumbnail_uri'] );
			$product['thumbnail_id'] = $thumbnail_id;
		}

		return $product;
	}

	/**
	 * Restores product images
	 *
	 * @param \stdClass $creation Creation object
	 *
	 * @return object|null
	 */
	public static function restore_product_images( $creation ) {
		if ( empty( $creation ) ) {
			return $creation;
		}

		$metadata = json_decode( $creation->metadata, true );
		if ( empty( $metadata ) ) {
			$metadata = [];
		}

		if ( isset( $metadata['product_images_restored'] ) && $metadata['product_images_restored'] ) {
			return $creation;
		}

		$products = self::$models_v2->mv_products_map->find(
			[
				'where' => [
					'creation' => $creation->id,
				],
			]
		);

		$scraper = new \Mediavine\Create\LinkScraper();
		$changed = false;
		foreach ( $products as $product ) {
			if ( $product->thumbnail_id ) {
				continue;
			}

			if ( ! isset( $product->link ) ) {
				continue;
			}

			$data = $scraper->scrape( $product->link );
			if ( ! isset( $data['remote_thumbnail_uri'] ) ) {
				continue;
			}
			$product->remote_thumbnail_uri = $data['remote_thumbnail_uri'];
			unset( $product->thumbnail_id );

			$product = self::prepare_product_thumbnail( (array) $product );
			$updated = self::$models_v2->mv_products_map->update( (array) $product );
			if ( $updated ) {
				$changed = true;
			}
		}

		if ( $changed ) {
			$metadata['product_images_restored'] = true;
			$creation->metadata                  = wp_json_encode( $metadata );
			$creation                            = self::$models_v2->mv_creations->update_without_modified_date( (array) $creation );
			return \Mediavine\Create\Creations::publish_creation( $creation->id );
		}

		return $creation;
	}

	/**
	 * Initialize class
	 * @return void
	 */
	public function init() {
		$this->amazon_queue = new Queue(
			[
				'transient_name' => 'mv_create_amazon_queue',
				'queue_name'     => 'mv_create_amazon_queue',
				'lock_timeout'   => 600,
				'auto_unlock'    => false,
			]
		);
		$this->amazon       = Amazon::get_instance();
		$this->api          = new Products_API();

		add_filter( 'mv_custom_schema', [ $this, 'custom_schema' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_filter( 'mv_dbi_after_update_' . $this->table_name, [ $this, 'cascade_after_update' ] );
		add_action( 'init', [ $this, 'refresh_product_images' ] );
		add_action( 'init', [ $this, 'step_amazon_queue' ] );
		add_action( 'mv_create_setting_updated_mv_create_paapi_secret_key', [ $this, 'lock_amazon_queue' ] );
	}

	/**
	 * Refresh Amazon images. Fired by Queue
	 * @return false|void
	 */
	public function refresh_product_images() {
		remove_action( 'mv_dbi_after_update_mv_products', [ self::get_instance(), 'cascade_after_update' ] );
		$transient = 'mv_amazon_expiring_products';
		if ( get_transient( $transient ) ) {
			return false;
		}

		$three_hours       = 3 * 60 * 60;
		$amazon_rate_limit = apply_filters( 'mv_create_amazon_rate_limit', $three_hours );
		$expiring          = $this->get_expiring_products( $amazon_rate_limit );
		if ( empty( $expiring ) ) {
			return false;
		}

		$expiring = array_column( $expiring, 'id' );
		$this->amazon_queue->push_many( $expiring );

		set_transient( $transient, time(), $amazon_rate_limit );
	}

	/**
	 * Lock the Amazon queue
	 * @return void
	 */
	public function lock_amazon_queue() {
		$timeout = 2 * DAY_IN_SECONDS;
		$this->amazon_queue->lock( $timeout );

		// Also set provision transient
		$transient = 'mv_create_amazon_provision';
		set_transient( $transient, true, $timeout );

		// Clear transient complete setting
		Settings::delete_setting( $transient . '_complete' );
	}

	/**
	 * Advance Amazon Queue
	 * @return false|mixed|void|null
	 */
	public function step_amazon_queue() {
		// Only run the queue if Amazon is setup
		if ( $this->amazon->amazon_affiliates_setup() ) {
			return $this->amazon_queue->step( [ $this, 'build_amazon_data' ] );
		}
	}

	/**
	 * Initialize the Amazon product Queue
	 * @return void
	 */
	public function initial_queue_products() {
		global $wpdb;
		$table       = self::$models_v2->mv_products->table_name;
		$query       = "SELECT id FROM $table WHERE link LIKE '%amazon.%'";
		// linter complains about prepared method not being used, but there's nothing to prepare
		$product_ids = $wpdb->get_col( $query ); // @phpcs:ignore
		if ( empty( $product_ids ) ) {
			return;
		}

		$this->amazon_queue->push_many( $product_ids );
	}

	/**
	 * Build the Amazon data array for Queue processing
	 * @param integer $product_id Product ID
	 *
	 * @return false|void
	 */
	public function build_amazon_data( $product_id ) {
		$product = (array) self::$models_v2->mv_products->select_one_by_id( $product_id );
		if ( empty( $product ) ) {
			return false;
		}

		if ( is_wp_error( $product ) ) {
			return false;
		}

		if ( empty( $product['asin'] ) ) {
			$product['asin'] = $this->amazon->get_asin_from_link( $product['link'] );
		}

		$result = $this->amazon->get_products_by_asin( $product['asin'] );

		// Move on if empty or is an error
		if ( empty( $result ) || is_wp_error( $result ) ) {
			return false;
		}

		$product['external_thumbnail_url'] = $result[ $product['asin'] ]['external_thumbnail_url'];
		$product['expires']                = $result[ $product['asin'] ]['expires'];
		self::$models_v2->mv_products->update( $product );
	}

	/**
	 * Process product after update
	 *
	 * @param array|object $product Product to modify after update
	 *
	 * @return array|object
	 */
	public function cascade_after_update( $product ) {
		global $wpdb;

		$update_values = [];

		if ( isset( $product->title ) ) {
			$update_values['title'] = $product->title;
		}

		// We want to update null values as well, so checking if property exists
		if ( property_exists( $product, 'thumbnail_id' ) ) {
			$update_values['thumbnail_id'] = $product->thumbnail_id;
		}

		if ( isset( $product->link ) ) {
			$update_values['link'] = $product->link;
		}

		add_filter( 'query', [ self::$models_v2->mv_products, 'allow_null' ] );
		$updated = $wpdb->update(
			$wpdb->prefix . 'mv_products_map',
			$update_values,
			[ 'product_id' => $product->id ]
		);
		remove_filter( 'query', [ self::$models_v2->mv_products, 'allow_null' ] );

		$result = self::$models_v2->mv_products_map->find(
			[
				'select' => [ 'creation' ],
				'where'  => [
					'product_id' => $product->id,
				],
			]
		);

		$ids = [];

		foreach ( $result as $item ) {
			$ids[] = $item->creation;
		}

		\Mediavine\Create\Publish::update_publish_queue( $ids );

		return $product;
	}

	/**
	 * Add table schema to array of table schemas for DB updates
	 * @param array $tables Array of table schema
	 *
	 * @return mixed
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
	 * Given a product id, return array of creations using that product
	 * @param integer $product_id Product ID
	 * @return array Array of [id, title] arrays
	 */
	public static function get_product_creations( $product_id ) {
		global $wpdb;
		$creations    = $wpdb->prefix . 'mv_creations';
		$products_map = $wpdb->prefix . 'mv_products_map';

		// SECURITY CHECKED: This query is properly prepared.
		$sql          = "SELECT $creations.type, $creations.object_id, $creations.id, $creations.title FROM $creations JOIN $products_map ON $creations.id = $products_map.creation WHERE $products_map.product_id = %d;";
		$prepared     = $wpdb->prepare( $sql, $product_id );
		$creations    = $wpdb->get_results( $prepared );

		return count( $creations ) ? $creations : [];
	}


	/**
	 * Filter products assigned to the card from the Product Select list
	 *
	 * @param array $creation_id Creation ID
	 * @param array $products    Array of products
	 *
	 * @return array Filtered list of products
	 */
	public static function filter_existing_products( $creation_id, $products ) {
		if ( ! $creation_id ) {
			return $products;
		}

		$product_maps = (array) self::$models_v2->mv_products_map->find( [
			'where' => [
				'creation' => $creation_id,
			],
		] );

		foreach ( $product_maps as $product_map ) {
			foreach ( $products as $index => $product ) {
				if ( $product_map->link !== $product->link ) {
					continue;
				}

				unset( $products[ $index ] );
			}
		}

		return $products;
	}

	/**
	 * Get products whose Amazon images are expiring within a certain timeframe.
	 *
	 * @param integer $within time in seconds from now--default is 3 hours
	 * @param integer $limit how many products to query at one time
	 * @return array of products expiring
	 */
	public function get_expiring_products( $within = 10800, $limit = 50 ) {
		$timestamp = date( 'Y-m-d H:i:s', strtotime( "+{$within} seconds" ) );
		$model     = self::$models_v2->mv_products;

		$model->set_select( '*' )
			->set_order_by( 'expires' )
			->set_order( 'ASC' )
			->set_limit( $limit );

		$products = self::$models_v2->mv_products->where(
			[
				// make sure the product is an Amazon link and has an expiration
				[ 'asin', 'IS NOT', 'NULL' ],
				[ 'expires', 'IS NOT', 'NULL' ],
				// and that the expiration is $within the $timestamp
				[ 'expires', '<', $timestamp ],
			]
		);

		return $products;
	}

	/**
	 * Register API routes
	 * @return void
	 */
	public function routes() {
		$namespace = $this->api_root . '/' . $this->api_version;

		register_rest_route(
			$namespace, '/products', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ self::$api_services, 'process_pagination' ],
								[ $this->api, 'find' ],
							], $request
						);
					},
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'upsert' ],
							], $request
						);
					},
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
			]
		);

		register_rest_route(
			$namespace, '/products/scrape', [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'scrape' ],
							],
							$request
						);
					},
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
			]
		);

		register_rest_route(
			$namespace, '/products/scrape-non-amazon', [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'scrape_non_amazon' ],
							],
							$request
						);
					},
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
			]
		);

		register_rest_route(
			$namespace, '/products/reset-amazon-thumbnails', [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'reset_amazon_thumbnails' ],
							],
							$request
						);
					},
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
			]
		);

		register_rest_route(
			$namespace, '/products/reset-amazon-provision', [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'reset_amazon_provision' ],
							],
							$request
						);
					},
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
			]
		);

		register_rest_route(
			$namespace, '/products/(?P<id>\d+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'find_one' ],
								[ $this->api, 'get_pagination_links' ],
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
