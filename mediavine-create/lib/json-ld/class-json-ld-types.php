<?php

namespace Mediavine\Create;

use Mediavine\Create\Helpers\Str;

/**
 * Class JSON_LD_Types
 *
 * @package Mediavine\Create
 */
class JSON_LD_Types {

	/**
	 * The JSON-LD types instance.
	 *
	 * @var JSON_LD_Types|null
	 */
	public static $instance = null;

	/**
	 * @var JSON_LD_Helpers
	 */
	private $json_ld_helpers;

	/**
	 * Gets the JSON-LD types instance.
	 *
	 * @return JSON_LD_Types
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Initializes the JSON-LD types instance.
	 */
	public function init() {
		$this->json_ld_helpers = new JSON_LD_Helpers();
	}

	/**
	 * Gets the JSON-LD schema types needed for How-Tos.
	 *
	 * @return array
	 */
	public function get_schema_types_diy() {
		return [
			'type'       => 'HowTo',
			'properties' => [
				'name'            => [
					'type' => 'string',
					'map'  => 'title',
				],
				'author'          => 'author',
				'datePublished'   => [
					'type' => 'date',
					'map'  => 'created',
				],
				'yield'           => 'string',
				'description'     => [
					'type'  => 'string',
					'map'   => 'description',
					'flags' => [
						'no_html' => true,
					],
				],
				'about'           => [
					'type' => 'string',
					'map'  => 'secondary_term_name',
				],
				'image'           => [
					'type' => 'image',
					'map'  => [
						'haystack' => 'images',
						'needle'   => 'object_id',
						'size'     => 'image_size',
					],
				],
				'prepTime'        => [
					'type'  => 'duration',
					'map'   => 'prep_time',
					'flags' => [
						'force' => true,
					],
				],
				// Hooked by Creations->additional_perform_time()
				// Hook used: mv_json_ld_value_prop_performTime
				'performTime'     => [
					'type'  => 'duration',
					'map'   => 'active_time',
					'flags' => [
						'force' => true,
					],
				],
				'totalTime'       => [
					'type'  => 'duration',
					'map'   => 'total_time',
					'flags' => [
						'force' => true,
					],
				],
				'tool'            => [
					'type' => 'list',
					'map'  => [
						'haystack'       => 'tools',
						'needle'         => 'original_text',
						'groups'         => true,
						'strip_brackets' => true,
					],
				],
				'supply'          => [
					'type' => 'list',
					'map'  => [
						'haystack'       => 'materials',
						'needle'         => 'original_text',
						'groups'         => true,
						'strip_brackets' => true,
					],
				],
				// Hooked by Creations->check_for_list_steps()
				// Hook used: mv_schema_types
				'step'            => [
					'type' => 'step',
					'map'  => 'instructions',
				],
				'external_video'  => 'video',
				'video'           => 'video',
				'keywords'        => 'string',
				'aggregateRating' => [
					'type' => 'rating',
					'map'  => [
						'ratingValue' => 'rating',
						'reviewCount' => 'rating_count',
					],
				],
				'review'          => [
					'type' => 'reviews',
					'map'  => 'id',
				],
				'url'             => [
					'type'  => 'string',
					'map'   => 'canonical_post_id',
					'flags' => [
						'get_permalink' => true,
					],
				],
			],
		];
	}

	/**
	 * Gets the JSON-LD schema types needed for Recipes.
	 *
	 * @return array
	 */
	public function get_schema_types_recipe() {
		return [
			'type'       => 'Recipe',
			'properties' => [
				'name'               => [
					'type' => 'string',
					'map'  => 'title',
				],
				'author'             => 'author',
				'datePublished'      => [
					'type' => 'date',
					'map'  => 'created',
				],
				'recipeYield'        => [
					'type'  => 'integer',
					'map'   => 'yield',
					'flags' => [
						'force' => true,
					],
				],
				'description'        => [
					'type'  => 'string',
					'map'   => 'description',
					'flags' => [
						'no_html' => true,
					],
				],
				'image'              => [
					'type' => 'image',
					'map'  => [
						'haystack' => 'images',
						'needle'   => 'object_id',
						'size'     => 'image_size',
					],
				],
				'recipeCategory'     => [
					'type' => 'string',
					'map'  => 'category_name',
				],
				'recipeCuisine'      => [
					'type' => 'string',
					'map'  => 'secondary_term_name',
				],
				'prepTime'           => [
					'type'  => 'duration',
					'map'   => 'prep_time',
					'flags' => [
						'force' => true,
					],
				],
				'cookTime'           => [
					'type'  => 'duration',
					'map'   => 'active_time',
					'flags' => [
						'force' => true,
					],
				],
				// Hooked by Creations->additional_perform_time()
				// Hook used: mv_json_ld_value_prop_performTime
				'performTime'        => [
					'type'  => 'duration',
					'map'   => 'active_time',
					'flags' => [
						'force' => true,
					],
				],
				'totalTime'          => [
					'type'  => 'duration',
					'map'   => 'total_time',
					'flags' => [
						'force' => true,
					],
				],
				'recipeIngredient'   => [
					'type' => 'list',
					'map'  => [
						'haystack'       => 'ingredients',
						'needle'         => 'original_text',
						'groups'         => true,
						'strip_brackets' => true,
					],
				],
				'recipeInstructions' => [
					'type' => 'step',
					'map'  => 'instructions',
				],
				'external_video'     => 'video',
				'video'              => 'video',
				'keywords'           => 'string',
				'suitableForDiet'    => [
					'type' => 'string',
					'map'  => 'suitable_for_diet',
				],
				'nutrition'          => 'nutrition',
				'aggregateRating'    => [
					'type' => 'rating',
					'map'  => [
						'ratingValue' => 'rating',
						'reviewCount' => 'rating_count',
					],
				],
				'review'             => [
					'type' => 'reviews',
					'map'  => 'id',
				],
				'url'                => [
					'type'  => 'string',
					'map'   => 'canonical_post_id',
					'flags' => [
						'get_permalink' => true,
					],
				],
			],
		];
	}

	/**
	 * Gets the JSON-LD schema types needed for Lists.
	 *
	 * @return array
	 */
	public function get_schema_types_list() {
		return [
			'type'       => 'ItemList',
			'properties' => [
				'name'            => [
					'type' => 'string',
					'map'  => 'title',
				],
				'description'     => [
					'type'  => 'string',
					'map'   => 'description',
					'flags' => [
						'no_html' => true,
					],
				],
				'itemListElement' => [
					'type' => 'item_list',
					'map'  => 'list_items',
				],
			],
		];
	}

	/**
	 * Gets the JSON-LD schema types needed for Create cards.
	 *
	 * @return array
	 */
	public function get_schema_types() {
		return [
			'diy'    => $this->get_schema_types_diy(),
			'recipe' => $this->get_schema_types_recipe(),
			'list'   => $this->get_schema_types_list(),
		];
	}

	/**
	 * Runs the value through several filters, opening expansion possibilities.
	 *
	 * @param  mixed  $value Value to be filtered
	 * @param  string $schema_type type of schema (e.g. string, integer, time)
	 * @param  string $schema_prop property name of the schema item
	 * @param  array  $json_ld The current build of the JSON-LD array
	 * @param  array  $creation The full creation array for relationships
	 * @return mixed Value after filters run
	 */
	public function filter_json_ld_value( $value, $schema_type, $schema_prop, $json_ld, $creation = [] ) {
		$value = apply_filters( 'mv_json_ld_value_', $value, $schema_type, $schema_prop, $json_ld, $creation );
		$value = apply_filters( 'mv_json_ld_value_type_' . $schema_type, $value, $schema_type, $schema_prop, $json_ld, $creation );
		$value = apply_filters( 'mv_json_ld_value_prop_' . $schema_prop, $value, $schema_type, $schema_prop, $json_ld, $creation );

		return $value;
	}

	/**
	 * Adds the @type property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $type Type of card
	 * @param array  $schema_types Schema types map
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_type( $json_ld, $type, $schema_types ) {
		if ( ! array_key_exists( $type, $schema_types ) || empty( $schema_types[ $type ]['type'] ) ) {
			return false;
		}

		$json_ld['@type'] = $schema_types[ $type ]['type'];

		return $json_ld;
	}

	/**
	 * Adds the author property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @param array  $flags Any flags to alter schema value
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_author( $json_ld, $value, $schema_prop, $creation, $flags = [] ) {
		$value = $this->filter_json_ld_value( $value, 'author', $schema_prop, $json_ld, $creation );

		$json_ld[ $schema_prop ] = [
			'@type' => 'Person',
			'name'  => $value,
		];

		return $json_ld;
	}

	/**
	 * Adds a date property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @param array  $flags Any flags to alter schema value
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_date( $json_ld, $value, $schema_prop, $creation, $flags = [] ) {
		$value = $this->filter_json_ld_value( $value, 'date', $schema_prop, $json_ld, $creation );
		$date  = strtotime( $value );

		if ( ! empty( $date ) ) {
			$date                    = gmdate( 'Y-m-d', $date );
			$json_ld[ $schema_prop ] = $date;
		}

		return $json_ld;
	}

	/**
	 * Adds a duration property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_duration( $json_ld, $value, $schema_prop, $creation ) {
		$added_arrays = $this->filter_json_ld_value( null, 'duration_arrays', $schema_prop, $json_ld, $creation );
		$value        = $this->json_ld_helpers->build_duration( $value, $added_arrays );
		$value        = $this->filter_json_ld_value( $value, 'duration', $schema_prop, $json_ld, $creation );

		// We force flags on durations for some 0 values, but we don't want ot output any blank values
		if ( isset( $value ) ) {
			$json_ld[ $schema_prop ] = $value;
		}

		return $json_ld;
	}

	/**
	 * Adds an image property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param array  $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $map_info Mapping array of needle and haystack
	 * @param array  $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_image( $json_ld, $value, $schema_prop, $map_info, $creation ) {
		$images          = [];
		$available_sizes = wp_list_pluck( $value, 'image_url', 'image_size' );

		foreach ( $value as $image ) {
			if ( empty( $image[ $map_info['needle'] ] ) && empty( $image[ $map_info['size'] ] ) ) {
				continue;
			}

			$object_id  = $image[ $map_info['needle'] ];
			$image_size = $image[ $map_info['size'] ];

			// Because we calculate highest resolution image, we can ignore the high_res suffixes
			if ( Images::size_has_resolution_suffix( $image_size ) ) {
				continue;
			}

			$highest_res_image = Images::get_highest_available_image_size( $object_id, $image[ $map_info['size'] ], $available_sizes );
			$image_meta        = wp_get_attachment_image_src( $object_id, $highest_res_image );
			if ( $image_meta ) {
				$images[] = $image_meta[0];
			}
		}

		if ( ! empty( $images ) ) {
			$images = $this->filter_json_ld_value( array_values( $images ), 'image', $schema_prop, $json_ld, $creation );

			// Remove duplicate images (array_unique sometimes forces associative arrays and is slower than array_flip)
			$unique_images           = array_merge( array_flip( array_flip( $images ) ) );
			$json_ld[ $schema_prop ] = $unique_images;
		}

		return $json_ld;
	}

	/**
	 * Adds an image property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param array  $value Values to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $map_info Mapping array of needle and haystack
	 * @param array  $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_list( $json_ld, $value, $schema_prop, $map_info, $creation ) {
		$map_data = $value;
		// Merge down all groups if groups flag exists
		if ( ! empty( $map_info['groups'] ) ) {
			$map_data = [];
			foreach ( $value as $group_value ) {
				foreach ( $group_value as $list_value ) {
					$map_data[] = $list_value;
				}
			}
		}
		$list_data = wp_list_pluck( $map_data, $map_info['needle'] );

		foreach ( $list_data as $key => $list_value ) {
			if ( empty( $list_value ) ) {
				continue;
			}
			if ( ! empty( $map_info['strip_brackets'] ) ) {
				$list_data[ $key ] = $this->json_ld_helpers->strip_square_brackets( $list_value );
			}
		}

		if ( ! empty( $list_data ) ) {
			$list_data               = $this->filter_json_ld_value( $list_data, 'list', $schema_prop, $json_ld, $creation );
			$json_ld[ $schema_prop ] = $list_data;
		}

		return $json_ld;
	}

	/**
	 * Adds a nutrition property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_nutrition( $json_ld, $value, $schema_prop, $creation ) {
		$nutrition_map = apply_filters(
			'mv_json_ld_nutrition_map', [
				'calories'        => [
					'schema' => 'calories',
					'text'   => __( ' calories', 'mediavine-create' ),
				],
				'carbohydrates'   => [
					'schema' => 'carbohydrateContent',
					'text'   => __( ' grams carbohydrates', 'mediavine-create' ),
				],
				'cholesterol'     => [
					'schema' => 'cholesterolContent',
					'text'   => __( ' milligrams cholesterol', 'mediavine-create' ),
				],
				'total_fat'       => [
					'schema' => 'fatContent',
					'text'   => __( ' grams fat', 'mediavine-create' ),
				],
				'fiber'           => [
					'schema' => 'fiberContent',
					'text'   => __( ' grams fiber', 'mediavine-create' ),
				],
				'protein'         => [
					'schema' => 'proteinContent',
					'text'   => __( ' grams protein', 'mediavine-create' ),
				],
				'saturated_fat'   => [
					'schema' => 'saturatedFatContent',
					'text'   => __( ' grams saturated fat', 'mediavine-create' ),
				],
				'serving_size'    => [
					'schema' => 'servingSize',
					'text'   => null,
				],
				'sodium'          => [
					'schema' => 'sodiumContent',
					'text'   => __( ' milligrams sodium', 'mediavine-create' ),
				],
				'sugar'           => [
					'schema' => 'sugarContent',
					'text'   => __( ' grams sugar', 'mediavine-create' ),
				],
				'trans_fat'       => [
					'schema' => 'transFatContent',
					'text'   => __( ' grams trans fat', 'mediavine-create' ),
				],
				'unsaturated_fat' => [
					'schema' => 'unsaturatedFatContent',
					'text'   => __( ' grams unsaturated fat', 'mediavine-create' ),
				],
			]
		);

		$has_nutrition = false;
		$nutrition     = [
			'@type' => 'NutritionInformation',
		];

		foreach ( $nutrition_map as $key => $schema_data ) {
			if ( ! isset( $value[ $key ] ) ) {
				continue;
			}
			if ( ! empty( $value[ $key ] ) || ( '0' === $value[ $key ] ) || ( 0 === $value[ $key ] ) ) {
				$nutrition[ $schema_data['schema'] ] = $value[ $key ] . $schema_data['text'];
				$has_nutrition                       = true;
			}
		}

		if ( $has_nutrition ) {
			$nutrition               = $this->filter_json_ld_value( $nutrition, 'nutrition', $schema_prop, $json_ld, $creation );
			$json_ld[ $schema_prop ] = $nutrition;
		}

		return $json_ld;
	}

	/**
	 * Adds a rating property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_rating( $json_ld, $value, $schema_prop, $creation ) {
		$aggregate_rating = [
			'@type' => 'AggregateRating',
		];

		$value = array_merge( $aggregate_rating, $value );
		$value = $this->filter_json_ld_value( $value, 'rating', $schema_prop, $json_ld, $creation );

		$json_ld['aggregateRating'] = $value;

		return $json_ld;
	}

	/**
	 * Adds reviews to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param mixed  $creation_id Creation ID
	 * @param string $schema_prop Schema property name
	 * @param array  $creation Full creation data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_reviews( $json_ld, $creation_id, $schema_prop, $creation ) {
		// Get reviews for this creation
		$reviews = \Mediavine\Create\Reviews::get_reviews( $creation_id, [ 'limit' => 100 ] );
		
		if ( is_wp_error( $reviews ) || empty( $reviews ) ) {
			return $json_ld;
		}

		$review_array = [];
		
		foreach ( $reviews as $review ) {
			// Skip reviews without content or very low ratings
			if ( empty( $review->review_content ) && $review->rating < 4 ) {
				continue;
			}
			
			$author_name = ! empty( $review->author_name ) ? $review->author_name : __( 'Anonymous', 'mediavine-create' );
			$author_name = mb_substr( $author_name, 0, Reviews_API::MAX_AUTHOR_NAME_LENGTH );

			$review_item = [
				'@type'         => 'Review',
				'author'        => [
					'@type' => 'Person',
					'name'  => $author_name,
				],
				'datePublished' => gmdate( 'c', strtotime( $review->created ) ),
				'reviewRating'  => [
					'@type'       => 'Rating',
					'ratingValue' => strval( $review->rating ),
					'bestRating'  => '5',
					'worstRating' => '1',
				],
			];

			if ( ! empty( $review->review_content ) ) {
				$review_item['reviewBody'] = mb_substr( wp_strip_all_tags( $review->review_content ), 0, Reviews_API::MAX_REVIEW_CONTENT_LENGTH );
			}

			if ( ! empty( $review->review_title ) ) {
				$review_item['name'] = mb_substr( wp_strip_all_tags( $review->review_title ), 0, Reviews_API::MAX_REVIEW_TITLE_LENGTH );
			}
			
			$review_array[] = $review_item;
		}
		
		if ( ! empty( $review_array ) ) {
			$review_array      = $this->filter_json_ld_value( $review_array, 'reviews', $schema_prop, $json_ld, $creation );
			$json_ld['review'] = $review_array;
		}

		return $json_ld;
	}

	/**
	 * Adds a step property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @param array  $flags Any flags to alter schema value
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_step( $json_ld, $value, $schema_prop, $creation, $flags = [] ) {
		// TODO: Add title support for steps
		// We need DOMDocument installed for this to work. Fallback to single block of steps
		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->add_json_ld_string( $json_ld, $value, $schema_prop, $creation, [ 'no_html' => true ] );
		}

		$value = $this->filter_json_ld_value( $value, 'step', $schema_prop, $json_ld, $creation );

		// Build DOMDocument with blank steps array
		$dom = new \DOMDocument();
		if ( function_exists( 'libxml_use_internal_errors' ) ) {
			libxml_use_internal_errors( true );
		}
		// Encode high-bit UTF-8 as numeric entities so libxml's ISO-8859-1
		// default does not collapse emoji/CJK/★ to "?". Prefer the XML
		// encoding prelude as a belt-and-suspenders when mbstring is absent.
		$html = Str::to_html_entities( do_shortcode( $value ) );
		$load = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		if ( function_exists( 'libxml_use_internal_errors' ) ) {
			libxml_use_internal_errors( false );
		}
		$lis   = $dom->getElementsByTagName( 'li' );
		$steps = [];
		$i     = 0;

		foreach ( $lis as $li ) {
			$text = $this->json_ld_helpers->remove_html( $li->textContent );
			$url  = get_permalink( $creation['canonical_post_id'] );
			$id   = $creation['id'];
			$pos  = $i + 1;

			$steps[ $i ] = [
				'@type' => 'HowToStep',
				'text'  => $text,
			];

			if ( ( 'diy' === $creation['type'] ) || ( 'recipe' === $creation['type'] ) ) {
				$steps[ $i ]['position'] = $pos;
				$steps[ $i ]['name']     = wp_trim_words( $text, 8, '...' );
				$steps[ $i ]['url']      = "$url#mv_create_{$id}_$pos";

				$name_span = $li->getElementsByTagName( 'span' );
				if ( $name_span->length && $name_span->item( 0 )->hasAttribute( 'data-schema-name' ) ) {
					$name_text = $name_span->item( 0 )->getAttribute( 'data-schema-name' );
					if ( ! empty( $name_text ) ) {
						$steps[ $i ]['name'] = $name_text;
					}
				}

				$imgs = $li->getElementsByTagName( 'img' );
				if ( empty( $imgs ) || $imgs instanceof \DOMNodeList && ! $imgs->length && $li->nextSibling ) {
					if ( 'div' === $li->nextSibling->nodeName ) {
						$imgs = $li->nextSibling->getElementsByTagName( 'img' );
					}
				}

				if ( $imgs->length && $imgs->item( 0 )->hasAttribute( 'src' ) ) {
					$steps[ $i ]['image'] = $imgs->item( 0 )->getAttribute( 'src' );
				}
			}

			++$i;
		}

		// Fallback to single block if no LI elements were found
		if ( empty( $steps ) ) {
			return $this->add_json_ld_string( $json_ld, $value, $schema_prop, $creation, [ 'no_html' => true ] );
		}

		$json_ld[ $schema_prop ] = $steps;

		return $json_ld;
	}

	/**
	 * Adds a property that's a string to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @param array  $flags Any flags to alter schema value
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_string( $json_ld, $value, $schema_prop, $creation, $flags = [] ) {
		$value = $this->filter_json_ld_value( $value, 'string', $schema_prop, $json_ld, $creation );

		if ( ! empty( $flags['get_permalink'] ) ) {
			$value = get_permalink( $value );
		}
		if ( ! empty( $flags['no_html'] ) ) {
			$value = $this->json_ld_helpers->remove_html( $value );
		}

		$json_ld[ $schema_prop ] = $value;

		return $json_ld;
	}

	/**
	 * Adds an integer property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @param array  $flags Any flags to alter schema value
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_integer( $json_ld, $value, $schema_prop, $creation, $flags = [] ) {
		$value = $this->filter_json_ld_value( $value, 'integer', $schema_prop, $json_ld, $creation );

		// First attempt at converting to integer
		$int_value = intval( $value );

		// If empty or doesn't start with a number
		if ( 0 === $int_value ) {
			// Find the first digit in the string
			preg_match( '/^\D*(?=\d)/', $value, $match );
			if ( isset( $match[0] ) ) {
				// If match found, remove the string and run intval starting at the digit
				$int_value = intval( substr( $value, strlen( $match[0] ) ) );
			} else {
				// Empty string or straight text so just return 1
				$int_value = 1;
			}
		}

		$json_ld[ $schema_prop ] = $int_value;

		return $json_ld;
	}

	/**
	 * Adds a video property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param string $mv_video JSON string data of Mediavine video data
	 * @param string $ext_video JSON string data of external video data
	 * @param array  $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_video( $json_ld, $mv_video, $ext_video, $creation ) {
		// Empty video fields must not emit a null/empty video node or trigger
		// an undefined-variable warning when neither input is present.
		if ( ! $mv_video && ! $ext_video ) {
			return $json_ld;
		}

		$video = null;

		if ( $mv_video ) {
			$value = (array) json_decode( $mv_video, true );
			$video = [ '@type' => 'VideoObject' ];

			if ( ! empty( $value['title'] ) ) {
				$video['name'] = $value['title'];
			}

			if ( ! empty( $value['rawData']['description'] ) ) {
				$video['description'] = $value['rawData']['description'];
			} elseif ( ! empty( $value['rawData']['keywords'] ) ) {
				$video['description'] = $value['rawData']['keywords'];
			} elseif ( ! empty( $creation['description'] ) ) {
				$video['description'] = $creation['description'];
			}

			if ( ! empty( $value['thumbnail'] ) ) {
				$video['thumbnailUrl'] = $value['thumbnail'];
			}

			$video_slug = '';
			if ( ! empty( $value['slug'] ) ) {
				$video_slug          = $value['slug'];
				$video['contentUrl'] = 'https://mediavine-res.cloudinary.com/video/upload/' . $value['slug'] . '.mp4';
			} elseif ( ! empty( $value['key'] ) ) {
				$video_slug          = $value['key'];
				$video['contentUrl'] = 'https://mediavine-res.cloudinary.com/video/upload/' . $value['key'] . '.mp4';
			}

			if ( ! empty( $video_slug ) ) {
				$thumbnail_url = $this->get_remote_video_thumbnail_url( $video_slug );
				if ( ! empty( $thumbnail_url ) ) {
					$video['thumbnailUrl'] = $thumbnail_url;
				}
			}

			if ( ! empty( $value['duration'] ) ) {
				$video['duration'] = $value['duration'];
			}

			if ( ! empty( $value['uploadDate'] ) ) {
				$video['uploadDate'] = $value['uploadDate'];
			} elseif ( ! empty( $creation['modified'] ) ) {
				$video['uploadDate'] = gmdate( 'c', strtotime( $creation['modified'] ) );
			}
		} elseif ( $ext_video ) {
			$value = json_decode( $ext_video, true );
			if ( ! is_array( $value ) ) {
				return $json_ld;
			}

			$required_keys = [ 'name', 'description', 'thumbnailUrl', 'contentUrl', 'duration', 'uploadDate' ];
			foreach ( $required_keys as $key ) {
				if ( ! isset( $value[ $key ] ) ) {
					return $json_ld;
				}
			}

			$video = [
				'@type'        => 'VideoObject',
				'name'         => $value['name'],
				'description'  => $value['description'],
				'thumbnailUrl' => $value['thumbnailUrl'],
				'contentUrl'   => $value['contentUrl'],
				'duration'     => $value['duration'],
				'uploadDate'   => $value['uploadDate'],
			];
		}

		if ( null === $video ) {
			return $json_ld;
		}

		$video            = $this->filter_json_ld_value( $video, 'video', 'video', $json_ld, $creation );
		$json_ld['video'] = $video;

		return $json_ld;
	}

	/**
	 * Resolves the thumbnail URL for a Mediavine video slug from the remote
	 * video.mediavine.com JSON-LD endpoint, caching the result in a transient.
	 *
	 * This runs on the public render path, so the remote lookup is cached
	 * (including negative results) to avoid an unbounded external call on every
	 * uncached page view. The service is disclosed under "== External services =="
	 * in README.txt.
	 *
	 * @param string $video_slug The Mediavine video slug/key.
	 * @return string The resolved thumbnail URL, or empty string if none.
	 */
	protected function get_remote_video_thumbnail_url( $video_slug ) {
		$cache_key = 'mv_create_video_thumb_' . md5( $video_slug );
		$cached    = get_transient( $cache_key );

		// A cached value is either a URL string or an empty-string sentinel for
		// a negative lookup; `false` means the transient is absent/expired.
		if ( false !== $cached ) {
			return $cached;
		}

		$api_endpoint  = sprintf( 'https://video.mediavine.com/videos/%s.json', $video_slug );
		$response      = wp_remote_get( $api_endpoint, [ 'timeout' => 3 ] );
		$thumbnail_url = '';

		if ( ! is_wp_error( $response ) ) {
			$data = wp_remote_retrieve_body( $response );
			if ( ! empty( $data ) ) {
				$video_data = json_decode( $data );
				if ( ! empty( $video_data->video->meta->thumbnailUrl ) ) {
					$thumbnail_url = $video_data->video->meta->thumbnailUrl;
				}
			}
		}

		/**
		 * Filters the TTL (in seconds) for the cached remote video thumbnail lookup.
		 *
		 * @param int    $ttl        Cache lifetime in seconds. Default 12 hours.
		 * @param string $video_slug The Mediavine video slug being looked up.
		 */
		$ttl = apply_filters( 'mv_create_video_thumbnail_cache_ttl', 12 * HOUR_IN_SECONDS, $video_slug );

		set_transient( $cache_key, $thumbnail_url, $ttl );

		return $thumbnail_url;
	}

	/**
	 * Adds a itemListElement property to JSON-LD data.
	 *
	 * @param array  $json_ld Current JSON-LD data
	 * @param array  $item_list Item list data to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array  $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_item_list( $json_ld, $item_list, $schema_prop, $creation = [] ) {
		$item_list_element = [];
		$current_host      = wp_parse_url(  home_url() );
		// Google requires ListItem position to be a 1-based number
		$position = 1;
		$types    = [ 'external', 'card' ];

		// Get the canonical post URL for the list (used for text item fragments)
		$list_canonical_url = null;
		if ( ! empty( $creation['canonical_post_id'] ) ) {
			$list_canonical_url = get_the_permalink( $creation['canonical_post_id'] );
		}

		foreach ( $item_list as $item ) {
			// Convert array to object if necessary
			if ( ! is_object( $item ) ) {
				$item = (object) $item;
			}

			// Section dividers (explicit type or legacy text-with-no-media)
			// are not list items and must not appear in ItemList.
			if ( \Mediavine\Create\Creations_Views::is_list_item_divider( (array) $item ) ) {
				continue;
			}

			// Handle text items with fragment URLs
			if ( 'text' === $item->content_type ) {
				// Text items need the list's canonical URL with a fragment identifier
				if ( empty( $list_canonical_url ) || empty( $item->id ) ) {
					continue;
				}
				$permalink = $list_canonical_url . '#create-list-item-' . $item->id;
			} else {
				// Get the correct permalink for linked items
				$permalink = null;
				if ( $item->url ) {
					$permalink = $item->url;
				} elseif ( ! empty( $item->canonical_post_id ) ) {
					$permalink = get_the_permalink( $item->canonical_post_id );
				} elseif ( ! in_array( $item->content_type, $types, true ) ) {
					$permalink = get_the_permalink( $item->relation_id );
				}

				// Skip empty / non-http(s) permalinks. Avoid wp_http_validate_url()
				// here — it calls gethostbyname() for off-site hosts, which can
				// stall schema generation (and tests) on DNS.
				if ( empty( $permalink ) ) {
					continue;
				}
				$permalink_parts  = wp_parse_url( $permalink );
				$permalink_scheme = is_array( $permalink_parts ) ? ( $permalink_parts['scheme'] ?? '' ) : '';
				$permalink_host   = is_array( $permalink_parts ) ? ( $permalink_parts['host'] ?? '' ) : '';
				if (
					empty( $permalink_host ) ||
					! in_array( $permalink_scheme, [ 'http', 'https' ], true )
				) {
					continue;
				}

				// Don't add external URLs to JSON-LD
				// If the link is a subdomain, we want to keep it in the JSON-LD
				// If the link is neither a subdomain nor the primary domain, skip it
				if ( ! Str::is_same_host_or_subdomain( $permalink_host, $current_host['host'] ?? '' ) ) {
					continue;
				}
			}

			$list_item = [
				'@type'    => 'ListItem',
				'position' => $position,
				'url'      => $permalink,
			];

			// Add name if available
			if ( ! empty( $item->title ) ) {
				$list_item['name'] = $item->title;
			}

			// Add image if available; hydrated items carry a resolved thumbnail_uri
			$image = null;
			if ( ! empty( $item->thumbnail_uri ) ) {
				$image = $item->thumbnail_uri;
			} elseif ( ! empty( $item->thumbnail_id ) ) {
				$image = wp_get_attachment_url( $item->thumbnail_id );
			}
			if ( ! empty( $image ) ) {
				$list_item['image'] = $image;
			}

			// Add description if available; the column stores wpautop'd HTML, and
			// scraped values can be entity-encoded, so decode before stripping
			// so double-encoded markup materializes and is removed
			if ( ! empty( $item->description ) ) {
				$description = html_entity_decode( (string) $item->description, ENT_QUOTES, 'UTF-8' );
				$description = $this->json_ld_helpers->remove_html( $description );
				if ( ! empty( $description ) ) {
					$list_item['description'] = $description;
				}
			}

			$item_list_element[] = $list_item;
			++$position;
		}

		$item_list_element          = $this->filter_json_ld_value( $item_list_element, 'item_list', $schema_prop, $json_ld, $creation );
		$json_ld['itemListElement'] = $item_list_element;
		// Count emitted elements, not raw list items, so the number matches
		// the markup after external links and dividers are skipped
		$json_ld['numberOfItems'] = count( $item_list_element );

		if ( ! empty( $list_canonical_url ) ) {
			$json_ld['url'] = $list_canonical_url;

			if ( ! empty( $creation['id'] ) ) {
				$json_ld['@id'] = $list_canonical_url . '#mv-create-list-' . $creation['id'];
			}
		}

		return $json_ld;
	}
}
