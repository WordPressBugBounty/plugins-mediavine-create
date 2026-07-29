<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Purr extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'purr';
	}

	/**
	 * Serialize a found-recipe row into Create card data.
	 *
	 * @param array $found_recipe Recipe stub from find.
	 * @return array|array[]|false
	 */
	public static function serialize_found( $found_recipe ) {
		return static::serializer( $found_recipe );
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
		return static::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
	}

	/**
	 * Post ID used for the SRP ratings double-check.
	 *
	 * @param array $stored_recipe Stored Create recipe.
	 * @return int|string|null
	 */
	public static function get_srp_ratings_post_id( $stored_recipe ) {
		return isset( $stored_recipe['original_id'] ) ? $stored_recipe['original_id'] : null;
	}

	/**
	 * Whether this importer supports the reimport endpoint.
	 *
	 * @return bool
	 */
	public static function supports_reimport() {
		return true;
	}

	private static $table_name = 'posts';
	private static $pairs      = [
		'title'               => 'recipe_title',
		'description'         => 'recipe_meta',
		'active_time'         => 'recipe_cook',
		'prep_time'           => 'recipe_prep',
		'total_time'          => 'recipe_time',
		'yield'               => 'recipe_yield',
		'instructions'        => 'recipe_directions',
		'ingredient_sections' => 'recipe_ingredients',
		'notes'               => 'recipe_notes',
		'thumbnail_id'        => '_thumbnail_id',
		'rating'              => 'crfp-average-rating',
		'rating_count'        => 'crfp-total-ratings',
	];

	private static $nutrition_pairs = [
		'carbohydrates'      => 'nutrition_carbohydrates',
		'cholesterol'        => 'nutrition_cholesterol',
		'total_fat'          => 'nutrition_fat',
		'fiber'              => 'nutrition_fiber',
		'protein'            => 'nutrition_protein',
		'saturated_fat'      => 'nutrition_saturated',
		'sodium'             => 'nutrition_sodium',
		'sugar'              => 'nutrition_sugar',
		'calories'           => 'calories',
		'serving_size'       => 'serving_size',
		'number_of_servings' => 'recipe_yield',
	];

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$data = [];

		if ( ! empty( $post_id ) ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return $data;
			}
			if ( 'post' === $post->post_type && strpos( $post->post_content, '[recipe]' ) !== false || strpos( $post->post_content, '[cft format=0]' ) !== false ) {
				$meta   = get_post_meta( $post_id );
				$data[] = [
					'original_id' => $post_id,
					'title'       => $meta['recipe_title'][0],
				];
			}
			return $data;
		}

		$statement = "SELECT
						ID AS original_id,
						ID AS canonical_post_id,
						pm.meta_value AS title
						FROM {$wpdb->posts} p
						JOIN {$wpdb->postmeta} pm
						ON p.ID = pm.post_id
						AND (
							p.post_content LIKE '%[recipe]%'
							OR p.post_content LIKE '%[cft format=0]%'
						)
						AND pm.meta_key = 'recipe_title'
						AND p.post_type = 'post'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		return $wpdb->get_results( $statement, ARRAY_A );
	}

	public static function serializer( $api_data ) {
		global $wpdb;

		$recipe_id = $api_data['original_id'];
		$formatted = [];

		if ( empty( $recipe_id ) ) {
			return $recipe_id;
		}

		$recipe   = get_post_meta( $recipe_id );
		$post     = get_post( $recipe_id );
		$category = get_the_category( $recipe_id );
		if ( ! empty( $category ) ) {
			$category = $category[0]->cat_ID;
		}
		$tags     = get_the_tags( $recipe_id );
		$keywords = '';
		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$keywords .= ', ' . $tag->name;
			}
			$keywords = trim( $keywords, ',' );
		}
		$author    = get_the_author_meta( 'display_name', $post->post_author );
		$title     = ! empty( $recipe['_recipe_title'] ) ? $recipe['recipe_title'][0] : '';
		$formatted = [
			'original_id'           => $recipe_id,
			'original_post_id'      => $recipe_id,
			'canonical_post_id'     => $recipe_id,
			'title'                 => $title,
			'active_time_label'     => __( 'Cook Time', 'mediavine-create' ),
			'prep_time_label'       => __( 'Prep Time', 'mediavine-create' ),
			'additional_time_label' => __( 'Additional Time', 'mediavine-create' ),
			'time_display'          => 'prep_time,active_time,additional_time,',
			'active_time'           => 0,
			'prep_time'             => 0,
			'additional_time'       => 0,
			'total_time'            => 0,
			'author'                => $author,
			'category'              => $category,
			'keywords'              => $keywords,
			'associated_posts'      => json_encode( [ (string) $recipe_id ] ),
		];

		foreach ( self::$pairs as $mv_key => $purr_key ) {

			if ( ! isset( $recipe[ $purr_key ] ) || ! empty( $formatted[ $mv_key ] ) ) {
				continue;
			}
			$value = $recipe[ $purr_key ][0];

			if ( in_array( $mv_key, [ 'prep_time', 'active_time', 'additional_time', 'total_time' ], true ) ) {
				$formatted[ $mv_key ] = MV_Recipe_Importer::time_to_seconds( $value );
				continue;
			}

			if ( 'ingredient_sections' === $mv_key ) {
				$formatted[ $mv_key ] = MV_Recipe_Importer::parse_ingredients( $value );
				continue;
			}

			if ( 'notes' === $mv_key ) {
				$formatted[ $mv_key ] = $value;
				continue;
			}

			if ( 'instructions' === $mv_key ) {
				$formatted[ $mv_key ] = self::parse_instructions( $value );
			}

			$formatted[ $mv_key ] = html_entity_decode( (string) $value );
		}

		$formatted['nutrition'] = self::parse_nutrition( $recipe );

		return $formatted;
	}

	public static function parse_instructions( $instructions ) {
		return str_replace( [ '<h4>', '</h4>' ], [ '<h3>', '</h3>' ], $instructions );
	}

	public static function get_ratings( $post_id, $mv_recipe_id ) {
		$ratings = [];
		$post    = get_post( $post_id );
		if ( $post ) {
			$importer     = new MV_Recipe_Importer;
			$post_ratings = $importer->get_ratings_from_comments( $post->ID, 'crfp-average-rating', $mv_recipe_id );
			if ( $post_ratings ) {
				foreach ( $post_ratings as $rating ) {
					$ratings[] = $rating;
				}
			}
		}
		return $ratings;
	}

	private static function parse_nutrition( $recipe ) {
		$nutrition = [];
		foreach ( self::$nutrition_pairs as $mv_key => $purr_key ) {

			if ( ! isset( $recipe[ $purr_key ] ) || empty( $recipe[ $purr_key ][0] ) ) {
				continue;
			}
			$value = $recipe[ $purr_key ][0];

			if ( in_array( $mv_key, [ 'serving_size', 'number_of_servings' ], true ) ) {
				if ( 'number_of_servings' === $mv_key ) {
					$value = MV_Recipe_Importer::extract_digits( $recipe[ $purr_key ][0] );
				}
				$nutrition[ $mv_key ] = $value;
				continue;
			}
			$nutrition[ $mv_key ] = MV_Recipe_Importer::extract_floats( $value );

		}
		return $nutrition;
	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		$models       = \Mediavine\MV_DBI::get_models( [ 'posts', 'mv_creations' ] );
		$post_model   = $models->posts;
		$recipe_model = $models->mv_creations;
		$importer     = new MV_Recipe_Importer;

		if ( empty( $api_data['original_id'] ) ) {
			return false;
		}

		// original_id is always a WordPress post ID; coerce to int so it cannot be
		// used to inject SQL when interpolated into the statement below.
		$original_id = absint( $api_data['original_id'] );

		$recipe = $recipe_model->find_one( $api_data['id'] );

		$purr_shortcode        = '[recipe]';
		$purr_legacy_shortcode = '[cft format=0]';
		$thumbnail             = wp_get_attachment_url( $recipe->thumbnail_id );
		$mv_shortcode          = '[mv_create key="' . $recipe->id . '" title="' . $recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

		$statement = "SELECT ID as post_id, post_content as original_content FROM {$models->posts->table_name} WHERE ID = {$original_id}";

		$posts = $post_model->find(
			[
				'prepared_statement' => $statement,
			]
		);

		if ( empty( $posts ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		foreach ( $posts as $post ) {
			$recipe_update = [
				'id'                => $api_data['id'],
				'original_post_id'  => $post->post_id,
				'canonical_post_id' => $post->post_id,
			];

			$post->updated_content = str_replace( $purr_shortcode, $mv_shortcode, $post->original_content );
			$post->updated_content = str_replace( $purr_legacy_shortcode, $mv_shortcode, $post->updated_content );
			if ( ! Str::contains( $post->updated_content, $mv_shortcode ) ) {
				continue;
			}

			$updated_post_id = wp_update_post(
				[
					'ID'           => $post->post_id,
					'post_content' => $post->updated_content,
				], true
			);

			if ( is_wp_error( $updated_post_id ) ) {
				$api_data['errors'] = $updated_post_id->get_error_messages();
				$api_data['error']  = 'Failed to replace Purr Card in Post Content';
				continue;
			}

			$recipe_model->update( $recipe_update );

			\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
		}

		return $api_data;

	}

}
