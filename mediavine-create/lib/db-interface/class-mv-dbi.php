<?php
namespace Mediavine;

use WP_Error;
use Mediavine\WordPress\Support\Arr;
use Mediavine\WordPress\Support\Str;

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
			$data['message'] = ! empty( $data['message'] ) ? $data['message'] : __( 'An error occurred with the request.', 'mediavine' );
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
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
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
			// SECURITY CHECKED: This query is properly prepared.
			$query     = $wpdb->prefix . $plugin_prefix . '%';
			$statement = $wpdb->prepare( 'SHOW TABLES LIKE %s', $query );
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
	 * @param array $data Data to be normalized
	 * @param boolean $allow_null Are null values allowed
	 * @return array Normalized data
	 */
	public function normalize_data( $data, $allow_null = false ) {
		global $wpdb;

		// SECURITY CHECKED: Everything in this query is already sanitized.
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
	 * @todo Refactor get_sprintf and get_wp_sprintf_type. Both methods are doing the same thing, but get_wp_sprintf is only used in one place
	 *
	 * @param mixed $var Variable to determine type
	 * @return string|false sprintf type
	 */
	public function get_sprintf( $var ) {
		$type = gettype( $var );

		switch ( $type ) {
			case 'string':
				if ( is_numeric( $type ) ) {
					return '%d';
				} else {
					return '%s';
				}
				// no break
			case 'boolean':
				return '%b';
			case 'integer':
				return '%d';
			case 'double':
				return '%f';
			default:
				return false;
		}
	}

	/**
	 * @todo Update get_sprintf by moving `case 'NULL'` to get_sprintf under `case 'string'`
	 * @todo Find and update references to use get_sprintf
	 * @todo Remove this method
	 */
	public function get_wp_sprintf_type( $var ) {
		$type = gettype( $var );

		switch ( $type ) {
			case 'string':
			case 'NULL':
				if ( is_numeric( $type ) ) {
					return '%d';
				}
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
		add_filter( 'query', [ $this, 'allow_null' ] );
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
		$date = date( 'Y-m-d H:i:s' );

		// get the columns from the table
		// SECURITY CHECKED: Everything in this query is already sanitized.
		$table_columns = $wpdb->get_col( 'DESC ' . $this->table_name, 0 );

		// Return with errors if found
		$handle_error = self::handle_error( $table_columns );
		if ( $handle_error ) {
			return $handle_error;
		}

		// default all values to null so we can add values where items are missing keys
		$defaults = [];
		foreach ( $table_columns as $column ) {
			$defaults[ $column ] = 'NULL';
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

			// get sprintf formats for item to prepare SQL
			$formats = [];
			foreach ( $item as $value ) {
				$formats[] = $this->get_wp_sprintf_type( $value );
				$values[]  = $value;
			}

			// generate the "(values...)" part of the insert query for this item
			$value_formats .= '(' . implode( ', ', $formats ) . '), ';
		}

		$insert_values = trim( $value_formats, ', ' );
		$statement     = "INSERT INTO {$this->table_name} ($insert_fields) VALUES $insert_values";

		// use the formats, Luke--escape SQL
		// SECURITY CHECKED: This query is properly prepared.
		$prepared = $wpdb->prepare( $statement, $values );

		add_filter( 'query', [ $this, 'allow_null' ] );
		$result = $wpdb->query( $prepared );
		return self::handle_error( $result, true );
	}

	/**
	 * @todo Remove method. This isn't being used
	 */
	public function after_select( $data ) {
		return $data;
	}

	/**
	 * Inserts new row into custom table
	 *
	 * @param  array $data Data to be inserted
	 * @return object Database query result from insert
	 */
	public function insert( $data ) {
		global $wpdb;

		$date             = date( 'Y-m-d H:i:s' );
		$data['created']  = $date;
		$data['modified'] = $date;

		$data = $this->before_create( $data );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$normalized_data = $this->normalize_data( $data );
		add_filter( 'query', [ $this, 'allow_null' ] );
		// SECURITY CHECKED: Everything in this query is already sanitized.
		$insert = $wpdb->insert( $this->table_name, $normalized_data );
		remove_filter( 'query', [ $this, 'allow_null' ] );

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
	 * @param bool $modify_date
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
	 * @param array $data
	 * @param array|integer|null $args an array of args or an integer id of the item being updated
	 * @param boolean $return_updated returns the updated record if true
	 * @return object|array|\WP_Error|null
	 */
	public function update_without_modified_date( $data, $args = null, $return_updated = true, $modify_date = false ) {
		return $this->update( $data, $args, $return_updated, $modify_date );
	}

	/**
	 * Update a DB record
	 *
	 * @param array $data
	 * @param array|integer|null $args an array of args or an integer id of the item being updated
	 * @param boolean $return_updated returns the updated record if true
	 * @param boolean $modify_date whether or not to update the `modified` date column
	 * @return object|array|\WP_Error|null
	 */
	public function update( $data, $args = null, $return_updated = true, $modify_date = true ) {
		global $wpdb;

		if ( isset( $data['created'] ) ) {
			unset( $data['created'] );
		}

		if ( $modify_date ) {
			$date             = date( 'Y-m-d H:i:s' );
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

		add_filter( 'query', [ $this, 'allow_null' ] );
		// SECURITY CHECKED: Everything in this query is already sanitized.
		$update = $wpdb->update( $this->table_name, $normalized_data, [ $args['col'] => $key ], $args['format'], $args['where_format'] );

		// Return with errors if found
		$handle_error = self::handle_error( $update );
		if ( $handle_error ) {
			return $handle_error;
		}

		remove_filter( 'query', [ $this, 'allow_null' ] );

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

		// SECURITY CHECKED: This query is properly prepared.
		$build_sql          = "SELECT * FROM `$this->table_name` WHERE " . $where_statement;
		$prepared_statement = $wpdb->prepare( $build_sql, $prepare_array );

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

		$results = [];

		// Array of params that should be handled with LIKE, not =
		// This probably won't ever change, since would only be used by search, practically.
		$like_params = [
			'published',
			'title',
			'post_title',
			'associated_posts',
		];

		// We no longer allow preprepared statements due to security concerns.
		if ( isset( $args['prepared_statement'] ) ) {
			// There is an exception for specific tables used by our importers.
			// Convert their prepared_statements to new SQL preparation.
			$allowed_tables = [
				'posts', // Purr Recipe Cards, Simple Recipes Pro, WP Tasty
				'amd_zlrecipe_recipes', // Zip Recipes, ZipList Recipes
			];
			$uses_importers_tables = in_array( $this->short_name, $allowed_tables );
			if ( $uses_importers_tables ) {
				$args['sql'] = $args['prepared_statement'];
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

			// SECURITY CHECKED: This query is properly prepared.
			$prepared = $wpdb->prepare($args['sql'], $args['params']);
			$results  = $wpdb->get_results( $prepared );
			return self::handle_error( $results, true );
		}

		$default_statement = "SELECT * FROM {$this->table_name} ORDER BY {$this->order_by} {$this->order} LIMIT {$this->limit} OFFSET {$this->offset}";

		if ( empty( $args ) && ! $search_params ) {
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
			$this->set_order_by( $args['order_by'] );
		}

		if ( isset( $args['order'] ) ) {
			$this->set_order( $args['order'] );
		}

		if ( isset( $args['select'] ) ) {
			$this->set_select( $args['select'] );
		}

		$build_sql = "SELECT $this->select FROM `$this->table_name`";
		$order_sql = $this->paginate_and_order();

		if ( $search_params || ! empty( $args['where'] ) && is_array( $args['where'] ) ) {
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

			if ( $search_params ) {
				foreach ( $search_params as $key => $value ) {
					if ( strlen( $search_statement ) === 0 ) {
						if ( strlen( $where_statement ) ) {
							$search_statement .= ' AND ';
						}
						$search_statement .= '(';
					} else {
						$search_statement .= ' OR';
					}
					$search_statement .= " $key LIKE '%%%s%%' ";
					$prepare_array[]   = $value;
				}
				$search_statement .= ')';
			}
			$build_sql          = $build_sql . ' WHERE ' . $where_statement . $search_statement . $order_sql;
			$prepared_statement = $wpdb->prepare( $build_sql, $prepare_array );

			// SECURITY CHECKED: This query is properly prepared.
			$results = $wpdb->get_results( $prepared_statement );
		} else {
			// SECURITY CHECKED: This query is properly prepared.
			$results = $wpdb->get_results( $build_sql . $order_sql );
		}

		$results = $this->after_find( $results );

		return self::handle_error( $results, true );
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
	 * Retrieve an entire SQL result set from the database
	 * @deprecated Use $this->find() instead
	 *
	 * @param  array  $args                Array containing basic SQL arguments
	 * @param  array  $prepared_statement Optional. Prepared SQL statement
	 * @return object Database query results
	 *
	 * TODO: Make this function use $this->find
	 */
	public function select( $args = [], $prepared_statement = null ) {
		global $wpdb;

		$limit    = $this->limit; // 50
		$offset   = $this->offset; // 0
		$order_by = $this->order_by; // 'created'
		$order    = $this->order; // 'DESC'

		if ( isset( $args['limit'] ) ) {
			$limit = (int) $args['limit'];
		}

		if ( isset( $args['offset'] ) ) {
			$offset = (int) $args['offset'];
		}

		if ( isset( $args['order_by'] ) ) {
			$order_by = preg_replace('/[^a-zA-Z0-9_]/', '', $args['order_by'] );
		}

		if ( isset( $args['order'] ) && ( 'ASC' === $args['order'] || 'asc' === $args['order'] ) ) {
			$order = 'ASC';
		}

		// We no longer allow preprepared statements due to security concerns
		if ( isset( $prepared_statement ) ) {
			$error = new WP_Error( 'preprepared-not-allowed', 'Preprepared SQL queries are no longer allowed. Using default query instead.' );
		}

		// SECURITY CHECKED: This query is properly prepared.
		$prepared = $wpdb->prepare( "SELECT * FROM `{$this->table_name}` ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d", [ $limit, $offset ] );
		$select   = $wpdb->get_results( $prepared );

		return self::handle_error( $select, true );
	}

	/**
	 * Add a basic where clause to the query.
	 *
	 * @param  string|array $column
	 * @param  mixed   $operator
	 * @param  mixed   $value
	 * @param  string  $after any SQL to insert after (LIMIT, ORDER, etc.)
	 * @return array|\WP_Error
	 */
	public function where( $column, $operator = '=', $value = null, $after = '' ) {
		// if $column is an array, we assume we're trying to pass in multiple qualifications at the same time
		// and can defer to `where_many`
		if ( is_array( $column ) ) {
			return $this->where_many( $column );
		}
		// if $column contains a space, we can assume it's a full `where` clause
		// and insert it into the statement.
		if ( is_string( $column ) && Str::contains( ' ', $column ) ) {
			$statement = "SELECT {$this->select} FROM {$this->table_name} WHERE {$column} ORDER BY {$this->order_by} {$this->order} LIMIT {$this->offset}, {$this->limit}";
			$statement = trim( $statement );
			return $this->db()->get_results( $statement );
		}

		// If the value is a string, we'll need to wrap it in quotes for the SQL to be valid
		$value_type = $this->get_sprintf( $value );
		$value      = '%s' === $value_type ? "'{$value}'" : $value;
		$where      = sprintf( '%s %s ' . $value_type, $column, $operator, $value );

		$statement = "SELECT * FROM {$this->table_name} WHERE {$where} {$after} ORDER BY {$this->order_by} {$this->order} LIMIT {$this->offset}, {$this->limit}";
		$statement = trim( $statement );
		return $this->db()->get_results( $statement );
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
			// If the value is a string, we'll need to wrap it in quotes for the SQL to be valid
			$value_type = $this->get_sprintf( $value );
			$value      = '%s' === $value_type ? "'{$value}'" : $value;
			if ( 'IN' === $operator ) {
				if ( is_array( $value ) ) {
					$value = "('" . implode( "','", $value ) . "')";
				} else {
					$value = "({$value})";
				}
			}
			// do one final verification of input types and format the `WHERE` strings
			$where .= sprintf(
				"%s%s %s $value_type%s %s ",
				$open_parentheses,
				$column,
				$operator,
				$value,
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
		add_filter( 'query', [ $this, 'allow_null' ] );
		return $this->db()->get_results( $statement );
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
	 * @return false|WP_Error|null
	 */
	public function delete( $args ) {
		global $wpdb;
		$item_to_delete = null;

		$defaults = apply_filters(
			"mv_db_select_one_defaults_{$this->table_name}", [
				'col' => 'id',
				'key' => null,
			]
		);

		// If $args not array, set key as id
		if ( ! is_array( $args ) ) {
			$args           = [ 'key' => $args ];
			$item_to_delete = $this->find_one_by_id( $args );
		}

		$args = array_merge( $defaults, $args );

		if ( $item_to_delete ) {
			$this->before_delete( $item_to_delete );
		}

		$where_array                 = [];
		$where_array[ $args['col'] ] = $args['key'];

		if ( ! empty( $args['where'] ) && is_array( $args['where'] ) ) {
			$where_array = $args['where'];
		}

		// SECURITY CHECKED: This delete is properly prepared.
		$deleted = $wpdb->delete( $this->table_name, $where_array );

		if ( $deleted ) {
			$data = $this->after_delete( $item_to_delete );
			return self::handle_error( $data, true );
		}

		return false;
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
	 * Appends a new item to an existing item
	 *
	 * @todo Evaluate if this method is even necessary. The method that calls this method is not being used anywhere in the code-base
	 *
	 * @param mixed $item
	 * @param mixed $new_item
	 * @param mixed $relationships
	 *
	 * @return mixed
	 */
	public function append_relationships( $item, $new_item, $relationships ) {
		$all_relationships = $relationships;

		if ( ! empty( $item->object_id ) ) {
			$item_permalink = get_the_permalink( $item->object_id );
			$post_title     = get_the_title( $item->object_id );
			$post_type      = get_post_type( $item->object_id );

			$all_relationships[ $post_type ]                            = [];
			$all_relationships[ $post_type ]['attributes']['id']        = $item->object_id;
			$all_relationships[ $post_type ]['attributes']['title']     = $post_title;
			$all_relationships[ $post_type ]['attributes']['permalink'] = $item_permalink;
		}

		if ( ! empty( $all_relationships ) ) {
			$new_item['relationships'] = $all_relationships;
		}

		return $new_item;
	}

	/**
	 * Prepare item
	 *
	 * @todo Remove this method. It is leftovers from Indexes and is not being used anywhere in the code-base
	 *
	 * @param mixed $item
	 * @param array $relationships
	 *
	 * @return mixed
	 */
	public function prepare_item( $item, $relationships = [] ) {
		$new_item = [];

		$new_item['type'] = $item->type;
		$new_item['id']   = intval( $item->id );
		unset( $item->id );
		unset( $item->type );
		foreach ( $item as $key => $value ) {
			$new_item['attributes'][ $key ] = '';
			// 0 and '0' should be allowed
			if ( $item->{$key} || ( 0 === $item->{$key} ) || ( '0' === $item->{$key} ) ) {
				$new_item['attributes'][ $key ] = $value;
			}

			// Make dates UNIX timestamps
			if ( in_array( $key, [ 'created', 'modified', 'published' ], true ) ) {
				$new_item['attributes'][ $key ] = mysql2date( 'U', $value );
			}
		}

		$new_item = $this->append_relationships( $item, $new_item, $relationships );

		return $new_item;
	}

	/**
	 * Returns the total number of results of a db query, ignoring limits.
	 * @param  array $args Array of arguments
	 * @return integer|\WP_Error Number of results
	 */
	public function get_count( $args, $search_params = null ) {
		$no_limit_args = array_merge(
			$args, [
				'limit'  => 999999,
				'offset' => 0,
				'select' => [ 'id' ],
			]
		);

		// There is no difference in performance time between using
		// MySQL's count and getting the `count()` of returned results
		$results = $this->find( $no_limit_args, $search_params );

		// Return with errors if found
		$handle_error = self::handle_error( $results );
		if ( $handle_error ) {
			return $handle_error;
		}

		return count( $results );
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
	 * @param integer|null `$offset` New offset value
	 * @return MV_DBI
	 */
	public function set_offset( $offset = null ) {
		$this->offset = ! is_null( $offset ) ? intval( $offset ) : $this->offset;
		return $this;
	}

	/**
	 * Overrides the default ORDER BY column
	 *
	 * @param string|null $order_by Defaults to `created` column
	 *
	 * @return MV_DBI
	 */
	public function set_order_by( $order_by = null ) {
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
		$this->order = strtoupper( $order ) ?: $this->order;
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
