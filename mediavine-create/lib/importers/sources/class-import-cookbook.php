<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\Helpers\Ingredient_Parse;
use Mediavine\Create\Importers\Helpers\Safe_Unserialize;
use Mediavine\Create\Importers\MV_Recipe_Importer;

class Import_Cookbook extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'cookbook';
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

	private static $regex           = '/<!--Cookbook Recipe (\d+)-->.+?<!--End Cookbook Recipe-->/ms';
	private static $shortcode_regex = '/\[cookbook_recipe id="(\d*)".*?[?^\]]/s';
	private static $pairs           = [
		'description'         => 'summary',
		'category'            => 'course',
		'cuisine'             => 'cuisine',
		'active_time'         => 'cook_time',
		'prep_time'           => 'prep_time',
		'additional_time'     => 'inactive_time',
		'instructions'        => 'instructions',
		'ingredient_sections' => 'ingredients',
		'nutrition'           => 'nutrition',
		'notes'               => 'notes',
		'number_of_servings'  => 'servings',
		'author'              => 'author',
		'thumbnail_id'        => 'image_id',
	];

	private static $nutrition_keys = [
		'serving_size',
		'calories',
		[ 'total_fat', 'fat' ],
		'saturated_fat',
		'trans_fat',
		'unsaturated_fat',
		'cholesterol',
		'sodium',
		'carbohydrates',
		'fiber',
		'sugar',
		'protein',
	];

	private static function cookbook_get_recipe_ids_from_content( $content ) {
		preg_match_all( static::$regex, $content, $matches );

		if ( ! isset( $matches[1] ) || ! is_array( $matches[1] ) ) {
			preg_match_all( static::$shortcode_regex, $content, $matches );

			if ( ! isset( $matches[1] ) || ! is_array( $matches[1] ) ) {
				return false;
			}
		}

		return array_map( 'absint', $matches[1] );
	}

	private static function get_canonical_post_ids( $cookbook_recipe_id ) {
		if ( empty( $cookbook_recipe_id ) ) {
			return false;
		}

		global $wpdb;

		// Cookbook recipe IDs are always numeric; coerce to int so the value
		// cannot break out of the LIKE literal below and inject SQL.
		$cookbook_shortcode = '<!--Cookbook Recipe ' . absint( $cookbook_recipe_id ) . '-->';

		$statement = "SELECT ID as id FROM {$wpdb->posts} WHERE post_content LIKE '%{$cookbook_shortcode}%' AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$posts     = $wpdb->get_results( $statement, ARRAY_A );

		if ( empty( $posts ) ) {
			return false;
		}
		return $posts;
	}

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$data = [];

		if ( ! empty( $post_id ) ) {
			$post = get_post( $post_id );

			if ( $post && Str::contains( $post->post_content, '<!--Cookbook' ) || $post && Str::contains( $post->post_content, '[cookbook_recipe' ) ) {
				$ids = static::cookbook_get_recipe_ids_from_content( $post->post_content );
				foreach ( $ids as $index => $recipe_id ) {
					$statement = "SELECT post_title as title FROM {$wpdb->posts} where ID = $recipe_id and post_type = 'cookbook_recipe'";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
					$results   = $wpdb->get_results( $statement );
					if ( empty( $results ) ) {
						continue;
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

		$statement = "SELECT id AS original_id, post_title AS title, IFNULL((SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status IN ('publish', 'draft') AND (post_content LIKE CONCAT('%[cookbook-recipe id=\"', original_id, '\"%') OR post_content LIKE CONCAT('%<!--Cookbook Recipe ', original_id, '-->%'))), FALSE) as canonical_post_id FROM {$wpdb->posts} WHERE post_type = 'cookbook_recipe';";
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

		$results = get_post_meta( $recipe_id );
		$post    = get_post( $recipe_id );
		$author  = get_the_author_meta( 'display_name', $post->post_author );

		// set up canonical post id
		$canonical_post_id  = 0;
		$canonical_post_ids = self::get_canonical_post_ids( $recipe_id );
		if ( ! empty( $canonical_post_ids ) ) {
			$canonical_post_id = Arr::first( $canonical_post_ids )['id'];
		}

		$formatted = [
			'original_id'           => $recipe_id,
			'original_post_id'      => $recipe_id,
			'canonical_post_id'     => $canonical_post_id,
			'title'                 => $post->post_title,
			'active_time_label'     => __( 'Cook Time', 'mediavine-create' ),
			'additional_time_label' => __( 'Inactive Time', 'mediavine-create' ),
			'total_time'            => 0,
			'yield'                 => '',
			'author'                => $author,
			'description'           => wp_strip_all_tags(  $post->post_content ),
		];

		foreach ( self::$pairs as $mv_key => $rm_key ) {
			$rm_key = 'cookbook_' . $rm_key;
			if ( ! isset( $results[ $rm_key ] ) || empty( $results[ $rm_key ][0] ) || ! empty( $formatted[ $mv_key ] ) ) {
				continue;
			}
			$value = $results[ $rm_key ][0];

			if ( Str::is( [ 'prep_time', 'active_time', 'additional_time' ], $mv_key ) ) {
				$seconds                  = static::time_to_seconds( $value );
				$formatted[ $mv_key ]     = $seconds;
				$formatted['total_time'] += $seconds;
				continue;
			}

			if ( 'instructions' === $mv_key ) {
				$formatted[ $mv_key ] = static::parse_instructions( $value );
				continue;
			}

			if ( 'ingredient_sections' === $mv_key ) {
				$formatted[ $mv_key ] = static::parse_ingredients( $value );
				continue;
			}

			if ( 'nutrition' === $mv_key ) {
				$formatted[ $mv_key ] = self::parse_nutrition( $value );
				continue;
			}

			if ( 'number_of_servings' === $mv_key ) {
				$formatted['nutrition'][ $mv_key ] = MV_Recipe_Importer::extract_digits( $value );
				continue;
			}

			if ( 'notes' === $mv_key ) {
				$formatted[ $mv_key ] = wpautop( $value );
				continue;
			}

			$formatted[ $mv_key ] = html_entity_decode( $value );
		}

		if ( ! empty( $results['_thumbnail_id'] ) ) {
			$formatted['thumbnail_id'] = $results['_thumbnail_id'][0];
		}

		$formatted['yield'] = static::parse_yield( $results );

		return $formatted;
	}

	private static function parse_yield( $recipe ) {
		$yield = '';
		if ( ! empty( $recipe['cookbook_servings'] ) ) {
			$yield .= $recipe['cookbook_servings'][0];
		}

		if ( ! empty( $recipe['cookbook_servings_unit'] ) ) {
			$yield .= ' ' . $recipe['cookbook_servings_unit'][0];
		}
		return $yield;
	}

	private static function time_to_seconds( $value ) {
		$time    = Safe_Unserialize::maybe( $value );
		$seconds = 0;
		if ( isset( $time['hours'] ) ) {
			$seconds += $time['hours'] * HOUR_IN_SECONDS;
		}
		if ( isset( $time['minutes'] ) ) {
			$seconds += $time['minutes'] * MINUTE_IN_SECONDS;
		}
		if ( isset( $time['seconds'] ) ) {
			$seconds += $time['seconds'];
		}
		return $seconds;
	}

	private static function parse_ingredients( $value ) {
		$heading = '';
		$section = [
			'ingredients' => [],
		];
		$value   = Safe_Unserialize::maybe( $value );
		if ( isset( $value['parsed'] ) ) {
			foreach ( $value['parsed'] as $group ) {
				if ( empty( $group['content'] ) ) {
					continue;
				}
				$content = $group['content'];
				$tag     = $group['tag'];

				$parsed = [];

				if ( Str::is( 'h4', $tag ) ) {
					$heading = $content;
					continue;
				}
				if ( Str::is( 'div', $tag ) ) {
					$ingredients            = MV_Recipe_Importer::parse_ingredients( $content );
					$section['ingredients'] = array_merge( $section['ingredients'], $ingredients[0]['ingredients'] );
					continue;
				}
				if ( ! is_array( $content ) ) {
					$parsed          = Ingredient_Parse::parse( $content );
					$parsed['group'] = $heading;
					if ( ! empty( $parsed['original_text'] ) ) {
						$section['ingredients'][] = $parsed;
					}
					continue;
				}
				foreach ( $content as $line ) {
					$parsed          = Ingredient_Parse::parse( $line );
					$parsed['group'] = $heading;
					if ( ! empty( $parsed['original_text'] ) ) {
						$section['ingredients'][] = $parsed;
					}
				}
			}
		}

		return [ $section ];
	}

	private static function parse_nutrition( $value ) {
		$nutrition = [];
		$value     = Safe_Unserialize::maybe( $value );

		foreach ( self::$nutrition_keys as $key ) {
			if ( 'serving_size' === $key && ! empty( $value[ $key ] ) ) {
				$nutrition[ $key ] = $value[ $key ];
				continue;
			}
			if ( is_array( $key ) ) {
				if ( ! empty( $value[ $key[1] ] ) ) {
					$nutrition[ $key[0] ] = MV_Recipe_Importer::extract_floats( $value[ $key[1] ] );
				}
				continue;
			}
			if ( ! empty( $value[ $key ] ) ) {
				$nutrition[ $key ] = MV_Recipe_Importer::extract_floats( $value[ $key ] );
			}
		}

		return $nutrition;
	}

	private static function parse_instructions( $value ) {
		$instructions = '';
		$value        = Safe_Unserialize::maybe( $value );
		if ( isset( $value['parsed'] ) ) {
			foreach ( $value['parsed'] as $group ) {
				$content = $group['content'];
				$tag     = $group['tag'];
				if ( empty( $content ) ) {
					continue;
				}
				if ( Str::is( [ 'h3', 'h4' ], $tag ) ) {
					$instructions .= "<h3>{$content}</h3>";
					continue;
				}
				if ( Str::is( 'div', $tag ) ) {
					$instructions .= MV_Recipe_Importer::process_block_text( $content );
					continue;
				}
				if ( Str::is( 'p', $tag ) ) {
					$instructions .= "<p>{$content}</p>";
					continue;
				}
				if ( Str::is( [ 'ul', 'ol' ], $tag ) ) {
					$instructions .= '<ol>';
					foreach ( $content as $line ) {
						$instructions .= "<li>{$line}</li>";
					}
					$instructions .= '</ol>';
				}
			}
		}
		return $instructions;
	}

	public static function get_ratings( $cookbook_recipe_id, $mv_recipe_id ) {
		$recipe_posts = self::get_canonical_post_ids( $cookbook_recipe_id );
		$ratings      = [];
		if ( $recipe_posts ) {
			$importer = new MV_Recipe_Importer;
			foreach ( $recipe_posts as $post ) {
				$post_ratings = $importer->get_ratings_from_comments( $post['id'], 'cookbook_comment_rating', $mv_recipe_id );
				if ( $post_ratings ) {
					foreach ( $post_ratings as $rating ) {
						$ratings[] = $rating;
					}
				}
			}
		}
		return $ratings;
	}

	public static function replace( $api_data ) {
		global $wpdb;
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		$recipe_model = new \Mediavine\MV_DBI( 'mv_creations' );

		if ( empty( $api_data['original_id'] ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$creation = $recipe_model->find_one( $api_data['id'] );
		if ( empty( $creation ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		// original_id is always a numeric recipe ID; coerce to int so it cannot
		// break out of the LIKE literals below and inject SQL.
		$cookbook_original_id = absint( $api_data['original_id'] );
		$cookbook_markup      = '<!--Cookbook Recipe ' . $cookbook_original_id . '-->';
		$cookbook_shortcode   = '[cookbook_recipe id="' . $cookbook_original_id . '"]';
		$thumbnail          = wp_get_attachment_url( $creation->thumbnail_id );
		$mv_shortcode       = '[mv_create key="' . $creation->id . '" title="' . $creation->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

		$statement = "SELECT ID as post_id,
						post_content
						FROM {$wpdb->posts}
						WHERE post_content
						LIKE '%{$cookbook_markup}%'
						OR post_content
						LIKE '%{$cookbook_shortcode}%'
						AND post_type
						NOT IN ('revision', 'attachment', 'nav_menu_item')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$posts     = $wpdb->get_results( $statement );

		if ( empty( $posts ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		foreach ( $posts as $post ) {
			$and    = self::process_replacement( $post->post_content, $api_data['original_id'], $mv_shortcode );
			$change = $and['change'];
			if ( $change ) {
				$updated_post_id = wp_update_post(
					[
						'ID'           => $post->post_id,
						'post_content' => $and['content'],
					], true
				);

				if ( is_wp_error( $updated_post_id ) ) {
					$errors = $updated_post_id->get_error_messages();
					continue;
				}

				$api_data['error'] = null;

				\Mediavine\Create\Creations::publish_creation( $creation->id );
			} else {
				$api_data['error'] = __( 'Failed to process shortcode replacement', 'mediavine-create' );
			}
		}

		return $api_data;

	}

	public static function process_replacement( $content, $cookbook_recipe_id, $mv_shortcode ) {
		$change             = false;
		$cookbook_markup    = '<!--Cookbook Recipe ' . $cookbook_recipe_id . '-->';
		$cookbook_shortcode = '[cookbook_recipe id="' . $cookbook_recipe_id . '"]';
		if ( Str::contains( $content, $cookbook_markup ) ) {
			// https://regex101.com/r/YkEVhL/1
			// Matches cookbook markup for specified recipe
			$re = '/<!--Cookbook Recipe ' . $cookbook_recipe_id . '-->(.+?)<!--End Cookbook Recipe-->/s';
			preg_match_all( $re, $content, $matches, PREG_OFFSET_CAPTURE, 0 );
			foreach ( $matches[0] as $match ) {
				$content = Str::replace( trim( $match[0] ), $mv_shortcode, $content );
				$change  = true;
			}
		}
		if ( Str::contains( $content, $cookbook_shortcode ) ) {
			$content = Str::replace( $cookbook_shortcode, $mv_shortcode, $content );
			if ( ! Str::contains( $content, $cookbook_shortcode ) ) {
				$change = true;
			}
		}
		return [
			'content' => $content,
			'change'  => $change,
		];
	}

}
