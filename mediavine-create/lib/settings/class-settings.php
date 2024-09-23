<?php
namespace Mediavine;

use Mediavine\Create\Theme_Checker;

/**
 * Settings class
 */
class Settings {

	private $api_route = 'mv-settings';

	private $api_version = 'v1';

	private $table_name = 'mv_settings';

	/**
	 * Settings API object
	 * @var Settings_API
	 */
	private $settings_api = null;

	private $api = null;

	public $db_version = '1.0.0';

	public static $models = null;

	public $schema = [
		'type'    => [
			'type'    => 'varchar(20)',
			'default' => '\'setting\'',
		],
		'slug'    => [
			'type'   => 'varchar(170)',
			'unique' => true,
		],
		'value'   => 'longtext',
		'data'    => 'longtext',
		'`group`' => [
			'type' => 'varchar(170)',
			'key'  => true,
		],
		'`order`' => 'tinyint(10)',
	];

	public static $all_settings = [];

	public static $slugged_settings = [];

	public static $grouped_settings = [];

	public static function create_settings_filter( $settings = [] ) {
		$gathered_settings = apply_filters( 'mv_create_settings', $settings );

		if ( is_array( $gathered_settings ) ) {

			$value_filtered = [];

			foreach ( $gathered_settings as $setting ) {
				// prevents undefined index notice from appearing in debug.log
				if ( empty( $setting['slug'] ) ) {
					continue;
				}

				$existing_setting = self::get_settings( $setting['slug'] );

				if ( isset( $setting['force_update_value'] ) && $setting['force_update_value'] ) {
					$value_filtered[] = $setting;
					continue;
				}

				if ( isset( $existing_setting->value ) ) {
					// Convert line breaks into `\n` so they insert properly into the db
					$existing_value   = str_replace( [ "\r", "\n" ], '\n', $existing_setting->value );
					$setting['value'] = $existing_value;
				}
				$value_filtered[] = $setting;
			}

			self::create_settings( $value_filtered );

		}

	}

	/**
	 * Migrates a setting from an old to a new value
	 * @param   array   $settings   Current list of settings
	 * @param   string  $slug       Slug to check
	 * @param   string  $old_value  Current value you want to check against
	 * @param   string  $new_value  New value you want
	 * @param   string  $callback   Callback to be run
	 * @return  array               List of settings after migrated change made
	 */
	public static function migrate_setting_value( array $settings, $slug, $old_value, $new_value, $callback = null ) {
		$current_value = self::get_setting( $slug );

		if ( 'boolean_switch' === $callback ) {
			$old_value = $current_value;
			$new_value = ! wp_validate_boolean( $current_value );
		}

		if ( $current_value && $current_value === $old_value ) {
			$settings_slugs = array_flip( wp_list_pluck( $settings, 'slug' ) );

			$settings[ $settings_slugs[ $slug ] ]['value']              = $new_value;
			$settings[ $settings_slugs[ $slug ] ]['force_update_value'] = true;
		}

		return $settings;
	}

	/**
	 * Migrates a setting slug to a new slug
	 * @param   array   $settings  Current list of settings
	 * @param   string  $old_slug  Current sug to be replaced
	 * @param   string  $new_slug  New slug you want
	 * @param   string  $callback  Callback to be run
	 * @return  array              List of settings after migrated change made
	 */
	public static function migrate_setting_slug( array $settings, $old_slug, $new_slug, $callback = null ) {
		$old_slug_value = self::get_setting( $old_slug );

		if ( 'boolean_switch' === $callback ) {
			$old_slug_value = ! wp_validate_boolean( $old_slug_value );
		}

		// $old_slug_value will be null if no setting
		if ( $old_slug_value || false === $old_slug_value ) {
			$settings_slugs = array_flip( wp_list_pluck( $settings, 'slug' ) );

			if ( isset( $settings_slugs[ $new_slug ] ) ) {
				$settings[ $settings_slugs[ $new_slug ] ]['value'] = $old_slug_value;
			}
			\Mediavine\Settings::delete_setting( $old_slug );
		}

		return $settings;
	}

	public static function create_settings( $settings ) {
		$Settings_Models = new MV_DBI( 'mv_settings' );

		$collection = [];
		if ( wp_is_numeric_array( $settings ) ) {
			foreach ( $settings as $setting ) {

				if ( isset( $setting['slug'] ) ) {
					$setting['slug'] = sanitize_text_field( $setting['slug'] );
				}

				if ( isset( $setting['value'] ) ) {
					$allowed_html     = [
						'a'      => [
							'class'  => true,
							'href'   => true,
							'target' => true,
						],
						'strong' => [
							'class' => true,
						],
						'em'     => [
							'class' => true,
						],
					];
					$setting['value'] = wp_kses( $setting['value'], $allowed_html );
				}

				if ( isset( $setting['value'] ) && isset( $setting['slug'] ) ) {
					$setting['value'] = apply_filters( $setting['slug'] . '_settings_value', $setting['value'] );
					$setting['value'] = sanitize_text_field( $setting['value'] );
				}

				if ( isset( $setting['data'] ) ) {
					$setting['data'] = wp_json_encode( $setting['data'] );
				}

				if ( isset( $setting['group'] ) ) {
					$setting['group'] = sanitize_text_field( $setting['group'] );
				}

				// Only add setting if it has slug
				if ( ! empty( $setting['slug'] ) ) {
					$collection[] = $Settings_Models->upsert( $setting );
				}
			}
			return $collection;
		}

		if ( isset( $settings['data'] ) ) {
			$settings['data'] = wp_json_encode( $settings['data'] );
		}

		// Only add setting if it has slug
		if ( ! empty( $settings['slug'] ) ) {
			return $Settings_Models->upsert( $settings );
		}

		return false;
	}

	public static function extract( $setting ) {
		if ( empty( $setting->data ) ) {
			return $setting;
		}
		if ( ! empty( $setting->value ) ) {
			$setting->value = str_replace( '\n', "\n", $setting->value );
		}
		$data = maybe_unserialize( $setting->data );
		if ( gettype( $data ) === 'string' ) {
			$data = json_decode( $setting->data );
			if ( gettype( $data ) === 'string' ) {
				$data = json_decode( $data );
			}
		}
		$setting->data = (array) $data;
		return $setting;
	}

	/**
	 * Retreives all settings, or by a specific criteria
	 *
	 * @param string $setting_slug Setting slug to retreive
	 * @param string $setting_group Settings of a particular group to retreive
	 * @param boolean $force_reset Should the settings be force pulled from the database, updating the retreived settings
	 * @return object|array Setting opject for a single setting, and the array for a group or all settings
	 */
	public static function get_settings( $setting_slug = null, $setting_group = null, $force_reset = false ) {
		// Build settings if they haven't yet stored, or if we are forcing a reset
		if ( empty( self::$all_settings ) || $force_reset ) {
			// Make sure our table exists before we build our settings
			if ( ! mv_create_table_exists( 'mv_settings' ) ) {
				return [];
			}

			$Settings = new MV_DBI( 'mv_settings' );

			self::$all_settings = $Settings->find( [ 'limit' => 200 ] );

			if ( ! empty( self::$all_settings ) ) {
				foreach ( self::$all_settings as &$setting ) {
					$setting = self::extract( $setting );

					self::$slugged_settings[ $setting->slug ] = $setting;

					if ( ! empty( $setting->group ) ) {
						self::$grouped_settings[ $setting->group ][] = $setting;
					}
				}
			}
		}

		if ( $setting_slug ) {
			if ( ! empty( self::$slugged_settings ) && isset( self::$slugged_settings[ $setting_slug ] ) ) {
				return self::$slugged_settings[ $setting_slug ];
			}

			// Fallback in the event we don't have a match (should never happen, but just in case)
			$Settings = new MV_DBI( 'mv_settings' );

			$setting = $Settings->find_one(
				[
					'col' => 'slug',
					'key' => $setting_slug,
				]
			);

			if ( $setting ) {
				return self::extract( $setting );
			}
			return null;
		}

		if ( $setting_group ) {
			if ( ! empty( self::$grouped_settings ) && isset( self::$grouped_settings[ $setting_group ] ) ) {
				return self::$grouped_settings[ $setting_group ];
			}

			// Fallback in the event we don't have a match (should never happen, but just in case)
			$Settings = new MV_DBI( 'mv_settings' );

			$settings = $Settings->find(
				[
					'where'    => [
						'`group`' => $setting_group,
					],
					'order_by' => '`order`',
					'order'    => 'ASC',
				]
			);

			if ( ! empty( $settings ) ) {
				foreach ( $settings as &$setting ) {
					$setting = self::extract( $setting );
				}
				return $settings;
			}

			return null;
		}

		return self::$all_settings;
	}

	/**
	 * Gets the setting value of a single setting
	 * @param string $setting_slug Slug to retrieve
	 * @param string $default_setting Default if no setting exists
	 * @return mixed|null Value from the setting or default setting or null if no setting found
	 */
	public static function get_setting( $setting_slug, $default_setting = null ) {
		$setting = \Mediavine\Settings::get_settings( $setting_slug );

		if ( isset( $setting->value ) ) {
			return $setting->value;
		}

		if ( isset( $default_setting ) ) {
			return $default_setting;
		}

		return null;
	}

	static function update_setting( $slug, $new_value ) {
		// Get the current setting so we have data for update
		$setting = (array) \Mediavine\Settings::get_settings( $slug );

		// Update setting with new value
		$setting['value'] = $new_value;
		\Mediavine\Settings::$models->mv_settings->upsert( $setting );
	}

	/**
	 * Deletes the setting value of a single setting or group
	 * @param string $setting_slug Slug to delete
	 * @param string $setting_group Group to delete - $setting_slug MUST be null
	 * @return boolean True if deleted, fasle if not deleted (usually because not found)
	 */
	public static function delete_settings( $setting_slug, $setting_group = null ) {
		$Settings_Models = new MV_DBI( 'mv_settings' );
		$args            = [];

		$args = [
			'col' => 'slug',
			'key' => $setting_slug,
		];

		if ( is_null( $setting_slug ) && ! empty( $setting_group ) ) {
			$args = [
				'col' => 'group',
				'key' => $setting_group,
			];
		}

		return $Settings_Models->delete( $args );
	}

	/**
	 * Deletes the setting value of a single setting (Alias of `delete_settings`)
	 * @param string $setting_slug Slug to delete
	 * @return boolean True if deleted, fasle if not deleted (usually because not found)
	 */
	public static function delete_setting( $setting_slug ) {
		self::delete_settings( $setting_slug );
	}

	/**
	 * Resets stored settings so a new query can be run. Mainly used in tests
	 */
	public static function reset_settings() {
		self::$all_settings     = [];
		self::$slugged_settings = [];
		self::$grouped_settings = [];
	}

	/**
	 * Initializes the class and adss filters and sets class state
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'mv_custom_schema', [ $this, 'custom_tables' ] );
		add_action( 'activated_plugin', [ $this, 'mcp_refresh' ] );
		add_action( 'after_switch_theme', [ $this, 'update_comments_selector_on_theme_change' ], 15 );
		add_action( 'mv_create_plugin_updated', [ $this, 'update_comments_selector_on_plugin_update' ], 100);

		self::$models       = MV_DBI::get_models(
			[
				$this->table_name,
			]
		);
		$this->settings_api = new Settings_API();

		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}


	/**
	 * Refresh Create settings when MCP is activated
	 *
	 * @param string $plugin Plugin name
	 * @return void
	 */
	public function mcp_refresh( $plugin ) {
		// if plugin that was activated is not MCP, then exit
		if ( false === strpos( $plugin, 'mediavine-control-panel.php', true ) ) {
			return;
		}

		// refresh version number
		update_option( 'mv_create_version', '' );
		update_option( 'mv_create_db_version', '' );
	}

	/**
	 * Update the default comments selector setting when the plugin is updated
	 *
	 * @return void
	 */
	public function update_comments_selector_on_plugin_update() {
		// we only want to update the comments selector on plugin update if Trellis is active
		if ( Theme_Checker::is_trellis() ) {
			self::update_setting( 'mv_create_public_reviews_el', '#mv-trellis-comments' );
		}
	}

	/**
	 * Update the default comments selector setting when the theme is changed
	 *
	 * @return void
	 */
	public function update_comments_selector_on_theme_change() {
		//check for the current theme and update comments selector appropriately
		self::update_setting( 'mv_create_public_reviews_el', Theme_Checker::is_trellis() ? '#mv-trellis-comments' : '#comments' );

		//refresh settings to make sure default value is also updated
		update_option( 'mv_create_version', '' );
		update_option( 'mv_create_db_version', '' );
	}

	/**
	 * @param  array Array of tables to be created
	 * @return array extends custom tables filter for processing
	 */
	public function custom_tables( $tables ) {
		$tables[] = [
			'version'    => $this->db_version,
			'table_name' => $this->table_name,
			'schema'     => $this->schema,
		];
		return $tables;
	}

	/**
	 * Create Routes for Settings API
	 *
	 * @return void
	 */
	function routes() {
		$route_namespace = $this->api_route . '/' . $this->api_version;

		register_rest_route(
			$route_namespace, '/settings', [
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this->settings_api, 'create' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/settings/(?P<id>\d+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read_single' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this->settings_api, 'update_single' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this->settings_api, 'delete' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/settings/slug/(?P<slug>\S+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read_single_by_slug' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\sanitize_slug(),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/group/(?P<slug>\S+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read_by_group' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\sanitize_slug(),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
			]
		);

		register_rest_route(
			$route_namespace, '/refresh-settings', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this->settings_api, 'refresh_settings' ],
				'permission_callback' => function ( \WP_REST_Request $request ) {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			$route_namespace, '/reset-settings', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this->settings_api, 'reset_db_settings' ],
				'permission_callback' => function ( \WP_REST_Request $request ) {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}
}
