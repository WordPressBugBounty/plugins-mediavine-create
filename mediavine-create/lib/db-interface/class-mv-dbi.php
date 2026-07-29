<?php
namespace Mediavine;

use WP_Error;
use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;

class MV_DBI {

	public $table_name = null;

	public $short_name = null;

	public $columns = [];

	protected $limit    = 50;
	protected $offset   = 0;
	protected $order_by = 'created';
	protected $order    = 'DESC';
	protected $select   = '*';

	public $result_type = OBJECT;

	/**
	 * Converts type to SQL column types
	 *
	 * @param object $type
	 *
	 * @return string
	 */
	public static function graph_type_to_sql( $type ) {
		switch ( $type->name ) {
			case 'Int':
				return 'bigint(20)';
			case 'Boolean':
				return 'tinyint(1)';
			case 'Float':
				return 'float(2,1)';
			default:
				return 'longtext';
		}
	}

	/**
	 * Normalize errors.
	 *
	 * This method takes a WP_Error or a string and turns it into a normalized error
	 * for consistent error handling.
	 *
	 * @param string|WP_Error $error
	 * @return WP_Error|null
	 */
	public static function normalize_errors( $error ) {
		if ( empty( $error ) ) {
			return null;
		}
		// set default data values
		$data = [
			'message'    => '',
			'error_code' => 'mv-error',
			'data'       => [],
		];
		// WPDB errors are just strings, so we set the error message to the error
		if ( is_string( $error ) ) {
			$data['message'] = $error;
		}
		if ( is_wp_error( $error ) ) {
			$data            = array_merge( $data, $error->get_error_data() );
			$data['message'] = ! empty( $data['message'] ) ? $data['message'] : __( 'An error occurred with the request.', 'mediavine-create' );
			$status          = '';
			if ( is_int( $error->get_error_code() ) ) {
				$status = $error->get_error_code();
			} else {
				$data['error_code'] = $error->get_error_code();
			}
			if ( isset( $data['status'] ) ) {
				$status = $data['status'];
			}
			$data['data']['status'] = $status;
		}

		return new \WP_Error( $data['error_code'], $data['message'], $data );
	}

	/**
	 * Handle DB errors.
	 *
	 * If no argument is passed, this will check for a WPDB error. If that is empty, the function returns null.
	 *
	 * If there is an argument passed or there is a WPDB error, the function normalizes the error
	 * and returns a new WP_Error.
	 *
	 * If the error logging setting is enabled in Create Settings, this will also log the error to Sentry.
	 *
	 * @param mixed|WP_Error $error
	 * @return WP_Error|null
	 */
	public static function handle_error( $error = null, $return_self = false ) {
		global $wpdb;
		if ( ! is_wp_error( $error ) && empty( $wpdb->last_error ) ) {
			return $return_self ? $error : null;
		}
		if ( ! is_wp_error( $error ) ) {
			$error = $wpdb->last_error;
		}

		$error = self::normalize_errors( $error );

		return $error;
	}

	/**
	 * Create a new DB table
	 *
	 * @param array $table Array of table parameters
	 *
	 * @return void|WP_Error|null
	 */
	public static function create_table( $table ) {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		if ( array_key_exists( 'table_name', $table ) && array_key_exists( 'sql', $table ) ) {
			$custom_table_name       = $wpdb->prefix . $table['table_name'];
			$custom_table_sql        = $table['sql'];
			$create_custom_table_sql = "CREATE TABLE $custom_table_name ( $custom_table_sql ) $charset_collate;";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$new_table = dbDelta( $create_custom_table_sql );
			$error     = self::handle_error( $new_table );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			update_option( $table['table_name'] . '_db_version', $table['version'] );
		}
	}

	/**
	 * Converts the schema array to usable SQL statements
	 *
	 * @see MV_DBI::create_custom_tables()
	 *
	 * @param array $fields
	 *
	 * @return string
	 */
	public static function schema_to_sql( $fields ) {
		$sql        = "id bigint(20) NOT NULL AUTO_INCREMENT,
						created datetime DEFAULT NULL,
						modified datetime DEFAULT NULL, \n";
		$key_clause = '';
		foreach ( $fields as $key => $value ) {
			$default  = '';
			$col_type = 'longtext';
			if ( gettype( $value ) === 'string' ) {
				$col_type = $value;
			}

			if ( gettype( $value ) === 'array' ) {
				if ( isset( $value['default'] ) ) {
					if ( 'NULL' === $value['default'] ) {
						$default = ' DEFAULT NULL ';
					} else {
						$default = " NOT NULL DEFAULT {$value['default']} ";
					}
				}
				if ( isset( $value['type'] ) ) {
					$col_type = $value['type'];
				}
				if ( isset( $value['key'] ) ) {
					$key_clause .= "KEY {$key} ({$key}),  \n";
				}
				if ( isset( $value['unique'] ) ) {
					$key_clause .= "UNIQUE KEY {$key} ({$key}),  \n";
				}
			}

			$sql .= "{$key} {$col_type}{$default}, \n";
		}
		$sql .= $key_clause;
		$sql .= 'PRIMARY KEY  (id)';

		return $sql;
	}

	/**
	 * Builds a table based on data provided by the `mv_custom_schema` hook
	 *
	 * @uses my_custom_schema
	 * @param array $tables
	 */
	public static function create_schema_tables( $tables = [] ) {
		$tables = apply_filters( 'mv_custom_schema', $tables );

		foreach ( $tables as $table ) {
			$table['sql'] = self::schema_to_sql( $table['schema'] );
			self::create_table( $table );
		}
	}

	/**
	 * Builds custom tables
	 * @uses mv_custom_tables
	 * @usedby Image_Models::create_custom_tables()
	 * @usedby Notifications::create_custom_tables()
	 * @usedby Reviews_Models::reviews_custom_tables()
	 * @param array $custom_tables
	 */
	public static function create_custom_tables( $custom_tables = [] ) {
		$custom_tables = apply_filters( 'mv_custom_tables', $custom_tables );

		if ( is_array( $custom_tables ) ) {

			// nest in subarray if only a single array exists
			if ( ! wp_is_numeric_array( $custom_tables ) ) {
				$custom_tables = [ $custom_tables ];
			}

			foreach ( $custom_tables as $custom_table ) {
				self::create_table( $custom_table );
			}
		}
	}

	/**
	 * Fetch an Object of Models
	 *
	 * @param  array  $table_names   Optional array of just the tables desired (minus db prefix)
	 * @param  string $plugin_prefix Optional prefix for a select set of tables
	 * @return object|null Model Object, includes reference ORM Methods in Object
	 */
	public static function get_models( $table_names = [], $plugin_prefix = null ) {
		$models = new \stdClass();
		global $wpdb;

		if ( $plugin_prefix ) {
			$query     = $wpdb->prefix . $plugin_prefix . '%';
			$statement = $wpdb->prepare( 'SHOW TABLES LIKE %s', $query );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
			$results   = $wpdb->get_results( $statement );

			foreach ( $results as $index => $value ) {
				foreach ( $value as $table_name ) {
					$simple_name            = str_replace( $wpdb->prefix, '', $table_name );
					$models->{$simple_name} = new self( $simple_name );
				}
			}
			return $models;
		}

		if ( ! empty( $table_names ) ) {
			foreach ( $table_names as $table_name ) {
				$models->{$table_name} = new self( $table_name );
			}
			return $models;
		}

		return null;
	}

	/**
	 * Evaluates database upgrade requirement and if necessary executes
	 *
	 * @param string $plugin_name plugin unique slug for use in option
	 * @param string $db_version to check if the version is initialized
	 * @return boolean true if upgraded, false if not necessary.
	 */
	public static function upgrade_database_check( $plugin_name, $db_version ) {
		if ( get_option( $plugin_name . '_db_version' ) !== $db_version ) {
			self::create_schema_tables();
			self::create_custom_tables();
			update_option( $plugin_name . '_db_version', $db_version );
			return true;
		}
		return false;
	}

	public function __construct( $table_name ) {
		global $wpdb;

		$table_name       = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name );
		$this->table_name = $wpdb->prefix . $table_name;
		$this->short_name = $table_name;
	}

	/**
	 * Checks if data is a valid JSON string
	 *
	 * @param mixed $data Data to be checked
	 * @return boolean Is data valid JSON
	 */
	public function is_valid_json( $data ) {
		if ( function_exists( 'json_validate' ) ) {
			return json_validate( $data );
		}

		$decoded_data = json_decode( $data );
		return $decoded_data !== null || json_last_error() === JSON_ERROR_NONE;
	}

	/**
	 * Normalizes data to only return data that exists as cols within table
	 *
	 * @param array   $data Data to be normalized
	 * @param boolean $allow_null Are null values allowed
	 * @return array Normalized data
	 */
	public function normalize_data( $data, $allow_null = false ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$table_columns   = $wpdb->get_col( 'DESC ' . $this->table_name, 0 );
		$normalized_data = [];

		foreach ( $table_columns as $column_name ) {
			// Handle null values first
			if (
				$allow_null &&
				array_key_exists( $column_name, $data ) &&
				is_null( $data[ $column_name ] )
			) {
				$normalized_data[ $column_name ] = null;
				continue;
			}

			// Skip if data not set or is array
			if ( ! isset( $data[ $column_name ] ) || is_array( $data[ $column_name ] ) ) {
				continue;
			}

			$normalized_data[ $column_name ] = $data[ $column_name ];

			// Bounce if we are dealing with a number.
			if ( ! is_numeric( $data[ $column_name ] ) ) {
				continue;
			}

			// Keep valid JSON strings as is.
			if ( ! $this->is_valid_json( $data[ $column_name ] ) ) {
				continue;
			}

			// Everything else needs to be escaped properly.
			$normalized_data[ $column_name ] = esc_sql( $data[ $column_name ] );
		}

		return $normalized_data;
	}

	/**
	 * Returns the sprintf type for preparing sql statements
	 *
	 * @param mixed $var Variable to determine type
	 * @return string|false sprintf type
	 */
	public function get_sprintf( $var ) {
		$type = gettype( $var );

		switch ( $type ) {
			case 'string':
			case 'NULL':
				// Always %s for strings — do not promote numeric-looking strings to %d
				// (wpdb would cast and truncate e.g. "4.5" / "007").
				return '%s';
			case 'boolean':
			case 'integer':
				return '%d';
			case 'double':
				return '%f';
			default:
				return false;
		}
	}

	/**
	 * Checks if duplicate exists in table
	 *
	 * @param array $where_array Columns and values to check against
	 * @return object|false Database query result of duplicate entry
	 */
	public function has_duplicate( $where_array ) {
		$args = [
			'where' => $where_array,
		];

		$duplicate = $this->select_one( $args );

		if ( $duplicate ) {
			return $duplicate;
		}

		return false;
	}

	/**
	 * Insert a singular row
	 * Wrapper method for MV_DBI::insert()
	 *
	 * @see MV_DBI::insert()
	 * @param array $data
	 *
	 * @return object|WP_Error|null
	 */
	public function create( $data ) {
		return $this->insert( $data );
	}

	/**
	 * Runs before data is inserted. Fires `mv_dbi_before_create` filter
	 *
	 * @see MV_DBI::insert()
	 * @param array $data
	 *
	 * @return mixed|void
	 */
	public function before_create( $data ) {
		$data        = apply_filters( 'mv_dbi_before_create', $data, $this->table_name );
		$filter_name = 'mv_dbi_before_create_' . $this->short_name;
		$data        = apply_filters( $filter_name, $data );
		return $data;
	}

	/**
	 * Runs after data is inserted. Fires `mv_dbi_after_create` filter
	 *
	 * @see MV_DBI::insert()
	 * @param array $data
	 *
	 * @return mixed|void
	 */
	public function after_create( $data ) {
		$data        = apply_filters( 'mv_dbi_after_create', $data, $this->table_name );
		$filter_name = 'mv_dbi_after_create_' . $this->short_name;
		$data        = apply_filters( $filter_name, $data );
		return $data;
	}

	/**
	 * Insert many items into the database in a single transaction.
	 *
	 * @param array $data Array of row arrays containing data to insert. Should match the table schema
	 * @return int|null|\WP_Error inserted count
	 */
	public function create_many( array $data ) {
		global $wpdb;

		if ( empty( $data ) || ! count( $data ) ) {
			return null;
		}
		$date = gmdate( 'Y-m-d H:i:s' );

		// get the columns from the table
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$table_columns = $wpdb->get_col( 'DESC ' . $this->table_name, 0 );

		// Return with errors if found
		$handle_error = self::handle_error( $table_columns );
		if ( $handle_error ) {
			return $handle_error;
		}

		// Sentinel for columns omitted from the row: emit SQL DEFAULT so NOT NULL
		// columns with a schema default (and AUTO_INCREMENT) work under strict mode.
		// Explicit null / 'NULL' still become a real SQL NULL literal.
		$use_default = new \stdClass();
		$defaults    = [];
		foreach ( $table_columns as $column ) {
			$defaults[ $column ] = $use_default;
		}

		// generate the "(columns...) part of the insert query
		$insert_fields = '`' . implode( '`, `', $table_columns ) . '`';

		$value_formats = '';
		$values        = [];
		foreach ( $data as $item ) {
			// set timestamps
			$item['created']  = $date;
			$item['modified'] = $date;

			// Remove arrays from item.
			// This prevents issue with other plugins adding meta
			// to any custom post type that may be used in lists.
			$item = array_filter(
				$item, function( $value ) {
				return ! is_array( $value );
				}
			);

			// If any keys are not set on the item, add the default
			$item = array_merge( $defaults, $item );
			// Remove any keys that aren't in the table columns list
			$item = Arr::only( $item, $table_columns );

			// Build formats with NULL/DEFAULT literals so we never need the allow_null query filter.
			$formats = [];
			foreach ( $item as $value ) {
				if ( $use_default === $value ) {
					$formats[] = 'DEFAULT';
					continue;
				}
				if ( null === $value || 'NULL' === $value ) {
					$formats[] = 'NULL';
					continue;
				}
				$formats[] = $this->get_sprintf( $value );
				$values[]  = $value;
			}

			// generate the "(values...)" part of the insert query for this item
			$value_formats .= '(' . implode( ', ', $formats ) . '), ';
		}

		$insert_values = trim( $value_formats, ', ' );
		$statement     = "INSERT INTO {$this->table_name} ($insert_fields) VALUES $insert_values";

		// use the formats, Luke--escape SQL
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$prepared = empty( $values ) ? $statement : $wpdb->prepare( $statement, $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$result = $wpdb->query( $prepared );
		return self::handle_error( $result, true );
	}

	/**
	 * Inserts new row into custom table
	 *
	 * @param  array $data Data to be inserted
	 * @return object Database query result from insert
	 */
	public function insert( $data ) {
		global $wpdb;

		$date             = gmdate( 'Y-m-d H:i:s' );
		$data['created']  = $date;
		$data['modified'] = $date;

		$data = $this->before_create( $data );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$normalized_data = $this->normalize_data( $data );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$insert = $this->with_allow_null(
			function() use ( $wpdb, $normalized_data ) {
				return $wpdb->insert( $this->table_name, $normalized_data );
			}
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Return with errors if found, before retrieving record data
		$handle_error = self::handle_error( $insert );
		if ( $handle_error ) {
			return $handle_error;
		}

		if ( $insert ) {
			$new_record = $this->select_one_by_id( $wpdb->insert_id );
			$new_record = $this->after_create( $new_record );
			return self::handle_error( $new_record, true );
		}

		return self::handle_error( $insert, true );
	}

	/**
	 * Find record, or create record if it doesn't exist
	 *
	 * @param array $data
	 * @param array $where_array
	 *
	 * @return array|object|WP_Error|null
	 */
	public function find_or_create( $data, $where_array = null ) {
		if ( ! $where_array && isset( $data['slug'] ) ) {
			$where_array = [
				'slug' => $data['slug'],
			];
		}

		if ( ! is_array( $where_array ) ) {
			$where_array = [
				'slug' => $where_array,
			];
		}

		$existing = $this->has_duplicate( $where_array );

		if ( $existing ) {
			return $existing;
		}

		$data = $this->normalize_data( $data );
		$new  = $this->insert( $data );

		return self::handle_error( $new, true );
	}

	/**
	 * Check for a record and update it without modifying the date. Is a wrapper for MV_DBI::upsert()
	 *
	 * @param array $data Data to be updated
	 * @param array $where_array Determines what record(s) to update
	 * @param false $modify_date Should the modified_date column be updated? False (no), True (yes) Defaults to false
	 *
	 * @return WP_Error|null
	 */
	public function upsert_without_modified_date( $data, $where_array = null, $modify_date = false ) {
		return $this->upsert( $data, $where_array, $modify_date );
	}

	/**
	 * Check for a record and update it if exists, or create a new one if it doesn't.
	 *
	 * @param array $data
	 * @param array $where_array
	 * @param bool  $modify_date
	 *
	 * @return WP_Error|null
	 */
	public function upsert( $data, $where_array = null, $modify_date = true ) {
		if ( ! $where_array && isset( $data['slug'] ) ) {
			$where_array = [
				'slug' => $data['slug'],
			];
		}

		if ( ! $where_array && isset( $data['id'] ) ) {
			$where_array = [
				'id' => $data['id'],
			];
		}

		if ( ! is_array( $where_array ) ) {
			$where_array = [
				'slug' => $where_array,
			];
		}

		/**
		 * Filters the whether normalized data should allow null values
		 *
		 * @param bool $allow_normalized_null Should the normalized data allow null values
		 */
		$allow_normalized_null = apply_filters( 'mv_create_allow_normalized_null', false );

		$data     = $this->normalize_data( $data, $allow_normalized_null );
		$existing = $this->has_duplicate( $where_array );

		if ( $existing ) {
			$args    = [
				'id' => $existing->id,
			];
			$updated = $this->update( $data, $args, true, $modify_date );
			return self::handle_error( $updated, true );
		}

		$new = $this->insert( $data );
		return self::handle_error( $new, true );
	}

	/**
	 * Runs before data is updated. Fires the `mv_dbi_before_update` filter
	 *
	 * @see MV_DBI::update()
	 * @param array $data
	 *
	 * @return WP_Error|null
	 */
	public function before_update( $data ) {
		$data        = apply_filters( 'mv_dbi_before_update', $data, $this->table_name );
		$filter_name = 'mv_dbi_before_update_' . $this->short_name;
		$data        = apply_filters( $filter_name, $data );
		return self::handle_error( $data, true );
	}

	/**
	 * Runs after data is updated. Fires the `mv_dbi_after_update` filter
	 *
	 * @see MV_DBI::update()
	 * @param array $data
	 *
	 * @return WP_Error|null
	 */
	public function after_update( $data ) {
		$data        = apply_filters( 'mv_dbi_after_update', $data, $this->table_name );
		$filter_name = 'mv_dbi_after_update_' . $this->short_name;
		$data        = apply_filters( $filter_name, $data );
		return self::handle_error( $data, true );
	}

	/**
	 * Update a DB record without updating the modified date
	 *
	 * @param array              $data
	 * @param array|integer|null $args an array of args or an integer id of the item being updated
	 * @param boolean            $return_updated returns the updated record if true
	 * @return object|array|\WP_Error|null
	 */
	public function update_without_modified_date( $data, $args = null, $return_updated = true, $modify_date = false ) {
		return $this->update( $data, $args, $return_updated, $modify_date );
	}

	/**
	 * Update a DB record
	 *
	 * @param array              $data
	 * @param array|integer|null $args an array of args or an integer id of the item being updated
	 * @param boolean            $return_updated returns the updated record if true
	 * @param boolean            $modify_date whether or not to update the `modified` date column
	 * @return object|array|\WP_Error|null
	 */
	public function update( $data, $args = null, $return_updated = true, $modify_date = true ) {
		global $wpdb;

		if ( isset( $data['created'] ) ) {
			unset( $data['created'] );
		}

		if ( $modify_date ) {
			$date             = gmdate( 'Y-m-d H:i:s' );
			$data['modified'] = $date;
		}

		$data = $this->before_update( $data );

		// Return with errors if found
		$handle_error = self::handle_error( $data );
		if ( $handle_error ) {
			return $handle_error;
		}

		$defaults = apply_filters(
			"mv_db_update_defaults_{$this->table_name}", [
				'col'          => 'id',
				'key'          => null,
				'format'       => null,
				'where_format' => null,
			]
		);

		if ( ! $args ) {
			if ( ! empty( $data['id'] ) ) {
				$args       = [];
				$args['id'] = $data['id'];
			}
		}

		// If $args not array, set value as id
		if ( ! is_array( $args ) ) {
			$args = [ 'id' => $args ];
		}

		$args = array_merge( $defaults, $args );
		$key  = ( ! empty( $args['id'] ) && ! $args['key'] ) ? esc_sql( $args['id'] ) : esc_sql( $args['key'] );

		/**
		 * Filters the whether normalized data should allow null values
		 *
		 * @param bool $allow_normalized_null Should the normalized data allow null values
		 */
		$allow_normalized_null = apply_filters( 'mv_create_allow_normalized_null', false );

		$normalized_data = self::normalize_data( $data, $allow_normalized_null );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$update = $this->with_allow_null(
			function() use ( $wpdb, $normalized_data, $args, $key ) {
				return $wpdb->update( $this->table_name, $normalized_data, [ $args['col'] => $key ], $args['format'], $args['where_format'] );
			}
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Return with errors if found
		$handle_error = self::handle_error( $update );
		if ( $handle_error ) {
			return $handle_error;
		}

		if ( $return_updated ) {

			$args   = [
				'col' => $args['col'],
				'key' => $key,
			];
			$record = $this->select_one( $args );
			$record = $this->after_update( $record );
			return self::handle_error( $record, true );
		}

		return self::handle_error( $update, true );
	}

	/**
	 * Alias for $this->select_one
	 *
	 * @see MV_DBI::find_one()
	 * @param array $args array of options to be passed to select_one
	 * @return object|array|WP_Error      DB Object response
	 */
	public function find_one( $args ) {
		return $this->select_one( $args );
	}

	/**
	 * Alias for $this->select_one_by_id
	 *
	 * @see MV_DBI::find_one_by_id()
	 * @param int $id ID of object
	 *
	 * @return array|object|WP_Error|null
	 */
	public function find_one_by_id( $id ) {
		return $this->select_one_by_id( $id );
	}

	/**
	 * Select an item based on args.
	 *
	 * @param array $args
	 * @return object|array|WP_Error
	 */
	public function select_one( $args ) {
		global $wpdb;

		$defaults = apply_filters(
			"mv_db_select_one_defaults_{$this->table_name}", [
				'col' => 'id',
				'key' => null,
			]
		);

		// If $args not array, set key as id
		if ( ! is_array( $args ) ) {
			$args = [ 'key' => (int) $args ];
		}

		$args = array_merge( $defaults, $args );

		// Setup where array if it doesn't exist
		if ( empty( $args['where'] ) || ! is_array( $args['where'] ) ) {
			$args['where'] = [
				$args['col'] => $args['key'],
			];
		}

		$where_statement = '';
		$prepare_array   = [];

		$operator = ' AND ';

		if ( isset( $args['where']['or'] ) ) {
			$operator      = ' OR ';
			$args['where'] = $args['where']['or'];
		}

		foreach ( $args['where'] as $key => $value ) {
			if ( ! empty( $where_statement ) ) {
				$where_statement .= $operator;
			}
			$sprintf_identifier = $this->get_sprintf( $value );
			if ( ! $sprintf_identifier ) {
				continue;
			}
			$prepare_array[]  = $value;
			$where_statement .= $key . ' = ' . $sprintf_identifier;
		}

		$build_sql          = "SELECT * FROM `$this->table_name` WHERE " . $where_statement;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$prepared_statement = $wpdb->prepare( $build_sql, $prepare_array );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$select = $wpdb->get_results( $prepared_statement, $this->result_type );

		// Return with errors if found
		$handle_error = self::handle_error( $select );
		if ( $handle_error ) {
			return $handle_error;
		}

		$select = $this->after_find( $select );
		// Return with errors if found
		$handle_error = self::handle_error( $select );
		if ( $handle_error ) {
			return $handle_error;
		}

		// return without array if array
		if ( ! empty( $select ) && wp_is_numeric_array( $select ) ) {
			return self::handle_error( $select[0], true );
		}

		return self::handle_error( $select, true );
	}

	/**
	 * Alias for $this->select_one
	 *
	 * @see MV_DBI::select_one()
	 * @param int $id
	 *
	 * @return array|object|WP_Error|null
	 */
	public function select_one_by_id( $id ) {
		return $this->select_one( (int) $id );
	}

	/**
	 * Selects data according to object_id
	 *
	 * @see MV_DBI::select_one()
	 * @param int $object_id
	 *
	 * @return array|object|WP_Error|null
	 */
	public function select_one_by_object_id( $object_id ) {
		$args = [
			'col' => 'object_id',
			'key' => (int) $object_id,
		];
		return $this->select_one( $args );
	}

	/**
	 * Retrieve an entire SQL result set from the database
	 *
	 * Prepare queries that need a prepared statement
	 *
	 * @param array $args Array containing basic SQL arguments or a prepared SQL statement
	 * @param array $search_params Array containing a list of column names that should be searched with LIKE/OR queries to support text search.
	 * @return object Database query results
	 */
	public function find( $args = [], $search_params = null ) {
		global $wpdb;

		// Shared model singletons retain mutable builder state; always restore
		// defaults after a query so one caller's limit/order cannot leak into the next.
		try {
			$results = [];

			// We no longer allow preprepared statements due to security concerns.
			if ( isset( $args['prepared_statement'] ) ) {
				// There is an exception for specific tables used by our importers.
				// Convert their prepared_statements to new SQL preparation.
				$allowed_tables        = [
					'posts', // Purr Recipe Cards, Simple Recipes Pro, WP Tasty
					'amd_zlrecipe_recipes', // Zip Recipes, ZipList Recipes
				];
				$uses_importers_tables = in_array( $this->short_name, $allowed_tables );
				if ( $uses_importers_tables ) {
					$args['sql']    = $args['prepared_statement'];
					$args['params'] = [];
				}

				if ( ! $uses_importers_tables ) {
					$error = new WP_Error( 'preprepared-not-allowed', 'Preprepared SQL queries are no longer allowed.' );
					return self::handle_error( $error );
				}
			}

			if ( isset( $args['sql'] ) && isset( $args['params'] ) ) {
				// Params must be an array.
				if ( ! is_array( $args['params'] ) ) {
					$error = new WP_Error( 'missing-prepared-params', 'SQL params for preparation are required.' );
					return self::handle_error( $error );
				}

				// Error anything that doesn't start with SELECT.
				$sql_command = strtoupper(trim($args['sql']));
				if ( strpos( $sql_command, 'SELECT' ) !== 0 ) {
					$error = new WP_Error( 'no-select-sql', 'SQL query must begin with SELECT.' );
					return self::handle_error( $error );
				}

				// We don't want to give access to the options or users tables.
				$has_safe_tables = true;
				$excluded_tables = [
					$wpdb->prefix . 'options',
					$wpdb->prefix . 'users',
					$wpdb->prefix . 'usermeta',
				];
				foreach ( $excluded_tables as $table ) {
					// None of our prepared_statements contained `users` or `options` so this is safe.
					if ( strpos( $args['sql'], $table ) !== false ) {
						$has_safe_tables = false;
					}
				}

				// Finally, let just be extra save and make sure the short_name exists within the query.
				if ( strpos( $args['sql'], $this->short_name ) === false ) {
					$has_safe_tables = false;
				}

				if ( ! $has_safe_tables ) {
					$error = new WP_Error( 'disallowed-table', 'Access to specified table is not allowed.' );
					return self::handle_error( $error );
				}

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
				$prepared = $wpdb->prepare($args['sql'], $args['params']);
				$results  = $wpdb->get_results( $prepared );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				return self::handle_error( $results, true );
			}

			$default_statement = "SELECT * FROM {$this->table_name} ORDER BY {$this->order_by} {$this->order} LIMIT {$this->limit} OFFSET {$this->offset}";

			if ( empty( $args ) && ! $search_params ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
				$results = $wpdb->get_results( $default_statement );
				return self::handle_error( $results, true );
			}

			if ( isset( $args['limit'] ) ) {
				$this->set_limit( $args['limit'] );
			}

			if ( isset( $args['offset'] ) ) {
				$this->set_offset( $args['offset'] );
			}

			if ( isset( $args['order_by'] ) ) {
				// order_by_trusted is only ever set to a server-generated expression
				// (never request input) — see Creations_API rating sort.
				$this->set_order_by( $args['order_by'], ! empty( $args['order_by_trusted'] ) );
			}

			if ( isset( $args['order'] ) ) {
				$this->set_order( $args['order'] );
			}

			if ( isset( $args['select'] ) ) {
				$this->set_select( $args['select'] );
			}

			$build_sql = "SELECT $this->select FROM `$this->table_name`";
			$order_sql = $this->paginate_and_order();

			if ( $this->has_where_conditions( $args, $search_params ) ) {
				list( $where_clause, $prepare_array ) = $this->build_where_clause( $args, $search_params );

				$build_sql          = $build_sql . ' WHERE ' . $where_clause . $order_sql;
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
				$prepared_statement = $wpdb->prepare( $build_sql, $prepare_array );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
				$results = $wpdb->get_results( $prepared_statement );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
				$results = $wpdb->get_results( $build_sql . $order_sql );
			}

			$results = $this->after_find( $results );

			return self::handle_error( $results, true );
		} finally {
			$this->reset_query_state();
		}
	}

	/**
	 * Whether any of the given args/search_params would produce a WHERE clause.
	 *
	 * @param array      $args
	 * @param array|null $search_params
	 * @return bool
	 */
	private function has_where_conditions( $args, $search_params ) {
		return $search_params || ! empty( $args['where'] ) && is_array( $args['where'] ) || ! empty( $args['conditions'] );
	}

	/**
	 * Builds a WHERE clause and its bound parameters from where/conditions/search args.
	 *
	 * Shared by find() and get_count() so both scan identical rows for the same args.
	 *
	 * @param array      $args          May contain 'where' (assoc array of column => value,
	 *                                  or column => ['IN'|'NOT IN'|comparison operator => value])
	 *                                  and/or 'conditions' (array of [column, operator, value] triples).
	 * @param array|null $search_params Column => term map searched with LIKE/IN, OR'd together.
	 * @return array{0: string, 1: array} [ $where_clause, $prepare_array ]. $where_clause is ''
	 *                                     when nothing in $args/$search_params produced a condition.
	 */
	private function build_where_clause( $args, $search_params ) {
		// Array of params that should be handled with LIKE, not =
		// This probably won't ever change, since would only be used by search, practically.
		$like_params = [
			'published',
			'title',
			'post_title',
			'associated_posts',
		];

		$where_statement  = '';
		$search_statement = '';
		$prepare_array    = [];

		if ( ! empty( $args['where'] ) ) {
			foreach ( $args['where'] as $key => $value ) {
				if ( ! empty( $where_statement ) ) {
					$where_statement .= ' AND ';
				}

				if ( is_array( $value ) ) {
					$statement = strtoupper( key( $value ) );
					// Should be IN or NOT IN
					if ( false !== strpos( $statement, 'IN' ) ) {
						$values        = current( $value );
						$prepare_array = $values;
						$fill          = [];

						foreach ( $prepare_array as $item ) {
							$sprintf_identifier = $this->get_sprintf( $item );
							if ( ! $sprintf_identifier ) {
								$fill[] = "'%s'";
								continue;
							}

							$fill[] = $sprintf_identifier;
						}

						$in               = implode( ', ', $fill );
						$where_statement .= $key . ' ' . $statement . ' (' . $in . ')';
					}

					// Comparison operators: ['column' => ['>=' => 5]]
					$allowed_operators = [ '>=', '<=', '>', '<', '!=' ];
					$operator_key      = key( $value );
					if ( in_array( $operator_key, $allowed_operators, true ) ) {
						$comp_value         = current( $value );
						$sprintf_identifier = $this->get_sprintf( $comp_value );
						if ( $sprintf_identifier ) {
							$prepare_array[]  = $comp_value;
							$where_statement .= $key . ' ' . $operator_key . ' ' . $sprintf_identifier;
						}
					}

					continue;
				}

				$sprintf_identifier = $this->get_sprintf( $value );
				if ( ! $sprintf_identifier ) {
					continue;
				}
				$prepare_array[] = $value;
				if ( in_array( $key, $like_params, true ) ) {
					$where_statement .= $key . " LIKE '%%%s%%'";
				} else {
					$where_statement .= $key . ' = ' . $sprintf_identifier;
				}
			}
		}

		// Additional conditions: [['column', '>=', value], ['column', '<=', value]]
		// Allows multiple conditions on the same column (unlike the where array).
		if ( ! empty( $args['conditions'] ) && is_array( $args['conditions'] ) ) {
			$allowed_operators = [ '=', '!=', '>=', '<=', '>', '<' ];
			foreach ( $args['conditions'] as $condition ) {
				if ( ! is_array( $condition ) || count( $condition ) < 3 ) {
					continue;
				}
				list( $col, $op, $val ) = $condition;
				if ( ! in_array( $op, $allowed_operators, true ) ) {
					continue;
				}
				$sprintf_identifier = $this->get_sprintf( $val );
				if ( ! $sprintf_identifier ) {
					continue;
				}
				if ( ! empty( $where_statement ) ) {
					$where_statement .= ' AND ';
				}
				$prepare_array[]  = $val;
				$where_statement .= $col . ' ' . $op . ' ' . $sprintf_identifier;
			}
		}

		if ( $search_params ) {
			foreach ( $search_params as $key => $value ) {
				// Array value means "column IN (...)" — OR'd into the search group.
				$is_in_clause = is_array( $value );
				if ( $is_in_clause && empty( $value ) ) {
					continue;
				}

				if ( strlen( $search_statement ) === 0 ) {
					if ( strlen( $where_statement ) ) {
						$search_statement .= ' AND ';
					}
					$search_statement .= '(';
				} else {
					$search_statement .= ' OR';
				}

				if ( $is_in_clause ) {
					$fill = [];
					foreach ( $value as $item ) {
						$sprintf_identifier = $this->get_sprintf( $item );
						$fill[]             = $sprintf_identifier ? $sprintf_identifier : "'%s'";
						$prepare_array[]    = $item;
					}
					$search_statement .= " $key IN (" . implode( ', ', $fill ) . ') ';
				} else {
					$search_statement .= " $key LIKE '%%%s%%' ";
					$prepare_array[]   = $value;
				}
			}
			if ( strlen( $search_statement ) > 0 ) {
				$search_statement .= ')';
			}
		}

		return [ $where_statement . $search_statement, $prepare_array ];
	}

	/**
	 * Lifecycle Hook that provides each item in a find response
	 * @param  array $data Returned DB data array
	 * @return array|\WP_Error $data Returned DB data array after filters on each item
	 */
	public function after_find( $data ) {
		foreach ( $data as &$item ) {
			$item        = apply_filters( 'mv_dbi_after_find', $item, $this->table_name );
			$filter_name = 'mv_dbi_after_find_' . $this->short_name;
			$item        = apply_filters( $filter_name, $item );
		}

		return self::handle_error( $data, true );
	}

	/**
	 * Add a basic where clause to the query.
	 *
	 * @param  string|array $column
	 * @param  mixed        $operator
	 * @param  mixed        $value
	 * @param  string       $after any SQL to insert after (LIMIT, ORDER, etc.)
	 * @return array|\WP_Error
	 */
	/**
	 * Validates a SQL column identifier against a strict allowlist.
	 *
	 * Accepts a bare identifier or a single `table.column` qualifier, each part
	 * optionally backtick-quoted. Returns the identifier on success or false if
	 * it contains anything else. SQL identifiers cannot be bound via
	 * $wpdb->prepare() on all supported versions, so they must be allowlisted.
	 *
	 * @param string $identifier
	 * @return string|false
	 */
	public static function sanitize_sql_identifier( $identifier ) {
		if ( ! is_string( $identifier ) ) {
			return false;
		}
		$identifier = trim( $identifier );
		if ( preg_match( '/^`?[a-zA-Z_][a-zA-Z0-9_]*`?(\.`?[a-zA-Z_][a-zA-Z0-9_]*`?)?$/', $identifier ) ) {
			return $identifier;
		}
		return false;
	}

	/**
	 * Validates a SQL comparison operator against a fixed allowlist.
	 *
	 * @param string $operator
	 * @return string|false Uppercased operator, or false if not allowed.
	 */
	public static function sanitize_sql_operator( $operator ) {
		$allowed  = [ '=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT' ];
		$operator = strtoupper( trim( (string) $operator ) );
		return in_array( $operator, $allowed, true ) ? $operator : false;
	}

	public function where( $column, $operator = '=', $value = null, $after = '' ) {
		// if $column is an array, we assume we're trying to pass in multiple qualifications at the same time
		// and can defer to `where_many`
		if ( is_array( $column ) ) {
			return $this->where_many( $column );
		}

		try {
			// if $column contains a space, we can assume it's a full `where` clause
			// and insert it into the statement. This is a documented advanced feature
			// for caller-built (trusted) clauses only; it is never fed request input
			// (no such caller exists in the plugin).
			if ( is_string( $column ) && Str::contains( $column, ' ' ) ) {
				$statement = "SELECT {$this->select} FROM {$this->table_name} WHERE {$column} ORDER BY {$this->order_by} {$this->order} LIMIT {$this->offset}, {$this->limit}";
				$statement = trim( $statement );
				return $this->db()->get_results( $statement );
			}

			// Allowlist the identifier and operator (which cannot be bound), then
			// bind the value through $wpdb->prepare().
			$safe_column   = self::sanitize_sql_identifier( $column );
			$safe_operator = self::sanitize_sql_operator( $operator );
			$value_type    = $this->get_sprintf( $value );
			if ( is_bool( $value ) ) {
				$value = (int) $value;
			}
			if ( false === $safe_column || false === $safe_operator || false === $value_type ) {
				return new WP_Error( 'mv-invalid-where', 'Invalid column, operator, or value supplied to where().', compact( 'column', 'operator', 'value' ) );
			}

			$where = $this->db()->prepare( "{$safe_column} {$safe_operator} {$value_type}", $value );

			$statement = "SELECT * FROM {$this->table_name} WHERE {$where} {$after} ORDER BY {$this->order_by} {$this->order} LIMIT {$this->offset}, {$this->limit}";
			$statement = trim( $statement );
			return $this->db()->get_results( $statement );
		} finally {
			$this->reset_query_state();
		}
	}

	/**
	 * Get results with several conditionals.
	 *
	 * If a single set of conditionals, the values come in the format [ $column, $operator, $value, $after ] (see `where` for more details)
	 * If an array of conditional sets, the values should look like [ $column, $operator, $value, $boolean ]
	 *  - $boolean is a SQL boolean string (`AND`, `OR`, etc.)
	 *  - if the last conditional set is actually a string, this is appended to the SQL statement,
	 *    allowing for LIMIT, and ORDER BY statements to be added
	 *
	 * Examples:
	 * `$dbi_model->where_many(['id', '=', 183]);` => `WHERE id = '183'`
	 * `$dbi_model->where_many(['title', 'LIKE', '%test%']);` => `WHERE title LIKE '%test%'`
	 * `$dbi_model->where_many([
	 *    ['title', 'LIKE', '%test%'],
	 *    ['created', '>', $last_year, 'or'],
	 *    ['created', '<=', $today],
	 * ]);` => `WHERE title LIKE '%test%' AND (created > '2020-11-29 21:52:17' OR created <= '2021-11-29 21:52:17')`
	 *
	 * @param array $array
	 * @return array|\WP_Error array of results or error on failure
	 */
	public function where_many( array $array ) {
		if ( empty( $array ) ) {
			return new WP_Error( 'mv-no-argument', 'No argument was supplied', $array );
		}
		// set the default where values in case we need to infer them
		// $column, $operator, $value, $after phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		$default_where_values = [ '', '=', null, '' ];

		// if the value of `$array[0]` is not an array, then we assume it is intended
		// to be at least some of the parameters of the `where` method.
		// We can merge the values with the default values and send the parameters to the `where` method.
		if ( ! is_array( $array[0] ) ) {
			// extract the column, operator, value, and after
			list( $column, $operator, $value, $after ) = array_merge( $array, $default_where_values );
			return $this->where( $column, $operator, $value, $after );
		}

		try {
			$wheres = [];
			$after  = '';
			foreach ( $array as $where_array ) {
				// if one of the values is a string, we assume it's meant to be `$after` SQL
				if ( ! is_array( $where_array ) ) {
					$after = $where_array;
					continue;
				}
				// if the array has two items, we assume an `AND column = value` situation
				// where index 0 is the column and index 1 is the value
				if ( 2 === count( $where_array ) ) {
					list( $column, $value ) = $where_array;
					$wheres[]               = [ $column, '=', $value, 'and' ];
					continue;
				}
				// if the count is 3, we assume the user wants to change the operator
				// `$column = $where_array[0]` phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// `$operator = $where_array[1]` phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// `$value = $where_array[2]` phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				if ( 3 === count( $where_array ) ) {
					list( $column, $operator, $value ) = $where_array;
					$wheres[]                          = [ $column, $operator, $value, 'and' ];
					continue;
				}
				// if the count is 4, we assume the user wants to change the operator and boolean
				// `$column = $where_array[0]` phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// `$operator = $where_array[1]` phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// `$value = $where_array[2]` phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// `$boolean = $where_array[3]` phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				if ( 4 === count( $where_array ) ) {
					list( $column, $operator, $value, $boolean ) = $where_array;
					$wheres[]                                    = [ $column, $operator, $value, $boolean ];
					continue;
				}
			}
			if ( empty( $wheres ) ) {
				return new WP_Error( 'mv-something-went-wrong', 'The where array could not be built successfully', compact( 'array', 'wheres' ) );
			}

			$where   = '';
			$boolean = 'AND';
			// now that we have all the `$wheres`, we iterate over them and build a `WHERE` statement
			foreach ( $wheres as $where_array ) {
				$close_parentheses                           = 'OR' === $boolean ? ')' : '';
				list( $column, $operator, $value, $boolean ) = $where_array;

				$boolean          = strtoupper( $boolean ); // normalize `and` and `or` and to `AND` and `OR`
				$open_parentheses = 'OR' === $boolean ? '(' : '';

				// Allowlist the identifier and operator (which cannot be bound), then
				// bind the value(s) through $wpdb->prepare().
				$safe_column   = self::sanitize_sql_identifier( $column );
				$safe_operator = self::sanitize_sql_operator( $operator );
				if ( false === $safe_column || false === $safe_operator ) {
					return new WP_Error( 'mv-invalid-where', 'Invalid column or operator supplied to where_many().', compact( 'column', 'operator' ) );
				}

				if ( 'IN' === $safe_operator || 'NOT IN' === $safe_operator ) {
					$values       = is_array( $value ) ? array_values( $value ) : [ $value ];
					$placeholders = implode( ', ', array_map( [ $this, 'get_sprintf' ], $values ) );
					$condition    = $this->db()->prepare( "{$safe_column} {$safe_operator} ({$placeholders})", $values );
				} elseif ( null === $value || 'NULL' === $value ) {
					// Emit a real SQL NULL literal — avoids the allow_null query-filter hack.
					$condition = "{$safe_column} {$safe_operator} NULL";
				} else {
					$value_type = $this->get_sprintf( $value );
					if ( is_bool( $value ) ) {
						$value = (int) $value;
					}
					$condition = $this->db()->prepare( "{$safe_column} {$safe_operator} {$value_type}", $value );
				}

				// format the `WHERE` strings; identifiers/operators are allowlisted and
				// values are bound via prepare() above.
				$where .= sprintf(
					'%s%s%s %s ',
					$open_parentheses,
					$condition,
					$close_parentheses,
					$boolean
				);
				// if the item is the last `where`, we don't want a boolean at the end
				if ( end( $wheres ) === $where_array ) {
					$where = trim( $where, " $boolean " );
				}
			}

			if ( empty( $where ) ) {
				return new WP_Error( 'mv-something-went-wrong', 'Something went wrong while building the where statement', compact( 'array', 'wheres', 'where' ) );
			}

			$statement = "SELECT {$this->select} FROM {$this->table_name} WHERE $where $after ORDER BY {$this->order_by} {$this->order} LIMIT {$this->offset}, {$this->limit}";
			$statement = trim( $statement );
			return $this->db()->get_results( $statement );
		} finally {
			$this->reset_query_state();
		}
	}

	/**
	 * Triggers before the object is deleted.
	 * Applies the `mv_dbi_before_delete` filter
	 * Triggers error handler if the object is an error
	 *
	 * @param array $data Data object being deleted
	 *
	 * @return WP_Error|null
	 */
	public function before_delete( $data ) {
		apply_filters( 'mv_dbi_before_delete', $data, $this->table_name );
		$filter_name = 'mv_dbi_before_delete_' . $this->short_name;
		$data        = apply_filters( $filter_name, $data );
		return self::handle_error( $data, true );
	}

	/**
	 * Triggers after the object is deleted.
	 * Applies the `mv_dbi_after_delete` filter
	 * Triggers error handler if the object is an error
	 *
	 * @param array $data Data object being deleted
	 *
	 * @return WP_Error|null
	 */
	public function after_delete( $data ) {
		apply_filters( 'mv_dbi_after_delete', $data, $this->table_name );
		$filter_name = 'mv_dbi_after_delete_' . $this->short_name;
		$data        = apply_filters( $filter_name, $data );
		return self::handle_error( $data, true );
	}

	/**
	 * Deletes a row or rows depending on provided parameters. The parameters used should be the same as `where` or `where_many` methods
	 *
	 * @see MV_DBI::where_many()
	 * @see MV_DBI::where()
	 *
	 * @param mixed $args Arguments to determine what rows to delete
	 *
	 * @return array|object|true|false|WP_Error Deleted row(s) after lifecycle hooks, true when
	 *                                          rows were deleted but no prefetched item is
	 *                                          available, or false when nothing matched.
	 */
	public function delete( $args ) {
		global $wpdb;

		$defaults = apply_filters(
			"mv_db_select_one_defaults_{$this->table_name}", [
				'col' => 'id',
				'key' => null,
			]
		);

		$items_to_delete = [];

		// Scalar id: pass the id itself to find_one_by_id (not a wrapping array —
		// (int) of a non-empty array is 1, which fetched the wrong row for hooks).
		if ( ! is_array( $args ) ) {
			$id   = $args;
			$args = [ 'key' => $id ];
			$item = $this->find_one_by_id( $id );
			if ( $item ) {
				$items_to_delete[] = $item;
			}
		}

		$args = array_merge( $defaults, $args );

		$where_array = [];
		if ( ! empty( $args['where'] ) && is_array( $args['where'] ) ) {
			$where_array = $args['where'];
			if ( empty( $items_to_delete ) ) {
				$found = $this->find(
					[
						'where'  => $where_array,
						'limit'  => 999999,
						'offset' => 0,
					]
				);
				if ( is_array( $found ) ) {
					$items_to_delete = $found;
				}
			}
		} else {
			$where_array[ $args['col'] ] = $args['key'];
			// Array-arg col/key path: also fetch the row so lifecycle hooks fire.
			if ( empty( $items_to_delete ) && null !== $args['key'] ) {
				$item = $this->find_one(
					[
						'col' => $args['col'],
						'key' => $args['key'],
					]
				);
				if ( $item ) {
					$items_to_delete[] = $item;
				}
			}
		}

		foreach ( $items_to_delete as $item ) {
			$this->before_delete( $item );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
		$deleted = $wpdb->delete( $this->table_name, $where_array );

		if ( ! $deleted ) {
			return false;
		}

		$after = [];
		foreach ( $items_to_delete as $item ) {
			$after[] = $this->after_delete( $item );
		}

		// Preserve the historical single-row return shape used by REST destroy handlers.
		if ( 1 === count( $after ) ) {
			return self::handle_error( $after[0], true );
		}

		// Multi-row success must be truthy (unlike the previous null return, which
		// was indistinguishable from failure under loose boolean checks).
		if ( empty( $after ) ) {
			return true;
		}

		return self::handle_error( $after, true );
	}


	/**
	 * Deletes an object by its id
	 * @param int $object_id
	 *
	 * @return WP_Error|null
	 */
	public function delete_by_id( $object_id ) {
		$delete = $this->delete( $object_id );

		return self::handle_error( $delete, true );
	}

	/**
	 * Returns the total number of results of a db query, ignoring limits.
	 * @param  array $args Array of arguments
	 * @return integer|\WP_Error Number of results
	 */
	public function get_count( $args, $search_params = null ) {
		global $wpdb;

		if ( $this->has_where_conditions( $args, $search_params ) ) {
			list( $where_clause, $prepare_array ) = $this->build_where_clause( $args, $search_params );

			$build_sql          = "SELECT COUNT(*) FROM `$this->table_name` WHERE " . $where_clause;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
			$prepared_statement = $wpdb->prepare( $build_sql, $prepare_array );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
			$count = $wpdb->get_var( $prepared_statement );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ORM/importer false positive: table/identifiers sanitized or allowlisted; values bound via prepare()/wpdb helpers
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `$this->table_name`" );
		}

		// Return with errors if found
		$handle_error = self::handle_error( $count );
		if ( $handle_error ) {
			return $handle_error;
		}

		return (int) $count;
	}

	/**
	 * Returns wpdb object
	 *
	 * @return \QM_DB|\wpdb
	 */
	private function db() {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Appears to correct how NULL is defined in SQL queries
	 * Is run by the query filter
	 *
	 * @param string $query
	 *
	 * @see \wpdb::query()
	 *
	 * @return array|string|string[]
	 */
	public function allow_null( $query ) {
		return str_ireplace( "'NULL'", 'NULL', $query );
	}

	/**
	 * Run a callback with the allow_null query filter attached, always removing it afterward.
	 *
	 * $wpdb->insert() / update() quote the string 'NULL'; this filter rewrites those
	 * to SQL NULL. The filter must never outlive the guarded query — a leak rewrites
	 * every subsequent query in the request and can silently corrupt content that
	 * legitimately contains the quoted word 'NULL'.
	 *
	 * @param callable $callback
	 * @return mixed
	 */
	private function with_allow_null( callable $callback ) {
		add_filter( 'query', [ $this, 'allow_null' ] );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', [ $this, 'allow_null' ] );
		}
	}

	/**
	 * Restores builder properties to their defaults.
	 *
	 * Shared model singletons mutate limit/offset/order_by/order/select across
	 * calls; find()/where()/where_many() invoke this after each query so state
	 * cannot leak between callers. Setters applied immediately before a query
	 * still take effect for that call.
	 *
	 * @return MV_DBI
	 */
	public function reset_query_state() {
		$this->limit    = 50;
		$this->offset   = 0;
		$this->order_by = 'created';
		$this->order    = 'DESC';
		$this->select   = '*';
		return $this;
	}

	/**
	 * Overrides the default limit of 50
	 *
	 * @param integer|null $limit
	 *
	 * @return MV_DBI
	 */
	public function set_limit( $limit = null ) {
		$this->limit = ! is_null( $limit ) ? intval( $limit ) : $this->offset;
		return $this;
	}

	/**
	 * Overrides the default offset value of 0
	 * @param integer|null ` $offset` New offset value
	 * @return MV_DBI
	 */
	public function set_offset( $offset = null ) {
		$this->offset = ! is_null( $offset ) ? intval( $offset ) : $this->offset;
		return $this;
	}

	/**
	 * Allowlist a SQL ORDER direction.
	 *
	 * Only `ASC` and `DESC` are ever valid. Anything else — including injection
	 * attempts such as `ASC LIMIT 1,1-- -` — collapses to the safe default
	 * `DESC`. ORDER directions are SQL keywords, not values, so they cannot be
	 * protected by `$wpdb->prepare()` and must be allowlisted here.
	 *
	 * @param string|null $order
	 * @return string 'ASC' or 'DESC'
	 */
	public static function sanitize_sql_order( $order = null ) {
		return ( is_string( $order ) && 'ASC' === strtoupper( trim( $order ) ) ) ? 'ASC' : 'DESC';
	}

	/**
	 * Allowlist a SQL ORDER BY column expression.
	 *
	 * Accepts a comma-separated list of bare or backtick-quoted column
	 * identifiers, each optionally table-qualified (`table.column`) and
	 * optionally suffixed with `ASC`/`DESC`. Any part containing SQL
	 * metacharacters — parentheses, quotes, comments, sub-selects, `SLEEP()`,
	 * etc. — fails the allowlist and is dropped; if nothing validates the
	 * `$fallback` column is returned. ORDER BY targets are identifiers, not
	 * values, so `$wpdb->prepare()` cannot protect them and they must be
	 * allowlisted here.
	 *
	 * @param string|null $order_by
	 * @param string      $fallback Safe column name returned when nothing validates.
	 * @return string
	 */
	public static function sanitize_sql_order_by( $order_by = null, $fallback = 'created' ) {
		if ( ! is_string( $order_by ) || '' === trim( $order_by ) ) {
			return $fallback;
		}

		$sanitized = [];
		foreach ( explode( ',', $order_by ) as $part ) {
			$part = trim( $part );
			// Optional backtick-quoted identifier, optional `table.column`, optional ASC/DESC.
			if ( preg_match( '/^`?[a-zA-Z_][a-zA-Z0-9_]*`?(\.`?[a-zA-Z_][a-zA-Z0-9_]*`?)?(\s+(ASC|DESC))?$/i', $part ) ) {
				$sanitized[] = $part;
			}
		}

		return empty( $sanitized ) ? $fallback : implode( ', ', $sanitized );
	}

	/**
	 * Overrides the default ORDER BY column
	 *
	 * @param string|null $order_by Defaults to `created` column
	 * @param bool        $trusted  When true, skip allowlisting. Reserved for
	 *                              server-generated expressions (e.g. the rating
	 *                              CASE sort) that are never derived from request
	 *                              input. NEVER pass user input with $trusted=true.
	 *
	 * @return MV_DBI
	 */
	public function set_order_by( $order_by = null, $trusted = false ) {
		if ( is_null( $order_by ) ) {
			return $this;
		}
		if ( ! $trusted ) {
			$order_by = self::sanitize_sql_order_by( $order_by, $this->order_by );
		}
		$this->order_by = $order_by ?: $this->order_by;
		return $this;
	}

	/**
	 * Overrides the default ORDER direction
	 *
	 * @param string|null $order
	 *
	 * @return MV_DBI
	 */
	public function set_order( $order = null ) {
		if ( null === $order || '' === $order ) {
			return $this;
		}
		$this->order = self::sanitize_sql_order( $order );
		return $this;
	}

	/**
	 * Overrides the default columns to select in a DB query. Default is `*`.
	 * This method is actually called by the `find` method if the `select` parameter contains columns.
	 * Otherwise, the query will default to `*`
	 *
	 * @param array|string|null $select Columns to select.
	 *
	 * @return MV_DBI
	 */
	public function set_select( $select = null ) {
		$select_string = '';
		if ( is_array( $select ) ) {
			foreach ( $select as $item ) {
				if ( is_array( $item ) ) {
				$select_string .= ', ' . $item[0] . ' AS ' . $item[1];
					continue;
				}
				$select_string .= ', ' . $item;
			}
			$select = trim( $select_string, ', ' );
		}
		$this->select = $select ?: $this->select;
		return $this;
	}

	/**
	 * Allows for pagination in queries. This method isn't used anywhere else, but is called by paginate_and_order()
	 *
	 * @see MV_DBI::paginate_and_order()
	 *
	 * @return string
	 */
	public function paginate() {
		return " LIMIT $this->offset, $this->limit";
	}

	/**
	 * Adds order/by statements for pagination
	 *
	 * @see MV_DBI::paginate_and_order()
	 *
	 * @return string
	 */
	public function get_order_sql() {
		return " ORDER BY $this->order_by $this->order";
	}

	/**
	 * Adds pagination to queries. Used by find() method
	 *
	 * @see MV_DBI::find()
	 *
	 * @return string
	 */
	public function paginate_and_order() {
		return $this->get_order_sql() . ' ' . $this->paginate();
	}
}
