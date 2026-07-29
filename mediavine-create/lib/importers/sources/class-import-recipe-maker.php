<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\Helpers\Safe_Unserialize;
use Mediavine\Create\Importers\MV_Recipe_Importer;
use Mediavine\Create\Plugin;

class Import_Recipe_Maker extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'recipe_maker';
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
		$full_recipe       = $serialized;
		$full_recipe['id'] = $stored_recipe['id'];
		return static::get_ratings( $full_recipe );
	}

	/**
	 * Whether this importer supports the reimport endpoint.
	 *
	 * @return bool
	 */
	public static function supports_reimport() {
		return true;
	}

	/**
	 * Prepare api_data before reimport. Return false to abort.
	 *
	 * @param array $api_data creation_id / original_id payload.
	 * @return array|false
	 */
	public static function prepare_reimport_data( $api_data ) {
		$api_data['original_id'] = static::find_recipe_id_from_parent_post( $api_data['original_id'] );
		if ( ! $api_data['original_id'] ) {
			return false;
		}
		return $api_data;
	}

	private static $post_type = 'wprm_recipe';
	public static $pairs      = [
		'canonical_post_id'     => 'parent_post_id',
		'original_post_id'      => 'parent_post_id',
		'yield'                 => 'servings',
		'number_of_servings'    => 'servings',
		'prep_time'             => 'prep_time',
		'cook_time'             => 'cook_time',
		'additional_time'       => 'custom_time',
		'additional_time_label' => 'custom_time_label',
		'ingredient_sections'   => 'ingredients',
		'instructions'          => 'instructions',
		'nutrition'             => 'nutrition',
		'notes'                 => 'notes',
		'category'              => 'type',
		'video'                 => 'video_embed',
		'external_video'        => 'video_metadata',
	];
	private static $recipe    = [];

	private static function is_recipe_maker( $content ) {
		$compatibility_string = '<!--WPRM';
		$shortcode            = '[wprm-recipe';
		$match                = false;

		if ( Str::contains( $content, $compatibility_string ) || Str::contains( $content, $shortcode ) ) {
			$match = true;
		}
		return $match;
	}

	public static function canonical_post_id( $recipe_id ) {
		return get_post_meta( $recipe_id, 'wprm_parent_post_id', true );
	}

	public static function find_recipes( $post_id = null ) {

		global $wpdb;
		$post_type = self::$post_type;

		$data = [];

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( $post && ( $post_type === $post->post_type || self::is_recipe_maker( $post->post_content ) ) ) {
				$re = '/<!\-\-WPRM Recipe (\d+).*?[^\-\->]/s';
				// Search for embeds
				preg_match_all( $re, $post->post_content, $matches );
				if ( empty( $matches ) || empty( $matches[1] ) ) {
					$re = '/\[wprm-recipe id="(\d+)".*?[?^\]]/s';
					// Search for embeds
					preg_match_all( $re, $post->post_content, $matches );

					if ( empty( $matches ) || empty( $matches[1] ) ) {
						return $data;
					}
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

		$statement = "SELECT id as original_id, post_title as title, IFNULL((SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='wprm_parent_post_id' AND post_id=original_id LIMIT 1), FALSE) as canonical_post_id FROM {$wpdb->posts} WHERE post_type='{$post_type}' AND post_status = 'publish';";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		return $wpdb->get_results( $statement, ARRAY_A );
	}

	public static function find_recipe_id_from_parent_post( $parent_post_id ) {
		global $wpdb;

		// parent_post_id comes from the request payload; coerce to int to prevent SQLi.
		$parent_post_id = absint( $parent_post_id );
		$statement = "SELECT post_id AS recipe_id FROM {$wpdb->postmeta} WHERE meta_key='wprm_parent_post_id' AND meta_value={$parent_post_id}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$results   = $wpdb->get_row( $statement );

		return ! empty( $results->recipe_id ) ? $results->recipe_id : false;
	}

	public static function serializer( $api_data ) {
		$prefix = 'wprm_';

		$recipe_id = $api_data['original_id'];
		$formatted = [];

		if ( empty( $recipe_id ) ) {
			return $recipe_id;
		}

		$results = get_post_meta( $recipe_id );
		$post    = get_post( $recipe_id );

		self::$recipe = $results;

		$formatted = [
			'original_id'       => $recipe_id,
			'title'             => html_entity_decode( $post->post_title ),
			'description'       => self::parse_description( $post->post_content ),
			'active_time_label' => __( 'Cook Time', 'mediavine-create' ),
			'total_time'        => 0,
			'nutrition'         => [],
		];

		foreach ( static::$pairs as $mv_key => $rm_key ) {

			if ( ! isset( $results[ $prefix . $rm_key ] ) ) {
				continue;
			}
			$value = $results[ $prefix . $rm_key ][0];

			if ( Str::is( [ 'prep_time', 'cook_time', 'additional_time' ], $mv_key ) ) {
				if ( empty( $value ) ) {
					unset( $formatted[ $mv_key ] );
					unset( $formatted[ $mv_key . '_label' ] );
					continue;
				}
				$formatted[ $mv_key ]     = $value * MINUTE_IN_SECONDS;
				$formatted['total_time'] += $formatted[ $mv_key ];
				continue;
			}

			if ( 'yield' === $mv_key && ! empty( $results[ $prefix . 'servings' ][0] ) ) {
				$formatted[ $mv_key ] = $results[ $prefix . 'servings' ][0] . ' ' . $results[ $prefix . 'servings_unit' ][0];
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

			if ( Str::is( [ 'external_video', 'video' ], $mv_key ) ) {
				$video_json = self::extract_video( $value );
				$video      = json_decode( $video_json );
				if ( empty( $video ) ) {
					continue;
				}

				if ( 'MEDIAVINE' === $video->source ) {
					$formatted['video'] = $video_json;
					continue;
				}

				$formatted['external_video'] = $video_json;
				continue;
			}

			$formatted[ $mv_key ] = html_entity_decode( $value );
		}

		$formatted['nutrition'] = self::parse_nutrition( $results );

		$formatted['author'] = '';

		if ( isset( $results['wprm_author_name'][0] ) ) {
			$formatted['author'] = $results['wprm_author_name'][0];
		}

		if ( empty( $formatted['author'] ) ) {
			$user_data           = get_userdata( $post->post_author );
			$formatted['author'] = $user_data->data->display_name;
			if ( isset( $user_data->data->first_name ) && isset( $user_data->data->last_name ) ) {
				$formatted['author'] = $user_data->data->first_name . ' ' . $user_data->data->last_name;
			}
		}

		$formatted['category'] = self::get_recipe_terms( $recipe_id, 'wprm_course' );
		$formatted['cuisine']  = self::get_recipe_terms( $recipe_id, 'wprm_cuisine' );
		$formatted['keywords'] = self::get_recipe_keywords( $recipe_id, 'wprm_keyword' );

		if ( ! empty( $results[ $prefix . 'rating' ] ) ) {
			$rating = unserialize( $results[ $prefix . 'rating' ][0], [ 'allowed_classes' => false ] );

			$formatted['rating']       = $rating['average'];
			$formatted['rating_count'] = $rating['count'];
		}

		if ( ! empty( $results['_thumbnail_id'] ) ) {
			$formatted['thumbnail_id'] = $results['_thumbnail_id'][0];
		}

		if ( empty( $formatted['number_of_servings'] ) ) {
			$formatted['number_of_servings'] = 1;
			if ( isset( $results[ $prefix . static::$pairs['number_of_servings'] ] ) ) {
				$formatted['number_of_servings'] = Arr::first( $results[ $prefix . static::$pairs['number_of_servings'] ], null ); // otherwise, get it from the WPRM recipe directly or default to null
			}
		}
		$formatted['nutrition']['number_of_servings'] = $formatted['number_of_servings'];

		return $formatted;
	}

	static function extract_video( $source_video = '' ) {
		$fields = [
			'name'         => '',
			'description'  => '',
			'uploadDate'   => '',
			'thumbnailUrl' => '',
			'duration'     => 'PT0S',
			'contentUrl'   => '',
			'embedUrl'     => '',
			'source'       => [ MV_Recipe_Importer::class, 'extract_video_source_from_video_url', 'embedUrl' ],
			'id'           => [ MV_Recipe_Importer::class, 'extract_video_id_from_video_url', 'embedUrl' ],
			'display'      => true,
		];

		if ( empty( $source_video ) ) {
			return '';
		}

		$wprm_video = Safe_Unserialize::maybe( $source_video );
		$video      = [];
		if ( $wprm_video === $source_video ) {
			$slug = Str::contains( $source_video, '.com' ) ?
				MV_Recipe_Importer::extract_video_id_from_video_url( $source_video ) :
				MV_Recipe_Importer::extract_mcp_video_slug_from_embed_code( $source_video );

			$video = MV_Recipe_Importer::get_mcp_video( $slug );
			if ( ! $video ) {
				return '';
			}

			$video['source'] = 'MEDIAVINE';

			return self::build_video( $video );
		}

		$source = '';

		// Recipe Maker changed something about the data being returned from YouTube...
		$video_url = '';
		if ( ! empty( $wprm_video['main']['contentUrl'] ) ) {
			$video_url = $wprm_video['main']['contentUrl'];
		} elseif ( ! empty( $wprm_video['@id'] ) ) {
			$video_url = $wprm_video['@id'];
		} else {
			return '';
		}

		$source = MV_Recipe_Importer::extract_video_source_from_video_url( $video_url );

		if ( empty( $video ) && 'MEDIAVINE' === $source ) {
			$slug  = MV_Recipe_Importer::extract_video_id_from_video_url( $video_url );
			$video = MV_Recipe_Importer::get_mcp_video( $slug );
			if ( ! $video ) {
				return '';
			}

			$video['source'] = $source;
		} elseif ( empty( $video ) ) {
			foreach ( $fields as $field => $default ) {
				// gets the source from either the contentUrl or embedUrl, depending on which is not empty
				if ( is_array( $default ) && count( $default ) > 2 && is_callable( [ $default[0], $default[1] ] ) ) {
					$wprm_video_url  = ( ! empty( $wprm_video['main'][ $default[2] ] ) ) ? $wprm_video['main'][ $default[2] ] : $wprm_video[ $default[2] ];
					$video[ $field ] = call_user_func( [ $default[0], $default[1] ], $wprm_video_url );
					continue;
				}

				$video[ $field ] = isset( $wprm_video['main'][ $field ] ) ? $wprm_video['main'][ $field ] : $default;
			}
		}

		if ( ! Str::is( [ 'MEDIAVINE', 'YOUTUBE', 'VIMEO' ], $video['source'] ) ) {
			return '';
		}

		return self::build_video( $video );
	}

	private static function get_recipe_terms( $post_id = 0, $term = null ) {
		global $wpdb;

		if ( ! $post_id || ! $term ) {
			return '';
		}

		$post_id = (int) $post_id;
		$terms   = wp_get_post_terms( $post_id, $term );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		if ( ! empty( $terms ) && isset( $terms[0]->name ) ) {
			return $terms[0]->name;
		}
		return '';
	}

	private static function get_recipe_keywords( $post_id = 0, $term = null ) {
		global $wpdb;

		if ( ! $post_id || ! $term ) {
			return '';
		}

		$post_id = (int) $post_id;
		$terms   = wp_get_post_terms( $post_id, $term );

		if ( is_wp_error( $terms ) ) {
			return '';
		}
		if ( ! empty( $terms ) ) {
			$keywords = '';

			foreach ( $terms as $keyword ) {
				$keywords .= $keyword->name . ',';
			}
			return trim( $keywords );
		}
		return '';
	}

	private static function parse_nutrition( $recipe ) {
		$nutrition        = [];
		$nutrition_prefix = 'wprm_nutrition_';
		$nutrition_pairs  = [
			'calories'        => 'calories',
			'carbohydrates'   => 'carbohydrates',
			'total_fat'       => 'fat',
			'saturated_fat'   => 'saturated_fat',
			'unsaturated_fat' => [ 'polyunsaturated_fat', 'monounsaturated_fat' ],
			'trans_fat'       => 'trans_fat',
			'cholesterol'     => 'cholesterol',
			'sodium'          => 'sodium',
			'fiber'           => 'fiber',
			'sugar'           => 'sugar',
			'protein'         => 'protein',
		];

		foreach ( $nutrition_pairs as $mv_nutrition_key => $rm_nutrition_key ) {
			if ( is_array( $rm_nutrition_key ) ) {
				$nutrition[ $mv_nutrition_key ] = 0;
				foreach ( $rm_nutrition_key as $key ) {
					if ( ! empty( $recipe[ $nutrition_prefix . $key ][0] ) ) {
						$nutrition[ $mv_nutrition_key ] = $nutrition[ $mv_nutrition_key ] + $recipe[ $nutrition_prefix . $key ][0];
					}
				}
				continue;
			}

			if ( ! empty( $recipe[ $nutrition_prefix . $rm_nutrition_key ][0] ) ) {
				$nutrition[ $mv_nutrition_key ] = $recipe[ $nutrition_prefix . $rm_nutrition_key ][0];
			}
		}

		$calculate_serving_size = function( $recipe ) {
			$number = ! empty( $recipe['wprm_nutrition_serving_size'][0] ) ? $recipe['wprm_nutrition_serving_size'][0] : '';
			$unit   = ! empty( $recipe['wprm_nutrition_serving_unit'][0] ) ? $recipe['wprm_nutrition_serving_unit'][0] : '';
			return $number . ' ' . $unit;
		};

		$nutrition['serving_size'] = $calculate_serving_size( $recipe );

		return $nutrition;
	}

	private static function parse_ingredients( $content ) {
		$global_ingredients = isset( self::$recipe['wprm_ingredient_links_type'][0] ) && 'global' === self::$recipe['wprm_ingredient_links_type'][0];

		$ingredients     = unserialize( $content, [ 'allowed_classes' => false ] );
		$ingredient_keys = [
			'quantity' => 'amount',
			'unit'     => 'unit',
			'name'     => 'name',
			'info'     => 'notes',
			'link'     => 'link',
		];

		$heading = '';
		$section = [];

		foreach ( $ingredients as $group ) {
			foreach ( $group['ingredients'] as $ingredient ) {
				$parsed = [
					'group'         => $group['name'],
					'original_text' => '',
				];
				foreach ( $ingredient_keys as $mv_ingredient_key => $rm_ingredient_key ) {
					if ( ! empty( $ingredient[ $rm_ingredient_key ] ) && 'link' !== $mv_ingredient_key ) {
						$parsed[ $mv_ingredient_key ] = $ingredient[ $rm_ingredient_key ];
						if ( 'info' === $mv_ingredient_key ) {
							$parsed['original_text'] .= ", {$parsed[ $mv_ingredient_key ]}";
							continue;
						}

						$parsed['original_text'] .= " {$parsed[ $mv_ingredient_key ]}";
					}
					if ( 'link' === $mv_ingredient_key ) {
						if ( ! $global_ingredients ) {
							if ( ! empty( $ingredient[ $rm_ingredient_key ] ) ) {
								$parsed[ $mv_ingredient_key ] = self::parse_ingredient_link( $ingredient[ $rm_ingredient_key ] );
							}
							continue;
						}
						$link     = get_term_meta( $ingredient['id'], 'wprmp_ingredient_link', true );
						$nofollow = get_term_meta( $ingredient['id'], 'wprmp_ingredient_link_nofollow', true );
						if ( empty( $link ) ) {
							if ( ! empty( $ingredient[ $rm_ingredient_key ] ) ) {
								$parsed[ $mv_ingredient_key ] = self::parse_ingredient_link( $ingredient[ $rm_ingredient_key ] );
							}
							continue;
						}
						$parsed[ $mv_ingredient_key ] = $link;
						$parsed['nofollow']           = 'follow' !== $nofollow;
						continue;
					}
				}

				$parsed['original_text']  = trim( html_entity_decode( $parsed['original_text'] ) );
				$section['ingredients'][] = $parsed;
			}
		}
		return [ $section ];
	}

	private static function parse_ingredient_link( $link ) {
		$link = Safe_Unserialize::maybe( $link );
		if ( ! empty( $link['url'] ) ) {
			return $link['url'];
		}
		return '';
	}

	private static function parse_description( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}
		$content = Str::to_html_entities( $content );

		return wpautop( $content );
	}

	private static function parse_instructions( $content ) {
		$Create                 = new \Mediavine\Create\Plugin();
		$instructions_groups    = unserialize( $content, [ 'allowed_classes' => false ] );
		$formatted_instructions = '';
		$parsed_instructions    = [];
		foreach ( $instructions_groups as $instructions_group ) {
			$section['heading'] = '';
			if ( ! empty( $instructions_group['name'] ) ) {
				$section['heading'] = "<h3>{$instructions_group['name']}</h3>";
			}
			$section['instructions'] = '<ol>';
			foreach ( $instructions_group['instructions'] as $instruction ) {
				$text = wpautop( $instruction['text'] );
				$text = Str::to_html_entities( $text );
				$doc  = new \DOMDocument();
				@$doc->loadHTML( $text, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
				$xpath = new \DOMXPath( $doc );
				foreach ( $xpath->query( '//p' ) as $p ) {
					$image_shortcode          = ! empty( $instruction['image'] ) ? '[mv_img id="' . $instruction['image'] . '"]' : '';
					$section['instructions'] .= '<li>' . $doc->saveHTML( $p ) . ' ' . $image_shortcode . '</li>';
				}
			}
			$section['instructions'] .= '</ol>';
			$parsed_instructions[]    = $section;
		}
		foreach ( $parsed_instructions as $section ) {
			$formatted_instructions .= $section['heading'] . $section['instructions'];
		}
		return $formatted_instructions;
	}

	public static function get_ratings_from_comments( $recipe ) {
		global $wpdb;
		$importer = MV_Recipe_Importer::get_instance();

		$models = \Mediavine\MV_DBI::get_models( [ 'wprm_ratings' ] );

		$statement = "SELECT
			%s as creation,
			rating,
			comment_author AS author_name,
			comment_author_email AS author_email,
			comment_content AS review_content,
			comment_date AS created,
			comment_date AS modified,
			CONCAT('Review from ', comment_author) AS review_title
			FROM {$wpdb->comments} AS c
			JOIN {$models->wprm_ratings->table_name} AS r ON (c.comment_id = r.comment_id)
			WHERE comment_post_ID='%s'";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$prepared = $wpdb->prepare( $statement, [ $recipe['id'], $recipe['canonical_post_id'] ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$ratings = $wpdb->get_results( $prepared, ARRAY_A );
		if ( $ratings ) {
			$importer::$comment_ratings_retrieved[] = $recipe['canonical_post_id'];
		}
		return $ratings;
	}

	public static function get_ratings_from_recipe( $recipe ) {
		global $wpdb;

		$models = \Mediavine\MV_DBI::get_models( [ 'wprm_ratings' ] );

		$statement = "SELECT
			%s as creation,
			rating
			FROM {$models->wprm_ratings->table_name}
			WHERE recipe_id='%d'";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$prepared = $wpdb->prepare( $statement, [ $recipe['id'], $recipe['original_id'] ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		return $wpdb->get_results( $prepared, ARRAY_A );
	}

	public static function get_ratings( $recipe ) {

		// WPRM has two different types of ratings
		$comment_ratings = self::get_ratings_from_comments( $recipe );
		$recipe_ratings  = self::get_ratings_from_recipe( $recipe );

		$ratings = array_merge( $comment_ratings, $recipe_ratings );

		return $ratings;

	}

	public static function replace( $api_data ) {
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		if ( empty( $api_data['original_id'] ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		// Remove Recipe Maker hooks on replace call. This prevents plugin conflicts.
		if ( class_exists( 'WPRM_Recipe_Saver' ) ) {
			remove_action( 'save_post', [ 'WPRM_Recipe_Saver', 'update_post' ] );
		}
		if ( class_exists( 'WPRM_Fallback_Recipe' ) ) {
			remove_action( 'rest_prepare_post', [ 'WPRM_Fallback_Recipe', 'replace_fallback_rest_api' ] );
			remove_action( 'rest_prepare_page', [ 'WPRM_Fallback_Recipe', 'replace_fallback_rest_api' ] );
		}

		global $wpdb;
		$models = \Mediavine\MV_DBI::get_models(
			[
				'posts',
				'mv_creations',
			]
		);

		$wprm_embed_check = '<!--End WPRM Recipe-->';
		$wprm_block_check = '<!-- wp:wp-recipe-maker/recipe';

		$recipe         = $models->mv_creations->find_one( $api_data['id'] );
		if ( empty( $recipe ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}
		// Ensure canonical_post_id is available in the response for the frontend
		if ( ! empty( $recipe->canonical_post_id ) ) {
			$api_data['canonical_post_id'] = $recipe->canonical_post_id;
		}
		if ( ! empty( $recipe->original_post_id ) ) {
			$api_data['original_post_id'] = $recipe->original_post_id;
		}
		$thumbnail      = wp_get_attachment_url( $recipe->thumbnail_id );
		$wprm_shortcode = '[wprm-recipe id="' . $api_data['original_id'] . '"]';
		$mv_shortcode   = '[mv_create key="' . $recipe->id . '" title="' . $recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';
		$mv_block       = MV_Recipe_Importer::get_gutenberg_block_from_shortcode( $mv_shortcode );

		$statement = "SELECT ID as post_id, post_content as original_content
						FROM {$wpdb->posts}
						WHERE post_type='post'
							AND post_status='publish'
							AND ( post_content LIKE '%{$wprm_embed_check}%'
							OR post_content LIKE '%{$wprm_block_check}%')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$posts     = $wpdb->get_results( $statement, ARRAY_A );

		if ( empty( $posts ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		foreach ( $posts as $post ) {
			// replace any blocks that might be in the post
			$updated = MV_Recipe_Importer::replace_recipe_blocks(
				$post['post_id'],
				$post['original_content'],
				$api_data['original_id'],
				'wp-recipe-maker/recipe',
				$mv_block
			);
			if ( $updated ) {
				$updated_post = get_post( $post['post_id'], ARRAY_A );
				$post         = [
					'post_id'          => $updated_post['ID'],
					'original_content' => $updated_post['post_content'],
				];
			}

			// Search for embeds
			$re = '/<!\-\-WPRM Recipe (\d+).*?[^\-\->].*<!\-\-End WPRM Recipe\-\->/s';

			preg_match( $re, $post['original_content'], $matches );

			if ( empty( $matches ) ) {
				if ( ! Str::contains( $post['original_content'], $wprm_shortcode ) ) {
					continue;
				}
			}

			$recipe_content = $matches[0];
			$post_recipe_id = $matches[1];
			if ( (int) $api_data['original_id'] !== (int) $post_recipe_id ) {
				continue;
			}

			$replace_with = $mv_shortcode;
			if ( function_exists( 'has_blocks' ) && has_blocks( $post['original_content'] ) ) {
				$replace_with = $mv_block;
			}

			// replace `<!--WPRM...` style recipe
			$updated_content = Str::replace( $recipe_content, $replace_with, $post['original_content'] );
			// replace `[wprm-recipe...]` shortcode just in case
			$updated_content = Str::replace( $wprm_shortcode, $replace_with, $updated_content );
			// if there were no replacements, no need to update the post
			if ( $updated_content === $post['original_content'] ) {
				continue;
			}

			$updated_post_id = wp_update_post(
				[
					'ID'           => $post['post_id'],
					'post_content' => $updated_content,
				], true
			);

			if ( is_wp_error( $updated_post_id ) ) {
				$api_data['errors'] = $updated_post_id->get_error_messages();
				$api_data['error']  = 'Failed to Replace Recipe Maker in Post Content';
				continue;
			}

			\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
		}

		return $api_data;

	}

	/**
	 * @param array $video
	 *
	 * @return bool|false|string
	 */
	private static function build_video( array $video ) {
		$video['imported'] = [
			'importer'         => 'recipe_maker',
			'imported_on'      => gmdate( 'Y-m-d H:i:s' ),
			'importer_version' => Plugin::VERSION,
		];

		return wp_json_encode( $video );
	}
}
