<?php
namespace Mediavine\Create;

class Supplies extends Plugin {

	public static $instance = null;

	public $api_root = 'mv-create';

	public $api_version = 'v1';

	public $api = null;

	private $table_name = 'mv_supplies';

	public $schema = [
		'type'          => 'varchar(20)',
		'creation'      => [
			'type' => 'bigint(20)',
			'key'  => true,
		],
		'original_text' => 'longtext',
		'note'          => 'longtext',
		'link'          => 'longtext',
		'`group`'       => 'longtext',
		'position'      => 'mediumint(9)',
		'amount'        => 'longtext',
		'max_amount'    => 'longtext',
		'nofollow'      => [
			'type'    => 'tinyint(1)',
			'default' => 1,
		],
	];

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	public static function get_creation_supplies( $creation_id, $type = null ) {
		global $wpdb;
		$table       = self::$models_v2->mv_supplies->table_name;
		$creation_id = intval( $creation_id );

		$prepared_statement = $wpdb->prepare( "SELECT * FROM {$table} WHERE creation = %d ORDER BY %s, %s ASC", [ $creation_id, 'type', 'position' ] );

		if ( $type ) {
			$prepared_statement = $wpdb->prepare( "SELECT * FROM {$table} WHERE creation = %d AND type = %s ORDER BY %s, %s ASC", [ $creation_id, $type, 'type', 'position' ] );
		}

		// SECURITY CHECKED: This query is properly prepared.
		return $wpdb->get_results( $prepared_statement );
	}

	public static function put_supplies_in_groups_array( $supplies = [] ) {
		$output = [];
		if ( is_array( $supplies ) ) {
			foreach ( $supplies as $supply ) {

				if ( ! isset( $output[ $supply->group ] ) ) {
					$output[ $supply->group ] = [];
				}
				$output[ $supply->group ][] = (array) $supply;

				usort( $output[ $supply->group ], [ '\Mediavine\Create\Supplies', 'sort_supply' ] );
			}
		}
		return $output;
	}

	function init() {
		$this->api = new Supplies_API();

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

	public static function delete_all_supplies( $creation_id, $type ) {
		return self::$models_v2->mv_supplies->delete(
			[
				'where' => [
					'creation' => $creation_id,
					'type'     => $type,
				],
			]
		);
	}

	public static function sort_supply( $a, $b ) {
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

	public static function prepare_supplies( $supplies ) {
		$supplies_list = [];
		$groups        = [];
		$no_group      = [];
		if ( is_array( $supplies ) ) {
			foreach ( $supplies as $supply ) {
				if ( ! empty( $supply->group ) ) {
					$groups[ $supply->group ][] = $supply;
				} else {
					$no_group[] = $supply;
				}
			}
			// Uses long key that likely will never be used as a real group title
			$supplies_list = array_merge( [ 'mv-has-no-group' => $no_group ], $groups );

		}
		return $supplies_list;
	}

	function routes() {
		$namespace = $this->api_root . '/' . $this->api_version;

		register_rest_route(
			$namespace, '/supplies', [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'create' ],
							],
							$request
						);
					},
					'permission_callback' => [ self::$api_services, 'permitted' ],
				],
			]
		);

		register_rest_route(
			$namespace, '/creations/(?P<id>\d+)/supplies', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function ( \WP_REST_Request $request ) {
						return \Mediavine\Create\API_Services::middleware(
							[
								[ $this->api, 'read_creation_supplies' ],
							],
							$request
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
								[ $this->api, 'set_supplies' ],
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

