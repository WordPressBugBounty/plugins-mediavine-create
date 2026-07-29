<?php

namespace Mediavine;

defined( 'ABSPATH' ) || exit;

use Mediavine\Create\API_Services;
use Mediavine\Create\GateKeeper;
use Mediavine\Create\Plugin;
use Mediavine\Create\Theme_Checker;
use Mediavine\Create\Helpers\Arr;

if ( class_exists( 'Mediavine\Settings' ) ) {

	class Settings_API extends Settings {

		private $api_services = null;

		function __construct() {
			$this->api_services = API_Services::get_instance();
		}

		/**
		 * API Function to create Settings, capable of processing both bulk and singular items
		 *
		 * @param  \WP_REST_Request object request object via API
		 * @return \WP_REST_Response object for output as JSON data
		 */
		public function create( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;

			$sanitized = $request->sanitize_params();
			$params    = $request->get_params();

			if ( is_wp_error( $sanitized ) ) {
				$status_code        = 403;
				$response['errors'] = $this->api_services->normalize_errors(
					$response['errors'], $status_code, [
						'title'   => __( 'Unsafe Content Submission', 'mediavine-create' ),
						'details' => __( 'You\'re submission includes unsafe characters', 'mediavine-create' ),
					], 'error'
				);
				return new \WP_REST_Response( $response, $status_code );
			}

			// Handle special transient settings before processing
			$params = $this->handle_transient_settings( $params );

			// If only transient settings were processed, return success
			if ( isset( $params['_transients_processed'] ) ) {
				return new \WP_REST_Response( [ 'success' => true ], 200 );
			}

			$collection = [];

			if ( wp_is_numeric_array( $params ) ) {
				foreach ( $params as $setting ) {
					$stored = self::create_settings( $setting );
					if ( $stored ) {
						$stored       = self::extract( $stored );
						$collection[] = $this->api_services->prepare_item_for_response( $stored, $request );
					}
				}
			}

			if ( ! empty( $collection ) ) {
				$response    = [];
				$response    = $collection;
				$status_code = 201;
				return new \WP_REST_Response( $response, $status_code );
			}

			$stored = self::create_settings( $params );

			if ( $stored ) {
				$stored      = self::extract( $stored );
				$response    = [];
				$response    = $this->api_services->prepare_item_for_response( $stored, $request );
				$status_code = 201;
				return new \WP_REST_Response( $response, $status_code );
			}

			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * API Function to read Settings Collection
		 *
		 * @param  \WP_REST_Request object request object via API
		 * @return \WP_REST_Response object for output as JSON data
		 */
		public function read( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;

			$settings = self::$models->mv_settings->find( [ 'limit' => self::LOAD_LIMIT ] );

			if ( $settings ) {
				return $this->respond_with_collection( $settings, $request );
			}

			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * API function to read Settings Group
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response
		 */
		public function read_by_group( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;
			$params      = $request->get_params();
			$settings    = self::$models->mv_settings->find(
				[
					'limit' => self::LOAD_LIMIT,
					'where' => [
						'`group`' => $params['slug'],
					],
				]
			);

			if ( $settings ) {
				return $this->respond_with_collection( $settings, $request );
			}

			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * Build a redacted collection response for settings list routes.
		 *
		 * @param array            $settings Settings rows from the DB.
		 * @param \WP_REST_Request $request  Incoming request.
		 * @return \WP_REST_Response
		 */
		private function respond_with_collection( $settings, \WP_REST_Request $request ) {
			$collection = [];
			foreach ( $settings as $setting ) {
				$setting      = self::extract( $setting );
				$collection[] = $this->api_services->prepare_item_for_response( $setting, $request );
			}

			// These routes are manage_options-gated, so credential values are kept; the
			// site JWT is still reduced to a presence flag (never needed browser-side).
			$collection = \Mediavine\Create\Sensitive_Settings::redact( $collection, true );

			return new \WP_REST_Response( $collection, 200 );
		}

		/**
		 * API Function to read Single Settings by setting id
		 *
		 * @param  \WP_REST_Request object request object via API
		 * @return \WP_REST_Response object for output as JSON data
		 */
		public function read_single( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;

			$params     = $request->get_params();
			$setting_id = intval( $params['id'] );
			$setting    = self::$models->mv_settings->find_one(
				[
					'col' => 'id',
					'key' => $params['id'],
				]
			);

			if ( $setting ) {
				$setting     = self::extract( $setting );
				$response    = [];
				$response    = $this->api_services->prepare_item_for_response( $setting, $request );
				// Manage_options-gated, but the site JWT is still reduced to a presence flag
				// so it can't be lifted via the single-setting routes either.
				$response    = \Mediavine\Create\Sensitive_Settings::redact( [ $response ], true )[0];
				$status_code = 200;
				return new \WP_REST_Response( $response, $status_code );
			}

			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * API Function to read Single Settings by setting slug
		 *
		 * @param  \WP_REST_Request object request object via API
		 * @return \WP_REST_Response object for output as JSON data
		 */
		public function read_single_by_slug( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;

			$params  = $request->get_params();
			$setting = self::$models->mv_settings->find_one(
				[
					'col' => 'slug',
					'key' => $params['slug'],
				]
			);

			if ( $setting ) {
				$setting     = self::extract( $setting );
				$response    = [];
				$response    = $this->api_services->prepare_item_for_response( $setting, $request );
				// Manage_options-gated, but the site JWT is still reduced to a presence flag
				// so it can't be lifted via the single-setting routes either.
				$response    = \Mediavine\Create\Sensitive_Settings::redact( [ $response ], true )[0];
				$status_code = 200;
				return new \WP_REST_Response( $response, $status_code );
			}

			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * API Function to read update single Setting
		 *
		 * @param  \WP_REST_Request object request object via API
		 * @return \WP_REST_Response object for output as JSON data
		 */
		public function update_single( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;

			$params = $request->get_params();

			// Partial updates may omit slug; hydrate from the existing row so
			// sanitize_setting can apply slug-specific filters (and so we 404
			// cleanly when the id does not exist).
			if ( ! empty( $params['id'] ) && empty( $params['slug'] ) ) {
				$existing = self::$models->mv_settings->find_one(
					[
						'col' => 'id',
						'key' => $params['id'],
					]
				);
				if ( ! $existing ) {
					return new \WP_REST_Response( $response, $status_code );
				}
				$params['slug'] = $existing->slug;
				if ( empty( $params['group'] ) && ! empty( $existing->group ) ) {
					$params['group'] = $existing->group;
				}
			}

			// Funnel through create_settings so update sanitizes and invalidates like create
			$setting = self::create_settings( $params );

			if ( ! $setting || is_wp_error( $setting ) ) {
				return new \WP_REST_Response( $response, $status_code );
			}

			if ( in_array( $setting->slug, \Mediavine\Create\Plugin::$create_settings_slugs, true ) ) {
				\Mediavine\Create\Publish::add_all_to_publish_queue();
			}

			$setting = self::extract( $setting );

			// if the card style was updated, and Trellis is active, purge the Critical CSS
			if ( 'mv_create_card_style' === $setting->slug && Theme_Checker::is_trellis() && function_exists( 'mv_trellis_purge_all_critical_css' ) ) {
				/**
				 * Purge all critical CSS when the global card style is updated.
				 *
				 * @function mv_trellis_purge_all_critical_css
				 *
				 * @since 1.8.0
				 */
				mv_trellis_purge_all_critical_css();
			}

			do_action( 'mv_create_setting_updated_' . $setting->slug, $setting );

			$response    = [];
			$response    = $this->api_services->prepare_item_for_response( $setting, $request );
			$status_code = 200;
			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * API Function to delete single Setting by setting ID
		 *
		 * @param  \WP_REST_Request object request object via API
		 * @return \WP_REST_Response object for output as JSON data
		 */
		public function delete( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;

			$sanitized = $request->sanitize_params();
			$params    = $request->get_params();

			$setting_id = intval( $params['id'] );

			$deleted = self::$models->mv_settings->delete( $setting_id );

			if ( $deleted ) {
				self::reset_settings();
				$response    = [];
				$status_code = 204;
			}

			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * Refresh Settings table
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response
		 */
		public function refresh_settings( \WP_REST_Request $request ) {
			$response = new \WP_REST_Response();

			update_option( 'mv_create_version', '' );
			update_option( 'mv_create_db_version', '' );

			$response->set_status( 201 );

			return $response;
		}

		/**
		 * Reset table to plugin defaults
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response
		 */
		public function reset_db_settings( \WP_REST_Request $request ) {
			$response = new \WP_REST_Response();
			// handle similar to Trellis
			/**
			 * @see \Mediavine\Trellis\Settings_API::reset_trellis_settings() for example
			 */

			// get all settings
			$settings = Settings::get_settings();
			$slugs    = Arr::pluck( $settings, 'slug' );

			// delete settings
			foreach ( $slugs as $option_name ) {
				// if we reset settings, let's not make the user register again
				if ( in_array( $option_name, [ 'mv_create_api_token', 'mv_create_api_user_id' ], true ) ) {
					continue;
				}

				Settings::delete_setting( $option_name );
			}

			// re-add settings
			$fresh_settings = Plugin::$settings;
			foreach ( $fresh_settings as $fresh ) {
				if ( ! empty( $fresh['slug'] ) && in_array( $fresh['slug'], [ 'mv_create_api_token', 'mv_create_api_user_id' ], true ) ) {
					continue;
				}
				Settings::create_settings( $fresh );
			}

			// refresh version number
			update_option( 'mv_create_version', '' );
			update_option( 'mv_create_db_version', '' );

			$response->set_status( 201 );

			return $response;
		}

		/**
		 * Request password reset for v1 JWT users migrating to v2 API
		 *
		 * @param \WP_REST_Request $request
		 * @return \WP_REST_Response
		 */
		public function request_password_reset( \WP_REST_Request $request ) {
			$email = $request->get_param( 'email' );

			if ( ! $email || ! is_email( $email ) ) {
				return new \WP_REST_Response(
					[
						'success' => false,
						'message' => __( 'Invalid email address', 'mediavine-create' ),
					],
					400
				);
			}

			// Call the Services API to request password reset
			$response = wp_remote_post(
				\Mediavine\Create\Plugin::$services_api_url . '/auth/request-password-reset',
				[
					'headers'     => [
						'Content-Type' => 'application/json',
					],
					'body'        => wp_json_encode( [ 'email' => $email ] ),
					'timeout'     => 10,
					'sslverify'   => true,
				]
			);

			// Check for WP errors
			if ( is_wp_error( $response ) ) {
				return new \WP_REST_Response(
					[
						'success' => false,
						'message' => __( 'Failed to send password reset email. Please try again.', 'mediavine-create' ),
					],
					500
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );
			$data        = json_decode( $body, true );

			// If successful, set transient to enforce 5-minute wait before resend
		if ( $status_code >= 200 && $status_code < 300 ) {
			set_transient( 'mv_create_password_reset_pending_' . get_current_user_id(), 1, 5 * MINUTE_IN_SECONDS );
			return new \WP_REST_Response(
				[
					'success' => true,
					'message' => __( 'Password reset email sent. Please check your inbox.', 'mediavine-create' ),
				],
				200
			);
		}

			// Handle errors from Services API
			$error_message = $data['message'] ?? __( 'Failed to send password reset email. Please try again.', 'mediavine-create' );

			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => $error_message,
				],
				$status_code
			);
		}

		/**
		 * Handle special transient settings that shouldn't be saved to the database.
		 *
		 * These pseudo-settings are used to control WordPress transients from the frontend.
		 *
		 * @param array $params The settings params from the API request.
		 * @return array Filtered params with transient settings removed.
		 */
		private function handle_transient_settings( $params ) {
			if ( ! is_array( $params ) ) {
				return $params;
			}

			// Handle both single setting and array of settings
			$is_numeric_array = wp_is_numeric_array( $params );
			$settings_to_process = $is_numeric_array ? $params : [ $params ];

			foreach ( $settings_to_process as $key => $setting ) {
				$slug = $setting['slug'] ?? null;

				// Handle password reset transient
				if ( 'mv_create_needs_password_reset_transient' === $slug ) {
					$value = $setting['value'] ?? null;
					if ( 'set' === $value ) {
						set_transient( 'mv_create_needs_password_reset', true, WEEK_IN_SECONDS );
					} elseif ( 'clear' === $value ) {
						delete_transient( 'mv_create_needs_password_reset' );
					}
					// Remove from params so it doesn't get saved to database
					if ( $is_numeric_array ) {
						unset( $params[ $key ] );
					} else {
						return []; // Single setting was a transient, return empty
					}
				}

				// Handle password reset pending transient
				if ( 'mv_create_password_reset_pending_transient' === $slug ) {
					$value = $setting['value'] ?? null;
					if ( 'set' === $value ) {
						set_transient( 'mv_create_password_reset_pending_' . get_current_user_id(), true, 5 * MINUTE_IN_SECONDS );
					}
					// Remove from params so it doesn't get saved to database
					if ( $is_numeric_array ) {
						unset( $params[ $key ] );
					} else {
						return []; // Single setting was a transient, return empty
					}
				}

				// Handle direct password reset setting (from frontend checkEmailConfirmation)
				if ( 'mv_create_needs_password_reset' === $slug ) {
					$value = $setting['value'] ?? null;
					if ( $value === true || $value === 'true' || $value === '1' || $value === 1 ) {
						set_transient( 'mv_create_needs_password_reset', true, WEEK_IN_SECONDS );
					} else {
						delete_transient( 'mv_create_needs_password_reset' );
					}
					// Don't save to database - transient is the source of truth
					if ( $is_numeric_array ) {
						unset( $params[ $key ] );
					} else {
						return [];
					}
				}
			}

			// Re-index array if items were removed
			if ( $is_numeric_array ) {
				$params = array_values( $params );
			}

			// Mark that transient settings were processed (for success response when all were transients)
			if ( empty( $params ) && ! empty( $settings_to_process ) ) {
				$params['_transients_processed'] = true;
			}

			return $params;
		}

		/**
		 * Reset all database version options for development purposes.
		 *
		 * This clears all version options, forcing the plugin to re-run
		 * database migrations on next load. Only available when WP_DEBUG is true.
		 *
		 * @param \WP_REST_Request $request The REST request.
		 * @return \WP_REST_Response
		 */
		public function reset_db_versions( \WP_REST_Request $request ) {
			// Only allow when dev mode is enabled
			if ( ! \Mediavine\Create\Plugin::is_dev_mode() ) {
				return new \WP_REST_Response(
					[
						'success' => false,
						'message' => __( 'This action is only available when dev mode is enabled.', 'mediavine-create' ),
					],
					403
				);
			}

			// List of tables that have version tracking
			$tables = [
				'mv_images',
				'mv_nutrition',
				'mv_products',
				'mv_products_map',
				'mv_reviews',
				'mv_reviews_responses',
				'mv_creations',
				'mv_supplies',
				'mv_relations',
				'mv_settings',
				'mv_revisions',
			];

			$deleted_options = [];

			// Delete main plugin versions
			delete_option( 'mv_create_version' );
			$deleted_options[] = 'mv_create_version';

			delete_option( 'mv_create_db_version' );
			$deleted_options[] = 'mv_create_db_version';

			// Delete individual table version options (stored without wpdb prefix)
			foreach ( $tables as $table ) {
				$option_name = $table . '_db_version';
				delete_option( $option_name );
				$deleted_options[] = $option_name;
			}

			return new \WP_REST_Response(
				[
					'success'         => true,
					'message'         => __( 'Database version options have been reset. The plugin will re-run migrations on next page load.', 'mediavine-create' ),
					'deleted_options' => $deleted_options,
				],
				200
			);
		}

		/**
		 * Reset subscription tier options.
		 *
		 * Deletes the subscription tier and synced_at options from wp_options,
		 * forcing the plugin to re-fetch subscription status on next check.
		 *
		 * @param \WP_REST_Request $request The REST request.
		 * @return \WP_REST_Response
		 */
		public function reset_subscription_tier( \WP_REST_Request $request ) {
			// Only allow when dev mode is enabled
			if ( ! \Mediavine\Create\Plugin::is_dev_mode() ) {
				return new \WP_REST_Response(
					[
						'success' => false,
						'message' => __( 'This action is only available when dev mode is enabled.', 'mediavine-create' ),
					],
					403
				);
			}

			$deleted_options = [];

			// Delete subscription tier from mv_settings table
			Settings::delete_setting( GateKeeper::SETTING_SUBSCRIPTION_TIER );
			$deleted_options[] = GateKeeper::SETTING_SUBSCRIPTION_TIER;

			// Delete subscription synced_at from mv_settings table
			Settings::delete_setting( GateKeeper::SETTING_SUBSCRIPTION_SYNCED_AT );
			$deleted_options[] = GateKeeper::SETTING_SUBSCRIPTION_SYNCED_AT;

			// Clear cached settings so next access reads fresh data
			Settings::reset_settings();

			return new \WP_REST_Response(
				[
					'success'         => true,
					'message'         => __( 'Subscription tier options have been reset. The plugin will re-fetch subscription status on next check.', 'mediavine-create' ),
					'deleted_options' => $deleted_options,
				],
				200
			);
		}
	}
}
