<?php
namespace Mediavine\Create;

class Nutrition extends Plugin {

	public static $instance = null;

	public $api_root = 'mv-create';

	public $api = null;

	public $api_version = 'v1';

	private $table_name = 'mv_nutrition';

	public $schema = [
		'creation'           => [
			'type' => 'bigint(20)',
			'key'  => true,
		],
		'serving_size'       => [
			'type'    => 'text',
			'default' => 'NULL',
		],
		'number_of_servings' => [
			'type'    => 'text',
			'default' => 'NULL',
		],
		'calories'           => 'text',
		'total_fat'          => 'text',
		'saturated_fat'      => 'text',
		'trans_fat'          => 'text',
		'unsaturated_fat'    => 'text',
		'cholesterol'        => 'text',
		'sodium'             => 'text',
		'carbohydrates'      => 'text',
		'fiber'              => 'text',
		'sugar'              => 'text',
		'protein'            => 'text',
		'net_carbs'          => 'text',
		'sugar_alcohols'     => 'text',
		'calculated'         => 'datetime',
		'source'             => 'text',
		'display_zeros'      => 'text',
	];

	public $singular = 'nutrition';

	public $plural = 'nutrition';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	public static function get_creation_nutrition( $creation_id ) {
		global $wpdb;
		$table       = self::$models_v2->mv_nutrition->table_name;
		$creation_id = intval( $creation_id );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$prepared_statement = $wpdb->prepare( "SELECT * FROM {$table} WHERE creation = %d", [ $creation_id ] );
		$nutrition          = $wpdb->get_results( $prepared_statement );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( count( $nutrition ) ) {
			return $nutrition[0];
		}
		return [];
	}

	function init() {
		$this->api = new Nutrition_API();
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

		// Server-side proxy for Studio nutrition calculation. Keeps the site JWT
		// on the server instead of shipping it to the browser.
		register_rest_route(
			$namespace, '/nutrition', [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'proxy' ],
							],
							$request
						);
					},
					'permission_callback' => [ \Mediavine\Permissions::class, 'editor' ],
				],
			]
		);

		register_rest_route(
			$namespace, '/creations/(?P<id>\d+)/nutrition', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'find_one' ],
							], $request
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
