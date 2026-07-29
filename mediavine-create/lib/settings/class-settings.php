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

	/**
	 * Max rows loaded into the request-level settings cache.
	 *
	 * Must stay above the number of registered Create settings; crossing this
	 * silently forces every uncached get_setting() into a fallback DB query.
	 *
	 * @var int
	 */
	const LOAD_LIMIT = 1000;

	public static $all_settings = [];

	public static $slugged_settings = [];

	public static $grouped_settings = [];

	/**
	 * Whether the request-level settings cache has been built.
	 *
	 * Distinguishes "not loaded yet" from "loaded but empty / with null misses".
	 *
	 * @var bool
	 */
	private static $cache_built = false;

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
	 * @param   array  $settings   Current list of settings
	 * @param   string $slug       Slug to check
	 * @param   string $old_value  Current value you want to check against
	 * @param   string $new_value  New value you want
	 * @param   string $callback   Callback to be run
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
	 * @param   array  $settings  Current list of settings
	 * @param   string $old_slug  Current sug to be replaced
	 * @param   string $new_slug  New slug you want
	 * @param   string $callback  Callback to be run
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
			self::delete_setting( $old_slug );
		}

		return $settings;
	}

	/**
	 * Sanitize a setting payload before upsert.
	 *
	 * Shared by create and update write paths so REST and PHP callers agree.
	 *
	 * Most values use sanitize_text_field. Exceptions:
	 * - custom CSS: tags stripped
	 * - list ad HTML: div/span markup with class/id/data-* preserved (render re-sanitizes)
	 * - known textareas: sanitize_textarea_field so newlines survive
	 *
	 * @param array $setting Setting fields.
	 * @return array Sanitized setting.
	 */
	public static function sanitize_setting( array $setting ) {
		if ( isset( $setting['slug'] ) ) {
			$setting['slug'] = sanitize_text_field( $setting['slug'] );
		}

		if ( isset( $setting['value'] ) && isset( $setting['slug'] ) ) {
			$setting['value'] = apply_filters( $setting['slug'] . '_settings_value', $setting['value'] );
			$setting['value'] = self::sanitize_setting_value( $setting['slug'], $setting['value'] );
		}

		if ( isset( $setting['data'] ) ) {
			$setting['data'] = wp_json_encode( $setting['data'] );
		}

		if ( isset( $setting['group'] ) ) {
			$setting['group'] = sanitize_text_field( $setting['group'] );
		}

		return $setting;
	}

	/**
	 * Sanitize a setting value according to its slug.
	 *
	 * @param string $slug  Setting slug.
	 * @param mixed  $value Raw value.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_setting_value( $slug, $value ) {
		if ( 'mv_create_custom_css' === $slug ) {
			return wp_strip_all_tags( $value );
		}

		if ( 'mv_create_list_ad_custom_html' === $slug ) {
			return self::sanitize_list_ad_html( $value );
		}

		// Textareas and multiline button labels must keep newlines (sanitize_text_field collapses them).
		$textarea_slugs = [
			'mv_create_nutrition_disclaimer',
			'mv_create_affiliate_message',
			'mv_create_custom_buttons',
		];
		if ( in_array( $slug, $textarea_slugs, true ) ) {
			return sanitize_textarea_field( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Allowlist sanitizer for list ad slot HTML.
	 *
	 * Permits div/span with class, id, and data-* attributes. Scripts, event
	 * handlers, and other tags/attrs are stripped. Matches the render-time
	 * allowlist described in the setting instructions.
	 *
	 * @param mixed $html Raw HTML.
	 * @return string Sanitized HTML.
	 */
	public static function sanitize_list_ad_html( $html ) {
		if ( ! is_string( $html ) ) {
			return '';
		}

		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '';
		}

		self::sanitize_list_ad_dom_node( $dom, $body );

		$result = '';
		foreach ( $body->childNodes as $child ) {
			$result .= $dom->saveHTML( $child );
		}

		return trim( $result );
	}

	/**
	 * Recursively sanitize list-ad DOM children in place.
	 *
	 * @param \DOMDocument $dom    Owner document.
	 * @param \DOMNode     $parent Parent whose children are sanitized.
	 * @return void
	 */
	private static function sanitize_list_ad_dom_node( \DOMDocument $dom, \DOMNode $parent ) {
		$allowed_tags   = [ 'div', 'span' ];
		$strip_entirely = [ 'script', 'style', 'iframe', 'form', 'object', 'embed' ];
		$children       = iterator_to_array( $parent->childNodes );

		foreach ( $children as $child ) {
			if ( ! ( $child instanceof \DOMElement ) ) {
				if ( ! ( $child instanceof \DOMText ) ) {
					$parent->removeChild( $child );
				}
				continue;
			}

			$tag = strtolower( $child->tagName );

			if ( in_array( $tag, $strip_entirely, true ) ) {
				$parent->removeChild( $child );
				continue;
			}

			if ( ! in_array( $tag, $allowed_tags, true ) ) {
				self::sanitize_list_ad_dom_node( $dom, $child );
				$grandchildren = iterator_to_array( $child->childNodes );
				foreach ( $grandchildren as $gc ) {
					$parent->insertBefore( $gc, $child );
				}
				$parent->removeChild( $child );
				continue;
			}

			$remove_attrs = [];
			foreach ( $child->attributes as $attr ) {
				$name    = strtolower( $attr->nodeName );
				$allowed = ( 'class' === $name )
					|| ( 'id' === $name )
					|| ( 1 === preg_match( '/^data-[a-z0-9-]+$/', $name ) );
				if ( ! $allowed ) {
					$remove_attrs[] = $attr->nodeName;
				}
			}
			foreach ( $remove_attrs as $attr_name ) {
				$child->removeAttribute( $attr_name );
			}

			self::sanitize_list_ad_dom_node( $dom, $child );
		}
	}

	/**
	 * Upsert one or more settings after sanitizing, then invalidate the static cache.
	 *
	 * @param array $settings Single setting associative array, or list of settings.
	 * @return array|object|false Upserted row(s), or false when slug is missing.
	 */
	public static function create_settings( $settings ) {
		$Settings_Models = new MV_DBI( 'mv_settings' );

		$collection = [];
		if ( wp_is_numeric_array( $settings ) ) {
			foreach ( $settings as $setting ) {
				$setting = self::sanitize_setting( $setting );

				// Only add setting if it has slug
				if ( ! empty( $setting['slug'] ) ) {
					$collection[] = $Settings_Models->upsert( $setting );
				}
			}
			self::reset_settings();
			return $collection;
		}

		$settings = self::sanitize_setting( $settings );

		// Only add setting if it has slug
		if ( ! empty( $settings['slug'] ) ) {
			$result = $Settings_Models->upsert( $settings );
			self::reset_settings();
			return $result;
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
		$data = json_decode($setting->data ?: '{}');
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
	 * @param string  $setting_slug Setting slug to retreive
	 * @param string  $setting_group Settings of a particular group to retreive
	 * @param boolean $force_reset Should the settings be force pulled from the database, updating the retreived settings
	 * @return object|array Setting opject for a single setting, and the array for a group or all settings
	 */
	public static function get_settings( $setting_slug = null, $setting_group = null, $force_reset = false ) {
		// Build settings if they haven't yet stored, or if we are forcing a reset
		if ( ! self::$cache_built || $force_reset ) {
			// Make sure our table exists before we build our settings
			if ( ! mv_create_table_exists( 'mv_settings' ) ) {
				return [];
			}

			$Settings = new MV_DBI( 'mv_settings' );

			self::$all_settings     = [];
			self::$slugged_settings = [];
			self::$grouped_settings = [];

			$loaded = $Settings->find( [ 'limit' => self::LOAD_LIMIT ] );

			if ( ! empty( $loaded ) ) {
				foreach ( $loaded as &$setting ) {
					$setting = self::extract( $setting );

					self::$all_settings[]                     = $setting;
					self::$slugged_settings[ $setting->slug ] = $setting;

					if ( ! empty( $setting->group ) ) {
						self::$grouped_settings[ $setting->group ][] = $setting;
					}
				}
			}

			self::$cache_built = true;
		}

		if ( $setting_slug ) {
			// array_key_exists so negative-cached null misses are not re-queried
			if ( array_key_exists( $setting_slug, self::$slugged_settings ) ) {
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
				$setting                                  = self::extract( $setting );
				self::$slugged_settings[ $setting_slug ] = $setting;
				return $setting;
			}

			// Negative cache: remember the miss so permission lookups etc. don't re-hit the DB
			self::$slugged_settings[ $setting_slug ] = null;
			return null;
		}

		if ( $setting_group ) {
			if ( array_key_exists( $setting_group, self::$grouped_settings ) ) {
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
					'limit'    => self::LOAD_LIMIT,
				]
			);

			if ( ! empty( $settings ) ) {
				foreach ( $settings as &$setting ) {
					$setting = self::extract( $setting );
				}
				self::$grouped_settings[ $setting_group ] = $settings;
				return $settings;
			}

			self::$grouped_settings[ $setting_group ] = null;
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
		$setting = self::get_settings( $setting_slug );

		if ( isset( $setting->value ) ) {
			return $setting->value;
		}

		if ( isset( $default_setting ) ) {
			return $default_setting;
		}

		return null;
	}

	/**
	 * Update a single setting value (sanitizes and invalidates the static cache).
	 *
	 * @param string $slug      Setting slug.
	 * @param mixed  $new_value New value.
	 * @return array|object|false Upserted row, or false on failure.
	 */
	public static function update_setting( $slug, $new_value ) {
		// Get the current setting so we have data for update
		$setting = (array) self::get_settings( $slug );

		// Ensure slug is set even when the setting did not previously exist
		$setting['slug']  = $slug;
		$setting['value'] = $new_value;

		return self::create_settings( $setting );
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

		$deleted = $Settings_Models->delete( $args );
		self::reset_settings();
		return $deleted;
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
	 * Resets stored settings so a new query can be run.
	 *
	 * Called automatically after writes; also used in tests for isolation.
	 */
	public static function reset_settings() {
		self::$all_settings     = [];
		self::$slugged_settings = [];
		self::$grouped_settings = [];
		self::$cache_built      = false;
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
		add_action( 'mv_create_plugin_updated', [ $this, 'remove_retired_paapi_credentials' ], 101 );

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
	 * Slugs of the retired PA-API 5.0 credential settings.
	 *
	 * Amazon shut PA-API down entirely, so these hold nothing usable. The
	 * Creators API replaced them and reads `_creators_credential_id` /
	 * `_creators_credential_secret` instead.
	 *
	 * Deliberately does NOT include `_paapi_marketplace` or `_paapi_tag`: those
	 * keep the legacy slug names but are still live inputs to the Creators API
	 * (region and Associate Tag). @see \Mediavine\Create\Amazon_Creators::init()
	 *
	 * @var string[]
	 */
	const RETIRED_PAAPI_SETTINGS = [
		'mv_create_paapi_access_key',
		'mv_create_paapi_secret_key',
	];

	/**
	 * Deletes the retired PA-API credential settings on plugin update.
	 *
	 * These stopped being registered when PA-API support was removed, but rows
	 * written by earlier versions survive in `mv_settings`. Because the settings
	 * API serves whatever is in the table, upgraded sites kept rendering both
	 * fields in the Amazon settings UI — storing a secret that can never be used
	 * — while fresh installs showed nothing. Dropping the rows makes the two
	 * cases agree.
	 *
	 * @return void
	 */
	public function remove_retired_paapi_credentials() {
		foreach ( self::RETIRED_PAAPI_SETTINGS as $slug ) {
			self::delete_setting( $slug );
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
					'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read' ],
					'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				],
			]
		);

		register_rest_route(
			$route_namespace, '/settings/(?P<id>\d+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read_single' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this->settings_api, 'update_single' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this->settings_api, 'delete' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\validate_id(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				],
			]
		);

		register_rest_route(
			$route_namespace, '/settings/slug/(?P<slug>\S+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read_single_by_slug' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\sanitize_slug(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				],
			]
		);

		register_rest_route(
			$route_namespace, '/group/(?P<slug>\S+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->settings_api, 'read_by_group' ],
					'args'                => \Mediavine\Create\API\V1\CreationsArgs\sanitize_slug(),
					'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				],
			]
		);

		register_rest_route(
			$route_namespace, '/refresh-settings', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this->settings_api, 'refresh_settings' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		register_rest_route(
			$route_namespace, '/reset-settings', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this->settings_api, 'reset_db_settings' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		register_rest_route(
			$route_namespace, '/request-password-reset', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this->settings_api, 'request_password_reset' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
				'args'                => [
					'email' => [
						'type'     => 'string',
						'required' => true,
						'validate_callback' => function( $email ) {
							return is_email( $email );
						},
					],
				],
			]
		);

		register_rest_route(
			$route_namespace, '/reset-db-versions', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this->settings_api, 'reset_db_versions' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);

		register_rest_route(
			$route_namespace, '/reset-subscription-tier', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this->settings_api, 'reset_subscription_tier' ],
				'permission_callback' => [ \Mediavine\Permissions::class, 'admin' ],
			]
		);
	}
}
