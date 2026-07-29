<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Zip_Recipes extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'zip_recipes';
	}

	/**
	 * Serialize a found-recipe row into Create card data.
	 *
	 * @param array $found_recipe Recipe stub from find.
	 * @return array|array[]|false
	 */
	public static function serialize_found( $found_recipe ) {
		return static::serializer( $found_recipe['original_id'] );
	}

	/**
	 * Collect native ratings after a recipe has been stored.
	 *
	 * @param array              $stored_recipe Stored Create recipe (has id).
	 * @param array              $serialized    Serialized source recipe.
	 * @param array              $found_recipe  Original find stub.
	 * @param MV_Recipe_Importer $context       Importer host (ratings helpers).
	 * @return array|false
	 */
	public static function get_import_ratings( $stored_recipe, $serialized, $found_recipe, MV_Recipe_Importer $context ) {
		return static::get_ratings( $stored_recipe );
	}

	/**
	 * Attach native ratings onto the arrays that will be returned.
	 *
	 * @param array $stored_recipe Stored recipe (by ref).
	 * @param array $found_recipe  Found stub (by ref).
	 * @param array $ratings       Rating rows.
	 * @return void
	 */
	public static function assign_native_ratings( &$stored_recipe, &$found_recipe, $ratings ) {
		$found_recipe['ratings'] = $ratings;
	}

	public static $table_name = 'amd_zlrecipe_recipes';

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
		'category'          => 'category',
		'cuisine'           => 'cuisine',
	];

	public static $nutrition_pairs = [
		'serving_size'  => 'serving_size',
		'calories'      => 'calories',
		'total_fat'     => 'fat',
		'carbohydrates' => 'carbs',
		'protein'       => 'protein',
		'sugar'         => 'sugar',
		'fiber'         => 'fiber',
		'saturated_fat' => 'saturated_fat',
		'sodium'        => 'sodium',
	];

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$table_name = 'amd_zlrecipe_recipes';
		$data       = [];

		$models = \Mediavine\MV_DBI::get_models( null, 'amd_' );

		if ( isset( $models->amd_zlrecipe_recipes ) ) {

			$is_ziprecipes = $models->amd_zlrecipe_recipes->normalize_data(
				[
					'category' => 'test',
				]
			);

			// Additional check because Zip Recipes and Ziplist have the same table name.
			if ( ! $is_ziprecipes ) {
				return $data;
			}

			if ( ! empty( $post_id ) ) {
				global $wpdb;
				$data = [];
				$post = get_post( $post_id );
				if ( $post ) {
					$re = '/\[amd-zlrecipe-recipe:(\d+).*?[?^\]]/s';
					// Search for embeds
					preg_match_all( $re, $post->post_content, $matches );
					if ( empty( $matches ) || empty( $matches[1] ) ) {
						return $data;
					}
					foreach ( $matches[1] as $index => $recipe_id ) {
						$statement          = "SELECT recipe_title as title FROM {$models->amd_zlrecipe_recipes->table_name} where recipe_id=%s";
						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
						$prepared_statement = $wpdb->prepare( $statement, $recipe_id );
						$results            = $wpdb->get_results( $prepared_statement );
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
						if ( empty( $results ) ) {
							return [];
						}
						$recipe = $results[0];
						$data[] = [
							'original_id' => $recipe_id,
							'title'       => $recipe->title,
						];
					}
				}
				return $data;
			}
			$statement = "SELECT recipe_title as title, recipe_id as original_id, IFNULL((SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status IN ('publish', 'draft') AND post_content LIKE CONCAT('%[amd-zlrecipe-recipe:', original_id, '%') LIMIT 1), FALSE) as canonical_post_id FROM {$models->amd_zlrecipe_recipes->table_name}";
			$data      = $models->amd_zlrecipe_recipes->find(
				[
					'prepared_statement' => $statement,
				]
			);
		}
		return $data;
	}

	public static function serializer( $recipe_id ) {
		$zip_model = new \Mediavine\MV_DBI( self::$table_name );

		$recipe = $zip_model->find_one(
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

			if ( 'rating' === $key ) {
				if ( ! empty( $recipe->recipe_rating ) ) {
					$recipe->rating = $recipe->recipe_rating;
				}
			}

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

				$time = new \DateInterval( $recipe->{$value} );
				if ( $time ) {
					$total             = ( $time->h * HOUR_IN_SECONDS ) + ( $time->i * MINUTE_IN_SECONDS ) + $time->s;
					$formatted[ $key ] = $total;
					continue;
				}
			}

			$formatted[ $key ] = html_entity_decode( $recipe->{$value} );
		}

		$formatted['active_time_label'] = __( 'Cook Time', 'mediavine-create' );

		$ingredients_sections = [];

		if ( ! empty( $recipe->ingredients ) ) {
			$ingredients = explode( PHP_EOL, $recipe->ingredients );

			$heading = 'mv-has-no-group';
			$section = [];
			foreach ( $ingredients as $ingredient ) {
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

	public static function ratings_table_exists() {
		global $wpdb;
		$statement = "SELECT count(*) as table_exists FROM information_schema.TABLES WHERE TABLE_NAME = '{$wpdb->prefix}zrdn_visitor_ratings'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$result = $wpdb->get_row( $statement );
		return $result ? $result->table_exists : 0;
	}

	public static function get_ratings( $recipe ) {
		global $wpdb;
		$ratings = [];

		if ( ! static::ratings_table_exists() ) {
			return $ratings;
		}

		// original_id is always a numeric recipe ID; coerce to int to prevent SQLi.
		$zrdn_recipe_id = absint( $recipe['original_id'] );
		$statement = "SELECT rating, user_id FROM {$wpdb->prefix}zrdn_visitor_ratings WHERE recipe_id={$zrdn_recipe_id}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$results   = $wpdb->get_results( $statement, ARRAY_A );
		if ( empty( $results ) ) {
			return $ratings;
		}
		$ratings = array_map(
			function( $rating ) use ( $recipe ) {
				return [
					'creation'       => $recipe['id'],
					'rating'         => $rating['rating'],
					'author_name'    => '',
					'author_email'   => '',
					'review_content' => '',
					'review_title'   => '',
				];
			}, $results
		);
		return $ratings;
	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		$success   = true;
		$zip_model = new \Mediavine\MV_DBI( self::$table_name );
		$mv_model  = new \Mediavine\MV_DBI( 'mv_creations' );

		$zip_recipe = $zip_model->find_one(
			[
				'col' => 'recipe_id',
				'key' => $api_data['original_id'],
			]
		);

		$mv_recipe = $mv_model->find_one( $api_data['id'] );

		if ( empty( $mv_recipe ) || empty( $zip_recipe ) || empty( $zip_recipe->post_id ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$post = get_post( $zip_recipe->post_id );

		if ( empty( $post ) || empty( $post->post_content ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$output  = $post->post_content;
		$content = $post->post_content;

		$needle           = '[amd-zlrecipe-recipe:' . $api_data['original_id'] . ']';
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

			if ( is_wp_error( $result ) ) {
				$success = false;
			}

			if ( $content === $output ) {
				$success = false;
			} else {
				$success = true;
			}
		} else {
			$api_data['error'] = $error_message;
		}

		if ( ! $success ) {
			$api_data['error'] = $error_message;
		}

		return $api_data;

	}

}
