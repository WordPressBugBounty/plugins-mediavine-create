<?php
namespace Mediavine\Create;

use Mediavine\Settings;

class Data_Sync extends Plugin {

	private static $instance = null;

	private static $data_sync_run = false;

	/**
	 * Makes sure class is only instantiated once
	 *
	 * @return object Instantiated class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Hooks to be run on class instantiation
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'mv_create_plugin_activated', [ $this, 'sync_data' ], 10 );
		add_action( 'mv_create_plugin_activated', [ $this, 'schedule_data_sync' ], 20 );
		add_action( 'mv_create_plugin_updated', [ $this, 'sync_data' ], 20 );
		add_action( 'mv_create_sync_data', [ $this, 'sync_data' ] );
		add_action( 'mv_create_plugin_deactivated', [ $this, 'sync_data' ], 10 );
		add_action( 'mv_create_plugin_deactivated', [ $this, 'remove_scheduled_data_sync' ], 20 );
	}

	/**
	 * Schedules data sync to run every 12 hours
	 *
	 * @return void
	 */
	public function schedule_data_sync() {
		// Make sure who don't already have something scheduled
		if ( ! wp_next_scheduled( 'mv_create_sync_data' ) ) {
			// Because we are also running data sync on every activation,
			// we wait 12 hours before running the first scheduled event
			wp_schedule_event( time() + HOUR_IN_SECONDS * 12, 'twicedaily', 'mv_create_sync_data' );
		}
	}

	/**
	 * Removes Schedules data sync event
	 *
	 * @return void
	 */
	public function remove_scheduled_data_sync() {
		wp_clear_scheduled_hook( 'mv_create_sync_data' );
	}

	/**
	 * Get MCP site id
	 *
	 * @return  string|null|false  Site id if exists and MCP active; false if doesn't exist; null if MCP not active
	 */
	public function get_mcp_site_id() {
		$mcp_site_id = null;
		if ( $this::$mcp_enabled ) {
			$mcp_site_id = get_option( 'MVCP_site_id', false );
		}

		return $mcp_site_id;
	}

	/**
	 * Retrieves site domain and removes www if it exists
	 *
	 * @return string
	 */
	public function get_site_domain() {
		// We require access to $_SERVER['HTTP_HOST'] to get this data if needed
		// phpcs:disable
		$site_domain = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		// phpcs:enable

		// Use WP site url fallback if server blocking HTTP_HOST
		if ( empty( $site_domain ) ) {
			$parsed_url = parse_url( site_url() );
			if ( ! empty( $parsed_url['host'] ) ) {
				$site_domain = $parsed_url['host'];
			}
		}

		if ( strpos( $site_domain, 'www.' ) === 0 ) {
			$site_domain = substr( $site_domain, 4 );
		}

		return $site_domain;
	}

	/**
	 * Syncs site and plugin data with Mediavine Product Data Sync API
	 *
	 * @return void
	 */
	public function sync_data() {
		// Check if the data sync has already run
		if ( $this::$data_sync_run ) {
			return;
		}

		// We can only run this if they have registered, due to WP plugin repo guidelines.
		// In our [TOC](https://www.mediavine.com/create-terms-of-service/), that must be
		// accepted for registration, we state "By using the Plugin, you consent to our
		// collection and use of personal data as outlined therein."
		if ( empty( Settings::get_setting( 'mv_create_api_email_confirmed', false ) ) ) {
			return;
		}

		global $wp_version;

		// Find what action was run
		$action_run = null;
		if ( did_action( 'mv_create_sync_data' ) ) {
			$action_run = 'scheduled_event';
		}
		if ( empty( $action_run ) && did_action( 'mv_create_plugin_updated' ) ) {
			$action_run = 'plugin_updated';
		}
		if ( empty( $action_run ) && did_action( 'mv_create_plugin_activated' ) ) {
			$action_run = 'plugin_activated';
		}
		if ( empty( $action_run ) && did_action( 'mv_create_plugin_deactivated' ) ) {
			$action_run = 'plugin_deactivated';
		}

		$site_domain = $this->get_site_domain();

		// Get admin email addresses
		$admins       = get_users( [ 'role__in' => [ 'administrator' ] ] );
		$admin_emails = wp_list_pluck( $admins, 'user_email' );

		// Build data array for sync
		$data_to_send = [
			'action_run'          => $action_run,
			'php_version'         => PHP_VERSION,
			'wp_version'          => $wp_version,
			// We quickly want to pull the db version, which is not easily pulled from $wpdb
			// phpcs:disable
			'mysql_version' => mysqli_get_client_info(),
			// phpcs:enable
			'email'               => wp_get_current_user()->user_email,
			'create_license'      => Settings::get_setting( 'mv_create_api_token' ),
			'create_license_user' => Settings::get_setting( 'mv_create_api_user_id' ),
			'create_version'      => $this::VERSION,
			'create_jtr_enabled'  => (bool) Settings::get_setting( 'mv_create_enable_jump_to_recipe', false ),
			'plugin_name'         => 'Create by Mediavine',
			'plugin_slug'         => 'mediavine-create',
			'plugin_dir'          => dirname( plugin_basename( $this::get_activation_path() ) ),
			'site_url'            => site_url(),
			'site_domain'         => $site_domain,
			'site_admin_emails'   => $admin_emails,
			'mcp_status'          => $this::$mcp_enabled,
			'mcp_site_id'         => $this->get_mcp_site_id(),
		];

		$data_sync = wp_remote_post(
			'https://product-data-sync.herokuapp.com/record', [
				'headers' => [
					'Content-Type' => 'application/json; charset=utf-8',
				],
				'body'    => wp_json_encode( $data_to_send ),
			]
		);

		// Prevent duplicate data syncs from running
		if ( ! empty( $data_sync ) ) {
			$this::$data_sync_run = true;
		}
	}
}

