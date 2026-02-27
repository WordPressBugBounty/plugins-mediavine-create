<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Ziplist {

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
	];

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$table_name = 'amd_zlrecipe_recipes';
		$data       = [];
		$models     = \Mediavine\MV_DBI::get_models( null, 'amd_' );

		if ( isset( $models->amd_zlrecipe_recipes ) ) {
			$is_ziprecipes = $models->amd_zlrecipe_recipes->normalize_data(
				[
					'category' => 'test',
				]
			);

			// Additional check because Zip Recipes and Ziplist have the same table name.
			if ( $is_ziprecipes ) {
				return $data;
			}
			if ( ! empty( $post_id ) ) {
				global $wpdb;
				$post = get_post( $post_id );
				if ( $post ) {
					// Current shortcode
					$re = '/\[amd-zlrecipe-recipe:\s?(\d+).*?[?^\]]/s';
					// Search for embeds
					preg_match_all( $re, $post->post_content, $matches );
					if ( empty( $matches ) || empty( $matches[1] ) ) {
						// Old shortcode
						$re = '/\[.+?id="amd\-zlrecipe\-recipe\-(\d*).+?[?^\]]/s';
						// Search for embeds
						preg_match_all( $re, $post->post_content, $matches );
						if ( empty( $matches ) || empty( $matches[1] ) ) {
							return $data;
						}
					}
					foreach ( $matches[1] as $recipe_id ) {
						$statement = "SELECT recipe_title as title FROM {$models->amd_zlrecipe_recipes->table_name} where recipe_id = %s";
						$prepared  = $wpdb->prepare( $statement, $recipe_id );
						$results   = $wpdb->get_results( $prepared );
						if ( empty( $results ) ) {
							continue;
						}
						$recipe = $results[0];
						$data[] = [
							'original_id' => $recipe_id,
							'title'       => $recipe->title,
						];
					}
					return $data;
				}
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

		$formatted['active_time_label'] = __( 'Cook Time', 'mediavine' );

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

		if ( ! empty( $recipe->serving_size ) ) {
			$nutrition['serving_size'] = $recipe->serving_size;
		}
		if ( ! empty( $recipe->calories ) ) {
			$nutrition['calories'] = MV_Recipe_Importer::extract_floats( $recipe->calories );
		}
		if ( ! empty( $recipe->fat ) ) {
			$nutrition['total_fat'] = MV_Recipe_Importer::extract_floats( $recipe->fat );
		}

		$formatted['nutrition'] = $nutrition;

		return $formatted;
	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine' );

		$success    = true;
		$post_model = new \Mediavine\MV_DBI( 'posts' );
		$mv_model   = new \Mediavine\MV_DBI( 'mv_creations' );
		$creation   = $mv_model->find_one( $api_data['id'] );

		$ziplist_shortcode = '[amd-zlrecipe-recipe:' . $api_data['original_id'] . ']';

		$statement = "SELECT ID,
						ID as post_id,
						ID AS original_post_id,
						ID AS canonical_post_id,
						post_content
						FROM {$post_model->table_name}
						WHERE post_content LIKE '%{$ziplist_shortcode}%'
						AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')";
		$posts     = $post_model->find( [ 'prepared_statement' => $statement ] );

		if ( empty( $posts ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		foreach ( $posts as $post ) {
			$output  = $post->post_content;
			$content = $post->post_content;

			$zip_shortcode = "[amd-zlrecipe-recipe:{$api_data['original_id']}]";

			if ( Str::contains( $zip_shortcode, $content ) ) {
				$thumbnail    = wp_get_attachment_url( $creation->thumbnail_id );
				$mv_shortcode = "[mv_create key=\"{$creation->id}\" title=\"{$creation->title}\" thumbnail=\"{$thumbnail}\" type=\"recipe\"]";

				$output = Str::replace( $zip_shortcode, $mv_shortcode, $content );

				$post_id = wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $output,
					]
				);

				$updated_post = get_post( $post_id );
				$success      = $updated_post !== $content;
				if ( is_wp_error( $post_id ) ) {
					$success = false;
				}
			} else {
				$api_data['error'] = $error_message;
			}

			if ( $success ) {
				\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
				continue;
			}
			$api_data['error'] = $error_message;

		}
		return $api_data;
	}

}
