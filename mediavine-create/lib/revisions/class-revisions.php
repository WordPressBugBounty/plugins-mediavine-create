<?php
namespace Mediavine\Create;

class Revisions extends Plugin {
	public $table_name = 'mv_revisions';

	public $schema = [
		'creation'       => [
			'type' => 'bigint(20)',
			'key'  => true,
		],
		'published_data' => 'longtext',
	];

	public $api_root = 'mv-create';

	public $api_version = 'v1';

	public $namespace;

	protected static $instance = null;

	private $max_revisions;

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	function init() {
		$this->namespace = $this->api_root . '/' . $this->api_version;
		/**
		 * Modify the maximum number of Create card revisions for a given Create card.
		 *
		 * @param int $max maximum number of revisions
		 */
		$this->max_revisions = (int) apply_filters( 'mv_create_maximum_number_of_revisions', 15 );

		add_filter( 'mv_custom_schema', [ $this, 'custom_schema' ] );

		add_action( 'mv_create_card_pre_publish', [ $this, 'add_creation_revision' ], 10, 2 );
		add_action( 'mv_create_post_create_revision', [ $this, 'delete_old_revisions' ], 10, 1 );
	}

	/**
	 * Create a revision.
	 *
	 * Add the new published data as a revision.
	 *
	 * @param int $creation_id
	 * @param string $published_data
	 * @return object created record
	 */
	public function create( $creation_id, $published_data ) {
		do_action( 'mv_create_pre_create_revision', $creation_id, $published_data );
		$revision = self::$models_v2->mv_revisions->create(
			[
				'creation'       => (int) $creation_id,
				'published_data' => $published_data,
			]
		);
		do_action( 'mv_create_post_create_revision', $creation_id, $published_data, $revision );
		return $revision;
	}

	public function delete_old_revisions( $creation_id ) {
		$count = (int) self::$models_v2->mv_revisions->get_count( [], [ 'creation' => $creation_id ] );

		// Only run revisions deletion if we have more revisions than the max allotted
		if ( $count && $count > $this->max_revisions ) {
			global $wpdb;
			$limit = $count - $this->max_revisions;

			// Delete all matches except those within the limit
			// SECURITY CHECKED: This query is properly prepared.
			$deletion_statement = "DELETE FROM {$wpdb->prefix}mv_revisions WHERE creation = %d ORDER BY creation ASC LIMIT %d";
			$prepared_statement = $wpdb->prepare( $deletion_statement, [ $creation_id, $limit ] );
			$wpdb->query( $prepared_statement );
		}
	}

	/**
	 * Adds a creation revision.
	 *
	 * Uses data passed in by `mv_create_card_post_publish` action.
	 *
	 * @param object $creation
	 * @param string $published
	 * @return void
	 */
	public function add_creation_revision( $creation, $published ) {
		$current_creation     = self::$models_v2->mv_creations->find_one_by_id( $creation->id );
		$current_published    = ! empty( $current_creation->published ) ? explode( '"object_id"', $current_creation->published )[1] : '';
		$unmodified_published = explode( '"object_id"', $published )[1];
		// if the published strings are equal, we don't need a revision
		if ( $current_published === $unmodified_published ) {
			return;
		}
		$this->create( $creation->id, $published );
	}

	/**
	 * Find a creation's revisions.
	 *
	 * @param int $creation_id
	 * @return array revisions for a given Create card
	 */
	public function find( $creation_id ) {
		$model = self::$models_v2->mv_revisions;
		$model->set_order_by( 'id' );
		return $model->where( 'creation', '=', $creation_id );
	}

	/**
	 * API callback to return revisions for a Create card.
	 *
	 * @param \WP_REST_Request $request
	 * @return array revisions for a given Create card
	 */
	public function get_revisions( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$creation_id = $request->get_param( 'id' );
		$authed      = $request->get_param( 'auth' );

		if ( ! $authed ) {
			return new \WP_REST_Response( [], 403 );
		}
		if ( empty( $creation_id ) ) {
			return [];
		}

		$revisions = $this->find( $creation_id );
		foreach ( $revisions as &$revision ) {
			$revision->published_json = $revision->published_data;

			$decoded                  = json_decode( $revision->published_data );
			$revision->published_data = $decoded;
		}

		$revisions = $this::$api_services->prepare_items_for_response( $revisions, $request );
		return API_Services::set_response_data( $revisions, $response );
	}

	public function custom_schema( $tables ) {
		$tables[] = [
			'version'    => self::DB_VERSION,
			'table_name' => $this->table_name,
			'schema'     => $this->schema,
		];
		return $tables;
	}

}
