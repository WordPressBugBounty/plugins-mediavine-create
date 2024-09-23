<?php
namespace Mediavine\Create;

use \WP_REST_Request as Request;
use \WP_REST_Response as Response;
use Mediavine\WordPress\Support\Arr;
use Mediavine\WordPress\Support\Str;

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
		$amazon_scraper = Amazon::get_instance();
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
		if ( empty( $product['link'] ) ) {
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

		$products = self::$models_v2->mv_products->find( $query_args );
		$products = Products::filter_existing_products( $creation_id, $products );

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
		$response->header( 'X-Total-Items', self::$models_v2->mv_products->get_count( $query_args ) );
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
			$amazon_scraper = Amazon::get_instance();
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
	 * @param Request $request
	 * @param Response $response
	 *
	 * @return array|\WP_Error|Response
	 */
	public function scrape_non_amazon( Request $request, Response $response ) {
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
			$amazon_scraper = Amazon::get_instance();
			$asin           = ! empty( $result->asin ) ? $result->asin : $amazon_scraper->get_asin_from_link( $link );
			//do external scrape
			if ( empty( $asin ) ) {
				$api_token_setting = \Mediavine\Settings::get_settings( 'mv_create_api_token' );
				$scraped           = wp_remote_post(
					'https://create-api.mediavine.com/api/v1/scraper/scrape', [
						'headers' => [
							'Content-Type'  => 'application/json; charset=utf-8',
							'Authorization' => 'bearer ' . $api_token_setting->value,
						],
						'body'    => wp_json_encode( [
							'url' => $link,
						] ),
						'method'  => 'POST',
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
				$result['existing']  = $existing;
				// If thumbnail ID and isn't external and hasn't been previously generated
				if ( isset( $existing->thumbnail_id ) && empty( $existing->thumbnail_uri ) ) {
					$result->existing->thumbnail_uri = wp_get_attachment_url( $existing->thumbnail_id );
				}
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
			$result['title'] = $result['title'];
		}

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
