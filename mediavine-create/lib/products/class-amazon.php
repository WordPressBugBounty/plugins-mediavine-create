<?php

namespace Mediavine\Create;

use Mediavine\Create\Amazon\ProductAdvertisingAPI\v1\com\amazon\paapi5\v1\ItemsResult;
use Mediavine\Create\Helpers\Arr;
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

	private $marketplace = 'US';

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
			self::$instance = new self();
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
			$this->tag         = Settings::get_setting( self::$settings_group . '_paapi_tag', '' );
			$this->marketplace = Settings::get_setting( self::$settings_group . '_paapi_marketplace', 'US' );
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


	private static $marketplace_config = [
		'US' => [ 'host' => 'webservices.amazon.com',        'region' => 'us-east-1' ],
		'UK' => [ 'host' => 'webservices.amazon.co.uk',      'region' => 'eu-west-1' ],
		'CA' => [ 'host' => 'webservices.amazon.ca',         'region' => 'us-east-1' ],
		'DE' => [ 'host' => 'webservices.amazon.de',         'region' => 'eu-west-1' ],
		'FR' => [ 'host' => 'webservices.amazon.fr',         'region' => 'eu-west-1' ],
		'JP' => [ 'host' => 'webservices.amazon.co.jp',      'region' => 'us-west-2' ],
		'AU' => [ 'host' => 'webservices.amazon.com.au',     'region' => 'us-east-1' ],
		'IN' => [ 'host' => 'webservices.amazon.in',         'region' => 'eu-west-1' ],
		'IT' => [ 'host' => 'webservices.amazon.it',         'region' => 'eu-west-1' ],
		'ES' => [ 'host' => 'webservices.amazon.es',         'region' => 'eu-west-1' ],
	];

	/**
	 * Set up configuration object
	 * @return Configuration
	 */
	public function setup_configuration() {
		$config = new Configuration();
		$config->setAccessKey( $this->key );
		$config->setSecretKey( $this->secret );

		$mc = self::$marketplace_config[ $this->marketplace ] ?? self::$marketplace_config['US'];
		$config->setHost( $mc['host'] );
		$config->setRegion( $mc['region'] );
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
		// Resolve amzn.to shortlinks to the full URL before parsing ASIN
		if ( false !== strpos( $link, 'amzn.to' ) ) {
			$response = wp_remote_get( $link, [ 'redirection' => 5, 'timeout' => 10 ] );
			if ( ! is_wp_error( $response ) ) {
				$http_response = $response['http_response'];
				if ( $http_response instanceof \WP_HTTP_Requests_Response ) {
					$requests_response = $http_response->get_response_object();
					if ( ! empty( $requests_response->url ) ) {
						$link = $requests_response->url;
					}
				}
			}
		}

		// https://regex101.com/r/PLxDdM/3
		$re = '/http[s]?:\/\/.+(?<code>\/gp|\/dp).+(?<asin>[a-zA-Z0-9]{10})/U';

		preg_match_all( $re, $link, $matches, PREG_SET_ORDER, 0 );
		if ( ! empty( $matches[0]['asin'] ) ) {
			return $matches[0]['asin'];
		}
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

		// AccessDenied - API access not enabled or AWS credentials need migration
		if ( 'AccessDenied' === $error->Code || 'AccessDeniedAwsUsers' === $error->Code ) {
			$error_response = [
				'code'    => 'access_denied',
				'message' => __( 'Amazon: API Access Not Enabled', 'mediavine' ),
				'data'    => [
					'status'    => 401,
					'message'   => __( "Amazon reports your Access Key doesn't have Product Advertising API access. If you're using AWS credentials, Amazon requires you to migrate them through Associates Central.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( 'Manage Credentials in Amazon Associates Central', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=AccessDeniedException',
				],
			];
		}

		// AssociateNotEligible - account doesn't meet eligibility requirements (10 sales in 30 days)
		if ( 'AssociateNotEligible' === $error->Code ) {
			$error_response = [
				'code'    => 'associate_not_eligible',
				'message' => __( 'Amazon: API Access Paused', 'mediavine' ),
				'data'    => [
					'status'    => 403,
					'message'   => __( "Amazon requires 10 qualified sales in the trailing 30 days to access their Product Advertising API. Once you meet this threshold, API access restores automatically. You can still add products manually.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/home/reports/summary',
					'link_text' => __( 'View Your Amazon Associates Dashboard', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=AssociateEligibilityException',
				],
			];
		}

		// InvalidPartnerTag - Store ID/tracking tag doesn't match credentials
		if ( 'InvalidPartnerTag' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_partner',
				'message' => __( 'Amazon: Invalid Store ID', 'mediavine' ),
				'data'    => [
					'status'    => 400,
					'message'   => __( "Amazon reports your Store ID (Partner Tag) doesn't match your API credentials. Your Store ID looks like \"yoursite-20\" - make sure it's from the same Amazon account as your API keys. Common mistake: using your Access Key ID instead of your Store ID.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Store ID in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=InvalidPartnerTagException',
				],
			];
		}

		// InvalidAssociate - credentials not linked to approved store
		if ( 'InvalidAssociate' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_associate',
				'message' => __( 'Amazon: Account Not Approved', 'mediavine' ),
				'data'    => [
					'status'    => 403,
					'message'   => __( "Amazon reports your credentials aren't linked to an approved Associates account. This usually means your Associates application is still pending, or you're using credentials from a different Amazon account than your approved store.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( 'Check Your Account in Amazon Associates Central', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=AssociateValidationException',
				],
			];
		}

		// InvalidSignature - credentials incorrect or still provisioning
		if ( 'InvalidSignature' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_signature',
				'message' => __( 'Amazon: Invalid Credentials', 'mediavine' ),
				'data'    => [
					'status'    => 401,
					'message'   => __( "Amazon couldn't validate your credentials. Check that your Secret Key is correct - it's a 40-character string, not your Store ID. If you just created new credentials, Amazon takes up to 48 hours to activate them.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Review Your Credentials in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=InvalidSignatureException',
				],
			];
		}

		// IncompleteSignature - Secret Key missing or malformed
		if ( 'IncompleteSignature' === $error->Code ) {
			$error_response = [
				'code'    => 'incomplete_signature',
				'message' => __( 'Amazon: Missing Secret Key', 'mediavine' ),
				'data'    => [
					'status'    => 400,
					'message'   => __( "Amazon reports your Secret Key is missing or incomplete. Your Secret Key is a 40-character string that looks like \"wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY\". Make sure you copied the entire key without extra spaces.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Re-enter Your Secret Key in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=IncompleteSignatureException',
				],
			];
		}

		// TooManyRequests - rate limit exceeded
		if ( 'TooManyRequests' === $error->Code ) {
			$error_response = [
				'code'    => 'too_many_requests',
				'message' => __( 'Amazon: Rate Limit Exceeded', 'mediavine' ),
				'data'    => [
					'status'    => 429,
					'message'   => __( "Amazon is rate-limiting your requests because you've exceeded their API limits. Wait a few minutes before trying again.", 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=TooManyRequestsException',
				],
			];
		}

		// RequestExpired - server clock out of sync
		if ( 'RequestExpired' === $error->Code ) {
			$error_response = [
				'code'    => 'request_expired',
				'message' => __( 'Amazon: Request Expired', 'mediavine' ),
				'data'    => [
					'status'    => 401,
					'message'   => __( "Amazon rejected the request because your server's clock is out of sync. Amazon requires requests to be within 15 minutes of the actual time. Contact your hosting provider to sync your server's clock.", 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=RequestExpiredException',
				],
			];
		}

		// UnrecognizedClient - Access Key ID not recognized
		if ( 'UnrecognizedClient' === $error->Code ) {
			$error_response = [
				'code'    => 'unrecognized_client',
				'message' => __( 'Amazon: Unknown Access Key', 'mediavine' ),
				'data'    => [
					'status'    => 401,
					'message'   => __( "Amazon doesn't recognize your Access Key ID. Your Access Key is a 20-character string starting with \"AKIA\". Common mistakes: using your Store ID instead, extra spaces, or using old/deleted credentials. Generate new credentials in Amazon Associates if needed.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Access Key in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=UnrecognizedClientException',
				],
			];
		}

		// InvalidParameterValue / MissingParameter
		if ( 'InvalidParameterValue' === $error->Code || 'MissingParameter' === $error->Code ) {
			$error_response = [
				'code'    => 'invalid_or_missing_parameter',
				'message' => __( 'Amazon: Invalid Request', 'mediavine' ),
				'data'    => [
					'status'    => 400,
					'message'   => __( 'Amazon reports an invalid or missing parameter in the request. This is usually a temporary issue - please try again.', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=ValidationException',
				],
			];
		}

		// UnknownOperation
		if ( 'UnknownOperation' === $error->Code ) {
			$error_response = [
				'code'    => 'unknown_operation',
				'message' => __( 'Amazon: Unknown Operation', 'mediavine' ),
				'data'    => [
					'status'    => 404,
					'message'   => __( 'Amazon received an unknown API operation. This is likely a plugin issue - please contact support.', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=UnknownOperationException',
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
			! Settings::get_setting( self::$settings_group . '_api_token', false )
		) {
			return new \WP_Error(
				'create_not_registered',
				__( 'Register to Access PRO Features', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( 'Create must be registered to access pro features like Amazon product scraping. Please register and then activate Amazon Affiliates or manually add an image and title.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_api' ),
					'link_text' => __( 'Register Create', 'mediavine' ),
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
					'link_url'  => 'mailto:support@create.studio',
					'link_text' => __( 'Contact support@create.studio for more information.', 'mediavine' ),
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
	 * @param int     $input_seconds Seconds
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
			'instructions' => __( "Uh oh! Another plugin is conflicting with Create's Amazon API Integration feature. Please disable your other Amazon plugins that utilize Amazon's API in order to activate this feature in Create. Contact support@create.studio for more information.", 'mediavine' ),
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
