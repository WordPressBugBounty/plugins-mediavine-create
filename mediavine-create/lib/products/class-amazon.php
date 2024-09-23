<?php

namespace Mediavine\Create;

use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\ItemsResult;
use Mediavine\WordPress\Support\Arr;
use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\ApiException;
use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\Configuration;
use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\PartnerType;
use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\api\DefaultApi;
use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsRequest;
use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsResource;
use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\GetItemsResponse;
use Mediavine\Settings;

class Amazon {

	protected static $instance;

	protected static $settings_group = 'mv_create';

	private $enabled = false;

	private $key = '';

	private $secret = '';

	private $tag = '';

	/**
	 * Amazon configuration class
	 * @var Configuration
	 */
	private $config;

	/**
	 * Amazon API property
	 * @var DefaultApi|null
	 */
	public $api = null;

	/**
	 * Return singleton instance
	 *
	 * @return Amazon
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Class initialization
	 */
	public function init() {
		if ( mv_create_table_exists( 'mv_settings' ) ) {
			$this->enabled = Settings::get_setting( self::$settings_group . '_enable_amazon', false );
			$this->key     = Settings::get_setting( self::$settings_group . '_paapi_access_key', '' );
			$this->secret  = Settings::get_setting( self::$settings_group . '_paapi_secret_key', '' );
			$this->tag     = Settings::get_setting( self::$settings_group . '_paapi_tag', '' );
		}

		$this->setup_configuration();
		$this->set_api();

		if ( $this->enabled ) {
			add_filter( 'mv_create_localized_admin_settings', [ $this, 'add_paapi_lockout_time' ] );
		}
	}

	/**
	 * Set API property
	 * @param DefaultApi $api
	 */
	public function set_api( $api = null ) {
		if ( is_null( $api ) ) {
			$api = new DefaultApi( new \Mediavine\Create\GuzzleHttp\Client(), $this->config );
		}

		$this->api = $api;
	}


	/**
	 * Set up configuration object
	 * @return Configuration
	 */
	public function setup_configuration() {
		$config = new Configuration();
		$config->setAccessKey( $this->key );
		$config->setSecretKey( $this->secret );

		/*
		 * PAAPI host and region to which you want to send request
		 * For more details refer: https://webservices.amazon.com/paapi5/documentation/common-request-parameters.html#host-and-region
		 */
		$config->setHost( 'webservices.amazon.com' );
		$config->setRegion( 'us-east-1' );
		$this->config = $config;

		return $config;
	}

	/**
	 * Parses an amazon link to retrieve the ASIN id
	 *
	 * @param string $link
	 *
	 * @return string|null
	 */
	public function get_asin_from_link( $link ) {
		// https://regex101.com/r/PLxDdM/3
		$re = '/http[s]?:\/\/.+(?<code>\/gp|\/dp).+(?<asin>[a-zA-Z0-9]{10})/U';

		preg_match_all( $re, $link, $matches, PREG_SET_ORDER, 0 );
		if ( ! empty( $matches[0]['asin'] ) ) {
			return $matches[0]['asin'];
		}
		// TODO: Add logic to get ASIN from amzn.to shortened links
		return null;
	}

	/**
	 * Checks if Amazon Affiliates is setup in Create settings
	 * @return bool True if fully setup
	 */
	public function amazon_affiliates_setup() {
		if (
			empty( $this->enabled ) ||
			empty( $this->key ) ||
			empty( $this->secret ) ||
			empty( $this->tag ) ||
			is_null( $this->api )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Get a batch of Amazon products according to ASINs
	 *
	 * @param array $asins Array of ASINs to scrape
	 * @return array|\WP_Error
	 */
	public function get_products_by_asin( $asins ) {
		// API must be enabled
		$api_enabled = $this->is_api_enabled();
		if ( is_wp_error( $api_enabled ) ) {
			return $api_enabled;
		}

		// Make sure Amazon is enabled and setup
		$api_is_setup = $this->is_affiliate_api_setup();
		if ( is_wp_error( $api_is_setup ) ) {
			return $api_is_setup;
		}

		// Make sure not within initial Amazon provisioning lockout
		$timeout = $this->get_amazon_provision_lockout();
		if ( is_wp_error( $timeout ) ) {
			return $timeout;
		}

		// this can happen in the advent that a singular asin is passed
		if ( is_string( $asins ) ) {
			$asins = (array) $asins;
		}

		$request = $this->get_request( $asins );

		$products = [];

		try {
			/**
			 * Response object.
			 * @var GetItemsResponse $response
			 */
			$response = $this->api->getItems( $request );

			/**
			 * Parsing the response
			 *
			 * @var ItemsResult $result
			 */
			$result = $response->getItemsResult();
			if ( ! is_null( $result ) && ! is_null( $result->getItems() ) ) {
				$items = $this->parse( $result->getItems() );

				foreach ( $items as $asin => $item ) {
					if ( is_null( $item ) ) {
						unset( $items[ $asin ] );
						continue;
					}

					$title = ! ( is_null( $item->getItemInfo() ) || is_null( $item->getItemInfo()->getTitle() ) || is_null( $item->getItemInfo()->getTitle()->getDisplayValue() ) )
						? $item->getItemInfo()->getTitle()->getDisplayValue()
						: null;

					// Get image, but if no image, this will be null
					$image_url = $item->getImages();
					if ( ! empty( $image_url ) ) {
						$image_url = $image_url->getPrimary()->getLarge()->getURL();
					}

					$products[ $asin ] = [
						'asin'                   => $asin,
						'title'                  => $title,
						'description'            => $title,
						'external_thumbnail_url' => $image_url,
						'expires'                => date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
					];
				}
			}
		} catch ( ApiException $exception ) {
			return $this->get_api_exceptions( $exception );
		} catch ( \Exception $exception ) {
		}

		return $products;
	}

	/**
	 * Get appropriate API exception messages
	 * @param ApiException $exception
	 *
	 * @return \WP_Error
	 */
	private function get_api_exceptions( ApiException $exception ) {
		// Default error if no errors are found
		$decode = json_decode( $exception->getResponseBody() );
		if ( empty( $decode->Errors[0] ) ) {
			return new \WP_Error(
				'unknown_api_error',
				__( 'Unknown API Error', 'mediavine' ),
				[
					'status'  => 400,
					'message' => __( 'An error occurred, but no error data was provided by the API.', 'mediavine' ),
				]
			);
		}

		$error                  = $decode->Errors[0];
		$error_response         = [
			'code'    => $error->Code,
			'message' => $error->Message,
		];
		$error_response['data'] = $error_response;

		// More human readable code for AccessDenied
		if ( 'AccessDenied' === $error->Code || 'AccessDeniedAwsUsers' === $error->Code ) {
			$error_response = [
				'code'    => 'access_denied',
				'message' => __( 'Invalid Amazon Credentials', 'mediavine' ),
				'data'    => [
					'status'    => 401,
					'message'   => __( "Your Access Key ID does not have Amazon's Product Advertising API access.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( "Sign up for Amazon's Product Advertising API", 'mediavine' ),
				],
			];
		}

		// More human readable code for InvalidPartnerTag
		if ( 'InvalidPartnerTag' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_partner',
				'message' => __( 'Invalid Partner Tag', 'mediavine' ),
				'data'    => [
					'status'    => 400,
					'message'   => __( 'The partner tag is not mapped to a valid associate store with your access key.', 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( "Sign up for Amazon's Product Advertising API", 'mediavine' ),
				],
			];
		}

		// More human readable code for InvalidAssociate
		if ( 'InvalidAssociate' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_associate',
				'message' => __( 'Invalid Partner Tag', 'mediavine' ),
				'data'    => [
					'status'    => 403,
					'message'   => __( 'Your access key is not mapped to primary of approved associate store.', 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( "Sign up for Amazon's Product Advertising API", 'mediavine' ),
				],
			];
		}

		// More human readable code for InvalidSignature
		if ( 'InvalidSignature' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_signature',
				'message' => __( 'Invalid Amazon Credentials', 'mediavine' ),
				'data'    => [
					'status'    => 401,
					'message'   => __( 'Your Amazon Affiliates credentials appear to be incorrect. It is also possible they may still be provisioning, which normally takes around 24-48 hours. Please review your settings or manually add an image and title.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Amazon Affiliates Settings', 'mediavine' ),
				],
			];
		}

		// More human readable code for IncompleteSignature
		if ( 'IncompleteSignature' === $error->Code ) {
			$error_response = [
				'code'    => 'incomplete_signature',
				'message' => __( 'Invalid Amazon Credentials', 'mediavine' ),
				'data'    => [
					'status'  => 400,
					'message' => __( 'The request signature did not include all of the required components.', 'mediavine' ),
				],
			];
		}

		// More human readable code for TooManyRequests
		if ( 'TooManyRequests' === $error->Code ) {
			$error_response = [
				'code'    => 'too_many_requests',
				'message' => __( 'Too Many Requests', 'mediavine' ),
				'data'    => [
					'status'    => 429,
					'message'   => __( "The request was denied due to request throttling. Please take a break and reduce the number of requests made to the Amazon Product Advertising API. For some reason Amazon also gives this error if you haven't had three qualifying purchases through your affiliate account within the past 30 days.", 'mediavine' ),
					'link_url'  => 'https://webservices.amazon.com/paapi5/documentation/contact-us.html',
					'link_text' => __( 'If this error still persists after at least an hour, and your account has had enough qualifying purchases, please contact Amazon support.', 'mediavine' ),
				],
			];
		}

		// More human readable code for RequestExpired
		if ( 'RequestExpired' === $error->Code ) {
			$error_response = [
				'code'    => 'request_expired',
				'message' => __( 'request_expired', 'mediavine' ),
				'data'    => [
					'status'  => 401,
					'message' => __( 'The request is past expiry date or the request date (either with 15 minute padding), or the request date occurs more than 15 minutes in the future.', 'mediavine' ),
				],
			];
		}

		// More human readable code for UnrecognizedClient
		if ( 'UnrecognizedClient' === $error->Code ) {
			$error_response = [
				'code'    => 'unrecognized_client',
				'message' => __( 'Invalid Amazon Credentials', 'mediavine' ),
				'data'    => [
					'status'    => 401,
					'message'   => __( 'Your Amazon Affiliates Access Key ID or security token appear to be invalid. Please review your credentials.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Amazon Affiliates Settings', 'mediavine' ),
				],
			];
		}

		// More human readable code for InvalidParameterValue
		if ( 'InvalidParameterValue' === $error->Code || 'MissingParameter' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_or_missing_parameter',
				'message' => __( 'Invalid Parameter', 'mediavine' ),
				'data'    => [
					'status'  => 400,
					'message' => __( 'Parameter is either invalid or missing. Please check your input and try again.', 'mediavine' ),
				],
			];
		}

		// Human readable code for UnknownOperation
		if ( 'UnknownOperation' === $error->Code ) {
			$error_response = [
				'code'    => 'unknown_operation',
				'message' => __( 'Operation Unknown', 'mediavine' ),
				'data'    => [
					'status'  => 404,
					'message' => __( 'The operation requested is invalid. Please verify that the operation name is typed correctly.', 'mediavine' ),
				],
			];
		}

		// Always add full Amazon Error to response data
		$error_response['data']['full_amazon_error'] = $error;

		return new \WP_Error(
			$error_response['code'],
			$error_response['message'],
			$error_response['data']
		);
	}

	/**
	 * Checks if api token and email confirmation have been set
	 *
	 * @return bool|\WP_Error
	 */
	public function is_affiliate_api_setup() {
		if ( $this->amazon_affiliates_setup() ) {
			return true;
		}

		// If we are not registered, we need a register error
		if (
			! Settings::get_setting( self::$settings_group . '_api_token', false ) ||
			! Settings::get_setting( self::$settings_group . '_api_email_confirmed', false )
		) {
			return new \WP_Error(
				'create_not_registered',
				__( 'Register to Access PRO Features', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( 'Create by Mediavine must be registered to access pro features like Amazon product scraping. Please register and then activate Amazon Affiliates or manually add an image and title.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_api' ),
					'link_text' => __( 'Register Create by Mediavine', 'mediavine' ),
				]
			);
		}

		// If we are registered, we can send the Amazon not setup error
		return new \WP_Error(
			'paapi_not_setup',
			__( 'Amazon Affiliates Not Setup', 'mediavine' ),
			[
				'status'    => 401,
				'message'   => __( 'Amazon Affiliates is not enabled or fully setup to process Amazon links. Please activate Amazon Affiliates or manually add an image and title.', 'mediavine' ),
				'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
				'link_text' => __( 'Activate Amazon Affiliates', 'mediavine' ),
			]
		);
	}

	/**
	 * Checks if api is enabled
	 *
	 * @return bool|\WP_Error
	 */
	public function is_api_enabled() {
		if ( is_null( $this->api ) ) {
			return new \WP_Error(
				'amazon_plugin_conflict',
				__( 'Conflict with Another Plugin', 'mediavine' ),
				[
					'status'    => 501,
					'message'   => __( "Uh oh! It looks like another plugin is conflicting with Create's Amazon API Integration feature. We're working on a way to avoid these conflicts, but in the meantime, entering the product details manually is the easiest solution!", 'mediavine' ),
					'link_url'  => 'mailto:create@mediavine.com',
					'link_text' => __( 'Contact create@mediavine.com for more information.', 'mediavine' ),
				]
			);
		}

		return true;
	}

	/**
	 * Retrieves the timeout status
	 * @return false|mixed|\WP_Error
	 */
	public function get_amazon_provision_lockout() {
		$timeout = $this->get_transient_timeout( 'mv_create_amazon_provision' );
		if ( $timeout ) {
			$time = $timeout - time();
			if ( $time > 0 ) {
				return new \WP_Error(
					'paapi_provisioning',
					__( 'Waiting for Amazon Affiliates Secret Access Key Provision', 'mediavine' ),
					[
						'status'  => 403,
						'message' => sprintf(
						// Translators: Remaining time
							__( 'Amazon Affiliates may still be provisioning. Expected time remaining: %s. Please manually add an image and title.', 'mediavine' ),
							$this->seconds_to_time( $time )
						),
					]
				);
			}
		}

		return $timeout;
	}

	/**
	 * Maps items into a new array with the ASIN as the key
	 *
	 * @param array $items
	 *
	 * @return array
	 */
	public function parse( $items ) {
		$map = [];
		foreach ( $items as $item ) {
			$map[ $item->getASIN() ] = $item;
		}
		return $map;
	}

	/**
	 * Retrieve transient timeout
	 * @param string $transient
	 *
	 * @return false|mixed
	 */
	public static function get_transient_timeout( $transient ) {
		global $wpdb;

		$complete = Settings::get_setting( $transient . '_complete', false );
		if ( $complete ) {
			// this function is looking for a timeout's existence, so if it is complete, its
			// existence is false
			return false;
		}

		// SECURITY CHECKED: This query is properly sanitized.
		$sanitized_transient = preg_replace('/[^a-zA-Z0-9_]/', '', $transient );
		$transient_timeout   = $wpdb->get_col(
			"
		  SELECT option_value
		  FROM $wpdb->options
		  WHERE option_name
		  LIKE '%_transient_timeout_$sanitized_transient%'
		"
		);

		if ( ! empty( $transient_timeout[0] ) ) {
			return $transient_timeout[0];
		}

		Settings::create_settings(
			[
				'slug'  => $transient . '_complete',
				'value' => true,
			]
		);

		return false;
	}

	/**
	 * Converts seconds to readable time
	 *
	 * @param int $input_seconds Seconds
	 * @param boolean $display_seconds Should seconds be displayed or just minutes and hours
	 * @return string Human readable time
	 */
	public function seconds_to_time( $input_seconds, $display_seconds = false ) {
		// Extract hours
		$hours = $input_seconds / HOUR_IN_SECONDS;

		// Extract minutes
		$minute_seconds = $input_seconds % HOUR_IN_SECONDS;
		$minutes        = floor( $minute_seconds / MINUTE_IN_SECONDS );

		$timeParts = [];
		$sections  = [
			[
				'time'     => (int) $hours,
				'singular' => __( 'hour', 'mediavine' ),
				'plural'   => __( 'hours', 'mediavine' ),
			],
			[
				'time'     => (int) $minutes,
				'singular' => __( 'minute', 'mediavine' ),
				'plural'   => __( 'minutes', 'mediavine' ),
			],
		];

		if ( $display_seconds ) {
			// Extract the remaining seconds
			$remaining_seconds = $input_seconds % MINUTE_IN_SECONDS;
			$seconds           = ceil( $remaining_seconds );

			$sections[] = [
				'time'     => (int) $seconds,
				'singular' => __( 'second', 'mediavine' ),
				'plural'   => __( 'seconds', 'mediavine' ),
			];
		}

		foreach ( $sections as $section ) {
			if ( $section['time'] > 0 ) {
				$timeParts[] = $section['time'] . ' ' . ( 1 === $section['time'] ? $section['singular'] : $section['plural'] );
			}
		}

		return implode( ', ', $timeParts );
	}

	/**
	 * PAAPI Lock-out time setting
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function add_paapi_lockout_time( $settings ) {
		// Get key for paapi secret
		$slugs = wp_list_pluck( $settings, 'slug' );
		if ( in_array( 'mv_create_paapi_secret_key', $slugs, true ) ) {
			$flipped = array_flip( $slugs );
			$key     = $flipped['mv_create_paapi_secret_key'];

			// Only move forward if a key exists
			if ( ! empty( $settings[ $key ]->value ) ) {
				// Check if locked out
				$timeout = $this->get_transient_timeout( 'mv_create_amazon_provision' );
				if ( $timeout ) {
					$time = $timeout - time();
					if ( $time > 0 ) {
						$settings[ $key ]->data['instructions'] .= sprintf(
							// Translators: Remaining time
							__( ' (Time remaining: %s)', 'mediavine' ),
							$this->seconds_to_time( $time )
						);
					}
				}
			}
		}

		return $settings;
	}

	/**
	 * Remove affiliate settings from settings array
	 * @param array $settings
	 *
	 * @return array
	 */
	public function remove_affiliates_settings( $settings ) {
		// Remove all affiliate settings
		foreach ( $settings as $key => $setting ) {
			if ( 'mv_create_affiliates' === $setting->group ) {
				unset( $settings[ $key ] );
			}
		}

		// Reset settings array to indexed array
		$settings = array_values( $settings );

		// Add notice to settings blocking affiliate settings
		$affiliates_notice        = new \stdClass();
		$affiliates_notice->id    = 0;
		$affiliates_notice->type  = 'setting';
		$affiliates_notice->slug  = 'mv_create_affiliates_conflict_notice';
		$affiliates_notice->data  = [
			'type'         => 'notice',
			'label'        => __( 'Conflict with Another Plugin', 'mediavine' ),
			'instructions' => __( "Uh oh! Another plugin is conflicting with Create's Amazon API Integration feature. Please disable your other Amazon plugins that utilize Amazon's API in order to activate this feature in Create. Contact create@mediavine.com for more information.", 'mediavine' ),
		];
		$affiliates_notice->group = 'mv_create_affiliates';
		$affiliates_notice->order = 1;

		$settings[] = $affiliates_notice;

		return $settings;
	}

	/**
	 * Get request for retrieving products
	 * @param array $asins
	 *
	 * @return GetItemsRequest
	 */
	public function get_request( $asins ) {
		$resources = [
			GetItemsResource::ITEM_INFOTITLE,
			GetItemsResource::IMAGESPRIMARYLARGE,
		];

		$item_ids = Arr::wrap( $asins );

		# Forming the request
		$request = new GetItemsRequest();
		$request->setItemIds( $item_ids );
		$request->setPartnerTag( $this->tag );
		$request->setPartnerType( PartnerType::ASSOCIATES );
		$request->setResources( $resources );

		return $request;
	}

	public function set_access_key( $key ) {
		$this->key = $key;
	}

	public function set_secret_key( $key ) {
		$this->secret = $key;
	}

	public function set_tag( $tag ) {
		$this->tag = $tag;
	}

	public function set_enabled( $enabled = true ) {
		$this->enabled = $enabled;
	}

	public function enable_debug() {
		$this->config->setDebug( true );
		$this->config->setDebugFile( 'php://stderr' );
	}
}
