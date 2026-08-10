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
	 * Transient key gating how often a signature failure may force a key re-fetch.
	 */
	const KEY_REFRESH_LOCK_TRANSIENT = 'mv_create_studio_key_refresh_lock';

	/**
	 * Minimum seconds between signature-failure-triggered key re-fetches.
	 */
	const KEY_REFRESH_COOLDOWN = 300;

	/**
	 * Transient key for backing off after a failed public-key fetch.
	 */
	const KEY_FETCH_BACKOFF_TRANSIENT = 'mv_create_studio_key_fetch_backoff';

	/**
	 * Seconds to skip public-key fetches after one fails.
	 */
	const KEY_FETCH_BACKOFF = 60;

	/**
	 * Option key for the bounded list of recently processed event IDs.
	 */
	const PROCESSED_EVENTS_OPTION = 'mv_create_webhook_processed_events';

	/**
	 * Maximum number of processed event IDs retained for idempotency.
	 */
	const MAX_PROCESSED_EVENTS = 500;

	/**
	 * Maximum age of a webhook timestamp before it's rejected (seconds).
	 */
	const MAX_TIMESTAMP_AGE = 300;

	/**
	 * Current signed envelope version.
	 */
	const ENVELOPE_VERSION = 1;

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
				'permission_callback' => [ \Mediavine\Permissions::class, 'allow_public' ],
			]
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * Supports the v1 signed envelope
	 * `{version, event_id, site_id, issued_at, type, payload}` and the legacy
	 * `{type, data}` body (replay window via unauthenticated X-Studio-Timestamp).
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook( \WP_REST_Request $request ) {
		$body      = $request->get_body();
		$signature = $request->get_header( 'X-Studio-Signature' );

		if ( empty( $signature ) ) {
			return new \WP_REST_Response( [ 'error' => 'Missing signature or timestamp' ], 400 );
		}

		$decoded = json_decode( $body, true );
		$is_v1   = is_array( $decoded ) && self::is_v1_envelope( $decoded );

		// Legacy envelopes still rely on the (unauthenticated) timestamp header.
		// Check it before crypto so missing-header clients get a clear 400.
		if ( ! $is_v1 ) {
			$timestamp = $request->get_header( 'X-Studio-Timestamp' );
			if ( empty( $timestamp ) ) {
				return new \WP_REST_Response( [ 'error' => 'Missing signature or timestamp' ], 400 );
			}

			if ( abs( time() - intval( $timestamp ) ) > self::MAX_TIMESTAMP_AGE ) {
				return new \WP_REST_Response( [ 'error' => 'Timestamp too old' ], 401 );
			}
		}

		$sig_decoded = base64_decode( $signature, true );
		if ( false === $sig_decoded ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid signature encoding' ], 401 );
		}

		$verified = self::verify_signature( $body, $sig_decoded );
		if ( is_wp_error( $verified ) ) {
			$status = 'unable_to_fetch_public_key' === $verified->get_error_code() ? 500 : 401;
			return new \WP_REST_Response( [ 'error' => $verified->get_error_message() ], $status );
		}

		if ( ! is_array( $decoded ) || empty( $decoded['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		if ( $is_v1 ) {
			return self::handle_v1_envelope( $decoded );
		}

		return self::dispatch( $decoded );
	}

	/**
	 * Whether the decoded body is a v1 signed envelope.
	 *
	 * @param array $decoded Decoded JSON body.
	 * @return bool
	 */
	private static function is_v1_envelope( array $decoded ) {
		if ( isset( $decoded['version'] ) && (int) $decoded['version'] >= self::ENVELOPE_VERSION ) {
			return true;
		}

		return isset( $decoded['event_id'], $decoded['site_id'], $decoded['issued_at'] );
	}

	/**
	 * Validate and dispatch a v1 signed envelope.
	 *
	 * @param array $envelope Decoded v1 envelope.
	 * @return \WP_REST_Response
	 */
	private static function handle_v1_envelope( array $envelope ) {
		if ( empty( $envelope['event_id'] ) || ! isset( $envelope['site_id'] ) || empty( $envelope['issued_at'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid envelope' ], 400 );
		}

		if ( abs( time() - intval( $envelope['issued_at'] ) ) > self::MAX_TIMESTAMP_AGE ) {
			return new \WP_REST_Response( [ 'error' => 'Timestamp too old' ], 401 );
		}

		// Only Studio can reach this point (the signature already verified), so
		// logging here can't be spammed by anonymous traffic and a rejection is
		// always worth a line: it means Studio and this site disagree about who
		// this site is, and every webhook will keep failing until that's fixed.
		$local_site_id = Create_Studio_Client::get_site_id();
		if ( empty( $local_site_id ) ) {
			// The site token is missing, cleared, or not a parseable JWT — the
			// site can't prove its own identity, so the envelope is refused and
			// Studio's queue will retry until the connection is repaired.
			Help::log(
				sprintf(
					'Create Studio webhook rejected: local site ID is unresolvable (event_id=%s, envelope site_id=%s). The mv_create_api_token setting is missing or malformed — reconnect the site to Create Studio.',
					isset( $envelope['event_id'] ) ? (string) $envelope['event_id'] : '(none)',
					(string) $envelope['site_id']
				)
			);

			return new \WP_REST_Response( [ 'error' => 'Site mismatch' ], 401 );
		}

		if ( (string) $envelope['site_id'] !== (string) $local_site_id ) {
			Help::log(
				sprintf(
					'Create Studio webhook rejected: envelope addressed to site %s but this site is %s (event_id=%s).',
					(string) $envelope['site_id'],
					(string) $local_site_id,
					isset( $envelope['event_id'] ) ? (string) $envelope['event_id'] : '(none)'
				)
			);

			return new \WP_REST_Response( [ 'error' => 'Site mismatch' ], 401 );
		}

		$event_id = (string) $envelope['event_id'];
		if ( self::has_processed_event( $event_id ) ) {
			return new \WP_REST_Response(
				[
					'status'   => 'duplicate',
					'event_id' => $event_id,
				],
				200
			);
		}

		// Prefer `payload` (v1); fall back to legacy `data` when Studio dual-writes.
		$inner = [];
		if ( isset( $envelope['payload'] ) && is_array( $envelope['payload'] ) ) {
			$inner = $envelope['payload'];
		} elseif ( isset( $envelope['data'] ) && is_array( $envelope['data'] ) ) {
			$inner = $envelope['data'];
		}

		$response = self::dispatch(
			[
				'type' => $envelope['type'],
				'data' => $inner,
			]
		);

		// Only record idempotency after a successful (2xx) dispatch so transient
		// failures can be retried by Studio's queue.
		if ( $response->get_status() >= 200 && $response->get_status() < 300 ) {
			self::record_processed_event( $event_id );
		}

		return $response;
	}

	/**
	 * Verify an RS256 signature, refreshing the cached public key once on failure.
	 *
	 * @param string $body        Raw request body.
	 * @param string $sig_decoded Binary signature.
	 * @return true|\WP_Error
	 */
	private static function verify_signature( $body, $sig_decoded ) {
		$public_key = self::get_public_key();
		if ( ! $public_key ) {
			return new \WP_Error( 'unable_to_fetch_public_key', 'Unable to fetch public key' );
		}

		$verified = openssl_verify( $body, $sig_decoded, $public_key, OPENSSL_ALGO_SHA256 );
		if ( 1 === $verified ) {
			return true;
		}

		// Stale cached key after rotation: re-fetch once and re-verify.
		//
		// This endpoint is public and a bogus signature is indistinguishable from
		// a rotation mismatch, so the re-fetch is rate limited — otherwise every
		// garbage POST would tie up a PHP worker on a blocking outbound request.
		if ( get_transient( self::KEY_REFRESH_LOCK_TRANSIENT ) ) {
			return new \WP_Error( 'invalid_signature', 'Invalid signature' );
		}
		set_transient( self::KEY_REFRESH_LOCK_TRANSIENT, 1, self::KEY_REFRESH_COOLDOWN );

		$public_key = self::get_public_key( true );
		if ( $public_key ) {
			$verified = openssl_verify( $body, $sig_decoded, $public_key, OPENSSL_ALGO_SHA256 );
			if ( 1 === $verified ) {
				// Rotation confirmed: let the next mismatch re-fetch immediately.
				delete_transient( self::KEY_REFRESH_LOCK_TRANSIENT );
				return true;
			}
		}

		return new \WP_Error( 'invalid_signature', 'Invalid signature' );
	}

	/**
	 * Whether an event_id was already processed successfully.
	 *
	 * @param string $event_id Event identifier.
	 * @return bool
	 */
	private static function has_processed_event( $event_id ) {
		$events = get_option( self::PROCESSED_EVENTS_OPTION, [] );
		if ( ! is_array( $events ) ) {
			return false;
		}

		return isset( $events[ $event_id ] );
	}

	/**
	 * Record a successfully processed event_id in a bounded map.
	 *
	 * @param string $event_id Event identifier.
	 */
	private static function record_processed_event( $event_id ) {
		$events = get_option( self::PROCESSED_EVENTS_OPTION, [] );
		if ( ! is_array( $events ) ) {
			$events = [];
		}

		$events[ $event_id ] = time();

		if ( count( $events ) > self::MAX_PROCESSED_EVENTS ) {
			asort( $events );
			$events = array_slice( $events, -1 * self::MAX_PROCESSED_EVENTS, null, true );
		}

		update_option( self::PROCESSED_EVENTS_OPTION, $events, false );
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

			case 'unit_conversion_refresh':
				return self::handle_unit_conversion_refresh( $data );

			default:
				return new \WP_REST_Response( [ 'error' => 'Unknown webhook type' ], 400 );
		}
	}

	/**
	 * Handle a "unit_conversion_refresh" webhook.
	 *
	 * Forces a re-fetch of the creation's unit conversions from Studio,
	 * rebuilding the cached metadata blob with the current schema version.
	 * Sent by Studio when a widget on the publisher's page detects that the
	 * PHP-emitted data-cs-config.unitConversion.version trails its expected
	 * schema version — the webhook is the firewall-friendly back-channel
	 * (signed RS256, recognized UA) that the in-page widget can't safely
	 * do directly with a cross-origin POST into WP-REST.
	 *
	 * Throttled by a 60-second transient lock per creation so concurrent
	 * widget signals from many readers coalesce to a single refresh.
	 *
	 * @param array $data { creation_id: int }
	 * @return \WP_REST_Response
	 */
	private static function handle_unit_conversion_refresh( array $data ) {
		$creation_id = isset( $data['creation_id'] ) ? (int) $data['creation_id'] : 0;
		if ( $creation_id <= 0 ) {
			return new \WP_REST_Response( [ 'error' => 'Missing or invalid creation_id' ], 400 );
		}

		$lock_key = 'cs_uc_refresh_lock_' . $creation_id;
		if ( get_transient( $lock_key ) ) {
			return new \WP_REST_Response( [ 'status' => 'throttled', 'creation_id' => $creation_id ], 200 );
		}
		set_transient( $lock_key, 1, 60 );

		$converter = Unit_Conversion::get_instance();
		$result    = $converter->convert_creation( $creation_id, true );

		if ( is_wp_error( $result ) ) {
			delete_transient( $lock_key );
			return new \WP_REST_Response(
				[
					'error'       => $result->get_error_message(),
					'creation_id' => $creation_id,
				],
				500
			);
		}

		return new \WP_REST_Response(
			[
				'status'      => 'refreshed',
				'creation_id' => $creation_id,
				'version'     => isset( $result['version'] ) ? (int) $result['version'] : null,
			],
			200
		);
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
						// Never ship credential-class values to Studio over the webhook.
						'settings'      => Sensitive_Settings::redact( Settings::get_settings(), false ),
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
			'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
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

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$read_size = min( $chunk, $remaining );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked streaming of the local debug.log to output; WP_Filesystem has no streaming read API.
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the debug.log handle opened for streaming above.
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
		if ( class_exists( '\LiteSpeed\Purge' ) || class_exists( 'LiteSpeed_Cache_API' ) ) {
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
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table )
			);
			if ( $exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table is $wpdb->prefix . allowlisted literal; COUNT(*) has no user input
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
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
	 * Get Amazon Creators API integration status.
	 *
	 * @return array
	 */
	private static function get_amazon_status() {
		$prefix     = Plugin::$settings_group;
		$enabled    = Settings::get_setting( $prefix . '_enable_amazon' );
		$has_id     = ! empty( Settings::get_setting( $prefix . '_creators_credential_id' ) );
		$has_secret = ! empty( Settings::get_setting( $prefix . '_creators_credential_secret' ) );
		$has_tag    = ! empty( Settings::get_setting( $prefix . '_paapi_tag' ) );

		return [
			'enabled'         => ! empty( $enabled ),
			'credentials_set' => $has_id && $has_secret && $has_tag,
			'has_credential_id'     => $has_id,
			'has_credential_secret' => $has_secret,
			'has_store_tag'         => $has_tag,
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			return [ 'total' => 0 ];
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table is $wpdb->prefix . literal; no user input in SQL
		$counts = $wpdb->get_results(
			"SELECT type, COUNT(*) as count FROM `{$table}` GROUP BY type",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			return [ 'error' => 'Creations table not found' ];
		}

		// Find a published post with a Create card.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table is $wpdb->prefix . literal; joins core $wpdb->posts; no user input
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

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
		// External URL — keep TLS verification enabled (unlike the localhost loopback above).
		if ( $result['mismatch'] && $result['script_url'] ) {
			$script_response = wp_remote_head(
				$result['script_url'],
				[
					'timeout' => 5,
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
		$valid_tiers = [ GateKeeper::TIER_FREE, GateKeeper::TIER_PRO, GateKeeper::TIER_FREE_PLUS, GateKeeper::TIER_TRIAL ];

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

		// Store trial fields from enhanced webhook payload.
		$is_trialing = ! empty( $data['is_trialing'] );
		Settings::create_settings(
			[
				'slug'  => GateKeeper::SETTING_IS_TRIALING,
				'value' => $is_trialing ? '1' : '',
				'group' => 'mv_create_subscription',
			]
		);
		Settings::create_settings(
			[
				'slug'  => GateKeeper::SETTING_TRIAL_DAYS_REMAINING,
				'value' => isset( $data['trial_days_remaining'] ) ? (int) $data['trial_days_remaining'] : 0,
				'group' => 'mv_create_subscription',
			]
		);
		Settings::create_settings(
			[
				'slug'  => GateKeeper::SETTING_TRIAL_END,
				'value' => isset( $data['trial_end'] ) ? $data['trial_end'] : '',
				'group' => 'mv_create_subscription',
			]
		);

		// Store active paid count if provided.
		if ( isset( $data['active_paid_count'] ) ) {
			Settings::create_settings(
				[
					'slug'  => GateKeeper::SETTING_ACTIVE_PAID_COUNT,
					'value' => (int) $data['active_paid_count'],
					'group' => 'mv_create_subscription',
				]
			);
		}

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

		try {
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
		} finally {
			// Always clear guard flag, even if an exception occurs.
			GateKeeper::$syncing_from_studio = false;
		}

		if ( empty( $updated ) ) {
			return new \WP_REST_Response( [ 'error' => 'No recognized settings in payload' ], 400 );
		}

		return new \WP_REST_Response( [ 'success' => true, 'updated' => $updated ], 200 );
	}

	/**
	 * Fetch the Studio public key, using a cached transient.
	 *
	 * @param bool $force_refresh When true, bypass the cache and re-fetch. The
	 *                           cached key is only replaced on a successful fetch,
	 *                           so a failed refresh can't leave the site keyless.
	 * @return string|false The PEM public key string, or false on failure.
	 */
	private static function get_public_key( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::PUBLIC_KEY_TRANSIENT );
			if ( ! empty( $cached ) ) {
				return $cached;
			}
		}

		// Studio unreachable or serving a bad body: stop hammering it (and stop
		// blocking PHP workers) until the backoff window expires.
		if ( get_transient( self::KEY_FETCH_BACKOFF_TRANSIENT ) ) {
			return false;
		}

		$url      = Plugin::$services_api_url . '/webhooks/public-key';
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::KEY_FETCH_BACKOFF_TRANSIENT, 1, self::KEY_FETCH_BACKOFF );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['key'] ) ) {
			set_transient( self::KEY_FETCH_BACKOFF_TRANSIENT, 1, self::KEY_FETCH_BACKOFF );
			return false;
		}

		$key = $body['key'];
		set_transient( self::PUBLIC_KEY_TRANSIENT, $key, DAY_IN_SECONDS );
		delete_transient( self::KEY_FETCH_BACKOFF_TRANSIENT );

		return $key;
	}
}
