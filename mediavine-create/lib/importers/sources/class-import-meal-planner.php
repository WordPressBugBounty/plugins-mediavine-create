<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Meal_Planner {


	public static $table_name = 'mpprecipe_recipes';

	public static $table_name_ratings = 'mpprecipe_ratings';

	public static $pairs = [
		'canonical_post_id' => 'post_id',
		'original_post_id'  => 'post_id',
		'title'             => 'recipe_title',
		'description'       => 'summary',
		'prep_time'         => 'prep_time',
		'cook_time'         => 'cook_time',
		'total_time'        => 'total_time',
		'yield'             => 'yield',
		'instructions'      => 'instructions',
		'notes'             => 'notes',
		'created'           => 'created_at',
		'rating'            => 'rating',
		'rating_count'      => 'rating_count',
		'author'            => 'author',
		'category'          => 'type',
		'cuisine'           => 'cuisine',
		'keywords'          => 'keywords',
	];

	public static $nutrition_pairs = [
		'serving_size'    => 'serving_size',
		'calories'        => 'calories',
		'total_fat'       => 'fat',
		'carbohydrates'   => 'carbs',
		'protein'         => 'protein',
		'fiber'           => 'fiber',
		'trans_fat'       => 'transfat',
		'unsaturated_fat' => 'unsatfat',
		'saturated_fat'   => 'satfat',
		'sodium'          => 'sodium',
		'sugar'           => 'sugar',
		'cholesterol'     => 'cholesterol',
	];

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$table_name = self::$table_name;
		$mpp_model  = new \Mediavine\MV_DBI( $table_name );

		// Check if table exists
		// TODO: Add something like this to ORM
		$table_query = $wpdb->prepare( 'SELECT table_name FROM information_schema.tables WHERE TABLE_NAME = %s', $wpdb->prefix . $table_name );
		if ( $wpdb->get_var( $table_query ) !== $wpdb->prefix . $table_name ) {
			return [];
		}

		if ( ! empty( $post_id ) ) {
			$data = [];
			$post = get_post( $post_id );

			if ( $post && strpos( $post->post_content, '[mpprecipe-recipe:' ) !== false ) {
				$re = '/\[mpprecipe-recipe:(\d*).*?[?^\]]/s';

				// Search for embeds
				preg_match( $re, $post->post_content, $matches );

				if ( empty( $matches ) || empty( $matches[1] ) ) {
					return $data;
				}

				$recipe_id = $matches[1];

				$statement = "SELECT recipe_title as title FROM {$mpp_model->table_name} where recipe_id = $recipe_id";
				$results   = $wpdb->get_results( $statement );
				if ( empty( $results ) ) {
					return $data;
				}
				$recipe = $results[0];
				$data[] = [
					'original_id' => $recipe_id,
					'title'       => $recipe->title,
				];
			}
			return $data;
		}
		if ( $post_id ) {
			return [];
		}

		/**
		 * A little black magic here.
		 *
		 * For canonical_post_id, we're running a subselect (SELECT ... ) as canonical_post_id.
		 * We've wrapped that subquery in an IFNULL() to return false (to help SelectImporters.js).
		 * In order to make sure we're getting the right canonical_post_id, we do an equality check to see if mpprecipe_recipes.post_id
		 * is equal to the result of the canonical_post_id subquery. This returns a true/false or 1/0 result, so we order by this result in a descending fashion.
		 * If the recipe's post_id is equal to the canonical_post_id, it is the first result.
		 */
		$statement = "SELECT recipe_id AS original_id,
		recipe_title AS title,
		IFNULL((SELECT ID FROM {$wpdb->posts}
			WHERE ID=mpp.post_id
			AND post_content LIKE CONCAT('%[mpprecipe-recipe:', original_id, '%')
			AND post_type='post'
			AND post_status IN ('publish', 'draft')
			ORDER BY ID=post_id
			LIMIT 1), FALSE) as canonical_post_id
		FROM {$mpp_model->table_name} mpp";

		return $wpdb->get_results( $statement, ARRAY_A );
	}

	public static function find_ratings( $creation_id, $post_id ) {
		global $wpdb;
		$ratings_model = new \Mediavine\MV_DBI( self::$table_name_ratings );

		$statement = "SELECT
			%s AS creation,
			CONCAT('Review from ', comment_author) AS review_title,
			comment_content AS review_content,
			comment_author_email AS author_email,
			comment_author AS author_name,
			comment_date AS created,
			comment_date AS modified,
			rating,
			created_at AS created
			FROM {$ratings_model->table_name} AS r
			JOIN {$wpdb->comments} AS c ON (c.comment_ID = r.comment_id)
			WHERE c.comment_approved = 1 AND c.comment_post_ID = %s";

		$prepared_statement = $wpdb->prepare( $statement, [ $creation_id, $post_id ] );

		return $wpdb->get_results( $prepared_statement, 'ARRAY_A' );
	}

	public static function serializer( $api_data ) {
		$recipe_id = $api_data['original_id'];
		$mpp_model = new \Mediavine\MV_DBI( self::$table_name );

		$recipe = $mpp_model->find_one(
			[
				'col' => 'recipe_id',
				'key' => $recipe_id,
			]
		);

		$formatted = [
			'title'        => '',
			'thumbnail_id' => '',
			'nutrition'    => '',
		];

		if ( ! empty( $recipe->recipe_image ) ) {
			$attachment_id = \Mediavine\Create\Images::get_attachment_id_from_url( $recipe->recipe_image );
			if ( $attachment_id ) {
				$formatted['thumbnail_id'] = $attachment_id;
			}
		}

		foreach ( self::$pairs as $key => $value ) {
			if ( empty( $recipe->{$value} ) ) {
				continue;
			}
			if ( 'instructions' === $key ) {
				$formatted[ $key ] = MV_Recipe_Importer::process_block_text( $recipe->{$value}, true, true );
				continue;
			}
			if ( 'notes' === $key ) {
				$formatted[ $key ] = MV_Recipe_Importer::process_block_text( $recipe->{$value} );
				continue;
			}

			if ( in_array( $value, [ 'prep_time', 'cook_time', 'total_time' ], true ) ) {
				$time = 0;
				// This checks for the present of a digit in the time field.
				// If a number is not present, but a letter is, DateInterval throws a fatal error
				// for invalid formatting.
				$recipe->{$value} = Str::replace( 'PTH', 'PT', $recipe->{$value} );
				$recipe->{$value} = Str::replace( 'HM', 'H', $recipe->{$value} );
				try {
					$time = new \DateInterval( $recipe->{$value} );
				} catch ( \Exception $e ) {
					$formatted[ $key ] = $time;
					continue;
				}

				if ( $time ) {
					$total             = ( $time->h * HOUR_IN_SECONDS ) + ( $time->i * MINUTE_IN_SECONDS ) + $time->s;
					$formatted[ $key ] = $total;
					continue;
				}
			}

			$formatted[ $key ] = html_entity_decode( $recipe->{$value} );
		}

		$formatted['active_time_label'] = __( 'Cook Time', 'mediavine' );

		$ingredients_sections = [];

		if ( ! empty( $recipe->ingredients ) ) {
			$ingredients = explode( PHP_EOL, $recipe->ingredients );

			$heading = 'mv-has-no-group';
			$section = [];
			foreach ( $ingredients as $ingredient ) {
				if ( '' === trim( $ingredient ) ) {
					continue;
				}

				$found_heading = MV_Recipe_Importer::is_heading( $ingredient );
				if ( $found_heading ) {
					$heading = $found_heading;
					continue;
				}

				$parsed                   = Ingredient_Parse::parse( $ingredient );
				$parsed['original_text']  = MV_Recipe_Importer::markdownish_to_html( $parsed['original_text'] );
				$parsed['group']          = $heading;
				$section['ingredients'][] = $parsed;
			}
			$ingredients_sections[] = $section;
		}

		$formatted['ingredient_sections'] = $ingredients_sections;

		$formatted = static::process_tagged_items( $formatted, $recipe_id );

		$nutrition = [];
		foreach ( self::$nutrition_pairs as $key => $value ) {
			if ( 'serving_size' === $key ) {
				$nutrition[ $key ] = $recipe->{$value};
				continue;
			}
			if ( ! empty( $recipe->{$value} ) ) {
				$nutrition[ $key ] = MV_Recipe_Importer::extract_floats( $recipe->{$value} );
			}
		}

		$formatted['nutrition'] = $nutrition;

		return $formatted;
	}

	private static function process_tagged_items( $recipe, $recipe_id ) {
		global $wpdb;

		$table_name  = 'mpprecipe_tags';
		$table_query = $wpdb->prepare( 'SELECT table_name FROM information_schema.tables WHERE TABLE_NAME = %s', $wpdb->prefix . $table_name );
		if ( $wpdb->get_var( $table_query ) !== $wpdb->prefix . $table_name ) {
			return $recipe;
		}

		$sql     = "SELECT tagged FROM {$wpdb->prefix}{$table_name} WHERE recipe_id={$recipe_id}";
		$results = $wpdb->get_row( $sql );
		if ( $results ) {
			$tagged = maybe_unserialize( $results->tagged );
			if ( empty( $tagged['text'] ) ) {
				return $recipe;
			}
			$text = $tagged['text'];

			if ( ! empty( $text['cuisines'] ) ) {
				$values            = array_values( $text['cuisines'] );
				$recipe['cuisine'] = $values[0];
			}
			if ( ! empty( $text['courses'] ) ) {
				$values             = array_values( $text['courses'] );
				$recipe['category'] = $values[0];
			}
		}

		return $recipe;
	}

	public static function replace( $api_data, $post_shortcodes = [] ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine' );
		$success           = true;
		$models            = \Mediavine\MV_DBI::get_models(
			[
				self::$table_name,
				'mv_creations',
			]
		);
		$mpp_model         = $models->{self::$table_name};
		$mv_model          = $models->mv_creations;

		$mpp_recipe = $mpp_model->find_one(
			[
				'col' => 'recipe_id',
				'key' => $api_data['original_id'],
			]
		);

		$mv_recipe = $mv_model->find_one( $api_data['id'] );

		if ( empty( $mpp_recipe ) || empty( $mpp_recipe->post_id ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		if ( empty( $post_shortcodes[ $api_data['original_id'] ] ) ) {
			$post_shortcodes[ $api_data['original_id'] ] = [ $mpp_recipe->post_id ];
		}

		foreach ( $post_shortcodes[ $api_data['original_id'] ] as $post_id ) {
			$post = get_post( $post_id );

			if ( empty( $post ) || empty( $post->post_content ) ) {
				$api_data['error'] = $error_message;
				return $api_data;
			}

			$output  = $post->post_content;
			$content = $post->post_content;

			$needle           = '[mpprecipe-recipe:' . $api_data['original_id'] . ']';
			$thumbnail        = wp_get_attachment_url( $mv_recipe->thumbnail_id );
			$formatted_recipe = '[mv_create key="' . $mv_recipe->id . '" title="' . $mv_recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

			if ( strpos( $content, $needle ) !== false ) {
				$output = str_replace( $needle, $formatted_recipe, $content );

				$result = wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $output,
					]
				);

				if ( is_wp_error( $result ) || $content === $output ) {
					$success = false;
				}
			} else {
				$api_data['error'] = $error_message;
				return $api_data;
			}
		}

		if ( ! $success ) {
			$api_data['error'] = $error_message;
		}

		return $api_data;

	}

}
