<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Simple_Recipes_Pro extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'simple_recipe_pro';
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
		return static::get_ratings( $stored_recipe['original_post_id'], $stored_recipe['id'] );
	}

	/**
	 * Whether to also run the Simple Recipes Pro ratings double-check.
	 *
	 * @return bool
	 */
	public static function should_check_srp_ratings() {
		return false;
	}

	public static $recipe_pairs = [
		'title'           => 'Name',
		'description'     => 'Description',
		'prep_time'       => 'Prep Time',
		'cook_time'       => 'Cook Time',
		'total_time'      => 'Total Time',
		'yield'           => 'Total Servings',
		'instructions'    => 'Directions',
		'notes'           => 'Notes',
		'category'        => 'Recipe Type',
		'cuisine'         => 'Cuisine',
		'author'          => 'By',
		'additional_time' => 'Wait Time',
	];

	public static $nutrition_pairs = [
		'serving_size'  => 'Serving Size',
		'calories'      => 'Calories',
		'total_fat'     => 'Total Fat',
		'carbohydrates' => 'Carbohydrate',
		'protein'       => 'Protein',
		'cholesterol'   => 'Cholesterol',
		'sugar'         => 'Sugars',
		'fiber'         => 'Dietary Fiber',
		'saturated_fat' => 'Saturated Fat',
		'sodium'        => 'Sodium',
	];

	public static function srp_read( $recipe ) {

		$postdata = [];
		$recipe   = preg_replace( '/\;([\w\s]*)\:/', '<field>$1<value>', $recipe );
		$fields   = explode( '<field>', $recipe );
		foreach ( $fields as $field ) {
			$part = explode( '<value>', $field );
			if ( isset( $part[0] ) && ! empty( $part[0] ) ) {
				$postdata[ $part[0] ] = isset( $part[1] ) ? trim( html_entity_decode( $part[1] ) ) : '';
			}
		}
		return $postdata;

	}

	public static function get_recipe_id( $original_id ) {
		global $wpdb;

		$meta = get_metadata_by_mid( 'post', $original_id );
		if ( ! $meta ) {
			return '0';
		}
		$shortcode = $meta->meta_key;
		$re        = '/.+?simple-recipe:(\d+).+?/i';
		preg_match( $re, $shortcode, $match );
		if ( ! empty( $match ) && is_numeric( $match[1] ) ) {
			return $match[1];
		}
		return '0';
	}

	public static function serializer( $recipe ) {
		global $wpdb;
		$postmeta    = new \Mediavine\MV_DBI( 'postmeta' );
		// original_id is always a numeric post-meta/post ID; coerce to int to prevent SQLi.
		$original_id = absint( $recipe['original_id'] );
		$srp_primary = $postmeta->find_one(
			[
				'col' => 'meta_id',
				'key' => $original_id,
			]
		);

		if ( ! $srp_primary ) {
			$statement = "SELECT
				meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_key LIKE '[simple-recipe:%'
				AND post_id = {$original_id}";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
			$srp_primary = $wpdb->get_results( $statement, 'OBJECT' );

			if ( $srp_primary && count( $srp_primary ) ) {
				$srp_primary = $srp_primary[0];
			}
		}

		$formatted = [
			'canonical_post_id' => $srp_primary->post_id,
			'original_post_id'  => $srp_primary->post_id,
		];

		$srp_data = self::srp_read( $srp_primary->meta_value );

		foreach ( self::$recipe_pairs as $key => $value ) {
			if ( empty( $srp_data[ $value ] ) ) {
				continue;
			}

			if ( in_array( $key, [ 'prep_time', 'cook_time', 'total_time' ], true ) ) {
				$srp_time = $srp_data[ $value ];

				$formatted[ $key ] = MV_Recipe_Importer::time_to_seconds( $srp_time );
				continue;
			}

			$formatted[ $key ] = $srp_data[ $value ];
		}

		$formatted['active_time_label'] = __( 'Cook Time', 'mediavine-create' );

		if ( isset( $formatted['additional_time'] ) ) {
			$formatted['additional_time_label'] = __( 'Wait Time', 'mediavine-create' );
		}

		$formatted['ingredient_sections'] = MV_Recipe_Importer::parse_ingredients( $srp_data['Ingredients'] );

		if ( ! empty( $srp_data['Image'] ) ) {
			$attachment_id = \Mediavine\Create\Images::get_attachment_id_from_url( $srp_data['Image'] );
			if ( $attachment_id ) {
				$formatted['thumbnail_id'] = $attachment_id;
			}
		}

		$nutrition = [];

		foreach ( self::$nutrition_pairs as $key => $value ) {
			if ( 'serving_size' === $key ) {
				$nutrition[ $key ] = $srp_data[ $value ];
				continue;
			}
			$nutrition[ $key ] = null;
			if ( ! empty( $srp_data[ $value ] ) ) {
				$nutrition[ $key ] = MV_Recipe_Importer::extract_floats( $srp_data[ $value ] );
			}
		}

		$formatted['nutrition'] = $nutrition;

		return $formatted;
	}

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$data = [];

		if ( ! empty( $post_id ) ) {
			$post = get_post( $post_id );
			if ( $post
				&& ( strpos( $post->post_content, 'simplerecipe' ) !== false
				|| strpos( $post->post_content, 'simple-recipe' ) !== false )
			) {
				$re = '/\[simple-recipe:(\d*).*?[?^\]]/s';

				// Search for embeds
				preg_match_all( $re, $post->post_content, $matches );

				if ( empty( $matches ) || empty( $matches[1] ) ) {
					$statement = "SELECT
						meta_id AS original_id,
						post_title AS title
						FROM {$wpdb->postmeta} AS pm
						JOIN {$wpdb->posts} AS p ON (p.ID = pm.post_id)
						WHERE p.ID = %d
						AND pm.meta_key LIKE '[simple-recipe%%'";
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
					$prepared  = $wpdb->prepare( $statement, $post_id );
					$results   = $wpdb->get_results( $prepared );
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( empty( $results ) ) {
						return $data;
					}
					$recipe = $results[0];
					$data[] = [
						'original_id' => $recipe->original_id,
						'title'       => $recipe->title,
					];
				}
				foreach ( $matches[1] as $index => $recipe_id ) {
					if ( empty( $matches[0] ) || empty( $matches[0][ $index ] ) ) {
						continue;
					}
					$shortcode = $matches[0][ $index ];
					$statement = "SELECT
						meta_id AS original_id,
						post_title AS title
						FROM {$wpdb->postmeta} AS pm
						JOIN {$wpdb->posts} AS p ON (p.ID = pm.post_id)
						WHERE pm.meta_key = %s";
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
					$prepared  = $wpdb->prepare( $statement, $shortcode );
					$results   = $wpdb->get_results( $prepared );
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( empty( $results ) ) {
						continue;
					}
					$recipe = $results[0];
					$data[] = [
						'original_id' => $recipe->original_id,
						'title'       => $recipe->title,
					];
				}
			}
			return $data;
		}

		$statement = "SELECT
			meta_id AS original_id,
			post_title AS title,
			p.ID as canonical_post_id
			FROM {$wpdb->postmeta} AS pm
			JOIN {$wpdb->posts} AS p ON (p.ID = pm.post_id)
			WHERE p.post_type='post'
			AND p.post_status IN ('publish', 'draft')
			AND pm.meta_key LIKE '[simple-recipe:%';";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		return $wpdb->get_results( $statement, 'ARRAY_A' );
	}

	public static function get_ratings( $original_id, $recipe_id ) {

		$importer    = new MV_Recipe_Importer;
		$ratings     = [];
		$srp_ratings = $importer->get_ratings_from_comments( $original_id, 'recipe_rating', $recipe_id );
		if ( $srp_ratings ) {
			return $srp_ratings;
		}

		/* Backward compatibility for pre-SRP 2.5 ratings */
		$srp_ratings = json_decode( get_post_meta( $original_id, '_ratings', true ), true );
		if ( ! $srp_ratings ) {
			return $ratings;
		}

		if ( is_array( $srp_ratings ) ) {
			foreach ( $srp_ratings as $user_id => $score ) {
				$rating = [
					'creation'       => $recipe_id,
					'rating'         => $score,
					'review_content' => '',
					'review_title'   => '',
				];
				$user   = get_userdata( $user_id );
				if ( $user ) {
					$rating['author_name']  = $user->user_login;
					$rating['author_email'] = $user->user_email;
				}
				$ratings[] = $rating;

			}
		}
		return $ratings;
	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		$models         = \Mediavine\MV_DBI::get_models( [ 'posts', 'mv_creations' ] );
		$post_model     = $models->posts;
		$creation_model = $models->mv_creations;
		$importer       = new MV_Recipe_Importer;

		if ( empty( $api_data['original_id'] ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$recipe = $creation_model->find_one( $api_data['id'] );

		// post_id resolves to a numeric WordPress post ID; coerce to int so it
		// cannot inject SQL via the LIKE/ID interpolations below.
		$api_data['post_id'] = absint( self::get_recipe_id( $api_data['original_id'] ) );

		$re               = '/(\[simple-recipe:' . $api_data['post_id'] . '.+?\])/i';
		$re_div           = '/<div[^>]+simplerecipe.*?>.*?<\/div>/';
		$simple_shortcode = "[simple-recipe:{$api_data['post_id']}";
		$thumbnail        = wp_get_attachment_url( $recipe->thumbnail_id );
		$mv_shortcode     = '[mv_create key="' . $recipe->id . '" title="' . $recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

		$statement = "SELECT
			ID as post_id,
			post_content as original_content
			FROM {$models->posts->table_name}
			WHERE (post_content LIKE '%{$simple_shortcode}%'
			OR ID = {$api_data['post_id']})
			AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')";
		$posts     = $post_model->find(
			[
				'prepared_statement' => $statement,
			]
		);

		if ( empty( $posts ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		foreach ( $posts as $post ) {
			$post->updated_content = preg_replace( [ $re, $re_div ], $mv_shortcode, $post->original_content );

			$update = [
				'ID'           => $post->post_id,
				'post_content' => $post->updated_content,
			];

			if ( ( strpos( $post->updated_content, $mv_shortcode ) === false ) ) {
				$api_data['error'] = $error_message;
				continue;
			}

			$updated_post_id = wp_update_post( $update, true );

			if ( is_wp_error( $updated_post_id ) ) {
				$api_data['error'] = $error_message;
				continue;
			}

			$api_data['error'] = null;
			\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
		}

		return $api_data;

	}

}
