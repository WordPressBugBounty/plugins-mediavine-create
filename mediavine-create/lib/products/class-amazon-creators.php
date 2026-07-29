<?php
/**
 * Amazon Creators API implementation.
 *
 * Uses the Amazon Creators API (replacement for PA-API 5.0) with OAuth 2.0 authentication.
 * Implementation uses direct HTTP calls via wp_remote_post, so no SDK or minimum PHP
 * version beyond the plugin's baseline is required.
 *
 * @package Mediavine\Create
 */

namespace Mediavine\Create;

use Mediavine\Settings;

class Amazon_Creators {

	protected static $instance;

	protected static $settings_group = 'mv_create';

	private $enabled = false;

	private $credential_id = '';

	private $credential_secret = '';

	private $tag = '';

	private $marketplace = 'US';

	/**
	 * Derived once from $marketplace + $credential_id in init(). Using
	 * memoized values avoids repeatedly re-parsing the credential string and
	 * doing map lookups across OAuth, API, and logging paths.
	 */
	private $region = 'na';
	private $marketplace_domain = 'www.amazon.com';
	private $credential_version = self::VERSION_V2;

	const VERSION_V2 = 'v2';
	const VERSION_V3 = 'v3';

	/**
	 * Marketplace code → Creators API region (groups shared OAuth endpoint).
	 * Docs: https://affiliate-program.amazon.com/creatorsapi/docs/en-us/introduction
	 */
	const MARKETPLACE_REGIONS = [
		// North America (version 2.1)
		'US' => 'na',
		'CA' => 'na',
		'MX' => 'na',
		'BR' => 'na',
		// Europe (version 2.2)
		'UK' => 'eu',
		'DE' => 'eu',
		'FR' => 'eu',
		'IT' => 'eu',
		'ES' => 'eu',
		'IN' => 'eu',
		'TR' => 'eu',
		'AE' => 'eu',
		'SA' => 'eu',
		// Far East (version 2.3)
		'JP' => 'fe',
		'AU' => 'fe',
		'SG' => 'fe',
	];

	/**
	 * Per-version config: OAuth token endpoint, scope, and — for v2 only — the
	 * API Authorization header's `Version X.Y` suffix. v2 uses Cognito with
	 * Basic Auth; v3 uses Login-with-Amazon with a JSON body.
	 */
	const VERSION_CONFIG = [
		self::VERSION_V2 => [
			'token_endpoints' => [
				'na' => 'https://creatorsapi.auth.us-east-1.amazoncognito.com/oauth2/token',
				'eu' => 'https://creatorsapi.auth.eu-south-2.amazoncognito.com/oauth2/token',
				'fe' => 'https://creatorsapi.auth.us-west-2.amazoncognito.com/oauth2/token',
			],
			'api_versions'    => [ 'na' => '2.1', 'eu' => '2.2', 'fe' => '2.3' ],
			'scope'           => 'creatorsapi/default',
		],
		self::VERSION_V3 => [
			'token_endpoints' => [
				'na' => 'https://api.amazon.com/auth/o2/token',
				'eu' => 'https://api.amazon.co.uk/auth/o2/token',
				'fe' => 'https://api.amazon.co.jp/auth/o2/token',
			],
			'api_versions'    => [],
			'scope'           => 'creatorsapi::default',
		],
	];

	const MARKETPLACE_DOMAINS = [
		'US' => 'www.amazon.com',
		'CA' => 'www.amazon.ca',
		'MX' => 'www.amazon.com.mx',
		'BR' => 'www.amazon.com.br',
		'UK' => 'www.amazon.co.uk',
		'DE' => 'www.amazon.de',
		'FR' => 'www.amazon.fr',
		'IT' => 'www.amazon.it',
		'ES' => 'www.amazon.es',
		'IN' => 'www.amazon.in',
		'TR' => 'www.amazon.com.tr',
		'AE' => 'www.amazon.ae',
		'SA' => 'www.amazon.sa',
		'JP' => 'www.amazon.co.jp',
		'AU' => 'www.amazon.com.au',
		'SG' => 'www.amazon.sg',
	];

	const API_HOST       = 'https://creatorsapi.amazon';
	const GET_ITEMS_PATH = '/catalog/v1/getItems';

	/** Credential ID prefix that identifies a v3.x (LwA) credential. */
	const V3_CREDENTIAL_PREFIX = 'amzn1.application-oa2-client.';

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	public function init() {
		if ( mv_create_table_exists( 'mv_settings' ) ) {
			$this->enabled           = Settings::get_setting( self::$settings_group . '_enable_amazon', false );
			$this->credential_id     = Settings::get_setting( self::$settings_group . '_creators_credential_id', '' );
			$this->credential_secret = Settings::get_setting( self::$settings_group . '_creators_credential_secret', '' );
			$this->tag               = Settings::get_setting( self::$settings_group . '_paapi_tag', '' );
			$this->marketplace       = Settings::get_setting( self::$settings_group . '_paapi_marketplace', 'US' );
		}
		$this->refresh_derived();
	}

	/**
	 * Recompute values derived from $marketplace and $credential_id. Also
	 * runs after any setter so test helpers see consistent state.
	 */
	private function refresh_derived() {
		$this->region             = self::MARKETPLACE_REGIONS[ $this->marketplace ] ?? 'na';
		$this->marketplace_domain = self::MARKETPLACE_DOMAINS[ $this->marketplace ] ?? 'www.amazon.com';
		$this->credential_version = ( 0 === strpos( $this->credential_id, self::V3_CREDENTIAL_PREFIX ) )
			? self::VERSION_V3
			: self::VERSION_V2;
	}

	/**
	 * Parses an Amazon link to retrieve the ASIN id.
	 *
	 * @param string $link Product URL.
	 * @return string|null
	 */
	public function get_asin_from_link( $link ) {
		// Resolve amzn.to shortlinks to the full URL before parsing ASIN.
		// Match the real host (not a bare substring, which allows SSRF via URLs
		// like http://169.254.169.254/?x=amzn.to) and fetch through the SSRF-guarded
		// helper, which validates the URL and every redirect hop (blocking
		// link-local/CGNAT that WP's own redirect check misses).
		$host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
		if ( 'amzn.to' === $host || 'www.amzn.to' === $host ) {
			$response = mv_create_safe_remote_get( $link, [ 'redirection' => 5, 'timeout' => 10 ] );
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

	public function amazon_affiliates_setup() {
		return ! empty( $this->enabled )
			&& ! empty( $this->credential_id )
			&& ! empty( $this->credential_secret )
			&& ! empty( $this->tag );
	}

	public function is_api_enabled() {
		return true;
	}

	public function is_affiliate_api_setup() {
		if ( $this->amazon_affiliates_setup() ) {
			return true;
		}

		if (
			! Settings::get_setting( self::$settings_group . '_api_token', false ) ||
			! Settings::get_setting( self::$settings_group . '_api_email_confirmed', false )
		) {
			return new \WP_Error(
				'create_not_registered',
				__( 'Register to Access PRO Features', 'mediavine-create' ),
				[
					'status'    => 401,
					'message'   => __( 'Create must be registered to access pro features like Amazon product scraping. Please register and then activate Amazon Affiliates or manually add an image and title.', 'mediavine-create' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_api' ),
					'link_text' => __( 'Register Create', 'mediavine-create' ),
				]
			);
		}

		return new \WP_Error(
			'creators_api_not_setup',
			__( 'Amazon Creators API Not Setup', 'mediavine-create' ),
			[
				'status'    => 401,
				'message'   => __( 'Amazon Creators API is not enabled or fully configured. Please enter your Creators API credentials from Associates Central, or manually add an image and title.', 'mediavine-create' ),
				'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
				'link_text' => __( 'Configure Amazon Affiliates', 'mediavine-create' ),
			]
		);
	}

	/**
	 * Retrieves the Amazon credential provisioning timeout status.
	 *
	 * @return false|mixed|\WP_Error
	 */
	public function get_amazon_provision_lockout() {
		$timeout = self::get_transient_timeout( 'mv_create_amazon_provision' );
		if ( $timeout ) {
			$time = $timeout - time();
			if ( $time > 0 ) {
				return new \WP_Error(
					'creators_provisioning',
					__( 'Waiting for Amazon Affiliates Credential Provision', 'mediavine-create' ),
					[
						'status'  => 403,
						'message' => sprintf(
							// Translators: Remaining time
							__( 'Amazon Affiliates may still be provisioning. Expected time remaining: %s. Please manually add an image and title.', 'mediavine-create' ),
							$this->seconds_to_time( $time )
						),
					]
				);
			}
		}

		return $timeout;
	}

	/**
	 * Retrieve transient timeout.
	 *
	 * @param string $transient Transient name.
	 * @return false|mixed
	 */
	public static function get_transient_timeout( $transient ) {
		global $wpdb;

		$complete = Settings::get_setting( $transient . '_complete', false );
		if ( $complete ) {
			// Looking for a timeout's existence — if complete, existence is false.
			return false;
		}

		$sanitized_transient = preg_replace( '/[^a-zA-Z0-9_]/', '', $transient );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
		$transient_timeout   = $wpdb->get_col(
			"
		  SELECT option_value
		  FROM $wpdb->options
		  WHERE option_name
		  LIKE '%_transient_timeout_$sanitized_transient%'
		"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

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
	 * Converts seconds to readable time.
	 *
	 * @param int     $input_seconds    Seconds.
	 * @param boolean $display_seconds  Whether seconds should be displayed.
	 * @return string Human readable time.
	 */
	public function seconds_to_time( $input_seconds, $display_seconds = false ) {
		$hours = $input_seconds / HOUR_IN_SECONDS;

		$minute_seconds = $input_seconds % HOUR_IN_SECONDS;
		$minutes        = floor( $minute_seconds / MINUTE_IN_SECONDS );

		$time_parts = [];
		$sections   = [
			[
				'time'     => (int) $hours,
				'singular' => __( 'hour', 'mediavine-create' ),
				'plural'   => __( 'hours', 'mediavine-create' ),
			],
			[
				'time'     => (int) $minutes,
				'singular' => __( 'minute', 'mediavine-create' ),
				'plural'   => __( 'minutes', 'mediavine-create' ),
			],
		];

		if ( $display_seconds ) {
			$remaining_seconds = $input_seconds % MINUTE_IN_SECONDS;
			$seconds           = ceil( $remaining_seconds );

			$sections[] = [
				'time'     => (int) $seconds,
				'singular' => __( 'second', 'mediavine-create' ),
				'plural'   => __( 'seconds', 'mediavine-create' ),
			];
		}

		foreach ( $sections as $section ) {
			if ( $section['time'] > 0 ) {
				$time_parts[] = $section['time'] . ' ' . ( 1 === $section['time'] ? $section['singular'] : $section['plural'] );
			}
		}

		return implode( ', ', $time_parts );
	}

	private function get_oauth_token() {
		$transient_key = 'mv_create_creators_oauth_token';
		$cached_token  = get_transient( $transient_key );

		if ( ! empty( $cached_token ) ) {
			return $cached_token;
		}

		$config         = self::VERSION_CONFIG[ $this->credential_version ];
		$token_endpoint = $config['token_endpoints'][ $this->region ] ?? reset( $config['token_endpoints'] );

		if ( self::VERSION_V3 === $this->credential_version ) {
			$request_args = [
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->credential_id,
					'client_secret' => $this->credential_secret,
					'scope'         => $config['scope'],
				] ),
			];
		} else {
			// Cognito requires client credentials via HTTP Basic Auth, not in the body.
			$basic_auth   = base64_encode( $this->credential_id . ':' . $this->credential_secret );
			$request_args = [
				'headers' => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . $basic_auth,
				],
				'body'    => [
					'grant_type' => 'client_credentials',
					'scope'      => $config['scope'],
				],
			];
		}
		$request_args['timeout'] = 30;

		$response = wp_remote_post( $token_endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			$this->log_oauth_failure( $token_endpoint, 'wp_error', $response->get_error_message() );
			return new \WP_Error(
				'creators_oauth_error',
				__( 'Amazon: OAuth Token Request Failed', 'mediavine-create' ),
				[
					'status'  => 500,
					'message' => sprintf(
						/* translators: %s: the transport-level error message */
						__( 'Could not obtain an OAuth token from Amazon. Error: %s', 'mediavine-create' ),
						$response->get_error_message()
					),
				]
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$body        = json_decode( $raw_body, true );

		if ( 200 !== $status_code || empty( $body['access_token'] ) ) {
			$this->log_oauth_failure( $token_endpoint, (string) $status_code, $raw_body );

			// Extract the specific Amazon error. Cognito returns { error, error_description }.
			$error_code = is_array( $body ) ? ( $body['error'] ?? '' ) : '';
			$error_desc = is_array( $body ) ? ( $body['error_description'] ?? '' ) : '';

			// Build a user-facing detail string that includes whatever Amazon told us.
			$detail_parts = [];
			if ( ! empty( $error_code ) ) {
				$detail_parts[] = $error_code;
			}
			if ( ! empty( $error_desc ) ) {
				$detail_parts[] = $error_desc;
			}
			if ( empty( $detail_parts ) && ! empty( $raw_body ) ) {
				// Amazon returned something unparseable; surface it truncated so the
				// user/support can see what Amazon actually said.
				$detail_parts[] = mb_substr( wp_strip_all_tags( $raw_body ), 0, 300 );
			}
			$detail = ! empty( $detail_parts )
				? implode( ': ', $detail_parts )
				: __( 'Unknown error', 'mediavine-create' );

			$guidance = $this->get_oauth_error_guidance( $error_code );

			return new \WP_Error(
				'creators_oauth_error',
				__( 'Amazon: OAuth Authentication Failed', 'mediavine-create' ),
				[
					'status'  => $status_code,
					'message' => sprintf(
						/* translators: 1: HTTP status code, 2: Amazon's error detail, 3: guidance */
						__( 'Amazon rejected your Creators API credentials (HTTP %1$d): %2$s %3$s', 'mediavine-create' ),
						$status_code,
						$detail,
						$guidance
					),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Credentials in Settings', 'mediavine-create' ),
				]
			);
		}

		$access_token = $body['access_token'];
		$expires_in   = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;

		// Cache with a 30-second buffer before actual expiry.
		$ttl = max( $expires_in - 30, 60 );
		set_transient( $transient_key, $access_token, $ttl );

		return $access_token;
	}

	/**
	 * Guidance for OAuth-level error codes (from Amazon's token endpoint).
	 * Built at call time so __() runs with the current locale.
	 */
	private function get_oauth_error_guidance( $error_code ) {
		$guidance = [
			'invalid_client'      => __( 'This usually means the Credential ID or Credential Secret is incorrect, or your credentials are still provisioning (allow up to 48 hours after creation).', 'mediavine-create' ),
			'invalid_scope'       => __( 'Your credentials do not have access to the Creators API scope. Verify the credentials were created in Associates Central under Tools > Creators API.', 'mediavine-create' ),
			'unauthorized_client' => __( 'Your Associates account may not be approved for the Creators API yet, or it was disabled. Check your account status in Associates Central.', 'mediavine-create' ),
			'invalid_grant'       => __( 'The client_credentials grant was rejected. Double-check your Credential ID and Secret for copy/paste errors (including trailing spaces).', 'mediavine-create' ),
			'invalid_request'     => __( 'The request was rejected as malformed. Please report this to support.', 'mediavine-create' ),
		];
		return $guidance[ $error_code ] ?? __( 'Please verify your Credential ID and Secret in Associates Central.', 'mediavine-create' );
	}

	// The credential secret is never logged; the credential ID is masked to its prefix.
	private function log_oauth_failure( $endpoint, $status_code, $body_or_msg ) {
		if ( ( defined( 'PHPUNIT_MV_TESTING' ) && PHPUNIT_MV_TESTING ) || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		Help::log(
			sprintf(
				'[mv_create Amazon Creators] OAuth failure | version=%s | endpoint=%s | status=%s | credential_id_prefix=%s | response=%s',
				$this->credential_version,
				$endpoint,
				$status_code,
				$this->mask_credential( $this->credential_id ),
				mb_substr( (string) $body_or_msg, 0, 1000 )
			)
		);
	}

	private function mask_credential( $value ) {
		if ( empty( $value ) ) {
			return '(empty)';
		}
		return mb_substr( (string) $value, 0, 12 ) . '…(' . mb_strlen( (string) $value ) . ' chars)';
	}

	private function log_api_failure( $status_code, $body_or_msg, $request = [] ) {
		if ( ( defined( 'PHPUNIT_MV_TESTING' ) && PHPUNIT_MV_TESTING ) || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$context = [
			'marketplace' => $this->marketplace,
			'region'      => $this->region,
			'domain'      => $this->marketplace_domain,
			'asins'       => $request['itemIds'] ?? [],
		];

		Help::log(
			sprintf(
				'[mv_create Amazon Creators] API failure | status=%s | context=%s | response=%s',
				$status_code,
				wp_json_encode( $context ),
				mb_substr( (string) $body_or_msg, 0, 1000 )
			)
		);
	}

	public function get_products_by_asin( $asins ) {
		$api_enabled = $this->is_api_enabled();
		if ( is_wp_error( $api_enabled ) ) {
			return $api_enabled;
		}

		$api_is_setup = $this->is_affiliate_api_setup();
		if ( is_wp_error( $api_is_setup ) ) {
			return $api_is_setup;
		}

		$timeout = $this->get_amazon_provision_lockout();
		if ( is_wp_error( $timeout ) ) {
			return $timeout;
		}

		// Coerce scalar ASINs to array; null/non-array means no ASINs to look up,
		// so short-circuit rather than sending Amazon an empty itemIds payload.
		if ( is_string( $asins ) ) {
			$asins = (array) $asins;
		}
		if ( ! is_array( $asins ) || empty( $asins ) ) {
			return [];
		}

		$token = $this->get_oauth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$request_body = [
			'partnerTag' => $this->tag,
			'itemIds'    => array_values( $asins ),
			'resources'  => [
				'itemInfo.title',
				'images.primary.large',
			],
		];

		// v2.x credentials append `, Version X.Y`; v3.x (LwA) uses just `Bearer`.
		$authorization = 'Bearer ' . $token;
		$api_version   = self::VERSION_CONFIG[ $this->credential_version ]['api_versions'][ $this->region ] ?? '';
		if ( '' !== $api_version ) {
			$authorization .= ', Version ' . $api_version;
		}

		$response = wp_remote_post(
			self::API_HOST . self::GET_ITEMS_PATH,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'x-marketplace' => $this->marketplace_domain,
					'Authorization' => $authorization,
				],
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log_api_failure( 'wp_error', $response->get_error_message(), $request_body );
			return new \WP_Error(
				'creators_api_request_error',
				__( 'Amazon: Creators API Request Failed', 'mediavine-create' ),
				[
					'status'  => 500,
					'message' => sprintf(
						/* translators: %s: the transport-level error message */
						__( 'Could not reach the Amazon Creators API. Error: %s', 'mediavine-create' ),
						$response->get_error_message()
					),
				]
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$body        = json_decode( $raw_body, true );

		if ( $status_code >= 400 ) {
			$this->log_api_failure( (string) $status_code, $raw_body, $request_body );
			return $this->get_api_exceptions( $status_code, $body );
		}

		$products = [];

		if ( ! empty( $body['itemsResult']['items'] ) ) {
			foreach ( $body['itemsResult']['items'] as $item ) {
				$asin = $item['asin'] ?? null;
				if ( empty( $asin ) ) {
					continue;
				}

				$title     = $item['itemInfo']['title']['displayValue'] ?? null;
				$image_url = $item['images']['primary']['large']['url'] ?? null;

				$products[ $asin ] = [
					'asin'                   => $asin,
					'title'                  => $title,
					'description'            => $title,
					'external_thumbnail_url' => $image_url,
					'expires'                => gmdate( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
				];
			}
		}

		return $products;
	}

	/**
	 * Normalize the exception class name. Handles both `type` and the legacy
	 * `__type` with namespace prefix (`com.amazon.creators#X`) and
	 * `ResponseContent` suffix — so `UnauthorizedExceptionResponseContent` and
	 * `com.amazon.creators#UnauthorizedException` both yield `UnauthorizedException`.
	 */
	private function normalize_exception_type( $body ) {
		$raw = $body['type'] ?? $body['__type'] ?? '';
		if ( empty( $raw ) ) {
			return '';
		}
		if ( false !== strpos( $raw, '#' ) ) {
			$raw = substr( $raw, strpos( $raw, '#' ) + 1 );
		}
		return preg_replace( '/ResponseContent$/', '', $raw );
	}

	/**
	 * Guidance per Amazon-documented `reason` code. Mirrors the tables at
	 * https://affiliate-program.amazon.com/creatorsapi/docs/en-us/troubleshooting/error-codes-and-messages
	 */
	private function get_reason_guidance( $reason ) {
		$guidance = [
			// ValidationException reasons
			'UnknownOperation'       => __( 'The requested API operation was not recognized by Amazon. This is likely a bug in the plugin — please report it.', 'mediavine-create' ),
			'CannotParse'            => __( 'Amazon could not parse the request. This is likely a bug in the plugin — please report it.', 'mediavine-create' ),
			'FieldValidationFailed'  => __( 'One or more request fields failed validation. See the field list for details.', 'mediavine-create' ),
			'InvalidAssociate'       => __( 'Your Creators API credentials are not linked to the Associate Tag for this marketplace. Verify the tag and marketplace selection in settings.', 'mediavine-create' ),
			'InvalidPartnerTag'      => __( 'The Associate Tag is invalid or is not mapped to the store associated with your credentials. Double-check the tag in your settings.', 'mediavine-create' ),

			// AccessDeniedException reasons
			'AssociateNotEligible'   => __( 'Your Associates account does not currently meet the eligibility requirements (10 qualified sales in the trailing 30 days). Access is restored within 2 days of new qualified sales.', 'mediavine-create' ),
			'AuthorizationFailed'    => __( 'Amazon rejected the authorization check. Verify your credentials and Associate Tag are for the same account/marketplace.', 'mediavine-create' ),

			// UnauthorizedException reasons
			'TokenExpired'           => __( 'The access token expired. The plugin will automatically refresh it on the next request.', 'mediavine-create' ),
			'InvalidToken'           => __( 'The access token is invalid or malformed. The plugin will regenerate it on the next request.', 'mediavine-create' ),
			'InvalidIssuer'          => __( 'The token issuer does not match the expected issuer for this marketplace. Verify that the selected marketplace matches the region where your credentials were created.', 'mediavine-create' ),
			'MissingClaim'           => __( 'The access token is missing required claims. This is likely an issue with how the plugin obtained the token — please report it.', 'mediavine-create' ),
			'MissingKeyId'           => __( 'The access token is missing its key identifier. This is likely an issue with how the plugin obtained the token — please report it.', 'mediavine-create' ),
			'UnsupportedClient'      => __( 'Your client credentials are not registered for the Creators API. Verify you generated them from Associates Central > Tools > Creators API.', 'mediavine-create' ),
			'InvalidClient'          => __( 'The client identifier does not match the expected value. Double-check your Credential ID for typos or extra whitespace.', 'mediavine-create' ),
			'MissingCredential'      => __( 'The request was missing authentication credentials. This is likely a plugin bug — please report it.', 'mediavine-create' ),
		];

		return $guidance[ $reason ] ?? '';
	}

	private function format_error_detail( $body ) {
		$parts          = [];
		$amazon_message = $body['message'] ?? '';
		$reason         = $body['reason'] ?? '';

		if ( ! empty( $amazon_message ) ) {
			$parts[] = $amazon_message;
		}

		if ( ! empty( $reason ) ) {
			/* translators: %s: reason text */
			$parts[] = sprintf( __( '[Reason: %s]', 'mediavine-create' ), $reason );
		}

		if ( ! empty( $body['fieldList'] ) && is_array( $body['fieldList'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of invalid field names */
				__( 'Invalid fields: %s', 'mediavine-create' ),
				implode( ', ', $body['fieldList'] )
			);
		}

		if ( ! empty( $body['resourceType'] ) || ! empty( $body['resourceId'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: resource type (e.g. "Item"), 2: resource ID (e.g. ASIN) */
				__( 'Resource: %1$s "%2$s"', 'mediavine-create' ),
				$body['resourceType'] ?? '?',
				$body['resourceId']   ?? '?'
			);
		}

		// Throttling retry hint.
		if ( isset( $body['retryAfterSeconds'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: number of seconds */
				__( 'Retry after %d seconds.', 'mediavine-create' ),
				(int) $body['retryAfterSeconds']
			);
		}

		$guidance = $this->get_reason_guidance( $reason );
		if ( ! empty( $guidance ) ) {
			$parts[] = $guidance;
		}

		return implode( ' ', $parts );
	}

	/**
	 * Map Amazon's exception type + reason to a structured WP_Error. Docs:
	 * https://affiliate-program.amazon.com/creatorsapi/docs/en-us/troubleshooting/error-codes-and-messages
	 */
	private function get_api_exceptions( $status_code, $body ) {
		$body           = is_array( $body ) ? $body : [];
		$exception_type = $this->normalize_exception_type( $body );
		$reason         = $body['reason'] ?? '';
		$detail         = $this->format_error_detail( $body );

		$base_data = [
			'status'         => $status_code,
			'exception_type' => $exception_type,
			'reason'         => $reason,
			'amazon_message' => $body['message'] ?? '',
		];

		$affiliates_link = [
			'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
			'link_text' => __( 'Check Your Settings', 'mediavine-create' ),
		];

		// Table of known exception shapes: [status, exception_type] → WP_Error spec.
		$map = [
			[
				'status'     => 401,
				'type'       => 'UnauthorizedException',
				'code'       => 'creators_unauthorized',
				'title'      => __( 'Amazon: Authentication Failed', 'mediavine-create' ),
				'base'       => __( 'Amazon rejected your Creators API credentials.', 'mediavine-create' ),
				'extra'      => array_merge( $affiliates_link, [ 'link_text' => __( 'Check Your Credentials in Settings', 'mediavine-create' ) ] ),
				'on_match'   => function () { delete_transient( 'mv_create_creators_oauth_token' ); },
			],
			[
				'status'     => 403,
				'type'       => 'AccessDeniedException',
				'code'       => 'creators_access_denied',
				'title'      => __( 'Amazon: Access Denied', 'mediavine-create' ),
				'base'       => __( 'Amazon denied access to the Creators API.', 'mediavine-create' ),
				'extra'      => [
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( 'Check Your Account in Amazon Associates Central', 'mediavine-create' ),
				],
			],
			[
				'status'     => 429,
				'type'       => 'ThrottleException',
				'code'       => 'creators_rate_limited',
				'title'      => __( 'Amazon: Rate Limit Exceeded', 'mediavine-create' ),
				'base'       => __( 'Amazon is rate-limiting your requests. Wait a few minutes before trying again.', 'mediavine-create' ),
				'extra'      => [ 'retry_after' => isset( $body['retryAfterSeconds'] ) ? (int) $body['retryAfterSeconds'] : null ],
			],
			[
				'status'     => 400,
				'type'       => 'ValidationException',
				'code'       => 'creators_validation_error',
				'title'      => __( 'Amazon: Invalid Request', 'mediavine-create' ),
				'base'       => __( 'Amazon reports an invalid request.', 'mediavine-create' ),
				'extra'      => [ 'field_list' => $body['fieldList'] ?? [] ],
			],
			[
				'status'     => 404,
				'type'       => 'ResourceNotFoundException',
				'code'       => 'creators_not_found',
				'title'      => __( 'Amazon: Resource Not Found', 'mediavine-create' ),
				'base'       => __( 'The requested Amazon resource was not found.', 'mediavine-create' ),
				'extra'      => array_merge( $affiliates_link, [
					'resource_type' => $body['resourceType'] ?? '',
					'resource_id'   => $body['resourceId']   ?? '',
				] ),
			],
			[
				'status'     => 500,
				'type'       => 'InternalServerException',
				'code'       => 'creators_server_error',
				'title'      => __( 'Amazon: Server Error', 'mediavine-create' ),
				'base'       => __( 'Amazon reported an unexpected server error. Please try again in a few minutes.', 'mediavine-create' ),
				'extra'      => [],
			],
		];

		foreach ( $map as $entry ) {
			if ( $status_code !== $entry['status'] && $exception_type !== $entry['type'] ) {
				continue;
			}
			if ( isset( $entry['on_match'] ) ) {
				$entry['on_match']();
			}
			return new \WP_Error(
				$entry['code'],
				$entry['title'],
				array_merge( $base_data, $entry['extra'], [
					'message' => $this->compose_message( $entry['base'], $detail ),
				] )
			);
		}

		return new \WP_Error(
			'creators_api_error',
			__( 'Amazon: Creators API Error', 'mediavine-create' ),
			array_merge( $base_data, [
				'message' => $this->compose_message(
					/* translators: %d: HTTP status code */
					sprintf( __( 'An error occurred with the Amazon Creators API (HTTP %d).', 'mediavine-create' ), $status_code ),
					$detail
				),
			] )
		);
	}

	private function compose_message( $base, $detail ) {
		return trim( empty( $detail ) ? $base : $base . ' ' . $detail );
	}

	/** Setters for testing. */
	public function set_credential_id( $id ) {
		$this->credential_id = $id;
		$this->refresh_derived();
	}
	public function set_credential_secret( $secret ) {
		$this->credential_secret = $secret;
	}
	public function set_tag( $tag ) {
		$this->tag = $tag;
	}
	public function set_marketplace( $marketplace ) {
		$this->marketplace = $marketplace;
		$this->refresh_derived();
	}
	public function set_enabled( $enabled = true ) {
		$this->enabled = $enabled;
	}
}
