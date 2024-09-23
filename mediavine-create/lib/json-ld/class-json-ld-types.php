<?php

namespace Mediavine\Create;

use Mediavine\WordPress\Support\Str;

class JSON_LD_Types {

	public static $instance = null;

	/**
	 * @var JSON_LD_Helpers
	 */
	private $json_ld_helpers;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
			self::$instance->init();
		}
		return self::$instance;
	}

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
	 * @param  mixed $value Value to be filtered
	 * @param  string $schema_type type of schema (e.g. string, integer, time)
	 * @param  string $schema_prop property name of the schema item
	 * @param  array $json_ld The current build of the JSON-LD array
	 * @param  array $creation The full creation array for relationships
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
	 * @param array $json_ld Current JSON-LD data
	 * @param string $type Type of card
	 * @param array $schema_types Schema types map
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
	 * @param array $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
	 * @param array $flags Any flags to alter schema value
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
	 * @param array $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
	 * @param array $flags Any flags to alter schema value
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_date( $json_ld, $value, $schema_prop, $creation, $flags = [] ) {
		$value = $this->filter_json_ld_value( $value, 'date', $schema_prop, $json_ld, $creation );
		$date  = strtotime( $value );

		if ( ! empty( $date ) ) {
			$date                    = date( 'Y-m-d', $date );
			$json_ld[ $schema_prop ] = $date;
		}

		return $json_ld;
	}

	/**
	 * Adds a duration property to JSON-LD data.
	 *
	 * @param array $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
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
	 * @param array $json_ld Current JSON-LD data
	 * @param array $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $map_info Mapping array of needle and haystack
	 * @param array $creation Creation card data
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
			$resolutions = apply_filters(
				'mv_create_image_resolutions', [
					'_medium_res',
					'_medium_high_res',
					'_high_res',
				]
			);
			foreach ( $resolutions as $resolution ) {
				$continue = false;
				if ( strpos( $image_size, $resolution ) ) {
					$continue = true;
					break;
				}
			}
			if ( $continue ) {
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
	 * @param array $json_ld Current JSON-LD data
	 * @param array $value Values to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $map_info Mapping array of needle and haystack
	 * @param array $creation Creation card data
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
	 * @param array $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_nutrition( $json_ld, $value, $schema_prop, $creation ) {
		$nutrition_map = apply_filters(
			'mv_json_ld_nutrition_map', [
				'calories'        => [
					'schema' => 'calories',
					'text'   => __( ' calories', 'mediavine' ),
				],
				'carbohydrates'   => [
					'schema' => 'carbohydrateContent',
					'text'   => __( ' grams carbohydrates', 'mediavine' ),
				],
				'cholesterol'     => [
					'schema' => 'cholesterolContent',
					'text'   => __( ' milligrams cholesterol', 'mediavine' ),
				],
				'total_fat'       => [
					'schema' => 'fatContent',
					'text'   => __( ' grams fat', 'mediavine' ),
				],
				'fiber'           => [
					'schema' => 'fiberContent',
					'text'   => __( ' grams fiber', 'mediavine' ),
				],
				'protein'         => [
					'schema' => 'proteinContent',
					'text'   => __( ' grams protein', 'mediavine' ),
				],
				'saturated_fat'   => [
					'schema' => 'saturatedFatContent',
					'text'   => __( ' grams saturated fat', 'mediavine' ),
				],
				'serving_size'    => [
					'schema' => 'servingSize',
					'text'   => null,
				],
				'sodium'          => [
					'schema' => 'sodiumContent',
					'text'   => __( ' milligrams sodium', 'mediavine' ),
				],
				'sugar'           => [
					'schema' => 'sugarContent',
					'text'   => __( ' grams sugar', 'mediavine' ),
				],
				'trans_fat'       => [
					'schema' => 'transFatContent',
					'text'   => __( ' grams trans fat', 'mediavine' ),
				],
				'unsaturated_fat' => [
					'schema' => 'unsaturatedFatContent',
					'text'   => __( ' grams unsaturated fat', 'mediavine' ),
				],
			]
		);

		$has_nutrition = false;
		$nutrition     = [
			'@type' => 'NutritionInformation',
		];

		foreach ( $nutrition_map as $key => $schema_data ) {
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
	 * @param array $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
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
	 * Adds a step property to JSON-LD data.
	 *
	 * @param array $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
	 * @param array $flags Any flags to alter schema value
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
		$dom = new \DOMDocument;
		if ( function_exists( 'libxml_use_internal_errors' ) ) {
			libxml_use_internal_errors( true );
		}
		$load = $dom->loadHTML( mb_convert_encoding( do_shortcode( $value ), 'HTML-ENTITIES', 'UTF-8' ) );

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
	 * @param array $json_ld Current JSON-LD data
	 * @param string $value Value to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
	 * @param array $flags Any flags to alter schema value
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
	 * @param array $json_ld Current JSON-LD data
	 * @param string $mv_video JSON string data of Mediavine video data
	 * @param string $ext_video JSON string data of external video data
	 * @param array $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_video( $json_ld, $mv_video, $ext_video, $creation ) {
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
				$api_endpoint = sprintf( 'https://video.mediavine.com/videos/%s.json', $video_slug );
				$data         = wp_remote_retrieve_body( wp_remote_get( $api_endpoint ) );

				if ( ! empty( $data ) ) {
					$video_data = json_decode( $data );
					if ( ! empty( $video_data->video->meta->thumbnailUrl ) ) {
						$video['thumbnailUrl'] = $video_data->video->meta->thumbnailUrl;
					}
				}
			}

			if ( ! empty( $value['duration'] ) ) {
				$video['duration'] = $value['duration'];
			}

			if ( ! empty( $value['uploadDate'] ) ) {
				$video['uploadDate'] = $value['uploadDate'];
			} elseif ( ! empty( $creation['modified'] ) ) {
				$video['uploadDate'] = date( 'c', strtotime( $creation['modified'] ) );
			}
		} elseif ( $ext_video ) {
			$value = (array) json_decode( $ext_video, true );
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

		$video            = $this->filter_json_ld_value( $video, 'video', 'video', $json_ld, $creation );
		$json_ld['video'] = $video;

		return $json_ld;
	}

	/**
	 * Adds a itemListElement property to JSON-LD data.
	 *
	 * @param array $json_ld Current JSON-LD data
	 * @param array $item_list Item list data to add to schema property
	 * @param string $schema_prop Name of schema property
	 * @param array $creation Creation card data
	 * @return array Updated JSON-LD data
	 */
	public function add_json_ld_item_list( $json_ld, $item_list, $schema_prop, $creation = [] ) {
		$item_list_element = [];
		$current_host      = parse_url( home_url() );
		$position          = 0;
		$types             = [ 'external', 'card' ];

		foreach ( $item_list as $item ) {
			// Convert array to object if necessary
			if ( ! is_object( $item ) ) {
				$item = (object) $item;
			}

			// exclude custom text fields from JSON schema
			if ( 'text' === $item->content_type ) {
				continue;
			}

			// Get the correct permalink
			$permalink = null;
			if ( $item->url ) {
				$permalink = $item->url;
			} elseif ( ! empty( $item->canonical_post_id ) ) {
				$permalink = get_the_permalink( $item->canonical_post_id );
			} elseif ( ! in_array( $item->content_type, $types, true ) ) {
				$permalink = get_the_permalink( $item->relation_id );
			}

			// No valid permalink, so move on
			if ( empty( $permalink ) || ! wp_http_validate_url( $permalink ) ) {
				continue;
			}

			// Don't add external URLs to JSON-LD
			$permalink_host = parse_url( $permalink );
			// If the link is a subdomain, we want to keep it in the JSON-LD
			// If the link is neither a subdomain nor the primary domain, skip it
			if ( ! Str::contains( $current_host['host'], $permalink_host['host'] ) && ! Str::is( $current_host['host'], $permalink_host['host'] ) ) {
				continue;
			}

			$item_list_element[] = [
				'@type'    => 'ListItem',
				'position' => $position,
				'url'      => $permalink,
			];
			$position            = $position + 1;
		}

		$item_list_element          = $this->filter_json_ld_value( $item_list_element, 'item_list', $schema_prop, $json_ld, $creation );
		$json_ld['itemListElement'] = $item_list_element;

		return $json_ld;
	}
}
