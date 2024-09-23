<?php

namespace Mediavine\Create;

use Mediavine\Settings;

if ( ! class_exists( 'Mediavine\Create\Plugin' ) ) {
	return;
}

class JSON_LD_Runtime extends Plugin {

	public static $instance = null;

	/**
	 * Singleton factory.
	 *
	 * @return JSON_LD|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Singleton __construct equivalent.
	 *
	 * @return void
	 */
	public function init() {
		$this->json_ld_types = JSON_LD_Types::get_instance();

		add_filter( 'wp_head', [ $this, 'output_json_ld' ] );
	}

	/**
	 * Finds all Create cards within content string.
	 *
	 * @param string $content Content to check for Create shortcodes
	 * @return array List of creation IDs on page
	 */
	public function find_create_cards( $content ) {
		$card_ids = [];

		// Does the Create shortcode exist on the page?
		if ( ! has_shortcode( $content, 'mv_create' ) && ! has_shortcode( $content, 'mv_recipe' ) ) {
			return $card_ids;
		}

		// Find full shortcode so we can pull ids
		$pattern = get_shortcode_regex();
		if (
			preg_match_all( '/' . $pattern . '/s', $content, $matches ) &&
			array_key_exists( 2, $matches ) &&
			(
				in_array( 'mv_create', $matches[2], true ) ||
				in_array( 'mv_recipe', $matches[2], true )
			)
		) {
			list( $full_shortcodes, $empty, $handles ) = $matches;

			foreach ( $handles as $i => $handle ) {
				// Check for Create shortcode
				if ( 'mv_create' === $handle || 'mv_recipe' === $handle ) {
					$shortcode_atts = shortcode_parse_atts( $full_shortcodes[ $i ] );

					// Pull key from shortcode with key
					if ( ! empty( $shortcode_atts['key'] ) ) {
						$create_id = $shortcode_atts['key'];
					}

					// If no key, check for post_id which was part of original mv_recipe shortcode
					if ( empty( $create_id ) && ! empty( $shortcode_atts['post_id'] ) ) {
						$create_id = $shortcode_atts['post_id'];
					}

					if ( ! empty( $create_id ) ) {
						$card_ids[] = $create_id;
					}
				}
			}
		}

		return $card_ids;
	}

	/**
	 * Gets the published data for multiple cards based on creation ids.
	 *
	 * @param array $card_ids IDs of creations to pull published data
	 * @return array List of published data in an `id` => `published_data` format
	 */
	public function get_multiple_cards_published_data( $card_ids ) {
		// Return early if for some reason we don't have a good array
		if ( empty( $card_ids ) || ! is_array( $card_ids ) ) {
			return '';
		}

		global $wpdb;

		$creations_table = self::$models_v2->mv_creations->table_name;
		$where_statement = '';
		$prepared_values = [];

		// Build the where statement
		foreach ( $card_ids as $card_id ) {
			$where_statement  .= 'id = %d OR ';
			$prepared_values[] = $card_id;
		}
		$where_statement = rtrim( $where_statement, ' OR ' );

		// We have to pass this query as a prepared statement because because our DBI currently
		// cannot support WHERE statements that use OR and have the same key, in this case `id`.

		// We are limiting this to 300, which is crazy high, but this is in case someone adds
		// 100 lists on a page because they put each item into its own list, plus some change.
		// Placeholders are located within $where_statement.
		$creations = self::$models_v2->mv_creations->find([
			'sql'    => "SELECT id, published FROM $creations_table WHERE $where_statement LIMIT 300",
			'params' => $prepared_values,
		]);

		// Key the creations with their ID number so we pull published data by an id
		$keyed_creations = wp_list_pluck( $creations, 'published', 'id' );

		return $keyed_creations;
	}

	/**
	 * Checks if schema is disabled for a creation.
	 *
	 * @param array $creation Published creation card data
	 * @return boolean
	 */
	public function is_json_ld_schema_disabled( $creation ) {
		// Check isset so old cards still display schema,
		// and check empty because of some PHP interpreting `! $var` as strict with 0 strings
		return (
			isset( $creation['schema_display'] ) &&
			empty( $creation['schema_display'] )
		);
	}

	/**
	 * Checks if a post matches the canonical post of a card.
	 *
	 * @param array $creation Published creation card data
	 * @param int $post_id Id of the post to check against
	 * @return boolean
	 */
	public function is_post_not_canonical( $creation, $post_id ) {
		return ( $post_id !== (int) $creation['canonical_post_id'] );
	}

	/**
	 * Combine schemas of multiple lists, if necessary.
	 *
	 * The name property of the schema will be pulled from the first list.
	 *
	 * @param array $list_data List of creation data for lists
	 * @return string Encoded JSON-LD string
	 */
	public function build_list_json_ld_schema( $list_data ) {
		$combined_list_items = [];
		foreach ( $list_data as $list ) {
			// If there's no JSON-LD, for any reason, we want to skip this list
			if ( empty( $list['json_ld'] ) ) {
				continue;
			}

			// Get first list's JSON-LD for base info
			if ( empty( $json_ld ) ) {
				$json_ld = $list['json_ld'];
			}

			// Combine list data
			if ( ! empty( $list['list_items'] ) ) {
				$combined_list_items = array_merge( $combined_list_items, $list['list_items'] );
			}
		}

		// If we have no list items, then we have no schema
		if ( empty( $combined_list_items ) ) {
			return '';
		}

		$json_ld_array = json_decode( $json_ld, true );

		// Replace original list items with updated combined list
		$combined_json_ld = $this->json_ld_types->add_json_ld_item_list( $json_ld_array, $combined_list_items, 'itemListElement' );

		return wp_json_encode( $combined_json_ld );
	}

	/**
	 * Check that a string is a valid JSON string.
	 *
	 * @param string $string_to_validate
	 * @return boolean
	 */
	public function is_valid_json_string( $string_to_validate ) {
		if ( empty( $string_to_validate ) ) {
			return false;
		}
		if ( ! is_string( $string_to_validate ) ) {
			return false;
		}
		json_decode( $string_to_validate );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}

		return true;
	}

	public function build_json_ld_schema_script_tag( $json_ld, $type, $creations, $post_id ) {
		/**
		 * Filters the JSON-LD schema to go in a script tag in wp_head
		 *
		 * @param string $json_ld Encoded json object of JSON-LD schema
		 * @param array $creations List of creations in an `id` => `published_data` format
		 * @param int $post_id Id of the current post
		 */
		$json_ld = apply_filters( 'mv_create_json_ld', $json_ld, $creations, $post_id );

		// Make sure final result is json string
		if ( ! $this->is_valid_json_string( $json_ld ) ) {
			return '';
		}

		return '<script type="application/ld+json" class="mv-create-json-ld mv-create-json-ld-' . $type . '">' . $json_ld . '</script>';
	}

	/**
	 * Builds the JSON-LD schema output.
	 *
	 * @param array $creations List of creations in an `id` => `published_data` format
	 * @param int $post_id ID of the current post
	 * @param array $card_order List of the order of the cards within a post
	 * @return string JSON-LD output within a <script> tag
	 */
	public function build_json_ld_schema( $creations, $post_id, $card_order ) {
		// Return early if for some reason we don't have good arrays
		if (
			empty( $creations ) ||
			! is_array( $creations ) ||
			empty( $card_order ) ||
			! is_array( $card_order )
		) {
			return '';
		}

		$json_ld_string  = '';
		$json_ld_strings = [];
		$list_data       = [];

		foreach ( $card_order as $card_id ) {
			$published_creation = json_decode( $creations[ $card_id ], true );

			// Should schema display?
			if (
				$this->is_json_ld_schema_disabled( $published_creation ) ||
				$this->is_post_not_canonical( $published_creation, $post_id )
			) {
				continue;
			}

			// If type is list, set aside for joining purposes.
			if ( 'list' === $published_creation['type'] ) {
				// Only add to list data if we have JSON-LD
				if ( ! empty( $published_creation['json_ld'] ) ) {
					$list_data[ $card_id ] = $published_creation;
				}
			}

			// Only move forward if we have a recipe or how-to.
			if ( ! in_array( $published_creation['type'], [ 'recipe', 'diy' ], true ) ) {
				continue;
			}

			// If no schema exists yet, we can build it.
			if ( empty( $json_ld_strings[ $published_creation['type'] ] ) ) {
				$json_ld_strings[ $published_creation['type'] ] = $published_creation['json_ld'];
			}
		}

		// Build list schema if we need to
		if ( ! empty( $list_data ) ) {
			$json_ld_strings['list'] = $this->build_list_json_ld_schema( $list_data );
		}

		// Build schema into string
		if ( ! empty( $json_ld_strings ) ) {
			foreach ( $json_ld_strings as $type => $json_ld ) {
				$json_ld_string .= $this->build_json_ld_schema_script_tag( $json_ld, $type, $creations, $post_id );
			}
		}

		return $json_ld_string;
	}

	/**
	 * Outputs the JSON-LD to a post.
	 *
	 * Hooked into wp_head.
	 *
	 * @return void
	 */
	public function output_json_ld( $post_data = null ) {
		// Check if schema should be output in wp_head
		if ( ! Settings::get_setting( 'mv_create_schema_in_head', true ) ) {
			return;
		}

		// Only add this to singular posts
		if ( ! is_singular() && empty( $post_data ) ) {
			return;
		}

		// For normal function runs. We pass the post data into the function for testing.
		if ( empty( $post_data ) ) {
			global $post;

			$post_data = $post;
		}

		// Find all Create cards attached to post.
		$card_ids = $this->find_create_cards( $post_data->post_content );

		// Get the published data of all the cards on the page.
		$creations = $this->get_multiple_cards_published_data( $card_ids );

		// Build JSON-LD output.
		$json_ld_output = $this->build_json_ld_schema( $creations, $post_data->ID, $card_ids );

		// Echo JSON-LD schema into <head> tag
		$allowed_tags = [
			'script' => [
				'class' => true,
				'type'  => true,
			],
		];
		echo wp_kses( $json_ld_output, $allowed_tags );
	}
}
