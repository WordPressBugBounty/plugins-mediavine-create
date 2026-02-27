<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_WP_Ultimate_Recipe {

	private static $post_type = 'recipe';
	public static $pairs      = [
		'title'               => 'title',
		'yield'               => 'servings_normalized',
		'prep_time'           => 'prep_time',
		'cook_time'           => 'cook_time',
		'additional_time'     => 'passive_time',
		'ingredient_sections' => 'ingredients',
		'instructions'        => 'instructions',
		'nutrition'           => 'nutritional',
		'notes'               => 'notes',
		'description'         => 'description',
		'rating'              => 'rating',
		'thumbnail_id'        => 'alternate_image',
	];

	public static function find_recipes( $post_id = null ) {

		global $wpdb;
		$post_type = self::$post_type;

		$data = [];

		if ( $post_id ) {
			$post = get_post( $post_id );

			// If recipe in post
			if ( $post && strpos( $post->post_content, '[ultimate-recipe' ) !== false ) {
				$shortcode_atts = explode( ']', explode( '[ultimate-recipe', $post->post_content )[1] )[0];
				$re             = '/\[ultimate-recipe id="(\d+)".*?[?^\]]/s';

				// Search for embeds
				preg_match_all( $re, $post->post_content, $matches );

				if ( empty( $matches ) || empty( $matches[1] ) ) {
					return $data;
				}
				foreach ( $matches[1] as $index => $recipe_id ) {
					$recipe = get_post( $recipe_id );
					if ( ! isset( $recipe ) || $post_type !== $recipe->post_type ) {
						return $data;
					}
					$data[] = [
						'original_id' => $recipe_id,
						'title'       => $recipe->post_title,
					];
				}
			}
			return $data;
		}

		$statement = "SELECT id as original_id, post_title as title, IFNULL((SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status IN ('publish', 'draft') AND post_content LIKE CONCAT('%[ultimate-recipe id=\"', original_id, '\"%') LIMIT 1), FALSE) as canonical_post_id FROM {$wpdb->posts} WHERE post_type = '{$post_type}' AND post_type != 'revision'";
		return $wpdb->get_results( $statement, ARRAY_A );
	}

	public static function serializer( $api_data ) {
		global $wpdb;
		$prefix = 'recipe_';

		$recipe_id = $api_data['original_id'];
		$formatted = [];

		if ( empty( $recipe_id ) ) {
			return $recipe_id;
		}

		$results = get_post_meta( $recipe_id );
		$post    = get_post( $recipe_id );
		$author  = get_the_author_meta( 'display_name', $post->post_author );

		$formatted = [
			'original_id'           => $recipe_id,
			'active_time_label'     => __( 'Cook Time', 'mediavine' ),
			'additional_time_label' => __( 'Passive Time', 'mediavine' ),
			'total_time'            => 0,
			'author'                => $author,
		];

		foreach ( self::$pairs as $mv_key => $wpurp_key ) {

			if ( ! isset( $results[ $prefix . $wpurp_key ] ) || empty( $results[ $prefix . $wpurp_key ][0] ) ) {
				continue;
			}
			$value = $results[ $prefix . $wpurp_key ][0];

			if ( in_array( $mv_key, [ 'prep_time', 'cook_time', 'additional_time' ], true ) ) {
				$time_string              = $value . ' ' . $results[ $prefix . $wpurp_key . '_text' ][0];
				$formatted[ $mv_key ]     = strtotime( $time_string, 0 );
				$formatted['total_time'] += $formatted[ $mv_key ];
				continue;
			}

			if ( 'ingredient_sections' === $mv_key ) {
				$formatted[ $mv_key ] = self::parse_ingredients( $value );
				continue;
			}

			if ( 'instructions' === $mv_key ) {
				$formatted[ $mv_key ] = self::parse_instructions( $value );
				continue;
			}

			if ( 'nutrition' === $mv_key ) {
				$formatted[ $mv_key ] = self::parse_nutrition( $value );
				continue;
			}

			$formatted[ $mv_key ] = html_entity_decode( $value );

		}

		if ( empty( $formatted['thumbnail_id'] ) && ! empty( $results['_thumbnail_id'] ) ) {
			$formatted['thumbnail_id'] = $results['_thumbnail_id'][0];
		}

		$terms = maybe_unserialize( $results['recipe_terms'][0] );

		foreach ( $terms['cuisine'] as $cuisine ) {
			if ( $cuisine ) {
				$formatted['cuisine'] = $cuisine;
				break;
			}
		}

		foreach ( $terms['category'] as $category ) {
			if ( $category ) {
				$formatted['category'] = $category;
				break;
			}
		}

		return $formatted;
	}

	private static function parse_ingredients( $content ) {
		$ingredients     = maybe_unserialize( $content );
		$ingredient_keys = [
			'quantity' => 'amount',
			'unit'     => 'unit',
			'name'     => 'ingredient',
			'info'     => 'notes',
		];

		$heading = '';
		$section = [];

		foreach ( $ingredients as $ingredient ) {
			$parsed = [
				'group'         => $ingredient['group'],
				'original_text' => '',
			];
			foreach ( $ingredient_keys as $mv_ingredient_key => $wpurp_ingredient_key ) {
				if ( ! empty( $ingredient[ $wpurp_ingredient_key ] ) ) {
					$parsed[ $mv_ingredient_key ] = $ingredient[ $wpurp_ingredient_key ];
					if ( 'info' === $mv_ingredient_key ) {
						$parsed['original_text'] .= ", {$parsed[ $mv_ingredient_key ]}";
						continue;
					}
					$parsed['original_text'] .= " {$parsed[ $mv_ingredient_key ]}";
				}
			}
			$parsed['original_text']  = trim( $parsed['original_text'] );
			$section['ingredients'][] = $parsed;
		}
		return [ $section ];
	}

	private static function parse_instructions( $content ) {
		$instructions  = maybe_unserialize( $content );
		$results_array = [];
		foreach ( $instructions as $instruction ) {
			if ( ! empty( $instruction['group'] ) && ! array_key_exists( $instruction['group'], $results_array ) ) {
				$results_array[$instruction['group']] = [];
			}
			$instruction['description']     = trim( $instruction['description'] );
			$instruction['image_shortcode'] = ! empty( $instruction['image'] ) ? '[mv_img id="' . $instruction['image'] . '"]' : '';
			$results_array[$instruction['group']][] = "{$instruction['description']}{$instruction['image_shortcode']}";
		}
		$result          = '';
		foreach ( $results_array as $section => $items) {
			if ( ! empty( $section ) ) {
				$result .= "<h3>{$section}</h3>";
			}
			if ( ! empty( $items ) ) {
				$result .= '<ol>';
				foreach ( $items as $item ) {
					$result .= "<li>{$item}</li>";
				}
				$result .= '</ol>';
			}
		}
		$stripped_result = preg_replace( '!(\<br ?/?\>)([ ]|\s)+!i', '<br />', $result );
		return $stripped_result;
	}

	private static function parse_nutrition( $content ) {

		if ( empty( $content ) ) {
			return;
		}

		$wpurp_nutrition = maybe_unserialize( $content );
		$nutrition       = [];

		$nutrition_pairs = [
			'serving_size'       => 'serving_unit',
			'number_of_servings' => 'serving_size',
			'calories'           => 'calories',
			'carbohydrates'      => 'carbohydrates',
			'fat'                => 'fat',
			'saturated_fat'      => 'saturated_fat',
			'unsaturated_fat'    => 'unsaturated_fat',
			'trans_fat'          => 'trans_fat',
			'cholesterol'        => 'cholesterol',
			'sodium'             => 'sodium',
			'fiber'              => 'fiber',
			'sugar'              => 'sugar',
			'protein'            => 'protein',
		];

		foreach ( $nutrition_pairs as $mv_nutrition_key => $wpurp_nutrition_key ) {
			if ( ! empty( $wpurp_nutrition[ $mv_nutrition_key ] ) ) {
				$nutrition[ $mv_nutrition_key ] = $wpurp_nutrition[ $wpurp_nutrition_key ];
			}
		}

		return $nutrition;
	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine' );

		global $wpdb;
		$models = \Mediavine\MV_DBI::get_models(
			[
				'posts',
				'mv_creations',
			]
		);

		$embed_check = '[ultimate-recipe';

		$importer = new MV_Recipe_Importer;

		if ( empty( $api_data['original_id'] ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$recipe       = $models->mv_creations->find_one( $api_data['id'] );
		if ( empty( $recipe ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}
		$thumbnail    = wp_get_attachment_url( $recipe->thumbnail_id );
		$mv_shortcode = '[mv_create key="' . $recipe->id . '" title="' . $recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

		$statement = "SELECT ID as post_id,
						post_content as original_content
						FROM {$wpdb->posts}
						WHERE post_content LIKE '%{$embed_check}%'
						AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')";
		$posts     = $wpdb->get_results( $statement, ARRAY_A );

		if ( empty( $posts ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		// Get id and full shortcode
		// https://regex101.com/r/Z0ejQv/1/
		$re = '/\[ultimate-recipe id="(\d*)".*\]/s';

		foreach ( $posts as $post ) {
			// Search for embeds
			preg_match( $re, $post['original_content'], $matches );

			if ( empty( $matches ) ) {
				$api_data['error'] = 'Failed to Replace WP Ultimate Recipe in Post Content';
				continue;
			}

			$recipe_content = $matches[0];
			$post_recipe_id = $matches[1];

			if ( (int) $api_data['original_id'] !== (int) $post_recipe_id ) {
				$api_data['error'] = $error_message;
				continue;
			}

			$updated_content = str_replace( $recipe_content, $mv_shortcode, $post['original_content'] );

			$updated_post_id = wp_update_post(
				[
					'ID'           => $post['post_id'],
					'post_content' => $updated_content,
				], true
			);
			if ( is_wp_error( $updated_post_id ) ) {
				$api_data['errors'] = $updated_post_id->get_error_messages();
				$api_data['error']  = 'Failed to Replace WP Ultimate Recipe in Post Content';
				continue;
			}

			$api_data['error'] = null;
			\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
		}

		return $api_data;

	}

	public static function get_ratings( $api_data ) {
		$all_ratings = [];
		$ratings     = get_post_meta( $api_data['original_id'], 'recipe_user_ratings' );
		if ( empty( $ratings ) ) {
			return;
		}

		foreach ( $ratings as $rating ) {
			$rating = maybe_unserialize( $rating );
			$name   = '';
			if ( $rating['user'] ) {
				$author = get_user( $rating['user'] );
				$name   = "{$author->firstName} {$author->lastName}";
			}

			$new_rating = [
				'rating'      => $rating['rating'],
				'creation'    => $api_data['id'],
				'author_name' => $name,
			];

			$all_ratings[] = $new_rating;
		}
		return $all_ratings;
	}

}
