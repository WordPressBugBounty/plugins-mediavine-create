<?php

namespace Mediavine;

use Mediavine\Create\API_Services;
use Mediavine\Create\Plugin;
use Mediavine\Create\Theme_Checker;
use Mediavine\WordPress\Support\Arr;

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
						'title'   => __( 'Unsafe Content Submission', 'mediavine' ),
						'details' => __( 'You\'re submission includes unsafe characters', 'mediavine' ),
					], 'error'
				);
				return new \WP_REST_Response( $response, $status_code );
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

			$settings = self::$models->mv_settings->find( [ 'limit' => 200 ] );

			if ( $settings ) {
				$collection = [];
				foreach ( $settings as $setting ) {
					$setting      = self::extract( $setting );
					$collection[] = $this->api_services->prepare_item_for_response( $setting, $request );
				}
				$response          = [];
				$response['links'] = $this->api_services->prepare_collection_links( $request );
				$response          = $collection;
				$status_code       = 200;
				return new \WP_REST_Response( $response, $status_code );
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
					'limit' => 200,
					'where' => [
						'`group`' => $params['slug'],
					],
				]
			);

			if ( $settings ) {
				$collection = [];
				foreach ( $settings as $setting ) {
					$setting      = self::extract( $setting );
					$collection[] = $this->api_services->prepare_item_for_response( $setting, $request );
				}
				$response          = [];
				$response['links'] = $this->api_services->prepare_collection_links( $request );
				$response          = $collection;
				$status_code       = 200;
				return new \WP_REST_Response( $response, $status_code );
			}

			return new \WP_REST_Response( $response, $status_code );
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
				$status_code = 200;
				return new \WP_REST_Response( $response, $status_code );
			}

			return new \WP_REST_Response( $response, $status_code );
		}

		/**
		 * API Function to read update Settings by setting using upsert methods
		 *
		 * @param  \WP_REST_Request object request object via API
		 * @return \WP_REST_Response object for output as JSON data
		 */
		public function update( \WP_REST_Request $request ) {
			$response    = $this->api_services->default_response;
			$status_code = $this->api_services->default_status;

			$sanitized = $request->sanitize_params();
			$params    = $request->get_params();

			if ( is_wp_error( $sanitized ) ) {
				$status_code        = 403;
				$response['errors'] = $this->api_services->normalize_errors(
					$response['errors'], $status_code, [
						'title'   => __( 'Unsafe Content Submission', 'mediavine' ),
						'details' => __( 'You\'re submission includes unsafe characters', 'mediavine' ),
					], 'error'
				);
				return new \WP_REST_Response( $response, $status_code );
			}

			$stored = $this->process_create( $params );

			if ( $stored ) {
				$response    = [];
				$response    = $this->api_services->prepare_item_for_response( $stored, $request );
				$status_code = 201;
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

			$params      = $request->get_params();
			$old_setting = $this->read_single( $request );
			if ( ! is_wp_error( $old_setting ) ) {
				$old_setting = $old_setting->get_data();
				if ( isset( $params['value'] ) && isset( $old_setting['slug'] ) ) {
					$params['value'] = apply_filters( $old_setting['slug'] . '_settings_value', $params['value'] );
				}
			}
			$setting = self::$models->mv_settings->upsert( $params );

			if ( in_array( $setting->slug, \Mediavine\Create\Plugin::$create_settings_slugs, true ) ) {
				\Mediavine\Create\Publish::add_all_to_publish_queue();
			}

			if ( $setting ) {
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
				if ( in_array( $option_name, [ 'mv_create_api_token', 'mv_create_api_email_confirmed', 'mv_create_api_user_id' ], true ) ) {
					continue;
				}

				Settings::delete_setting( $option_name );
			}

			// re-add settings
			$fresh_settings = Plugin::$settings;
			foreach ( $fresh_settings as $fresh ) {
				if ( ! empty( $fresh['slug'] ) && in_array( $fresh['slug'], [ 'mv_create_api_token', 'mv_create_api_email_confirmed', 'mv_create_api_user_id' ], true ) ) {
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
	}
}
