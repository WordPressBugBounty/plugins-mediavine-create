<?php
namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * Products class
 */
class Products extends Plugin {


	/**
	 * Products table database version
	 * Increment this when changing the products table schema
	 */
	const PRODUCTS_DB_VERSION = '2.0.2';

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
	 * Shared Amazon refresh service for product cards.
	 *
	 * @var Amazon_Refresh_Service
	 */
	public $amazon_refresh;

	/**
	 * DB table schema structure
	 * @var string[]
	 */
	public $schema = [
		'title'                  => 'text',
		'description'            => 'text',
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
	 * Instance of Amazon object (Amazon or Amazon_Creators via adapter)
	 * @var Amazon|Amazon_Creators
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
		if ( empty($product['thumbnail_id']) && ! empty($product['external_thumbnail_url']) ) {
			return $product;
		}

		// If the type is the product map, we need to get the true product's asin
		if ( ! empty($product['type']) && 'product_map' === $product['type'] ) {
			$true_product = self::$models_v2->mv_products->select_one($product['product_id']);
			if ( ! empty($true_product) && property_exists($true_product, 'asin') ) {
				$product['asin'] = $true_product->asin;
			}
		}

		// Attempt to create a new thumbnail, but only if no ASIN
		$has_asin = Amazon_Adapter::get_instance()->get_asin_from_link($product['link']);
		if ( ! empty($product['remote_thumbnail_uri']) && empty($product['asin']) && ! $has_asin ) {
			// Some results won't include protocol -or- use relative URLs, so we coerce these to absolute URLs.
			if ( strpos($product['remote_thumbnail_uri'], 'http') === false ) {
				if ( strpos($product['remote_thumbnail_uri'], '//') === 0 ) { // Only catch at beginning
					$product['remote_thumbnail_uri'] = 'http:' . $product['remote_thumbnail_uri'];
				} else {
					$parsed_url                      = wp_parse_url( $product['remote_thumbnail_uri']);
					$product['remote_thumbnail_uri'] = 'http://' . $parsed_url['host'] . $product['remote_thumbnail_uri'];
				}
			}

			$thumbnail_id            = Images::get_attachment_id_from_url($product['remote_thumbnail_uri']);
			$product['thumbnail_id'] = $thumbnail_id;
		}

		return $product;
	}

	/**
	 * Restores missing product images via LinkScraper.
	 *
	 * Queue/cron only — never call from card read/render (prep_creation_view).
	 * Always records completion so dead product links are not re-scraped forever.
	 *
	 * @param \stdClass $creation Creation object
	 *
	 * @return object|null
	 */
	public static function restore_product_images( $creation ) {
		if ( empty( $creation ) ) {
			return $creation;
		}

		$metadata = json_decode( ! empty( $creation->metadata ) ? $creation->metadata : '{}', true );
		if ( empty( $metadata ) ) {
			$metadata = [];
		}

		if ( ! empty( $metadata['product_images_restored'] ) ) {
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

			if ( empty( $product->link ) ) {
				continue;
			}

			$data = $scraper->scrape( $product->link );
			if ( empty( $data['remote_thumbnail_uri'] ) ) {
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

		// Always mark done — including when scrapes fail — so dead links stop
		// re-entering the restore sweep (and formerly the render path) forever.
		$metadata['product_images_restored'] = true;
		$creation->metadata                  = wp_json_encode( $metadata );
		$creation                            = self::$models_v2->mv_creations->update_without_modified_date( (array) $creation );

		if ( $changed ) {
			return \Mediavine\Create\Creations::publish_creation( $creation->id );
		}

		return $creation;
	}

	/**
	 * Schedule a one-off background sweep that restores missing product images.
	 *
	 * Previously ran synchronously (with remote HTTP) on every card read/render.
	 * Queued once per upgrade so scrapes happen off the front-end path.
	 *
	 * Remove after January 2027 (or 12 minor releases past 2.5.4).
	 *
	 * @param string $last_plugin_version Version being upgraded from.
	 *
	 * @return void
	 */
	public static function schedule_product_images_restore( $last_plugin_version = '' ) {
		if ( empty( $last_plugin_version ) ) {
			$last_plugin_version = get_option( 'mv_create_version', Plugin::VERSION );
		}

		if ( version_compare( $last_plugin_version, '2.5.4', '>=' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'mv_create_restore_product_images' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mv_create_restore_product_images' );
		}
	}

	/**
	 * Restore missing product thumbnails in small batches, rescheduling until done.
	 *
	 * @return void
	 */
	public static function process_product_images_restore_batch() {
		global $wpdb;

		$batch_size = 10;
		$table      = $wpdb->prefix . 'mv_products_map';
		$creations  = $wpdb->prefix . 'mv_creations';

		// Creations with at least one product map row missing a thumbnail that
		// have not yet completed (or been marked complete after a failed scrape).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time migration; table names are $wpdb->prefix . literal
		$creation_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.creation
				FROM {$table} pm
				INNER JOIN {$creations} c ON c.id = pm.creation
				WHERE ( pm.thumbnail_id IS NULL OR pm.thumbnail_id = 0 )
					AND ( c.metadata IS NULL OR c.metadata NOT LIKE %s )
				LIMIT %d",
				'%"product_images_restored":true%',
				$batch_size
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $creation_ids ) ) {
			return;
		}

		foreach ( $creation_ids as $creation_id ) {
			$creation = self::$models_v2->mv_creations->find_one_by_id( (int) $creation_id );
			if ( empty( $creation ) ) {
				continue;
			}
			self::restore_product_images( $creation );
		}

		// More may remain — process the next batch on the following cron tick.
		if ( count( $creation_ids ) >= $batch_size ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mv_create_restore_product_images' );
		}
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
		$this->amazon       = Amazon_Adapter::get_instance();
		$this->api          = new Products_API();

		$this->amazon_refresh = new Amazon_Refresh_Service(
			[
				'queue'              => $this->amazon_queue,
				'amazon'             => $this->amazon,
				'model'              => self::$models_v2->mv_products,
				'expiring_transient' => 'mv_amazon_expiring_products',
				'url_field'          => 'link',
				'before_refresh'     => function () {
					remove_action( 'mv_dbi_after_update_mv_products', [ self::get_instance(), 'cascade_after_update' ] );
				},
				'apply_result'       => function ( $product, $item ) {
					$product['external_thumbnail_url'] = $item['external_thumbnail_url'];
					$product['expires']                = $item['expires'];
					return $product;
				},
			]
		);
		$this->amazon_refresh->register();

		add_filter( 'mv_custom_schema', [ $this, 'custom_schema' ] );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_filter( 'mv_dbi_after_update_' . $this->table_name, [ $this, 'cascade_after_update' ] );
		add_action( 'mv_create_setting_updated_mv_create_creators_credential_secret', [ $this, 'lock_amazon_queue' ] );
		add_action( Plugin::PLUGIN_DOMAIN . '_plugin_updated', [ __CLASS__, 'schedule_product_images_restore' ], 106 );
		add_action( 'mv_create_restore_product_images', [ __CLASS__, 'process_product_images_restore_batch' ] );
	}

	/**
	 * Refresh Amazon images. Fired by Queue
	 *
	 * @return false|void
	 */
	public function refresh_product_images() {
		return $this->amazon_refresh->refresh_expiring();
	}

	/**
	 * Lock the Amazon queue
	 * @return void
	 */
	public function lock_amazon_queue() {
	   $timeout = 2 * DAY_IN_SECONDS;
		$this->amazon_queue->lock($timeout);

		// Also set provision transient
		$transient = 'mv_create_amazon_provision';
		set_transient($transient, true, $timeout);

		// Clear transient complete setting
		Settings::delete_setting($transient . '_complete');
	}

	/**
	 * Advance Amazon Queue
	 * @return false|mixed|void|null
	 */
	public function step_amazon_queue() {
		return $this->amazon_refresh->step_queue();
	}

	/**
	 * Initialize the Amazon product Queue
	 * @return void
	 */
	public function initial_queue_products() {
	  global $wpdb;
		$table = self::$models_v2->mv_products->table_name;
		$query = "SELECT id FROM $table WHERE link LIKE '%amazon.%'";
		// linter complains about prepared method not being used, but there's nothing to prepare
		$product_ids = $wpdb->get_col($query); // @phpcs:ignore
		if ( empty($product_ids) ) {
			return;
		}

		$this->amazon_queue->push_many($product_ids);
	}

	/**
	 * Build the Amazon data array for Queue processing
	 * @param integer $product_id Product ID
	 *
	 * @return false|void
	 */
	public function build_amazon_data( $product_id ) {
		return $this->amazon_refresh->build_amazon_data( $product_id );
	}

	/**
	 * Process product after update
	 *
	 * Cascades product updates to both products_map and relations tables,
	 * then triggers republish of affected creation cards.
	 *
	 * @param array|object $product Product to modify after update
	 *
	 * @return array|object
	 */
	public function cascade_after_update( $product ) {
		global $wpdb;

		$update_values = [];

		if ( isset($product->title) ) {
			$update_values['title'] = $product->title;
		}

		// We want to update null values as well, so checking if property exists
		if ( property_exists($product, 'thumbnail_id') ) {
			$update_values['thumbnail_id'] = $product->thumbnail_id;
		}

		if ( isset($product->link) ) {
			$update_values['link'] = $product->link;
		}

		// Cascade updates to products_map table
		if ( ! empty( $update_values ) ) {
			add_filter('query', [ self::$models_v2->mv_products, 'allow_null' ]);
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
			$wpdb->update(
				$wpdb->prefix . 'mv_products_map',
				$update_values,
				[ 'product_id' => $product->id ]
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			remove_filter('query', [ self::$models_v2->mv_products, 'allow_null' ]);
		}

		// Cascade updates to relations table (for list items)
		// The relations table uses 'url' instead of 'link'
		$relations_values = $update_values;
		if ( isset( $relations_values['link'] ) ) {
			$relations_values['url'] = $relations_values['link'];
			unset( $relations_values['link'] );
		}

		if ( ! empty( $relations_values ) ) {
			add_filter('query', [ self::$models_v2->mv_products, 'allow_null' ]);
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
			$wpdb->update(
				$wpdb->prefix . 'mv_relations',
				$relations_values,
				[
					'relation_id'  => $product->id,
					'content_type' => 'product',
				]
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			remove_filter('query', [ self::$models_v2->mv_products, 'allow_null' ]);
		}

		// Collect creation IDs from products_map
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

		// Collect creation IDs from relations (list items)
		$relations_result = self::$models_v2->mv_relations->find(
			[
				'select' => [ 'creation' ],
				'where'  => [
					'relation_id'  => $product->id,
					'content_type' => 'product',
				],
			]
		);

		foreach ( $relations_result as $relation ) {
			$ids[] = $relation->creation;
		}

		// Remove duplicates and trigger republish
		$ids = array_unique( $ids );

		if ( ! empty( $ids ) ) {
			\Mediavine\Create\Publish::update_publish_queue($ids);
		}

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
			'version'    => self::PRODUCTS_DB_VERSION,
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

		$sql       = "SELECT $creations.type, $creations.object_id, $creations.id, $creations.title FROM $creations JOIN $products_map ON $creations.id = $products_map.creation WHERE $products_map.product_id = %d;";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$prepared  = $wpdb->prepare($sql, $product_id);
		$creations = $wpdb->get_results($prepared);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return count($creations) ? $creations : [];
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

		$product_maps = (array) self::$models_v2->mv_products_map->find([
			'where' => [
				'creation' => $creation_id,
			],
		]);

		foreach ( $product_maps as $product_map ) {
			foreach ( $products as $index => $product ) {
				if ( $product_map->link !== $product->link ) {
					continue;
				}

				unset($products[ $index ]);
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
		return $this->amazon_refresh->get_expiring( $within, $limit );
	}

	/**
	 * Register API routes
	 * @return void
	 */
	public function routes() {
	  $namespace = $this->api_root . '/' . $this->api_version;

		register_rest_route(
			$namespace,
			'/products',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ self::$api_services, 'process_pagination' ],
								[ $this->api, 'find' ],
							],
							$request
						);
					},
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/products/scrape',
			[
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/products/scrape-non-amazon',
			[
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/products/reset-amazon-thumbnails',
			[
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/products/reset-amazon-provision',
			[
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		// Debug endpoint for testing Amazon API error messages (only in development)
		// Completely open when WP_DEBUG is true - only returns mock error data, no security risk
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			register_rest_route(
				$namespace,
				'/products/debug-amazon-error',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => function ( \WP_REST_Request $request ) {
							return $this->api->debug_amazon_error( $request, new \WP_REST_Response() );
						},
						'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ],
					],
				]
			);
		}

		register_rest_route(
			$namespace,
			'/products/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'find_one' ],
								[ $this->api, 'get_pagination_links' ],
							],
							$request
						);
					},
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'destroy' ],
							],
							$request
						);
					},
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);
	}
}
