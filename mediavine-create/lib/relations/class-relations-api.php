<?php

namespace Mediavine\Create;

use Mediavine\Create\API_Services;
use Mediavine\MV_DBI;
use Mediavine\Settings;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Helpers\Arr;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This plugin requires WordPress' );
}

if ( class_exists( 'Mediavine\Create\Supplies' ) ) {
	/**
	 * Class Relations_API
	 * @package Mediavine\Create
	 */
	class Relations_API extends Creations {

		/**
		 * Search for related content. Adds internal or external links to a List card
		 *
		 * @param \WP_REST_Request  $request
		 * @param \WP_REST_Response $response
		 *
		 * @return array|\WP_Error|\WP_REST_Response
		 */
		public function content_search( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$sanitized = $request->sanitize_params();

			if ( is_wp_error( $sanitized ) ) {
				return new \WP_Error( 'Missing Required Field', __( 'Unsafe Data', 'mediavine-create' ) );
			}

			$params = $request->get_params();

			if ( empty( $params['search'] ) ) {
				return [];
			}

			global $wpdb;

			// Parse types filter - separate card types from post types and product
			$requested_types = isset( $params['types'] ) && is_array( $params['types'] ) ? $params['types'] : [];
			$card_types      = [ 'recipe', 'diy', 'list' ];
			$post_types_list = [ 'post', 'page' ];

			// Determine which card types to include
			$filtered_card_types = empty( $requested_types )
				? $card_types
				: array_intersect( $requested_types, $card_types );

			// Determine which post types to include
			$filtered_post_types = empty( $requested_types )
				? $post_types_list
				: array_intersect( $requested_types, $post_types_list );

			// Determine if products should be included
			$include_products = empty( $requested_types ) || in_array( 'product', $requested_types, true );

			$query_args = [
				'where' => [],
				'limit' => 1000,
			];

			$creation_search = [];

			$search_term = $params['search'];

			// Only search creations if card types are requested
			$creations = [];
			if ( ! empty( $filtered_card_types ) ) {
				if ( isset( $params['search'] ) ) {
					$creation_search['published'] = $params['search'];
					$query_args['select']         = [ 'id as relation_id', 'canonical_post_id', 'description', 'title', "'card' AS content_type", 'type AS secondary_type', 'thumbnail_id' ];
				}

				// Add type filter to query using IN clause
				$query_args['where']['type'] = [ 'in' => $filtered_card_types ];

				$creations = self::$models_v2->mv_creations->find( $query_args, $creation_search );

				foreach ( $creations as &$creation ) {
					$creation->thumbnail_uri = wp_get_attachment_url( $creation->thumbnail_id );
					Relations::prepare_card_item( $creation );
				}
			}

			// Only search posts if post types are requested
			$results = [];
			if ( ! empty( $filtered_post_types ) ) {
				$allowed_post_types = json_decode(Settings::get_setting('mv_create_allowed_cpt_types') ?: '[]', true);
				if ( empty( $allowed_post_types ) ) {
					$allowed_post_types = [ 'post', 'page' ];
				} else {
					$allowed_post_types = array_merge( [ 'post', 'page' ], $allowed_post_types );
				}

				// Filter allowed_post_types to only include requested types
				$allowed_post_types        = array_map( 'esc_attr', array_intersect( $allowed_post_types, $filtered_post_types ) );
				$allowed_post_types_string = implode( ', ', array_fill( 0, count( $allowed_post_types ), '%s' ) );

				if ( ! empty( $allowed_post_types ) ) {
					$statement = "SELECT id, id as canonical_post_id, id as relation_id, post_title as title, 'post' as content_type, post_type as secondary_type FROM $wpdb->posts WHERE post_title LIKE '%%%s%%' AND post_status = 'publish' AND post_type IN (" . $allowed_post_types_string . ')';

					if ( isset( $params['all'] ) ) {
						$search_term_array = [
							$search_term,
							$search_term,
						];

						$statement = "SELECT id, id as canonical_post_id, id as relation_id, post_title as title, 'post' as content_type, post_type as secondary_type FROM $wpdb->posts WHERE (post_title LIKE '%%%s%%' OR post_content LIKE '%%%s%%') AND post_status = 'publish' AND post_type IN (" . $allowed_post_types_string . ')';

						$query_params = array_merge( $search_term_array, $allowed_post_types );
					} else {
						$query_params = array_merge( [ $search_term ], $allowed_post_types );
					}

					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
					$prepared = $wpdb->prepare( $statement, $query_params );
					$results  = $wpdb->get_results( $prepared );
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

					foreach ( $results as &$post ) {
						$post->thumbnail_id  = get_post_thumbnail_id( $post->id );
						$post->thumbnail_uri = wp_get_attachment_url( $post->thumbnail_id );
						unset( $post->id ); // $post->id has to be unset, otherwise this causes problems with adding cards that are on other lists
					}
				}
			}

			// Search products if requested (Pro only)
			$products = [];
			if ( $include_products && \Mediavine\Create\Plugin::is_pro() ) {
				$products_table = self::$models_v2->mv_products->table_name;

				$statement = "SELECT
					id,
					id as relation_id,
					title,
					link as url,
					'product' as content_type,
					NULL as secondary_type,
					thumbnail_id,
					external_thumbnail_url
				FROM $products_table
				WHERE title LIKE '%%%s%%'
				ORDER BY title ASC
				LIMIT 50";

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct $wpdb access on custom/plugin tables; values bound via prepare() where applicable
				$prepared = $wpdb->prepare( $statement, $search_term );
				$products = $wpdb->get_results( $prepared );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter

				// Format product results
				foreach ( $products as &$product ) {
					// Set thumbnail_uri from external URL or local thumbnail
					if ( ! empty( $product->external_thumbnail_url ) ) {
						$product->thumbnail_uri = $product->external_thumbnail_url;
					} elseif ( ! empty( $product->thumbnail_id ) ) {
						$product->thumbnail_uri = wp_get_attachment_url( $product->thumbnail_id );
					} else {
						$product->thumbnail_uri = '';
					}

					// Clean up temporary fields
					unset( $product->id, $product->external_thumbnail_url );
				}
			}

			// set up and return response data
			$response = API_Services::set_response_data(
				[
					'creations' => $creations,
					'posts'     => $results,
					'products'  => $products,
				], $response
			);

			$response->set_status( 200 );

			return $response;
		}

		/**
		 * Read Card relations
		 *
		 * @param \WP_REST_Request  $request
		 * @param \WP_REST_Response $response
		 *
		 * @return \WP_Error|\WP_REST_Response
		 */
		public function read_creation_relations( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$params = $request->get_params();
			$data   = [];

			if ( isset( $params['id'] ) ) {
				$data = Relations::get_creation_relations( $params['id'] );
			}

			if ( ! wp_is_numeric_array( $data ) ) {
				return new \WP_Error( 404, __( 'No Entries Found', 'mediavine-create' ), [ 'message' => __( 'No relations were found for the given create card', 'mediavine-create' ) ] );
			}
			foreach ( $data as &$relation ) {
				$relation = self::$api_services->prepare_item_for_response( $relation, $request );
			}
			$response = API_Services::set_response_data( $data, $response );
			$response->set_status( 200 );

			return $response;
		}

		/**
		 * Gets the existing ASINs along with a record of the original relations.
		 *
		 * @param int $creation_id ID of creation
		 * @return array List of existing ASINs and original relations data
		 */
		public function get_existing_asins( $creation_id ) {
			// Get original data and existing ASINs to prevent us from hammering Amazon's API
			$original_relations = Relations::get_creation_relations( $creation_id );
			$existing_asins     = array_filter( Arr::pluck( $original_relations, 'asin' ) );

			return [
				'existing_asins'     => $existing_asins,
				'original_relations' => $original_relations,
			];
		}

		/**
		 * Normalizes the relations data so it can be associated with a card.
		 *
		 * @param array  $data Data of all relations to be added to card
		 * @param int    $creation_id ID of creation
		 * @param string $type Type of data added to card
		 * @param array  $existing_data Array containing existing asins and original relations data
		 * @return array List of relations and any error data
		 */
		public function normalize_relations_data( $data, $creation_id, $type, $existing_data ) {
			if ( ! is_array( $data ) ) {
				return [
					'relations' => [],
					'errors'    => [],
				];
			}

			$relations = [];
			$errors    = [];

			$existing_asins     = $existing_data['existing_asins'];
			$original_relations = $existing_data['original_relations'];

			// Loop through relation data and build any missing relation data
			foreach ( $data as $relation ) {
				$relation['creation'] = $creation_id;
				$relation['type']     = $type;

				// Sanitize any user-supplied URL so dangerous protocols (e.g. javascript:)
				// are stripped before the value is ever stored or emitted.
				if ( isset( $relation['url'] ) ) {
					$relation['url'] = esc_url_raw( $relation['url'] );
				}

				// Make sure empty ID strings are set as NULL or 0
				$relation['id']                = empty( $relation['id'] ) ? 'NULL' : $relation['id'];
				$relation['relation_id']       = empty( $relation['relation_id'] ) ? '0' : $relation['relation_id'];
				$relation['canonical_post_id'] = empty( $relation['canonical_post_id'] ) ? '0' : $relation['canonical_post_id'];

				// We have had instances with undefined indexes for the relationship data
				$default  = [
					'title'       => '',
					'description' => '',
					'nofollow'    => '',
					'link_text'   => '',
					'position'    => '',
				];
				$relation = array_merge( $default, $relation );

				// Handle product content type
				if ( isset( $relation['content_type'] ) && 'product' === $relation['content_type'] ) {
					$product_result = $this->normalize_product_relation( $relation, $errors );
					if ( is_wp_error( $product_result ) ) {
						$errors[] = $product_result;
						continue;
					}
					$relation = $product_result;
					$relations[] = $relation;
					continue;
				}

				// Check if the link is an Amazon link and scrape appropriately.
				// Method will return false if the link is not an Amazon link, which
				// should allow it to be processed normally
				$is_amazon_link = $this->scrape_amazon_link( $relation, $existing_asins, $original_relations, $errors );
				if ( $is_amazon_link ) {
					$relation = $is_amazon_link;
				}

				// checks for non-Amazon links and retrieves image accordingly
				$relation = $this->get_image_for_external_link( $relation );

				// if user has assigned their own image to an Amazon link
				$relation = $this->get_user_image_for_amazon_link( $relation );

				$relations[] = $relation;
			}

			return [
				'relations' => $relations,
				'errors'    => $errors,
			];
		}

		/**
		 * Set relations for a card
		 *
		 * @param \WP_REST_Request  $request
		 * @param \WP_REST_Response $response
		 * @todo Add unit test for this method
		 * @return \WP_REST_Response
		 */
		public function set_relations( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$params      = $request->get_params();
			$creation_id = $params['id'];
			$type        = $params['type'];

			/**
			 * Get original data and existing ASINs to prevent us from hammering Amazon's API
			 *
			 * @var array $existing_data {
			 *    @type array $existing_asins
			 *    @type array $original_relations
			 * }
			 */
			$existing_data = $this->get_existing_asins( $creation_id );

			$data = $params['data'];

			if ( ! wp_is_numeric_array( $data ) ) {
				return $response;
			}

			$relations = $this->normalize_relations_data( $data, $creation_id, $type, $existing_data );
			$errors    = $relations['errors'];

			Relations::delete_all_relations( $creation_id, $type );
			self::$models_v2->mv_relations->create_many( $relations['relations'] );

			$relations = Relations::get_creation_relations( $creation_id );
			$relations = static::filter_duplicate_list_items( $relations );
			$relations = self::$api_services->prepare_items_for_response( $relations, $request );

			$response = API_Services::set_response_data( $relations, $response );
			if ( $errors ) {
				$response->errors = $errors;
			}

			$response->set_status( 201 );

			return $response;
		}

		/**
		 * Filter duplicate relations from an array and delete duplicates.
		 *
		 * @param array $relations
		 * @return array $filtered the filtered relations
		 */
		public static function filter_duplicate_list_items( $relations ) {
			$relations_map = [
				'internal' => [],
				'external' => [],
			];
			$filtered      = Arr::map(
				$relations,
				function ( $relation ) use ( &$relations_map ) {
					// first we check if the relation is an external link
					if ( ! empty( $relation->url ) ) {
						// if it is, we check if it has already been added to the map
						if ( array_search( $relation->url, $relations_map['external'], true ) !== false ) {
							// if it has, we delete it
							self::$models_v2->mv_relations->delete( $relation->id );
							return null;
						}
						// otherwise, we add it to the map as the first of its kind
						$relations_map['external'][] = $relation->url;
					}
					// next, do the same for internal links
					if ( ! empty( $relation->relation_id ) ) {
						// check to see if it has already been added to the map
						if ( array_search( $relation->relation_id, $relations_map['internal'], true ) !== false ) {
							// if it has, we delete it
							self::$models_v2->mv_relations->delete( $relation->id );
							return null;
						}
						// otherwise, we add it to the map as the first of its kind
						$relations_map['internal'][] = $relation->relation_id;
					}
					return $relation;
				}
			);
			return array_filter( $filtered );
		}

		/**
		 * Parse relation for Amazon data
		 *
		 * @param array $relation
		 * @param array $existing_asins
		 * @param array $original_relations
		 * @param array $errors
		 * @todo Add unit test for this method
		 * @return array|bool
		 */
		public function scrape_amazon_link( $relation, $existing_asins, $original_relations, &$errors ) {

			// check url for asin
			if ( empty( $relation['asin'] ) && empty( $relation['url'] ) ) {
				return false;
			}

			// Amazon affiliate isn't set up
			$amazon_scraper = Amazon_Adapter::get_instance();
			if ( ! $amazon_scraper->amazon_affiliates_setup() ) {
				return false;
			}

			// If not an ASIN or ASIN is malformed
			$asin = $amazon_scraper->get_asin_from_link( $relation['url'] );
			if ( empty( $asin ) || Str::length( $asin ) !== 10 ) {
				return false;
			}

			$result = $this->get_amazon_products_metadata( $asin, $existing_asins, $amazon_scraper, $original_relations );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result; // passed via reference
				return $relation;
			}

			$relation['meta']    = json_encode( $result ); // dump scrape results into meta
			$relation['asin']    = $asin;
			$relation['expires'] = $result['expires'];

			if ( ! empty( $result['external_thumbnail_url'] ) ) {
				$relation['thumbnail_uri'] = $result['external_thumbnail_url'];
			}

			return $relation;
		}

		/**
		 * Checks if a user has specified their own image in place of Amazon's and
		 * adds it accordingly
		 *
		 * @param array $relation
		 * @todo add unit test for this method
		 * @return array
		 */
		public function get_user_image_for_amazon_link( $relation ) {

			if ( empty( $relation['asin'] ) ) {
				return $relation;
			}

			if ( empty( $relation['thumbnail_id'] ) ) {
				return $relation;
			}

			$meta = json_decode( $relation['meta'] ?: '{}' );

			// Ensure $meta is an object (json_decode can return array if meta contains [])
			if ( ! is_object( $meta ) ) {
				$meta = (object) [];
			}

			// reassign external_thumbnail_url
			$img = wp_get_attachment_image_src( $relation['thumbnail_id'], 'full' );

			// no point in overwriting if the urls already match
			$current_url = $meta->external_thumbnail_url ?? null;
			if ( ! empty( $img[0] ) && $img[0] !== $current_url ) {
				$meta->external_thumbnail_url = $img[0];
				$meta->expires                = null;

				$relation['expires'] = null;
				$relation['meta']    = json_encode( $meta );
			}

			return $relation;
		}

		/**
		 * Get image for an non-Amazon external link
		 *
		 * @param array $relation
		 *
		 * @return array
		 */
		public function get_image_for_external_link( array $relation ) {
			// TODO: Test to see if we can return early if `thumbnail_id` is already set. It's
			// possible this would break things.
			if ( ! empty( $relation['asin'] ) || empty( $relation['thumbnail_uri'] ) ) {
				return $relation;
			}

			$relation['thumbnail_id'] = Images::get_attachment_id_from_url( $relation['thumbnail_uri'] );

			// Set alt text on the sideloaded attachment if available.
			if ( ! empty( $relation['thumbnail_id'] ) && ! empty( $relation['thumbnail_alt'] ) ) {
				$existing_alt = get_post_meta( $relation['thumbnail_id'], '_wp_attachment_image_alt', true );
				if ( empty( $existing_alt ) ) {
					update_post_meta( $relation['thumbnail_id'], '_wp_attachment_image_alt', sanitize_text_field( $relation['thumbnail_alt'] ) );
				}
			}

			return $relation;
		}

		/**
		 * Find the previous ASIN in $existing_asins, if it doesn't exist, scrape the URL for the meta data
		 *
		 * @param string                  $asin ASIN to be scraped
		 * @param array                   $existing_asins Array of existing ASINs to check against
		 * @param Amazon_Creators $amazon_scraper Scraper instance returned by Amazon_Adapter
		 * @param array                   $original_relations Original relations to pull meta from if the key does exist
		 *
		 * @return array|\WP_Error JSON decoded Amazon metadata or WP_Error if the link can't be scraped
		 */
		public function get_amazon_products_metadata( $asin, $existing_asins, $amazon_scraper, $original_relations ) {
			// find the previous ASIN in $existing_asins array
			// this key should also match the position of the existing list item
			$key    = array_search( $asin, $existing_asins, true );
			$result = null;
			if ( false === $key ) { // if key doesn't exist, go ahead and scrape the url
				$scraped = $amazon_scraper->get_products_by_asin( $asin );
				if ( is_wp_error( $scraped ) ) {
					return $scraped;
				}

				if ( ! empty( $scraped[ $asin ] ) ) {
					$result = $scraped[ $asin ];
				}

				return $result;
			}

			// if key DOES exist, use that entry's meta data
			// otherwise return if the keys don't match with the original
			if ( empty( $original_relations[ $key ]->meta ) ) {
				// Return associative array (not empty []) so json_encode produces "{}" not "[]"
				// Include expected keys that scrape_amazon_link accesses
				return [
					'expires'                => null,
					'external_thumbnail_url' => null,
				];
			}

			return json_decode($original_relations[ $key ]->meta ?: '{}', true);
		}

		/**
		 * Normalize product relation data
		 *
		 * Handles product list items by validating the product exists, merging product defaults
		 * with list-specific overrides, and enforcing Pro gating.
		 *
		 * @param array $relation Relation data
		 * @param array $errors   Errors array (passed by reference)
		 * @return array|\WP_Error Normalized relation data or WP_Error
		 */
		public function normalize_product_relation( $relation, &$errors ) {
			// Pro feature gating
			if ( ! \Mediavine\Create\Plugin::is_pro() ) {
				return new \WP_Error(
					'pro_required',
					__( 'Product list items require Mediavine Create Pro', 'mediavine-create' ),
					[ 'status' => 403 ]
				);
			}

			// Validate product exists
			if ( empty( $relation['relation_id'] ) || '0' === $relation['relation_id'] ) {
				return new \WP_Error(
					'invalid_product',
					__( 'Product ID is required for product list items', 'mediavine-create' ),
					[ 'status' => 400 ]
				);
			}

			$product_id = intval( $relation['relation_id'] );
			$product    = self::$models_v2->mv_products->select_one_by_id( $product_id );

			if ( empty( $product ) || is_wp_error( $product ) ) {
				return new \WP_Error(
					'product_not_found',
					sprintf(
						/* translators: %d: product ID */
						__( 'Product with ID %d not found', 'mediavine-create' ),
						$product_id
					),
					[ 'status' => 404 ]
				);
			}

			// Store product defaults for fields not overridden by the list
			// These will be merged in Relations::prepare_product_item()

			// Preserve list-specific overrides if they exist
			// Otherwise, denormalize product data into relation for backwards compatibility
			if ( empty( $relation['title'] ) ) {
				$relation['title'] = Scraped_Content_Normalizer::sanitize_title( $product->title );
			}

			if ( empty( $relation['url'] ) ) {
				$relation['url'] = $product->link;
			}

			// Handle thumbnail
			if ( ! empty( $relation['thumbnail_id'] ) ) {
				// List has custom thumbnail - get URI
				$relation['thumbnail_uri'] = wp_get_attachment_url( $relation['thumbnail_id'] );
			} elseif ( ! empty( $product->external_thumbnail_url ) ) {
				// Use product's external thumbnail (e.g., Amazon)
				$relation['thumbnail_uri'] = $product->external_thumbnail_url;
			} elseif ( ! empty( $product->thumbnail_id ) ) {
				// Use product's local thumbnail
				$relation['thumbnail_id']  = $product->thumbnail_id;
				$relation['thumbnail_uri'] = wp_get_attachment_url( $product->thumbnail_id );
			}

			// Set nofollow for Amazon products
			if ( ! empty( $product->asin ) ) {
				$relation['nofollow'] = true;
				$relation['asin']     = $product->asin;
			}

			return $relation;
		}
	}
}
