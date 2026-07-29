<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Yummly extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'yummly';
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
		$rating = isset( $serialized['rating'] ) ? $serialized['rating'] : null;
		if ( empty( $rating ) ) {
			return false;
		}
		$ratings = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$ratings[] = [
				'creation' => $stored_recipe['id'],
				'rating'   => $rating,
			];
		}
		return $ratings;
	}

	public static $table_name = 'amd_yrecipe_recipes';

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
	];

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$table_name = 'amd_yrecipe_recipes';
		$data       = [];
		$models     = \Mediavine\MV_DBI::get_models( null, 'amd_' );

		if ( isset( $models->amd_yrecipe_recipes ) ) {
			if ( ! empty( $post_id ) ) {
				global $wpdb;
				$post = get_post( $post_id );
				if ( $post ) {
					$re = '/\[amd-yrecipe-recipe:(\d+).*?]/s';

					preg_match_all( $re, $post->post_content, $matches );

					if ( empty( $matches ) ) {
						return $data;
					}

					foreach ( $matches[1] as $index => $recipe_id ) {
						$statement = "SELECT recipe_id as id, recipe_title as title FROM {$models->amd_yrecipe_recipes->table_name} where recipe_id = %s";
						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
						$prepared  = $wpdb->prepare( $statement, $recipe_id );
						$results   = $wpdb->get_results( $prepared );
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
						if ( empty( $results ) ) {
							continue;
						}

						$recipe = $results[0];
						$data[] = [
							'original_id' => $recipe->id,
							'title'       => $recipe->title,
						];
					}
				}

				return $data;
			}

			$statement = "SELECT recipe_title as title, recipe_id as original_id, IFNULL((SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status IN ('publish', 'draft') AND post_content LIKE CONCAT('%[amd-yrecipe-recipe:', original_id, '%') LIMIT 1), FALSE) as canonical_post_id FROM {$models->amd_yrecipe_recipes->table_name}";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
			return $wpdb->get_results( $statement, ARRAY_A );
		}
		return [];
	}

	public static function serializer( $recipe_id ) {
		$y_model = new \Mediavine\MV_DBI( self::$table_name );

		$recipe = $y_model->find_one(
			[
				'col' => 'recipe_id',
				'key' => $recipe_id,
			]
		);

		$formatted = [
			'title'        => '',
			'thumbnail_id' => '',
			'nutrition'    => [],
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

		if ( ! empty( $recipe->serving_size ) ) {
			$formatted['nutrition']['serving_size'] = $recipe->serving_size;
		}
		if ( ! empty( $recipe->calories ) ) {
			$formatted['nutrition']['calories'] = $recipe->calories;
		}
		if ( ! empty( $recipe->fat ) ) {
			$formatted['nutrition']['fat'] = $recipe->fat;
		}

		return $formatted;
	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		$success  = true;
		$y_model  = new \Mediavine\MV_DBI( self::$table_name );
		$mv_model = new \Mediavine\MV_DBI( 'mv_creations' );

		$y_recipe = $y_model->find_one(
			[
				'col' => 'recipe_id',
				'key' => $api_data['original_id'],
			]
		);

		$mv_recipe = $mv_model->find_one( $api_data['id'] );

		if ( empty( $y_recipe ) || empty( $y_recipe->post_id ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$post = get_post( $y_recipe->post_id );

		if ( empty( $post ) || empty( $post->post_content ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$output  = $post->post_content;
		$content = $post->post_content;

		$needle           = '/\[amd-yrecipe-recipe:' . $api_data['original_id'] . '.*?[?^\]]/s';
		$thumbnail        = wp_get_attachment_url( $mv_recipe->thumbnail_id );
		$formatted_recipe = '[mv_create key="' . $mv_recipe->id . '" title="' . $mv_recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';
		if ( preg_match( $needle, $content ) ) {

			$output = preg_replace( $needle, $formatted_recipe, $content );

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
			$api_data['error'] = 'No Yummly Recipe Found';
		}

		if ( ! $success ) {
			$api_data['error'] = $error_message;
		}

		\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
		return $api_data;
	}
}
