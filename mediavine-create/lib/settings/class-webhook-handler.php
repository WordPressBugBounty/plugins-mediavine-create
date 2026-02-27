<?php
/**
 * Webhook handler for Create Studio → Create plugin communication.
 *
 * Receives RS256-signed webhooks from Create Studio, verifies signatures
 * using the public key fetched from Studio, and dispatches by event type.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

use Mediavine\Settings;

/**
 * Handles incoming webhooks from Create Studio.
 */
class Webhook_Handler {

	/**
	 * Transient key for caching the Studio public key.
	 */
	const PUBLIC_KEY_TRANSIENT = 'mv_create_studio_public_key';

	/**
	 * Maximum age of a webhook timestamp before it's rejected (seconds).
	 */
	const MAX_TIMESTAMP_AGE = 300;

	/**
	 * Initialize the webhook handler by registering REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the webhook REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			'mv-create/v1',
			'/webhooks/studio',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle_webhook' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook( \WP_REST_Request $request ) {
		$body      = $request->get_body();
		$signature = $request->get_header( 'X-Studio-Signature' );
		$timestamp = $request->get_header( 'X-Studio-Timestamp' );

		// Validate required headers.
		if ( empty( $signature ) || empty( $timestamp ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing signature or timestamp' ], 400 );
		}

		// Replay protection.
		$now = time();
		if ( abs( $now - intval( $timestamp ) ) > self::MAX_TIMESTAMP_AGE ) {
			return new \WP_REST_Response( [ 'error' => 'Timestamp too old' ], 401 );
		}

		// Fetch and verify with public key.
		$public_key = self::get_public_key();
		if ( ! $public_key ) {
			return new \WP_REST_Response( [ 'error' => 'Unable to fetch public key' ], 500 );
		}

		$sig_decoded = base64_decode( $signature, true );
		if ( false === $sig_decoded ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid signature encoding' ], 401 );
		}

		$verified = openssl_verify( $body, $sig_decoded, $public_key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
		}

		// Parse payload and dispatch.
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		return self::dispatch( $payload );
	}

	/**
	 * Dispatch a verified webhook payload by type.
	 *
	 * @param array $payload The decoded webhook payload.
	 * @return \WP_REST_Response
	 */
	private static function dispatch( array $payload ) {
		$type = $payload['type'];
		$data = isset( $payload['data'] ) ? $payload['data'] : [];

		switch ( $type ) {
			case 'debug':
				return self::handle_debug( $data );

			case 'subscription_change':
				return self::handle_subscription_change( $data );

			case 'settings_update':
				return self::handle_settings_update( $data );

			default:
				return new \WP_REST_Response( [ 'error' => 'Unknown webhook type' ], 400 );
		}
	}

	/**
	 * Maximum number of debug log lines per request.
	 */
	const MAX_LOG_LINES = 5000;

	/**
	 * Maximum download file size in bytes (50 MB).
	 */
	const MAX_DOWNLOAD_SIZE = 52428800;

	/**
	 * Handle the "debug" webhook type.
	 *
	 * Accepts an optional `scope` parameter:
	 * - `all` (default): environment, plugins, theme, connection, settings, compatibility, features
	 * - `logs`: debug log content only
	 * - `log_download`: streams the full debug.log file as a download
	 * - `environment`: just environment info
	 * - `client_check`: fetches a post with a Create card and checks for client build version mismatch
	 *
	 * @param array $data The webhook data.
	 * @return \WP_REST_Response
	 */
	private static function handle_debug( array $data = [] ) {
		$scope = isset( $data['scope'] ) ? $data['scope'] : 'all';

		switch ( $scope ) {
			case 'logs':
				return new \WP_REST_Response( [ 'logs' => self::get_debug_log( $data ) ], 200 );

			case 'log_download':
				return self::handle_log_download();

			case 'environment':
				return new \WP_REST_Response( [ 'environment' => self::get_environment_info() ], 200 );

			case 'client_check':
				return new \WP_REST_Response( [ 'client_check' => self::check_client_version() ], 200 );

			default: // 'all'
				return new \WP_REST_Response(
					[
						'environment'   => self::get_environment_info(),
						'plugins'       => self::get_plugins_info(),
						'theme'         => self::get_theme_info(),
						'connection'    => self::get_connection_info(),
						'settings'      => Settings::get_settings(),
						'compatibility' => self::get_compatibility_info(),
						'features'      => self::get_features_info(),
					],
					200
				);
		}
	}

	/**
	 * Get environment information.
	 *
	 * @return array
	 */
	private static function get_environment_info() {
		global $wpdb;

		return [
			'create_version'     => Plugin::VERSION,
			'wp_version'         => get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'mysql_version'      => $wpdb->db_version(),
			'memory_limit'       => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'      => ini_get( 'post_max_size' ),
			'wp_memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'not set',
			'wp_debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'       => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'multisite'          => is_multisite(),
			'site_url'           => get_site_url(),
			'home_url'           => get_home_url(),
			'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown',
			'is_ssl'             => is_ssl(),
			'timezone'           => wp_timezone_string(),
		];
	}

	/**
	 * Get information about all installed plugins.
	 *
	 * @return array
	 */
	private static function get_plugins_info() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', [] );
		$plugins        = [];

		foreach ( $all_plugins as $path => $plugin_data ) {
			$file_path  = WP_PLUGIN_DIR . '/' . $path;
			$modified   = file_exists( $file_path ) ? filemtime( $file_path ) : null;

			$plugins[] = [
				'name'     => $plugin_data['Name'],
				'version'  => $plugin_data['Version'],
				'author'   => $plugin_data['AuthorName'],
				'active'   => in_array( $path, $active_plugins, true ),
				'path'     => $path,
				'modified' => $modified ? gmdate( 'c', $modified ) : null,
			];
		}

		return $plugins;
	}

	/**
	 * Get active theme information.
	 *
	 * @return array
	 */
	private static function get_theme_info() {
		$theme    = wp_get_theme();
		$is_child = $theme->parent() !== false;

		return [
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'author'      => $theme->get( 'Author' ),
			'template'    => get_template(),
			'stylesheet'  => get_stylesheet(),
			'is_child'    => $is_child,
			'parent_name' => $is_child ? $theme->parent()->get( 'Name' ) : null,
		];
	}

	/**
	 * Get Create Studio connection information.
	 *
	 * @return array
	 */
	private static function get_connection_info() {
		return [
			'studio_url'              => Plugin::$services_api_url,
			'public_key_cached'       => (bool) get_transient( self::PUBLIC_KEY_TRANSIENT ),
			'is_pro'                  => Plugin::is_pro(),
			'subscription_tier'       => Settings::get_setting( GateKeeper::SETTING_SUBSCRIPTION_TIER ),
			'subscription_synced_at'  => Settings::get_setting( GateKeeper::SETTING_SUBSCRIPTION_SYNCED_AT ),
		];
	}

	/**
	 * Read debug log lines from the WP debug log file.
	 *
	 * @param array $data Request data with optional `lines` and `search` params.
	 * @return array
	 */
	private static function get_debug_log( array $data ) {
		$requested_lines = isset( $data['lines'] ) ? absint( $data['lines'] ) : 200;
		$requested_lines = min( $requested_lines, self::MAX_LOG_LINES );
		$search          = isset( $data['search'] ) ? $data['search'] : '';

		// Determine log file path.
		$log_path = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$log_path = WP_DEBUG_LOG;
		}

		if ( ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			return [
				'exists'      => false,
				'lines'       => [],
				'total_size'  => 0,
				'total_lines' => 0,
				'truncated'   => false,
			];
		}

		$total_size = filesize( $log_path );

		try {
			$file        = new \SplFileObject( $log_path, 'r' );
			$file->seek( PHP_INT_MAX );
			$total_lines = $file->key();

			// Read from the end of the file.
			$lines      = [];
			$start_line = max( 0, $total_lines - $requested_lines );

			$file->seek( $start_line );
			while ( ! $file->eof() ) {
				$line = rtrim( $file->current(), "\r\n" );
				$file->next();

				if ( '' === $line ) {
					continue;
				}

				if ( ! empty( $search ) && false === stripos( $line, $search ) ) {
					continue;
				}

				$lines[] = $line;
			}

			return [
				'exists'      => true,
				'lines'       => $lines,
				'total_size'  => $total_size,
				'total_lines' => $total_lines,
				'truncated'   => $total_lines > $requested_lines,
			];
		} catch ( \Exception $e ) {
			return [
				'exists'      => true,
				'lines'       => [],
				'total_size'  => $total_size,
				'total_lines' => 0,
				'truncated'   => false,
				'error'       => 'Unable to read log file',
			];
		}
	}

	/**
	 * Handle the "log_download" scope by returning the full debug log file content.
	 *
	 * Streams the file directly to avoid loading it all into PHP memory via WP_REST_Response.
	 * Caps at MAX_DOWNLOAD_SIZE to prevent excessive memory/bandwidth usage.
	 *
	 * @return \WP_REST_Response|void Returns error response on failure, or streams file and exits.
	 */
	private static function handle_log_download() {
		$log_path = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$log_path = WP_DEBUG_LOG;
		}

		if ( ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			return new \WP_REST_Response( [ 'error' => 'No debug log file found' ], 404 );
		}

		$file_size = filesize( $log_path );

		if ( $file_size > self::MAX_DOWNLOAD_SIZE ) {
			// Stream only the last MAX_DOWNLOAD_SIZE bytes.
			$offset    = $file_size - self::MAX_DOWNLOAD_SIZE;
			$send_size = self::MAX_DOWNLOAD_SIZE;
		} else {
			$offset    = 0;
			$send_size = $file_size;
		}

		// Bypass WP REST response handling and stream the file directly.
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Length: ' . $send_size );
		header( 'Content-Disposition: attachment; filename="debug.log"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $log_path, 'rb' );
		if ( ! $handle ) {
			return new \WP_REST_Response( [ 'error' => 'Unable to read log file' ], 500 );
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$remaining = $send_size;
		$chunk     = 8192;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$read_size = min( $chunk, $remaining );
			$data      = fread( $handle, $read_size );
			if ( false === $data ) {
				break;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $data;
			$remaining -= strlen( $data );

			if ( ob_get_level() ) {
				ob_flush();
			}
			flush();
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Get compatibility information: ad networks, caching, conflicts, competing recipe plugins.
	 *
	 * @return array
	 */
	private static function get_compatibility_info() {
		return [
			'ad_network'        => self::get_ad_network_info(),
			'caching'           => self::get_caching_info(),
			'known_conflicts'   => self::get_known_conflicts(),
			'recipe_plugins'    => self::get_competing_recipe_plugins(),
			'system_checks'     => self::get_system_checks(),
		];
	}

	/**
	 * Get feature gating and integration status.
	 *
	 * @return array
	 */
	private static function get_features_info() {
		$tier = GateKeeper::get_subscription_tier();

		$features = [];
		foreach ( GateKeeper::get_gated_features() as $feature ) {
			$features[ $feature ] = GateKeeper::can_access( $feature );
		}

		return [
			'subscription_tier' => $tier,
			'is_pro'            => Plugin::is_pro(),
			'gated_features'    => $features,
			'amazon'            => self::get_amazon_status(),
			'card_stats'        => self::get_card_stats(),
		];
	}

	/**
	 * Get ad network detection info.
	 *
	 * @return array
	 */
	private static function get_ad_network_info() {
		$mcp_active   = Plugin_Checker::is_mcp_active();
		$mcp_site_id  = get_option( 'MVCP_site_id', '' );
		$is_journey   = Plugin_Checker::is_journey_site();
		$has_mv_ads   = Plugin_Checker::has_mv_ads();

		$network = 'none';
		if ( $mcp_active && $mcp_site_id ) {
			$network = 'mediavine';
		} elseif ( $is_journey ) {
			$network = 'raptive';
		}

		return [
			'network'              => $network,
			'has_mv_ads'           => $has_mv_ads,
			'mcp_installed'        => $mcp_active,
			'mcp_authenticated'    => $mcp_active && ! empty( $mcp_site_id ),
			'mcp_site_id'          => ! empty( $mcp_site_id ) ? $mcp_site_id : null,
			'journey_site'         => $is_journey,
			'grow_site_uuid'       => get_option( 'grow_site_uuid', '' ) ?: null,
		];
	}

	/**
	 * Get caching plugin detection info.
	 *
	 * @return array
	 */
	private static function get_caching_info() {
		$detected = [];

		if ( function_exists( 'remove_page_cache_by_post_id' ) ) {
			$detected[] = 'Cachify';
		}
		if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
			$detected[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_fast_cache_build_url_from_file' ) ) {
			$detected[] = 'WP Fast Cache';
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			$detected[] = 'WP Fastest Cache';
		}
		if ( class_exists( '\WebSharks\CometCache\Classes\ApiBase' ) ) {
			$detected[] = 'Comet Cache';
		}
		if ( file_exists( WP_CONTENT_DIR . '/wp-cache-config.php' ) && function_exists( 'wpsc_delete_post_cache' ) ) {
			$detected[] = 'WP Super Cache';
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			$detected[] = 'WP Rocket';
		}
		if ( method_exists( 'LiteSpeed_Cache_API', 'purge' ) ) {
			$detected[] = 'LiteSpeed Cache';
		}
		if ( class_exists( 'Autoptimize' ) ) {
			$detected[] = 'Autoptimize';
		}

		// Object cache detection.
		$object_cache = wp_using_ext_object_cache();

		return [
			'plugins'      => $detected,
			'count'        => count( $detected ),
			'object_cache' => $object_cache,
		];
	}

	/**
	 * Get known plugin/theme conflicts that are actively mitigated.
	 *
	 * @return array
	 */
	private static function get_known_conflicts() {
		$conflicts = [];

		if ( class_exists( 'WP_Rocket\Plugin' ) ) {
			$conflicts[] = [
				'plugin'     => 'WP Rocket',
				'issue'      => 'JS minification combines inline settings',
				'mitigated'  => true,
			];
		}

		if ( class_exists( 'ET_Bloom' ) ) {
			$conflicts[] = [
				'plugin'     => 'Bloom (Elegant Themes)',
				'issue'      => 'Popup display interferes with card preview/print',
				'mitigated'  => true,
			];
		}

		if ( function_exists( 'wp_access_helper_create_container' ) ) {
			$conflicts[] = [
				'plugin'     => 'WP Accessibility Helper',
				'issue'      => 'Footer container interferes with card rendering',
				'mitigated'  => true,
			];
		}

		if ( class_exists( '\SimpleRecipePro\Recipes_Schema' ) ) {
			$conflicts[] = [
				'plugin'     => 'Simple Recipe Pro',
				'issue'      => 'Duplicate JSON-LD schema output',
				'mitigated'  => true,
			];
		}

		if ( class_exists( 'WPSEO_Premium' ) ) {
			$conflicts[] = [
				'plugin'     => 'Yoast SEO Premium',
				'issue'      => 'May cause redirect conflicts with Create post slugs',
				'mitigated'  => true,
			];
		}

		return $conflicts;
	}

	/**
	 * Detect competing recipe plugins by checking for known post types, tables, and classes.
	 *
	 * @return array
	 */
	private static function get_competing_recipe_plugins() {
		global $wpdb;

		$detected = [];

		// Post type based detection.
		$recipe_post_types = [
			'wprm_recipe'    => 'WP Recipe Maker',
			'tasty_recipe'   => 'Tasty Recipes',
			'cookbook_recipe' => 'Cookbook',
			'recipe'         => 'WP Ultimate Recipe',
		];

		foreach ( $recipe_post_types as $post_type => $name ) {
			if ( post_type_exists( $post_type ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
						$post_type
					)
				);
				$detected[] = [
					'name'   => $name,
					'method' => 'post_type',
					'count'  => (int) $count,
				];
			}
		}

		// Table based detection.
		$recipe_tables = [
			'amd_yrecipe_recipes'  => 'Yummly',
			'amd_zlrecipe_recipes' => 'ZipList / Zip Recipes',
			'mpprecipe_recipes'    => 'Meal Planner Pro',
		];

		foreach ( $recipe_tables as $table => $name ) {
			$full_table = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table )
			);
			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$detected[] = [
					'name'   => $name,
					'method' => 'database_table',
					'count'  => (int) $count,
				];
			}
		}

		// Class based detection (for plugins that may not have data yet).
		$recipe_classes = [
			'EasyRecipe'                 => 'EasyRecipe',
			'SimpleRecipePro\Plugin'     => 'Simple Recipe Pro',
			'Jegtflavor\Recipe_Post'     => 'Jegtflavor/Flavor',
		];

		foreach ( $recipe_classes as $class => $name ) {
			if ( class_exists( $class ) ) {
				$detected[] = [
					'name'   => $name,
					'method' => 'class_exists',
					'count'  => null,
				];
			}
		}

		return $detected;
	}

	/**
	 * Get system requirement checks.
	 *
	 * @return array
	 */
	private static function get_system_checks() {
		$checks = [];

		// Pretty permalinks.
		$permalink_structure = get_option( 'permalink_structure', '' );
		$checks['permalinks'] = [
			'ok'      => ! empty( $permalink_structure ),
			'value'   => $permalink_structure ?: '(plain)',
			'message' => empty( $permalink_structure ) ? 'Pretty permalinks required for REST API' : null,
		];

		// PHP extensions.
		$checks['php_mbstring'] = [
			'ok'    => extension_loaded( 'mbstring' ),
			'value' => extension_loaded( 'mbstring' ) ? 'loaded' : 'missing',
		];
		$checks['php_xml'] = [
			'ok'    => extension_loaded( 'xml' ),
			'value' => extension_loaded( 'xml' ) ? 'loaded' : 'missing',
		];
		$checks['php_openssl'] = [
			'ok'    => extension_loaded( 'openssl' ),
			'value' => extension_loaded( 'openssl' ) ? 'loaded' : 'missing',
		];

		// DB version mismatch.
		$stored_db_version = get_option( 'mv_create_db_version', '' );
		$checks['db_version'] = [
			'ok'      => $stored_db_version === Plugin::DB_VERSION,
			'value'   => $stored_db_version ?: '(not set)',
			'expected' => Plugin::DB_VERSION,
			'message' => $stored_db_version !== Plugin::DB_VERSION ? 'DB version mismatch — migration may be pending' : null,
		];

		return $checks;
	}

	/**
	 * Get Amazon PA-API integration status.
	 *
	 * @return array
	 */
	private static function get_amazon_status() {
		$prefix  = Plugin::$settings_group;
		$enabled = Settings::get_setting( $prefix . '_enable_amazon' );
		$has_key = ! empty( Settings::get_setting( $prefix . '_paapi_access_key' ) );
		$has_sec = ! empty( Settings::get_setting( $prefix . '_paapi_secret_key' ) );
		$has_tag = ! empty( Settings::get_setting( $prefix . '_paapi_tag' ) );

		return [
			'enabled'            => ! empty( $enabled ),
			'credentials_set'    => $has_key && $has_sec && $has_tag,
			'has_access_key'     => $has_key,
			'has_secret_key'     => $has_sec,
			'has_store_tag'      => $has_tag,
		];
	}

	/**
	 * Get Create card counts by type.
	 *
	 * @return array
	 */
	private static function get_card_stats() {
		global $wpdb;

		$table = $wpdb->prefix . 'mv_creations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			return [ 'total' => 0 ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results(
			"SELECT type, COUNT(*) as count FROM `{$table}` GROUP BY type",
			ARRAY_A
		);

		$stats = [ 'total' => 0 ];
		if ( $counts ) {
			foreach ( $counts as $row ) {
				$stats[ $row['type'] ] = (int) $row['count'];
				$stats['total']       += (int) $row['count'];
			}
		}

		return $stats;
	}

	/**
	 * Check for client build version mismatch.
	 *
	 * Finds a published post with a Create card, fetches its HTML, and looks for
	 * the client script tag to verify the version matches the installed plugin.
	 *
	 * @return array
	 */
	private static function check_client_version() {
		global $wpdb;

		$table = $wpdb->prefix . 'mv_creations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			return [ 'error' => 'Creations table not found' ];
		}

		// Find a published post with a Create card.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_id = $wpdb->get_var(
			"SELECT c.canonical_post_id
			 FROM `{$table}` c
			 INNER JOIN {$wpdb->posts} p ON c.canonical_post_id = p.ID
			 WHERE c.canonical_post_id IS NOT NULL
			   AND c.canonical_post_id > 0
			   AND p.post_status = 'publish'
			 ORDER BY c.id DESC
			 LIMIT 1"
		);

		if ( ! $post_id ) {
			return [ 'error' => 'No published post with a Create card found' ];
		}

		$post_url = get_permalink( (int) $post_id );
		if ( ! $post_url ) {
			return [ 'error' => 'Could not get permalink for post ' . $post_id ];
		}

		// Build a loopback-safe URL for self-requests.
		// In containerized environments (Docker), the site URL may use a host:port
		// that's only reachable from the host machine. Rewrite to 127.0.0.1 on the
		// internal server port and pass the original Host header so WordPress
		// still resolves the request correctly.
		$parsed    = wp_parse_url( $post_url );
		$host      = isset( $parsed['host'] ) ? $parsed['host'] : 'localhost';
		$port      = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$host_header = $host . $port;

		// Replace scheme + host + port with a loopback address on port 80.
		$loopback_url = preg_replace(
			'#^https?://[^/]+#',
			'http://127.0.0.1',
			$post_url
		);

		// Fetch the page HTML using the loopback URL.
		$response = wp_remote_get(
			$loopback_url,
			[
				'timeout'    => 15,
				'user-agent' => 'Create-Debug-Bot/1.0',
				'sslverify'  => false,
				'headers'    => [
					'Host' => $host_header,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'error'    => 'Failed to fetch page: ' . $response->get_error_message(),
				'post_url' => $post_url,
			];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$html        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return [
				'error'       => 'Page returned HTTP ' . $status_code,
				'post_url'    => $post_url,
				'status_code' => $status_code,
			];
		}

		// Look for the Create client bundle script tag.
		// Pattern: bundle.X.Y.Z.js (with or without path prefix).
		$expected_version = Plugin::VERSION;
		$result           = [
			'post_url'         => $post_url,
			'post_id'          => (int) $post_id,
			'plugin_version'   => $expected_version,
			'client_version'   => null,
			'mismatch'         => false,
			'script_found'     => false,
			'script_url'       => null,
		];

		// Match the Create client bundle script.
		if ( preg_match( '/bundle\.([0-9]+\.[0-9]+\.[0-9]+[^"\']*?)\.js/i', $html, $matches ) ) {
			$result['script_found']   = true;
			$result['client_version'] = $matches[1];
			$result['mismatch']       = ( $matches[1] !== $expected_version );
		}

		// Also try to find the full script src for more context.
		if ( preg_match( '/src=["\']([^"\']*bundle\.[0-9]+\.[0-9]+\.[0-9]+[^"\']*\.js)["\']/', $html, $src_matches ) ) {
			$result['script_url'] = $src_matches[1];
		}

		// Check if the script URL returns a 404 (only if mismatch detected).
		if ( $result['mismatch'] && $result['script_url'] ) {
			$script_response = wp_remote_head(
				$result['script_url'],
				[
					'timeout'    => 5,
					'sslverify'  => false,
				]
			);

			if ( ! is_wp_error( $script_response ) ) {
				$result['script_status'] = wp_remote_retrieve_response_code( $script_response );
			}
		}

		return $result;
	}

	/**
	 * Handle the "subscription_change" webhook type.
	 *
	 * Updates the local subscription tier setting to match Studio.
	 *
	 * @param array $data The webhook data containing 'tier'.
	 * @return \WP_REST_Response
	 */
	private static function handle_subscription_change( array $data ) {
		if ( empty( $data['tier'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing tier in data' ], 400 );
		}

		$tier        = $data['tier'];
		$valid_tiers = [ GateKeeper::TIER_FREE, GateKeeper::TIER_PRO, GateKeeper::TIER_FREE_PLUS ];

		if ( ! in_array( $tier, $valid_tiers, true ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid tier' ], 400 );
		}

		// Update subscription tier using the same pattern as GateKeeper::sync_subscription_tier().
		Settings::create_settings(
			[
				'slug'  => GateKeeper::SETTING_SUBSCRIPTION_TIER,
				'value' => $tier,
				'group' => 'mv_create_subscription',
			]
		);

		Settings::create_settings(
			[
				'slug'  => GateKeeper::SETTING_SUBSCRIPTION_SYNCED_AT,
				'value' => gmdate( 'c' ),
				'group' => 'mv_create_subscription',
			]
		);

		Settings::reset_settings();

		// If downgrading, switch gated themes to the fallback.
		GateKeeper::enforce_feature_fallbacks();

		return new \WP_REST_Response( [ 'success' => true, 'tier' => $tier ], 200 );
	}

	/**
	 * Allowed settings that can be updated via webhook from Create Studio.
	 *
	 * Maps Studio payload keys to Create setting slugs and sanitization callbacks.
	 *
	 * @var array
	 */
	private static $allowed_webhook_settings = [
		'interactive_mode_enabled' => [
			'slug'     => 'mv_create_enable_interactive_mode',
			'sanitize' => 'boolval',
		],
		'interactive_mode_button_text' => [
			'slug'     => 'mv_create_interactive_mode_button_text',
			'sanitize' => 'sanitize_text_field',
		],
		'interactive_mode_cta_variant' => [
			'slug'     => 'mv_create_interactive_mode_cta_variant',
			'sanitize' => 'sanitize_text_field',
		],
		'interactive_mode_cta_title' => [
			'slug'     => 'mv_create_interactive_mode_cta_title',
			'sanitize' => 'sanitize_text_field',
		],
		'interactive_mode_cta_subtitle' => [
			'slug'     => 'mv_create_interactive_mode_cta_subtitle',
			'sanitize' => 'sanitize_text_field',
		],
	];

	/**
	 * Handle the "settings_update" webhook type.
	 *
	 * Updates local plugin settings based on values pushed from Create Studio.
	 * Only allows explicitly whitelisted settings to be updated.
	 *
	 * @param array $data The webhook data containing setting key-value pairs.
	 * @return \WP_REST_Response
	 */
	private static function handle_settings_update( array $data ) {
		if ( empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing or invalid settings in data' ], 400 );
		}

		// Set guard flag to prevent sync loops.
		GateKeeper::$syncing_from_studio = true;

		$updated = [];

		foreach ( $data['settings'] as $key => $value ) {
			if ( ! isset( self::$allowed_webhook_settings[ $key ] ) ) {
				continue;
			}

			$config   = self::$allowed_webhook_settings[ $key ];
			$slug     = $config['slug'];
			$sanitize = $config['sanitize'];

			// For boolval, convert to the format Create settings expect (truthy string or empty).
			if ( 'boolval' === $sanitize ) {
				$sanitized = $value ? '1' : '';
			} else {
				$sanitized = call_user_func( $sanitize, $value );
			}

			Settings::update_setting( $slug, $sanitized );
			$updated[ $key ] = $sanitized;
		}

		// Clear guard flag.
		GateKeeper::$syncing_from_studio = false;

		if ( empty( $updated ) ) {
			return new \WP_REST_Response( [ 'error' => 'No recognized settings in payload' ], 400 );
		}

		return new \WP_REST_Response( [ 'success' => true, 'updated' => $updated ], 200 );
	}

	/**
	 * Fetch the Studio public key, using a cached transient.
	 *
	 * @return string|false The PEM public key string, or false on failure.
	 */
	private static function get_public_key() {
		$cached = get_transient( self::PUBLIC_KEY_TRANSIENT );
		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$url      = Plugin::$services_api_url . '/webhooks/public-key';
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['key'] ) ) {
			return false;
		}

		$key = $body['key'];
		set_transient( self::PUBLIC_KEY_TRANSIENT, $key, DAY_IN_SECONDS );

		return $key;
	}
}
