<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Create\Importers\Helpers\Safe_Unserialize;
use Mediavine\Create\Importers\MV_Recipe_Importer;
use Mediavine\Create\Helpers\Str;

class Import_Simple_Recipes_Pro_Latest extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'simple_recipe_pro_latest';
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
		return Import_Simple_Recipes_Pro::get_ratings( $stored_recipe['original_id'], $stored_recipe['id'] );
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
		'title'           => 'recipename',
		'prep_time'       => 'prep_time',
		'cook_time'       => 'cook_time',
		'total_time'      => 'total_time',
		'serving_size'    => 'serving_size',
		'instructions'    => 'directions',
		'notes'           => 'notes',
		'cuisine'         => 'cuisine',
		'author'          => 'author',
		'additional_time' => 'wait_time',
		'nutrition'       => 'nutrition_data',
		'servings'        => 'servings',
		'yields'          => 'servings',
	];

	public static $nutrition_pairs = [
		'serving_size'  => 'servingsize',
		'calories'      => 'calories',
		'total_fat'     => 'totalfat',
		'carbohydrates' => 'carbohydrate',
		'protein'       => 'protein',
		'cholesterol'   => 'cholesterol',
		'sugar'         => 'sugars',
		'fiber'         => 'dietaryfiber',
		'saturated_fat' => 'saturatedfat',
		'sodium'        => 'sodium',
	];

	private static $prefix = '_simple_recipe_pro_';

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$data = [];

		if ( ! empty( $post_id ) ) {
			$post = get_post( $post_id );
			if ( $post
				&& ( strpos( $post->post_content, 'simplerecipe' ) !== false
				|| strpos( $post->post_content, 'simple-recipe' ) !== false ) ) {
				$srp_latest_re = '/\[simple-recipe id="(\d+)".*?[?^\]]/s';
				preg_match_all( $srp_latest_re, $post->post_content, $matches );

				if ( empty( $matches ) || empty( $matches[1] ) ) {
					return [];
				}
				foreach ( $matches[1] as $index => $recipe_id ) {
					if ( empty( $matches[1] ) || empty( $matches[1][ $index ] ) ) {
						continue;
					}
					$statement = "SELECT
							meta_value as title,
							{$recipe_id} AS original_id
							FROM {$wpdb->postmeta} AS pm
							WHERE pm.post_id='{$recipe_id}'
							AND pm.meta_key='_simple_recipe_pro_recipename'";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
					$results   = $wpdb->get_results( $statement );
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

		$statement = "SELECT post_content
			FROM {$wpdb->posts}
			WHERE post_type='post'
			AND post_status IN ('publish', 'draft')
			AND post_content LIKE '%[simple-recipe id=%';";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$posts     = $wpdb->get_results( $statement, 'ARRAY_A' );
		if ( ! empty( $posts ) ) {
			foreach ( $posts as $result ) {
				$re = '/\[simple-recipe id="(\d+)".*?[?^\]]/s';
				preg_match_all( $re, $result['post_content'], $matches );
				if ( $matches && ! empty( $matches[1] ) ) {
					foreach ( $matches[1] as $match ) {
						$statement = "SELECT
							meta_value as title,
							{$match} AS original_id,
							{$match} AS canonical_post_id
							FROM {$wpdb->postmeta} AS pm
							WHERE pm.post_id='{$match}'
							AND pm.meta_key='_simple_recipe_pro_recipename'";
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
						$recipe    = $wpdb->get_results( $statement, 'ARRAY_A' );
						if ( $recipe ) {
							$data[] = $recipe[0];
						}
					}
				}
			}
		}
		if ( ! empty( $data ) ) {
			$data = array_map( 'unserialize', array_unique( array_map( 'serialize', $data ) ) );
		}
		return $data;
	}

	public static function serializer( $api_data ) {
		global $wpdb;

		// original_id is always a WordPress post ID; coerce to int to prevent SQLi.
		$original_id = absint( $api_data['original_id'] );

		$statement = "SELECT
			meta_key,
			meta_value
			FROM {$wpdb->postmeta}
			WHERE meta_key LIKE '_simple_recipe_pro%'
			AND post_id = {$original_id}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$srp_primary = $wpdb->get_results( $statement, 'ARRAY_A' );

		if ( $srp_primary ) {
			foreach ( $srp_primary as $meta ) {
				// phpcs:ignore WordPress.VIP.SlowDBQuery.slow_db_query_meta_key
				$srp_data[ $meta['meta_key'] ] = $meta['meta_value'];
			}
		}

		$formatted        = [
			'canonical_post_id' => $original_id,
			'original_post_id'  => $original_id,
		];
		$yields_or_serves = 'Yields' === $srp_data[ self::$prefix . 'labels_servings' ] ? 'yields' : 'servings';

		foreach ( self::$recipe_pairs as $key => $value ) {
			$srp_value = $srp_data[ self::$prefix . $value ];
			if ( empty( $srp_value ) ) {
				continue;
			}

			if ( in_array( $key, [ 'prep_time', 'cook_time', 'total_time', 'additional_time' ], true ) ) {
				$srp_time = $srp_value;

				$formatted[ $key ] = MV_Recipe_Importer::time_to_seconds( $srp_time );
				continue;
			}

			if ( in_array( $key, [ 'servings', 'yields' ], true ) ) {
				$formatted[ $key ] = null;
				$formatted[ $key ] = $yields_or_serves === $key ? $srp_data[ self::$prefix . $value ] : null;
				continue;
			}

			if ( 'nutrition' === $key ) {
				$srp_nutrition          = json_decode( $srp_value, true );
				$formatted['nutrition'] = self::parse_nutrition( $srp_nutrition );
				continue;
			}

			$formatted[ $key ] = $srp_value;
		}

		$formatted['description'] = self::parse_description( $srp_data[ self::$prefix . 'description' ] );

		$formatted['active_time_label'] = __( 'Cook Time', 'mediavine-create' );

		if ( isset( $formatted['additional_time'] ) ) {
			$formatted['additional_time_label'] = __( 'Wait Time', 'mediavine-create' );
		}

		$formatted['ingredient_sections'] = self::parse_ingredients( $srp_data[ self::$prefix . 'ingredients' ] );

		if ( ! empty( $srp_data[ self::$prefix . 'image' ] ) ) {
			$attachment_id = \Mediavine\Create\Images::get_attachment_id_from_url( $srp_data[ self::$prefix . 'image' ] );
			if ( $attachment_id ) {
				$formatted['thumbnail_id'] = $attachment_id;
			}
		}

		return $formatted;
	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		$models       = \Mediavine\MV_DBI::get_models( [ 'posts', 'mv_creations' ] );
		$post_model   = $models->posts;
		$recipe_model = $models->mv_creations;
		$importer     = new MV_Recipe_Importer;

		if ( empty( $api_data['original_id'] ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$recipe = $recipe_model->find_one( $api_data['id'] );

		// original_id is always a WordPress post ID; coerce to int so it cannot
		// inject SQL via the LIKE/ID interpolations below.
		$api_data['post_id'] = absint( $api_data['original_id'] );

		$re               = '/(\[simple-recipe id="' . $api_data['post_id'] . '".*?\])/i';
		$simple_shortcode = '[simple-recipe id="' . $api_data['post_id'] . '"';
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

		$api_data['error'] = null;
		foreach ( $posts as $post ) {
			$post->updated_content = preg_replace( $re, $mv_shortcode, $post->original_content );

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

	private static function is_ingredient_heading( $ingredient ) {
		return strpos( $ingredient, ':' ) ? trim( $ingredient, ':' ) : '';
	}

	private static function parse_ingredients( $ingredients ) {
		$srp_ingredients = Safe_Unserialize::maybe( $ingredients );

		$heading = '';
		$section = [];

		foreach ( $srp_ingredients as $ingredient ) {
			$ingredient_text = '';

			if ( '' === trim( $ingredient['ingredient'] ) ) {
				continue;
			}
			$ingredient_text = $ingredient['portion'] . ' ' . $ingredient['ingredient'];

			$found_heading = self::is_ingredient_heading( $ingredient['ingredient'] );
			if ( $found_heading ) {
				$heading = $found_heading;
				continue;
			}

			$parsed                   = Ingredient_Parse::parse( $ingredient_text );
			$parsed['original_text']  = MV_Recipe_Importer::markdownish_to_html( $parsed['original_text'] );
			$parsed['group']          = $heading;
			$section['ingredients'][] = $parsed;
		}

		return [ $section ];
	}

	private static function parse_nutrition( $srp_nutrition ) {
		$nutrition = [];

		foreach ( self::$nutrition_pairs as $nutrition_key => $nutrition_value ) {
			$nutrition[ $nutrition_key ] = null;
			if ( ! empty( $srp_nutrition[ $nutrition_value ] ) ) {
				$nutrition[ $nutrition_key ] = $srp_nutrition[ $nutrition_value ];
			}
		}

		return $nutrition;
	}

	private static function parse_description( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}
		$description = '';
		$content     = Str::to_html_entities( $content );
		$content     = wpautop( $content );
		$doc         = new \DOMDocument();
		@$doc->loadHTML( $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$xpath       = new \DOMXPath( $doc );
		foreach ( $xpath->query( '//p' ) as $p ) {
			$description .= $doc->saveHTML( $p ) . "\n";
		}
		return trim( $description );
	}
}
