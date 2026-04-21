<?php
namespace Mediavine\Create;

use \WP_REST_Request as Request;
use \WP_REST_Response as Response;
use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;

/**
 * Products_API class
 * Handles REST functions for the /products endpoint
 */
class Products_API extends Products {

	/**
	 * Insert a product
	 *
	 * @param Request  $request WordPress Request object
	 * @param Response $response WordPress Response object
	 *
	 * @return \WP_Error|Response
	 */
	public function upsert( Request $request, Response $response ) {
		$product          = $request->get_params();
		$result           = null;
		$or_statement     = [];
		$fields_to_update = [
			'id',
			'created',
			'modified',
			'title',
			'description',
			'link',
			'thumbnail_id',
			'remote_thumbnail_uri',
			'asin',
			'external_thumbnail_url',
			'expires',
			'thumbnail_uri',
		];

		$product = Arr::only( $product['data'], $fields_to_update );

		// get Amazon data here
		$amazon_scraper = Amazon_Adapter::get_instance();
		$asin           = $amazon_scraper->get_asin_from_link( $product['link'] );

		if ( ! empty( $asin ) && 10 === Str::length( $asin ) ) {
			$product['asin'] = $asin;
		}

		if ( ! empty( $product['remote_thumbnail_uri'] ) && ! empty( $asin ) ) {
			$product['external_thumbnail_url'] = $product['remote_thumbnail_uri'];
			$product['thumbnail_uri']          = $product['remote_thumbnail_uri'];
		}

		if ( ! empty( $product['thumbnail_id'] ) ) {
			$img_url = wp_get_attachment_url( $product['thumbnail_id'] );

			$product['external_thumbnail_url'] = $img_url;
			$product['remote_thumbnail_uri']   = $img_url;
			$product['thumbnail_uri']          = $img_url;
		}

		// Attempt to create a new thumbnail if there isn't one
		if ( ! empty( $product['remote_thumbnail_uri'] ) && empty( $asin ) ) {
			$product = static::prepare_product_thumbnail( $product );
		}

		$result        = [];
		$found_product = [];

		if ( ! empty( $product['id'] ) ) {
			// Check for id in params
			$found_product = self::$models_v2->mv_products->find_one( $product['id'] );
		}

		if ( empty( $found_product ) && ! empty( $product['link'] ) ) {
			// If not, check for product with same link
			// If exists, return
			$found_product_by_link = self::$models_v2->mv_products->find_one(
				[
					'where' => [
						'link' => $product['link'],
					],
				]
			);
			if ( ! empty( $found_product_by_link ) ) {
				$found_product = $found_product_by_link;
				$product['id'] = $found_product->id;
			}
		}

		if ( ! empty( $found_product ) ) {
			// Allow normalized null to potentially reset thumbnails ids
			add_filter( 'mv_create_allow_normalized_null', '__return_true' );
			$result = self::$models_v2->mv_products->update(
				$product
			);
			remove_filter( 'mv_create_allow_normalized_null', '__return_false' );
		}

		// Sanitize title - remove excessive whitespace and newlines from scraped content
		if ( ! empty( $product['title'] ) ) {
			$product['title'] = preg_replace( '/\s+/', ' ', trim( $product['title'] ) );
		}

		// Make sure title and link before Create
		if ( empty( $product['title'] ) ) {
			return new \WP_Error(
				'missing_required_title',
				__( 'Title Not Found', 'mediavine' ),
				[
					'status'  => 400,
					'message' => __( 'The product is missing a title', 'mediavine' ),
				]
			);
		}
		if ( empty( $product['id'] ) && empty( $product['link'] ) ) {
			return new \WP_Error(
				'missing_required_link',
				__( 'URL Not Found', 'mediavine' ),
				[
					'status'  => 400,
					'message' => __( 'The product is missing a link', 'mediavine' ),
				]
			);
		}

		// If not, create
		if ( empty( $result ) ) {
			$result = self::$models_v2->mv_products->create( $product );
		}

		if ( empty( $result ) ) {
			return new \WP_Error(
				404,
				__( 'Entry Not Found', 'mediavine' ),
				[
					'message'    => __( 'The Product could not be found', 'mediavine' ),
					'error_code' => 'product_not_found',
				]
			);
		}
		$data     = self::$api_services->prepare_item_for_response( $result, $request );
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Find products for a given request
	 *
	 * @param Request  $request WordPress REST Request object
	 * @param Response $response WordPress REST Response object
	 *
	 * @return Response
	 */
	public function find( Request $request, Response $response ) {
		$allowed_params = [
			'title',
		];
		$params         = $request->get_params();
		$query_args     = [];
		if ( isset( $response->query_args ) ) {
			$query_args = $response->query_args;
		}

		$query_args['where'] = [];

		// Default ordering: newest first. Must be set explicitly because the shared model
		// instance can have its order contaminated by other hooks (e.g. refresh_product_images
		// sets order_by='expires' and order='ASC' on the same instance).
		if ( ! isset( $query_args['order_by'] ) ) {
			$query_args['order_by'] = 'created';
		}
		if ( ! isset( $query_args['order'] ) ) {
			$query_args['order'] = 'DESC';
		}

		$creation_id = false;
		if ( ! empty( $params['creation'] ) ) {
			$creation_id = $params['creation'];
			unset( $params['creation'] );
		}

		if ( isset( $params['search'] ) ) {
			$query_args['where']['title'] = $params['search'];
		}

		if ( ! empty( $params ) ) {
			foreach ( $params as $param => $value ) {
				if ( in_array( $param, $allowed_params, true ) ) {
					$query_args['where'][ $param ] = $value;
				}
			}
		}

		// Handle filter parameter for Amazon vs Other products using raw SQL
		$count_query_args = $query_args;
		if ( ! empty( $params['filter'] ) && 'all' !== $params['filter'] ) {
			$table = self::$models_v2->mv_products->table_name;
			$where_clauses = [];
			$prepare_values = [];

			// Build existing where clauses
			if ( ! empty( $query_args['where']['title'] ) ) {
				$where_clauses[] = "title LIKE '%%%s%%'";
				$prepare_values[] = $query_args['where']['title'];
			}

			// Add filter for Amazon/Other
			if ( 'amazon' === $params['filter'] ) {
				$where_clauses[] = 'asin IS NOT NULL AND asin != %s';
				$prepare_values[] = '';
			} elseif ( 'other' === $params['filter'] ) {
				$where_clauses[] = '(asin IS NULL OR asin = %s)';
				$prepare_values[] = '';
			}

			if ( ! empty( $where_clauses ) ) {
				$where_sql = implode( ' AND ', $where_clauses );

				// Get pagination params
				$limit = isset( $params['limit'] ) ? (int) $params['limit'] : 20;
				$page = isset( $params['page'] ) ? (int) $params['page'] : 1;
				$offset = ( $page - 1 ) * $limit;

				$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created DESC LIMIT {$limit} OFFSET {$offset}";
				$count_sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$where_sql}";
				$query_args = [
					'sql'    => $sql,
					'params' => $prepare_values,
				];
				$count_query_args = [
					'sql'    => $count_sql,
					'params' => $prepare_values,
				];
			}
		}

		$products = self::$models_v2->mv_products->find( $query_args );
		$products = Products::filter_existing_products( $creation_id, $products );

		// Get count - use raw SQL count if filter was applied
		$total_count = 0;
		if ( ! empty( $count_query_args['sql'] ) ) {
			global $wpdb;
			$count_result = $wpdb->get_var( $wpdb->prepare( $count_query_args['sql'], $count_query_args['params'] ) );
			$total_count = (int) $count_result;
		} else {
			$total_count = self::$models_v2->mv_products->get_count( $count_query_args );
		}

		if ( wp_is_numeric_array( $products ) ) {
			$data = [];
			foreach ( $products as $product ) {
				// Thumbnail order: local, external, null
				$product->thumbnail_uri = null;
				if ( isset( $product->external_thumbnail_url ) ) {
					$product->thumbnail_uri = $product->external_thumbnail_url;
				}
				if ( ! empty( $product->thumbnail_id ) ) {
					$product->thumbnail_uri = wp_get_attachment_url( $product->thumbnail_id );
				}

				$product->creations = Products::get_product_creations( $product->id );
				$data[]             = self::$api_services->prepare_item_for_response( $product, $request );
			}

			$response->set_status( 200 );
		}
		$response = API_Services::set_response_data( $data, $response );
		$response->header( 'X-Total-Items', $total_count );
		return $response;
	}

	/**
	 * Return singular product
	 *
	 * @param Request  $request WordPress Request object
	 * @param Response $response WordPress Response object
	 *
	 * @return \WP_Error|Response
	 */
	public function find_one( Request $request, Response $response ) {
		$params  = $request->get_params();
		$product = self::$models_v2->mv_products->find_one( $params['id'] );

		if ( empty( $product ) ) {
			return new \WP_Error(
				404,
				__( 'Entry Not Found', 'mediavine' ),
				[
					'message'    => __( 'The Product could not be found', 'mediavine' ),
					'error_code' => 'product_not_found',
				]
			);
		}

		if ( isset( $product->external_thumbnail_url ) ) {
			$product->thumbnail_uri = isset( $product->external_thumbnail_url ) ? $product->external_thumbnail_url : '';
		}

		if ( isset( $product->thumbnail_id ) ) {
			$product->thumbnail_uri = wp_get_attachment_url( $product->thumbnail_id );
		}

		$product->creations = Products::get_product_creations( $product->id );

		$data     = self::$api_services->prepare_item_for_response( $product, $request );
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Get pagination details for products neighboring given product.
	 *
	 * @param Request  $request WordPress Request object
	 * @param Response $response WordPress Response object

	 * @return Response $response
	 */
	public function get_pagination_links( Request $request, Response $response ) {
		$product = $response->get_data()['data'];

		$args             = [
			'table'  => 'mv_products',
			'fields' => [ 'id', 'title' ],
			'id'     => $product['id'],
		];
		$product['links'] = Paginator::make_links( $args );

		return API_Services::set_response_data( $product, $response );
	}

	/**
	 * Scrape product url for data
	 *
	 * @param Request  $request WordPress Request object
	 * @param Response $response WordPress Response object
	 *
	 * @return array|\WP_Error|Response
	 */
	public function scrape( Request $request, Response $response ) {
		$simulated = $this->maybe_simulate_scrape_error( $request );
		if ( $simulated ) {
			return $simulated;
		}

		$params = $request->get_params();
		$link   = $params['link'];

		$result = self::$models_v2->mv_products->find_one(
			[
				'where' => [
					'link' => $link,
				],
			]
		);

		if ( ! empty( $result ) ) {
			$existing = (array) $result;
		}

		// If the result doesn't exist or doesn't have a thumbnail, we make a fresh attempt.
		if ( ! $result || ! empty( $result->thumbnail_id ) || empty( $result->external_thumbnail_url ) ) {
			$amazon_scraper = Amazon_Adapter::get_instance();
			$asin           = ! empty( $result->asin ) ? $result->asin : $amazon_scraper->get_asin_from_link( $link );
			if ( ! empty( $asin ) && Str::length( $asin ) === 10 ) {
				$scraped = $amazon_scraper->get_products_by_asin( $asin );
				if ( is_wp_error( $scraped ) ) {
					return $scraped;
				}
				if ( ! empty( $scraped[ $asin ] ) ) {
					$result = $scraped[ $asin ];
					if ( ! empty( $existing ) ) {
						$result['rescraped'] = true;
						$result['existing']  = $existing;
					}
				}
			}
		}

		if ( ! $result ) {
			return new \WP_Error(
				404,
				__( 'No Data Found', 'mediavine' ),
				[
					'message'    => __( 'The Product link scrape did not turn up any results', 'mediavine' ),
					'error_code' => 'scrape_empty',
				]
			);
		}

		// If thumbnail ID and isn't external and hasn't been previously generated
		if ( isset( $result->thumbnail_id ) && empty( $result->thumbnail_uri ) ) {
			$result->thumbnail_uri = wp_get_attachment_url( $result->thumbnail_id );
		}

		// If is an object and has a thumbnail URL (re-processed product)
		if ( ! empty( $result->external_thumbnail_url ) ) {
			$result->thumbnail_id         = null;
			$result->thumbnail_uri        = $result->external_thumbnail_url;
			$result->remote_thumbnail_uri = $result->external_thumbnail_url;
		}

		// If has external thumbnail url and is array (product doesn't already exist)
		if ( ! empty( is_array( $result ) && $result['external_thumbnail_url'] ) ) {
			$result['thumbnail_id']         = null;
			$result['thumbnail_uri']        = $result['external_thumbnail_url'];
			$result['remote_thumbnail_uri'] = $result['external_thumbnail_url'];
		}

		$response = API_Services::set_response_data( $result, $response );
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Scrape NON-AMAZON product url for data
	 *
	 * @param Request  $request
	 * @param Response $response
	 *
	 * @return array|\WP_Error|Response
	 */
	public function scrape_non_amazon( Request $request, Response $response ) {
		$simulated = $this->maybe_simulate_scrape_error( $request );
		if ( $simulated ) {
			return $simulated;
		}

		$params = $request->get_params();
		$link   = $params['link'];

		$result = self::$models_v2->mv_products->find_one(
			[
				'where' => [
					'link' => $link,
				],
			]
		);

		if ( ! empty( $result ) ) {
			$existing = (array) $result;
		}

		// If the result doesn't exist or doesn't have a thumbnail, we make a fresh attempt.
		if ( ! $result || ! empty( $result->thumbnail_id ) || empty( $result->external_thumbnail_url ) ) {
			$amazon_scraper = Amazon_Adapter::get_instance();
			$asin           = ! empty( $result->asin ) ? $result->asin : $amazon_scraper->get_asin_from_link( $link );
			// If an ASIN was found, use PAAPI instead of the external scraper
			if ( ! empty( $asin ) && Str::length( $asin ) === 10 ) {
				$scraped = $amazon_scraper->get_products_by_asin( $asin );
				if ( is_wp_error( $scraped ) ) {
					return $scraped;
				}
				if ( ! empty( $scraped[ $asin ] ) ) {
					$result = $scraped[ $asin ];
					if ( ! empty( $existing ) ) {
						$result['rescraped'] = true;
						$result['existing']  = $existing;
					}
				}
			}
			//do external scrape
			if ( empty( $asin ) ) {
				$api_token_setting = \Mediavine\Settings::get_settings( 'mv_create_api_token' );
				$services_api_url = self::$services_api_url;
				$scrape_url = $services_api_url . '/scraper/scrape';
				$scraped           = wp_remote_post(
					$scrape_url, [
						'headers' => [
							'Content-Type'  => 'application/json; charset=utf-8',
							'Authorization' => 'bearer ' . $api_token_setting->value,
						],
						'body'    => wp_json_encode( [
							'url' => $link,
						] ),
					]
				);
				if ( is_wp_error( $scraped ) ) {
					return $scraped;
				}
				if ( ! empty( $scraped['body'] ) ) {
					$result = json_decode( $scraped['body'], true )['data'];
					// $result = [
					// 	'remote_thumbnail_uri'
					// ]
					if ( ! empty( $existing ) ) {
						$result['rescraped'] = true;
						$result['existing']  = $existing;
					}
				}
			}
		}

		if ( ! $result ) {
			return new \WP_Error(
				404,
				__( 'No Data Found', 'mediavine' ),
				[
					'message'    => __( 'The Product link scrape did not turn up any results', 'mediavine' ),
					'error_code' => 'scrape_empty',
				]
			);
		}

		if ( ! empty( $existing ) ) {
				$result['rescraped'] = true;
				// If thumbnail ID and isn't external and hasn't been previously generated
				if ( isset( $existing['thumbnail_id'] ) && empty( $existing['thumbnail_uri'] ) ) {
					$existing['thumbnail_uri'] = wp_get_attachment_url( $existing['thumbnail_id'] );
				}
				$result['existing']  = $existing;
				
		}

		// If thumbnail ID and isn't external and hasn't been previously generated
		if ( isset( $result->thumbnail_id ) && empty( $result->thumbnail_uri ) ) {
			$result->thumbnail_uri = wp_get_attachment_url( $result->thumbnail_id );
		}

		// If is an object and has a thumbnail URL (re-processed product)
		if ( ! empty( $result->external_thumbnail_url ) ) {
			$result->thumbnail_id         = null;
			$result->thumbnail_uri        = $result->external_thumbnail_url;
			$result->remote_thumbnail_uri = $result->external_thumbnail_url;
		}

		// If has external thumbnail url and is array (product doesn't already exist)
		if ( ! empty( is_array( $result ) && $result['external_thumbnail_url'] ) ) {
			$result['thumbnail_id']         = null;
			$result['thumbnail_uri']        = $result['external_thumbnail_url'];
			$result['remote_thumbnail_uri'] = $result['external_thumbnail_url'];
			$result['title']                = $result['title'];
		}
		error_log('scrape_non_amazon result: ' . print_r($result, true));

		$response = API_Services::set_response_data( $result, $response );
		$response->set_status( 200 );
		return $response;
	}

	/**
	 * Reset Amazon thumbnails
	 *
	 * @param Request  $request WordPress Request object
	 * @param Response $response WordPress Response object
	 *
	 * @return Response
	 */
	public function reset_amazon_thumbnails( Request $request, Response $response ) {
		global $wpdb;

		// We need to run this query specifically because our DBI can't handle `IS NOT NULL`.
		$table = self::$models_v2->mv_products->table_name;
		$sql   = "SELECT id, external_thumbnail_url FROM $table WHERE
			thumbnail_id IS NOT NULL AND
			asin IS NOT NULL AND
			external_thumbnail_url IS NOT NULL";

		$amazon_products = self::$models_v2->mv_products->find([
			'sql'    => $sql,
			'params' => [],
		]);

		$result = null;
		if ( ! empty( $amazon_products ) ) {
			$values    = 'VALUES ';
			$republish = [];
			foreach ( $amazon_products as $product ) {
				if ( ! empty( $product->id ) && ! empty( $product->external_thumbnail_url ) ) {
					$product_id = (int) $product->id;
					$values    .= "($product_id, null), ";
					// Prep data for cascade into mv_products_map
					$product->thumbnail_id = null;
					$republish[]           = $product;
				}
			}

			if ( 'VALUES ' !== $values ) {
				// Remove last ", " from $values
				$values = substr( $values, 0, -2 );

				// SECURITY CHECKED: This query is properly sanitized.
				$query = "INSERT INTO $table (id, thumbnail_id) $values ON DUPLICATE KEY UPDATE thumbnail_id=VALUES(thumbnail_id)";

				add_filter( 'query', [ self::$models_v2->mv_products, 'allow_null' ] );
				$result = $wpdb->query( $query );
				remove_filter( 'query', [ self::$models_v2->mv_products, 'allow_null' ] );
			}

			if ( $result ) {
				// Result will be number of updates multiplied by 2 due to the updating of many, so we will half it
				$result = intval( $result / 2 );

				// Cascade through products map, which will trigger republish as well
				if ( ! empty( $republish ) ) {
					foreach ( $republish as $product ) {
						$this->cascade_after_update( $product );
					}
				}
			}
		}

		$response = API_Services::set_response_data( $result, $response );
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Resets Amazon provision lock
	 *
	 * @return void
	 */
	public function reset_amazon_provision() {
		delete_transient( 'mv_create_amazon_provision' );
	}

	/**
	 * Check if a debug error should be simulated for scrape requests.
	 * Only active when WP_DEBUG is enabled. Pass ?simulate_error=error_code
	 * alongside a scrape request to trigger the corresponding Amazon error.
	 *
	 * @param Request $request WordPress Request object
	 * @return \WP_Error|null WP_Error if simulating, null otherwise
	 */
	private function maybe_simulate_scrape_error( Request $request ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return null;
		}

		$error_code = $request->get_param( 'simulate_error' );
		if ( empty( $error_code ) ) {
			return null;
		}

		// Reuse the debug endpoint to get the mock error
		$debug_request = new \WP_REST_Request( 'GET' );
		$debug_request->set_param( 'error_code', $error_code );

		$debug_response = $this->debug_amazon_error( $debug_request, new \WP_REST_Response() );

		return is_wp_error( $debug_response ) ? $debug_response : null;
	}

	/**
	 * Debug endpoint to test Amazon API error messages.
	 * Only available when WP_DEBUG is enabled.
	 *
	 * Usage: GET /wp-json/mv-create/v1/products/debug-amazon-error?error_code=associate_not_eligible
	 *
	 * Available error codes:
	 * - access_denied
	 * - associate_not_eligible
	 * - invalid_partner
	 * - invalid_associate
	 * - invalid_signature
	 * - incomplete_signature
	 * - too_many_requests
	 * - request_expired
	 * - unrecognized_client
	 * - invalid_or_missing_parameter
	 * - unknown_operation
	 * - amazon_plugin_conflict
	 * - create_not_registered
	 * - paapi_not_setup
	 * - paapi_provisioning
	 *
	 * @param Request  $request WordPress Request object
	 * @param Response $response WordPress Response object
	 *
	 * @return \WP_Error
	 */
	public function debug_amazon_error( Request $request, Response $response ) {
		$error_code = $request->get_param( 'error_code' );

		if ( empty( $error_code ) ) {
			return new \WP_Error(
				'missing_error_code',
				__( 'Missing error_code parameter', 'mediavine' ),
				[
					'status'  => 400,
					'message' => __( 'Please provide an error_code parameter. Available codes: access_denied, associate_not_eligible, invalid_partner, invalid_associate, invalid_signature, incomplete_signature, too_many_requests, request_expired, unrecognized_client, invalid_or_missing_parameter, unknown_operation, amazon_plugin_conflict, create_not_registered, paapi_not_setup, paapi_provisioning, creators_api_not_setup, creators_unauthorized, creators_access_denied, creators_rate_limited, creators_validation_error, creators_not_found, creators_oauth_error, creators_api_error', 'mediavine' ),
				]
			);
		}

		$errors = [
			'access_denied'              => new \WP_Error(
				'access_denied',
				__( 'Amazon: API Access Not Enabled', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( "Amazon reports your Access Key doesn't have Product Advertising API access. If you're using AWS credentials, Amazon requires you to migrate them through Associates Central.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( 'Manage Credentials in Amazon Associates Central', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=AccessDeniedException',
				]
			),
			'associate_not_eligible'     => new \WP_Error(
				'associate_not_eligible',
				__( 'Amazon: API Access Paused', 'mediavine' ),
				[
					'status'    => 403,
					'message'   => __( "Amazon requires 10 qualified sales in the trailing 30 days to access their Product Advertising API. Once you meet this threshold, API access restores automatically. You can still add products manually.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/home/reports/summary',
					'link_text' => __( 'View Your Amazon Associates Dashboard', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=AssociateEligibilityException',
				]
			),
			'invalid_partner'            => new \WP_Error(
				'invalid_partner',
				__( 'Amazon: Invalid Store ID', 'mediavine' ),
				[
					'status'    => 400,
					'message'   => __( "Amazon reports your Store ID (Partner Tag) doesn't match your API credentials. Your Store ID looks like \"yoursite-20\" - make sure it's from the same Amazon account as your API keys. Common mistake: using your Access Key ID instead of your Store ID.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Store ID in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=InvalidPartnerTagException',
				]
			),
			'invalid_associate'          => new \WP_Error(
				'invalid_associate',
				__( 'Amazon: Account Not Approved', 'mediavine' ),
				[
					'status'    => 403,
					'message'   => __( "Amazon reports your credentials aren't linked to an approved Associates account. This usually means your Associates application is still pending, or you're using credentials from a different Amazon account than your approved store.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( 'Check Your Account in Amazon Associates Central', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=AssociateValidationException',
				]
			),
			'invalid_signature'          => new \WP_Error(
				'invalid_signature',
				__( 'Amazon: Invalid Credentials', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( "Amazon couldn't validate your credentials. Check that your Secret Key is correct - it's a 40-character string, not your Store ID. If you just created new credentials, Amazon takes up to 48 hours to activate them.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Review Your Credentials in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=InvalidSignatureException',
				]
			),
			'incomplete_signature'       => new \WP_Error(
				'incomplete_signature',
				__( 'Amazon: Missing Secret Key', 'mediavine' ),
				[
					'status'    => 400,
					'message'   => __( "Amazon reports your Secret Key is missing or incomplete. Your Secret Key is a 40-character string that looks like \"wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY\". Make sure you copied the entire key without extra spaces.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Re-enter Your Secret Key in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=IncompleteSignatureException',
				]
			),
			'too_many_requests'          => new \WP_Error(
				'too_many_requests',
				__( 'Amazon: Rate Limit Exceeded', 'mediavine' ),
				[
					'status'    => 429,
					'message'   => __( "Amazon is rate-limiting your requests because you've exceeded their API limits. Wait a few minutes before trying again.", 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=TooManyRequestsException',
				]
			),
			'request_expired'            => new \WP_Error(
				'request_expired',
				__( 'Amazon: Request Expired', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( "Amazon rejected the request because your server's clock is out of sync. Amazon requires requests to be within 15 minutes of the actual time. Contact your hosting provider to sync your server's clock.", 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=RequestExpiredException',
				]
			),
			'unrecognized_client'        => new \WP_Error(
				'unrecognized_client',
				__( 'Amazon: Unknown Access Key', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( "Amazon doesn't recognize your Access Key ID. Your Access Key is a 20-character string starting with \"AKIA\". Common mistakes: using your Store ID instead, extra spaces, or using old/deleted credentials. Generate new credentials in Amazon Associates if needed.", 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Access Key in Settings', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=UnrecognizedClientException',
				]
			),
			'invalid_or_missing_parameter' => new \WP_Error(
				'invalid_or_missing_parameter',
				__( 'Amazon: Invalid Request', 'mediavine' ),
				[
					'status'    => 400,
					'message'   => __( 'Amazon reports an invalid or missing parameter in the request. This is usually a temporary issue - please try again.', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=ValidationException',
				]
			),
			'unknown_operation'          => new \WP_Error(
				'unknown_operation',
				__( 'Amazon: Unknown Operation', 'mediavine' ),
				[
					'status'    => 404,
					'message'   => __( 'Amazon received an unknown API operation. This is likely a plugin issue - please contact support.', 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/troubleshooting/error-messages.html#:~:text=UnknownOperationException',
				]
			),
			'amazon_plugin_conflict'     => new \WP_Error(
				'amazon_plugin_conflict',
				__( 'Plugin Conflict Detected', 'mediavine' ),
				[
					'status'    => 501,
					'message'   => __( "Another plugin is conflicting with Create's Amazon integration. This is a known issue with some Amazon affiliate plugins. Try deactivating other Amazon-related plugins, or add products manually.", 'mediavine' ),
					'link_url'  => 'mailto:support@create.studio',
					'link_text' => __( 'Contact Create Support', 'mediavine' ),
				]
			),
			'create_not_registered'      => new \WP_Error(
				'create_not_registered',
				__( 'Create Registration Required', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( 'Amazon product scraping requires Create to be registered. Register your site to enable this feature, or add products manually.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_api' ),
					'link_text' => __( 'Register Create', 'mediavine' ),
				]
			),
			'paapi_not_setup'            => new \WP_Error(
				'paapi_not_setup',
				__( 'Amazon Integration Not Configured', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( 'Amazon Affiliates needs to be enabled and configured in Create settings before you can scrape Amazon products.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Configure Amazon Affiliates', 'mediavine' ),
				]
			),
			'paapi_provisioning'         => new \WP_Error(
				'paapi_provisioning',
				__( 'Amazon: Credentials Still Activating', 'mediavine' ),
				[
					'status'    => 403,
					'message'   => __( "Amazon takes up to 48 hours to activate new API credentials. You can add products manually while waiting, or try again later.", 'mediavine' ),
					'docs_url'  => 'https://webservices.amazon.com/paapi5/documentation/register-for-pa-api.html#:~:text=credentials%20take%20up%20to%2072%20hours',
				]
			),
			// Creators API errors
			'creators_api_not_setup'     => new \WP_Error(
				'creators_api_not_setup',
				__( 'Amazon Creators API Not Setup', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( 'Amazon Creators API is not enabled or fully configured. Please enter your Creators API credentials from Associates Central, or manually add an image and title.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Configure Amazon Affiliates', 'mediavine' ),
				]
			),
			'creators_unauthorized'      => new \WP_Error(
				'creators_unauthorized',
				__( 'Amazon: Authentication Failed', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( 'Amazon rejected your Creators API credentials. Please verify your Credential ID and Secret in Associates Central.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Credentials in Settings', 'mediavine' ),
				]
			),
			'creators_access_denied'     => new \WP_Error(
				'creators_access_denied',
				__( 'Amazon: Access Denied', 'mediavine' ),
				[
					'status'    => 403,
					'message'   => __( "Amazon denied access to the Creators API. This may mean your credentials don't have the required permissions, or your Associates account isn't eligible.", 'mediavine' ),
					'link_url'  => 'https://affiliate-program.amazon.com/assoc_credentials/home',
					'link_text' => __( 'Check Your Account in Amazon Associates Central', 'mediavine' ),
				]
			),
			'creators_rate_limited'      => new \WP_Error(
				'creators_rate_limited',
				__( 'Amazon: Rate Limit Exceeded', 'mediavine' ),
				[
					'status'  => 429,
					'message' => __( "Amazon is rate-limiting your requests. Wait a few minutes before trying again.", 'mediavine' ),
				]
			),
			'creators_validation_error'  => new \WP_Error(
				'creators_validation_error',
				__( 'Amazon: Invalid Request', 'mediavine' ),
				[
					'status'  => 400,
					'message' => __( 'Amazon reports an invalid request to the Creators API. This is usually a temporary issue - please try again.', 'mediavine' ),
				]
			),
			'creators_not_found'         => new \WP_Error(
				'creators_not_found',
				__( 'Amazon: Resource Not Found', 'mediavine' ),
				[
					'status'    => 404,
					'message'   => __( 'The requested Amazon resource was not found. Please verify your Partner Tag and region settings.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Settings', 'mediavine' ),
				]
			),
			'creators_oauth_error'       => new \WP_Error(
				'creators_oauth_error',
				__( 'Amazon: OAuth Authentication Failed', 'mediavine' ),
				[
					'status'    => 401,
					'message'   => __( 'Amazon rejected your Creators API credentials during OAuth authentication. Please verify your Credential ID and Secret in Associates Central.', 'mediavine' ),
					'link_url'  => admin_url( 'options-general.php?page=mv_settings#tab=mv_create_affiliates' ),
					'link_text' => __( 'Check Your Credentials in Settings', 'mediavine' ),
				]
			),
			'creators_api_error'         => new \WP_Error(
				'creators_api_error',
				__( 'Amazon: Creators API Error', 'mediavine' ),
				[
					'status'  => 500,
					'message' => __( 'An unexpected error occurred with the Amazon Creators API. Please try again later.', 'mediavine' ),
				]
			),
		];

		if ( ! isset( $errors[ $error_code ] ) ) {
			return new \WP_Error(
				'unknown_error_code',
				__( 'Unknown error code', 'mediavine' ),
				[
					'status'  => 400,
					'message' => sprintf(
						__( 'The error code "%s" is not recognized. Available codes: %s', 'mediavine' ),
						$error_code,
						implode( ', ', array_keys( $errors ) )
					),
				]
			);
		}

		return $errors[ $error_code ];
	}

	/**
	 * Remove product
	 *
	 * @param Request  $request WordPress Request object
	 * @param Response $response WordPress Response object
	 *
	 * @return \WP_Error|Response
	 */
	public function destroy( Request $request, Response $response ) {
		$params         = $request->get_params();
		$deleted        = self::$models_v2->mv_products->delete( $params['id'] );
		$maps_to_delete = self::$models_v2->mv_products_map->find(
			[
				'where' => [ 'product_id' => $params['id'] ],
			]
		);

		// $wpdb->delete only returns number of rows deleted, not the IDs
		$deleted_maps = self::$models_v2->mv_products_map->delete(
			[
				'where' => [
					'product_id' => $params['id'],
				],
			]
		);

		$this->reset_related_cards( $maps_to_delete );

		if ( ! $deleted ) {
			return new \WP_Error(
				409,
				__( 'Entry Could Not Be Deleted', 'mediavine' ),
				[
					'message'    => __( 'A conflict occurred and the product could not be deleted', 'mediavine' ),
					'error_code' => 'product_not_deleted',
				]
			);
		}
		$data     = self::$api_services->prepare_item_for_response( $deleted, $request );
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 204 );

		return $response;
	}

	/**
	 * Find related cards and update published field
	 *
	 * @param array $product_maps Array of product maps
	 *
	 * @return bool
	 */
	public function reset_related_cards( $product_maps ) {
		if ( empty( $product_maps ) ) {
			return false;
		}

		$creations_to_update = [];
		foreach ( $product_maps as $product_map ) {
			if ( ! isset( $product_map->creation ) ) {
				continue;
			}

			$creations_to_update[] = $product_map->creation;
		}

		Publish::update_publish_queue( $creations_to_update );

		return true;
	}
}
