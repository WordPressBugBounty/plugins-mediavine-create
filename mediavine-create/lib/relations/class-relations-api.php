<?php

namespace Mediavine\Create;

use Mediavine\Create\API_Services;
use Mediavine\MV_DBI;
use Mediavine\Settings;
use Mediavine\WordPress\Support\Str;
use Mediavine\WordPress\Support\Arr;

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
		 * @param \WP_REST_Request $request
		 * @param \WP_REST_Response $response
		 *
		 * @return array|\WP_Error|\WP_REST_Response
		 */
		public function content_search( \WP_REST_Request $request, \WP_REST_Response $response ) {
			$sanitized = $request->sanitize_params();

			if ( is_wp_error( $sanitized ) ) {
				return new \WP_Error( 'Missing Required Field', __( 'Unsafe Data', 'mediavine' ) );
			}

			$params = $request->get_params();

			if ( empty( $params['search'] ) ) {
				return [];
			}

			global $wpdb;

			$query_args = [
				'where' => [],
				'limit' => 1000,
			];

			$creation_search = [];

			$search_term = $params['search'];

			if ( isset( $params['search'] ) ) {
				$creation_search['published'] = $params['search'];
				$query_args['select']         = [ 'id as relation_id', 'canonical_post_id', 'description', 'title', "'card' AS content_type", 'type AS secondary_type', 'thumbnail_id' ];
			}

			$allowed_post_types = json_decode( Settings::get_setting( 'mv_create_allowed_cpt_types' ), true );
			if ( empty( $allowed_post_types ) ) {
				$allowed_post_types = array_map( 'esc_attr', [ 'post', 'page' ] );
			} else {
				$allowed_post_types = array_map( 'esc_attr', array_merge( [ 'post', 'page' ], $allowed_post_types ) );
			}

			$allowed_post_types_string = implode( ', ', array_fill( 0, count( $allowed_post_types ), '%s' ) );

			$statement = "SELECT id, id as canonical_post_id, id as relation_id, post_title as title, 'post' as content_type, post_type as secondary_type FROM $wpdb->posts WHERE post_title LIKE '%%%s%%' AND post_status = 'publish' AND post_type IN (" . $allowed_post_types_string . ')';

			if ( isset( $params['all'] ) ) {
				$search_term = [
					$search_term,
					$search_term,
				];

				$statement = "SELECT id, id as canonical_post_id, id as relation_id, post_title as title, 'post' as content_type, post_type as secondary_type FROM $wpdb->posts WHERE (post_title LIKE '%%%s%%' OR post_content LIKE '%%%s%%') AND post_status = 'publish' AND post_type IN (" . $allowed_post_types_string . ')';
			}

			if ( ! is_array( $search_term ) ) {
				$search_term = [ $search_term ];
			}
			$params = array_merge( $search_term, $allowed_post_types );

			// SECURITY CHECKED: This query is properly prepared.
			$prepared = $wpdb->prepare( $statement, $params );
			$results  = $wpdb->get_results( $prepared );

			foreach ( $results as &$post ) {
				$post->thumbnail_id  = get_post_thumbnail_id( $post->id );
				$post->thumbnail_uri = wp_get_attachment_url( $post->thumbnail_id );
				unset( $post->id ); // $post->id has to be unset, otherwise this causes problems with adding cards that are on other lists
			}

			$creations = self::$models_v2->mv_creations->find( $query_args, $creation_search );

			foreach ( $creations as &$creation ) {
				$creation->thumbnail_uri = wp_get_attachment_url( $creation->thumbnail_id );
				Relations::prepare_card_item( $creation );
			}

			// set up and return response data
			$response = API_Services::set_response_data(
				[
					'creations' => $creations,
					'posts'     => $results,
				], $response
			);

			$response->set_status( 200 );

			return $response;
		}

		/**
		 * Read Card relations
		 *
		 * @param \WP_REST_Request $request
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
				return new \WP_Error( 404, __( 'No Entries Found', 'mediavine' ), [ 'message' => __( 'No relations were found for the given create card', 'mediavine' ) ] );
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
		 * @param array $data Data of all relations to be added to card
		 * @param int $creation_id ID of creation
		 * @param string $type Type of data added to card
		 * @param array $existing_data Array containing existing asins and original relations data
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
		 * @param \WP_REST_Request $request
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
			$amazon_scraper = Amazon::get_instance();
			if ( ! $amazon_scraper->amazon_affiliates_setup() ) {
				return false;
			}

			// If not an ASIN or ASIN is malformed
			$asin = $amazon_scraper->get_asin_from_link( $relation['url'] );
			if ( empty( $asin ) && Str::length( $asin ) !== 10 ) {
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

			$meta = json_decode( $relation['meta'] );

			// reassign external_thumbnail_url
			$img = wp_get_attachment_image_src( $relation['thumbnail_id'], 'full' );

			// no point in overwriting if the urls already match
			if ( ! empty( $img[0] ) && $img[0] !== $meta->external_thumbnail_url ) {
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

			return $relation;
		}

		/**
		 * Find the previous ASIN in $existing_asins, if it doesn't exist, scrape the URL for the meta data
		 *
		 * @param string $asin ASIN to be scraped
		 * @param array $existing_asins Array of existing ASINs to check against
		 * @param Amazon $amazon_scraper Amazon scraper class instance
		 * @param array $original_relations Original relations to pull meta from if the key does exist
		 *
		 * @return array|\WP_Error JSON decoded Amazon metadata or WP_Error if the link can't be scraped
		 */
		public function get_amazon_products_metadata( $asin, $existing_asins, Amazon $amazon_scraper, $original_relations ) {
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
				return [];
			}

			return json_decode( $original_relations[ $key ]->meta, true );
		}
	}
}
