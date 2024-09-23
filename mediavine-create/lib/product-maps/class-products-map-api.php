<?php
namespace Mediavine\Create;

use Mediavine\WordPress\Support\Arr;
use Mediavine\WordPress\Support\Str;

/**
 * Endpoints for our v1 Products Map API
 */
class Products_Map_API extends Products {

	/**
	 * Add products to a creation
	 *
	 * @param \WP_REST_Request  $request  Request that was sent
	 * @param \WP_REST_Response $response Response that was received
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function upsert( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params      = $request->get_params();
		$creation_id = $params['id'];

		// Initial products map data
		$products_map = $params['data'];
		if ( empty( $products_map ) ) {
			Products_Map::delete_all_products_maps( $creation_id );

			$data     = [];
			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 200 );
			return $response;
		}

		$amazon_scraper = Amazon::get_instance();
		$existing_asins = Products_Map::get_existing_asins_for_creation( $creation_id, $products_map );

		/**
		 * Parse all products here. Expected functionality:
		 * - Should not download the Amazon image to the Media Library
		 * - Should store the Amazon image url in the database
		 * - User should be able to override the default Amazon image with one of their own choosing.
		 */
		$maps_to_create = Arr::map(
			$products_map,
			function ( $product_map ) use ( $creation_id, $existing_asins, $amazon_scraper ) {
				// if product does not have a title, return the product map
				if ( empty( $product_map['title'] ) ) {
					return $product_map;
				}

				/**
				 * This appears to correct the data from the request. The product id is `id` in the request data array
				 * so it needs to be reassigned to `product_id` for upsert to work properly.
				 *
				 * @todo Explore the possibility of handling this on the UI side as part of the request payload
				 */
				if ( ! isset( $product_map['product_id'] ) && ! empty( $product_map['id'] ) ) {
					$product_map['product_id'] = $product_map['id'];
				}

				// Unset empty slug if old DB col from original beta versions still exists
				// Unset other array items that do not match the columns from the mv_products_map table
				unset( $product_map['slug'] );
				unset( $product_map['id'] );
				unset( $product_map['type'] );

				/**
				 * These lines handle upsertion back to the products table.
				 *
				 * Should we do this? It seems odd to handle upserting to the product table inside a closure
				 *
				 * $product_map is upserted to the products table
				 */
				$product_map['creation'] = $creation_id;

				$upsert_properties = [ 'link' => $product_map['link'] ];

				if ( isset( $product_map['product_id'] ) ) {
					$upsert_properties = [ 'id' => $product_map['product_id'] ];
				}

				/**
				 * Parse the current product link for an asin and then check it against the $existing_asins array to ensure
				 * that we're not rescraping a product that already exists.
				 *
				 * This can fail if the card we're editing doesn't have Recommended Products added to it yet.
				 */
				$asin = $amazon_scraper->get_asin_from_link( $product_map['link'] ); // this method doesn't hit the API, thankfully

				$asin_exists = in_array( $asin, $existing_asins, true );
				if ( $amazon_scraper->amazon_affiliates_setup() && ! $asin_exists && ! empty( $asin ) && 10 === Str::length( $asin ) ) {
					// we already have the ASIN, so assign early in case of data-loss
					$product_map['asin'] = $asin;

					/**
					 * Bypass the API scrape completely since the data we need already exists in the request payload,
					 * and an expiration date can be assigned manually. Leave the rest to product Queue to keep
					 * the links updated.
					 * Existing data in request payload:
					 *      - thumbnail url : either remote_thumbnail_uri or external_thumbnail_url
					 *      - ASIN : can be retrieved using the link parameter
					 *      - Expiration date — not part of the request payload but can be calculated
					 */
					// fill out the data with what we have, and let the Amazon Product Queue update it when it runs
					$thumbnail_uri = ! empty( $product_map['remote_thumbnail_uri'] )
						? $product_map['remote_thumbnail_uri'] : $product_map['thumbnail_uri'];

					// We only want the Amazon thumbnail uri if the user hasn't assigned a thumbnail from the Media Library
					if ( empty( $product_map['thumbnail_id'] ) ) {
						$product_map['thumbnail_id']           = null;
						$product_map['thumbnail_uri']          = $thumbnail_uri;
						$product_map['external_thumbnail_url'] = $thumbnail_uri;
						$product_map['remote_thumbnail_uri']   = $thumbnail_uri;
						$product_map['expires']                = gmdate( 'Y-m-d G:i:s', strtotime( '+1 day' ) );
					} else {
						$img_url = wp_get_attachment_url( $product_map['thumbnail_id'] );

						$product_map['external_thumbnail_url'] = $img_url;
						$product_map['expires']                = '';
					}
				}

				/**
				 * Attempt to create a new thumbnail if there isn't one
				 * This should only happen with non-Amazon external links
				 */
				if ( empty( $asin ) && ! empty( $product_map['remote_thumbnail_uri'] ) ) {
					$product_map = static::prepare_product_thumbnail( $product_map );
				}

				/**
				 * Upsert the product itself back to `mv_products` table
				 *
				 * Should we be doing this here with ALL products or only with new products,
				 * ie: products that aren't listed on the Recommended Products page?
				 */
				add_filter( 'mv_create_allow_normalized_null', '__return_true' );
				$product = self::$models_v2->mv_products->upsert(
					$product_map,
					$upsert_properties
				);
				remove_filter( 'mv_create_allow_normalized_null', '__return_true' );

				/**
				 * Product is empty, then it can't be found
				 * This line could be causing issues as well. Inside the closure, if a product isn't found, then a
				 * WP_Error object is returned to be included in the $maps_to_create array
				 */
				if ( empty( $product ) ) {
					return new \WP_Error( 404, __( 'Entry Not Found', 'mediavine' ), [ 'message' => __( 'The Product could not be found', 'mediavine' ) ] );
				}

				if ( ! isset( $product_map['product_id'] ) && ! empty( $product->id ) ) {
					$product_map['product_id'] = $product->id;
				}

				/**
				 * On Amazon links that error out, we never get this far so the type column remains unpopulated
				 */
				$product_map['type'] = 'product_map';

				return $product_map;
			}
		);

		/**
		 * Delete the product maps before submitting to the database so we're not getting duplicates this way
		 */
		Products_Map::delete_all_products_maps( $creation_id );
		$maps_to_create = array_filter( $maps_to_create ); // this line is designed to remove empty items from an array — another place where missing data could happen
		self::$models_v2->mv_products_map->create_many( $maps_to_create );

		/**
		 * Once again, grab existing product maps, then filter out any potential duplicates
		 * Finally, run through a foreach loop.
		 */
		$products_map = self::$models_v2->mv_products_map->where( [ 'creation', '=', $creation_id ] );
		$products_map = self::filter_duplicate_product_maps( $products_map );

		/**
		 * What exactly is this foreach loop accounting for?
		 * - Missing or incorrect images?
		 * - Is there something with the product_id?
		 *      + Reassigning id to product_id is also happening in the closure. Why are we doing this again if this is the same data that we added a few lines ago
		 * - Unsetting an old column that was part of a previous version of Create?
		 *      + This is already handled in the closure. Why are we doing it again if this is the same data that we added a few lines ago?
		 */
		foreach ( $products_map as &$product_map ) {
			if ( ! isset( $product_map->product_id ) && ! empty( $product_map->id ) ) {
				$product_map->product_id = $product_map->id;
			}

			// Unset empty slug if old DB col from original beta versions still exists
			unset( $product_map->slug );

			$product_map->thumbnail_uri = Products_Map::get_correct_thumbnail_src( $product_map );
		}

		/**
		 * This only sorts by the order column from the database.
		 * Instead of using usort, could be handled by adjusting the where query a few lines up to include an ORDER BY directive
		 */
		usort( $products_map, [ '\Mediavine\Create\Products_Map', 'sort_product_map' ] );

		if ( ! empty( $products_map ) ) {
			$response = API_Services::set_response_data( $products_map, $response );
			$response->set_status( 201 );
		}

		return $response;
	}

	/**
	 * Filter duplicate product maps from an array and delete duplicates.
	 *
	 * @param array $products_map  the products map to be filtered
	 * @return array $filtered     the filtered products map
	 */
	public static function filter_duplicate_product_maps( $products_map ) {
		$product_maps_ids = [];
		$filtered         = Arr::map(
			$products_map,
			function ( $map ) use ( &$product_maps_ids ) {
				if ( array_key_exists( $map->product_id, $product_maps_ids ) ) {
					self::$models_v2->mv_products_map->delete( $map->id );
					return null;
				}
				$product_maps_ids[ $map->product_id ] = $map->product_id;
				return $map;
			}
		);
		return array_filter( $filtered );
	}

	/**
	 * Find productions associated with a given creation
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 *
	 * @return \WP_REST_Response
	 */
	public function find( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$data   = [];
		$params = $request->get_params();

		$query_args = [];
		if ( isset( $response->query_args ) ) {
			$query_args = $response->query_args;
		}

		$product_maps = self::$models_v2->mv_products_map->where( [ 'creation', '=', $params['id'] ] );
		usort( $product_maps, [ '\Mediavine\Create\Products_Map', 'sort_product_map' ] );

		if ( wp_is_numeric_array( $product_maps ) ) {
			foreach ( $product_maps as $product_map ) {
				$product_map->thumbnail_uri = Products_Map::get_correct_thumbnail_src( $product_map );
				$data[]                     = $product_map;
			}

			$response->set_status( 200 );
		}

		$response = API_Services::set_response_data( $data, $response );
		$response->header( 'X-Total-Items', self::$models_v2->mv_products_map->get_count( $query_args ) );

		return $response;
	}

	/**
	 * Remove a specified product map
	 *
	 * @param \WP_REST_Request $request
	 * @param \WP_REST_Response $response
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request, \WP_REST_Response $response ) {
		$params = $request->get_params();

		$deleted = self::$models_v2->mv_products_map->delete( $params['id'] );

		if ( ! $deleted ) {
			return new \WP_Error( 409, __( 'Entry Could Not Be Deleted', 'mediavine' ), [ 'message' => __( 'A conflict occurred and the Product Maps could not be deleted', 'mediavine' ) ] );
		}
		$data     = self::$api_services->prepare_item_for_response( $deleted, $request );
		$response = API_Services::set_response_data( $data, $response );
		$response->set_status( 204 );

		return $response;
	}
}
