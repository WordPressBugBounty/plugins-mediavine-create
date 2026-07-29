<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Simple_Recipes_Pro_Legacy extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'simple_recipe_pro_legacy';
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

	// MV -> SRP
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
		'ingredients'     => 'Ingredients',
	];

	public static $nutrition_pairs = [
		// SRP -> MV
		'servingsize'   => 'serving_size',
		'calories'      => 'calories',
		'totalfat'      => 'total_fat',
		'carbohydrates' => 'carbohydrate',
		'protein'       => 'protein',
		'cholesterol'   => 'cholesterol',
		'sugars'        => 'sugar',
		'dietaryfiber'  => 'fiber',
		'saturatedfat'  => 'saturated_fat',
		'sodium'        => 'sodium',
	];

	public static function srp_read( $recipe ) {
		$postdata = [];

		// Sanitize this to match existing parsing
		foreach ( self::$recipe_pairs as $key => $value ) {
			$recipe = str_replace( $value, "\n;$value", $recipe );
		}

		$recipe = preg_replace( '/;([\w\s]*)\:/', '<field>$1<value>', $recipe );
		$fields = explode( '<field>', $recipe );
		foreach ( $fields as $field ) {
			$part = explode( '<value>', $field );
			if ( isset( $part[0] ) && ! empty( $part[0] ) ) {
				$postdata[ trim( $part[0] ) ] = isset( $part[1] ) ? trim( html_entity_decode( $part[1] ) ) : '';
			}
		}
		return $postdata;

	}

	public static function parse_ingredients( $ingredients ) {
		$group     = 'mv-has-no-group';
		$formatted = [];
		foreach ( $ingredients as $ingredient ) {
			if ( preg_match( '/.+:$/', $ingredient['ingredient'] ) ) {
				$group = str_replace( ':', '', $ingredient['ingredient'] );
			} else {
				if ( isset( $ingredient['portion'] ) ) {
					$ingredient['ingredient'] = $ingredient['portion'] . ' ' . $ingredient['ingredient'];
				}
				$parsed                                = Ingredient_Parse::parse( $ingredient['ingredient'] );
				$parsed['group']                       = $group;
				$formatted['section']['ingredients'][] = $parsed;
			}
		}
		return $formatted;
	}

	public static function serializer( $recipe ) {
		global $wpdb;

		$postmeta           = new \Mediavine\MV_DBI( 'postmeta' );
		$original_id        = $recipe['original_id'];
		$srp_raw            = $postmeta->find(
			[
				'order_by' => 'post_id',
				'where'    => [
					'meta_key' => '_simple_recipe_pro_plaintext', // phpcs:disable
					'post_id'  => $original_id,
				],
			]
		);
		$srp_ingredients    = $postmeta->find(
			[
				'order_by' => 'post_id',
				'where'    => [
					'meta_key' => '_simple_recipe_pro_ingredients', // phpcs:disable
					'post_id'  => $original_id,
				],
			]
		);
		$srp_image          = $postmeta->find(
			[
				'order_by' => 'post_id',
				'where'    => [
					'meta_key' => '_simple_recipe_pro_image', // phpcs:disable
					'post_id'  => $original_id,
				],
			]
		);
		$srp_nutrition_data = $postmeta->find(
			[
				'order_by' => 'post_id',
				'where'    => [
					'meta_key' => '_simple_recipe_pro_nutrition_data', // phpcs:disable
					'post_id'  => $original_id,
				],
			]
		);

		if ( ! $srp_raw ) {
			return false;
		}

		$formatted = [
			'canonical_post_id' => $original_id,
			'original_post_id'  => $original_id,
		];

		$srp_data = self::srp_read( $srp_raw[0]->meta_value );

		foreach ( self::$recipe_pairs as $key => $value ) {
			if ( empty( $srp_data[ $value ] ) ) {
				continue;
			}

			if ( in_array( $key, [ 'prep_time', 'cook_time', 'total_time' ], true ) ) {
				$srp_time = $srp_data[ $value ];

				$formatted[ $key ] = MV_Recipe_Importer::time_to_seconds( $srp_time );
				continue;
			}

			if ( 'instructions' === $key || 'notes' === $key ) {
				// Replace <br> tags with new lines
				$formatted[ $key ] = str_replace( [ '<br>', '<br/>', '<br />' ], '\n', $srp_data[ $value ] );
				// Replace new lines with paragraphs *if* they're not immediately preceded by a closing tag or a new line
				$formatted[ $key ] = preg_replace( '/(?<![\>\n])\n+/', '</p><p>', '<p>' . $srp_data[ $value ] . '</p>' );
				continue;
			}

			$formatted[ $key ] = $srp_data[ $value ];
		}

		$formatted['active_time_label'] = __( 'Cook Time', 'mediavine-create' );

		if ( isset( $formatted['additional_time'] ) ) {
			$formatted['additional_time_label'] = __( 'Wait Time', 'mediavine-create' );
		}

		$formatted['ingredient_sections'] = self::parse_ingredients( unserialize( $srp_ingredients[0]->meta_value, [ 'allowed_classes' => false ] ) );

		unset( $formatted['ingredients'] );

		if ( $srp_image ) {
			$attachment_id = \Mediavine\Create\Images::get_attachment_id_from_url( $srp_image[0]->meta_value );
			if ( $attachment_id ) {
				$formatted['thumbnail_id'] = $attachment_id;
			}
		}

		if ( $srp_nutrition_data ) {
			$nutrition = [];
			foreach ( (array) json_decode( $srp_nutrition_data[0]->meta_value ) as $key => $value ) {
				if (
					'servingsize_oz' === $key ||
					'caloriesfat' === $key ||
					! isset( self::$nutrition_pairs[ $key ] )
				) {
					continue;
				}
				$nutrition[ self::$nutrition_pairs[ $key ] ] = MV_Recipe_Importer::extract_floats( $value );
			}

			$formatted['nutrition'] = $nutrition;
		}

		if ( isset( $formatted['description'] ) ) {
			$formatted['description'] = wp_strip_all_tags( $formatted['description'] );
		}

		return $formatted;
	}

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$data = [];

		if ( ! empty( $post_id ) ) {
			$post = get_post( $post_id );
			if ( $post && strpos( $post->post_content, '[simple-recipe]' ) !== false ) {
				$statement = "SELECT
					post_id AS original_id,
					meta_value AS title
					FROM {$wpdb->postmeta} AS pm
					WHERE pm.post_id = %d
					AND pm.meta_key = '_simple_recipe_pro_recipename'";
				$prepared  = $wpdb->prepare( $statement, $post_id );

				$results = $wpdb->get_results( $prepared );
				if ( empty( $results ) ) {
					return;
				}
				$recipe = $results[0];
				$data[] = [
					'original_id' => $recipe->original_id,
					'title'       => $recipe->title,
				];
			}
			return $data;
		}

		$statement = "SELECT
			DISTINCT post_id AS original_id,
			p.ID as canonical_post_id,
			post_title AS title
			FROM {$wpdb->postmeta} AS pm
			JOIN {$wpdb->posts} AS p ON (p.ID = pm.post_id)
			WHERE p.post_type='post'
			AND p.post_status IN ('publish', 'draft')
			AND p.post_content LIKE '%[simple-recipe]%'";

		return $wpdb->get_results( $statement, 'ARRAY_A' );
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

		// original_id is always a WordPress post ID; coerce to int so it cannot be
		// used to inject SQL when interpolated into the statement below.
		$original_id = absint( $api_data['original_id'] );

		$recipe = $recipe_model->find_one( $api_data['id'] );

		$simple_shortcode = '[simple-recipe]';
		$thumbnail        = wp_get_attachment_url( $recipe->thumbnail_id );
		$mv_shortcode     = '[mv_create key="' . $recipe->id . '" title="' . $recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

		$statement = "SELECT ID as post_id,
						post_content as original_content
						FROM {$models->posts->table_name}
						WHERE ID = {$original_id}
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
			$post->updated_content = str_replace( $simple_shortcode, $mv_shortcode, $post->original_content );

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
