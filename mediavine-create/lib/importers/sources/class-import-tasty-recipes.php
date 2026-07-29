<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Arr;
use Mediavine\Create\Helpers\Str;
use Mediavine\Create\Importers\Helpers\Safe_Unserialize;
use Mediavine\Create\Importers\MV_Recipe_Importer;
use Mediavine\Create\Plugin;

class Import_Tasty_Recipes extends Abstract_Source_Importer {

	/**
	 * Registry slug for this importer.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'tasty';
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
	 * Whether this importer supports the reimport endpoint.
	 *
	 * @return bool
	 */
	public static function supports_reimport() {
		return true;
	}

	/**
	 * When true, reimport never publishes even if publish=true was requested.
	 *
	 * @return bool
	 */
	public static function reimport_forces_unpublished() {
		return true;
	}

	/**
	 * Run reimport serialization for this source.
	 *
	 * @param array $api_data creation_id / original_id payload.
	 * @return array|false
	 */
	public static function reimport( $api_data ) {
		return static::import_missed_reviews( $api_data );
	}

	private static $table_name = 'posts';
	private static $pairs      = [
		'canonical_post_id'   => 'post_id',
		'original_post_id'    => 'post_id',
		'title'               => 'title',
		'description'         => 'description',
		'category'            => 'category',
		'cuisine'             => 'cuisine',
		'keywords'            => 'keywords',
		'serving_size'        => 'serving_size',
		'active_time'         => 'cook_time',
		'prep_time'           => 'prep_time',
		'yield'               => 'yield',
		'instructions'        => 'instructions',
		'ingredient_sections' => 'ingredients',
		'notes'               => 'notes',
		'created'             => 'created_at',
		'rating'              => 'average_rating',
		'rating_count'        => 'total_reviews',
		'video'               => 'video_url_response',
	];

	private static function get_canonical_post_ids( $tasty_recipe_id ) {
		if ( empty( $tasty_recipe_id ) ) {
			return false;
		}

		global $wpdb;

		// Tasty recipe IDs are always numeric; coerce to int so the value cannot
		// break out of the LIKE literals below and inject SQL.
		$tasty_recipe_id  = absint( $tasty_recipe_id );
		$tasty_shortcode  = '[tasty-recipe id="' . $tasty_recipe_id . '"]';
		$tasty_block_code = 'wp:wp-tasty/tasty-recipe {"id":' . $tasty_recipe_id;

		$statement = "SELECT ID as id FROM {$wpdb->posts} WHERE post_content LIKE '%{$tasty_shortcode}%' OR post_content LIKE '%{$tasty_block_code}%' AND post_type NOT IN ('revision', 'attachment', 'nav_menu_item')";
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
			if ( $post && strpos( $post->post_content, '[tasty-recipe' ) !== false ) {
				$re = '/\[tasty-recipe id="(\d+)".*?[?^\]]/s';
				// Search for embeds
				preg_match_all( $re, $post->post_content, $matches );

				if ( empty( $matches ) || empty( $matches[1] ) ) {
					return $data;
				}
				foreach ( $matches[1] as $index => $recipe_id ) {
					$recipe_id = absint( $recipe_id );
					$statement = "SELECT post_title as title FROM {$wpdb->posts} where ID = $recipe_id and post_type = 'tasty_recipe'";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
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

		$statement = "SELECT id AS original_id, post_title AS title, IFNULL((SELECT ID FROM {$wpdb->posts} WHERE post_type ='post' AND post_status IN ('publish', 'draft') AND (post_content LIKE CONCAT('%[tasty-recipe id=\"', original_id, '\"%') OR post_content LIKE CONCAT('%wp:wp-tasty/tasty-recipe {\"id\":',original_id,'%')) LIMIT 1), FALSE) as canonical_post_id FROM {$wpdb->posts} WHERE post_type = 'tasty_recipe'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		return $wpdb->get_results( $statement, ARRAY_A );
	}

	public static function serializer( $api_data ) {
		$recipe_id = $api_data['original_id'];
		$formatted = [];

		if ( empty( $recipe_id ) ) {
			return $recipe_id;
		}

		$results = get_post_meta( $recipe_id );
		$post    = get_post( $recipe_id );
		$author  = get_the_author_meta( 'display_name', $post->post_author );

		$formatted = [
			'original_id'       => $api_data['original_id'],
			'title'             => $post->post_title,
			'active_time_label' => __( 'Cook Time', 'mediavine-create' ),
			'total_time'        => 0,
			'author'            => $author,
		];

		foreach ( self::$pairs as $mv_key => $rm_key ) {

			if ( ! isset( $results[ $rm_key ] ) || ! empty( $formatted[ $mv_key ] ) ) {
				continue;
			}

			$value = $results[ $rm_key ][0];

			if ( in_array( $mv_key, [ 'prep_time', 'active_time' ], true ) ) {
				$formatted[ $mv_key ]     = MV_Recipe_Importer::time_to_seconds( $value );
				$formatted['total_time'] += $formatted[ $mv_key ];
				continue;
			}

			if ( 'ingredient_sections' === $mv_key ) {
				$formatted[ $mv_key ] = MV_Recipe_Importer::parse_ingredients( $value );
				continue;
			}

			if ( 'nutrition' === $mv_key ) {
				$formatted[ $mv_key ] = self::parse_nutrition( $value );
				continue;
			}

			if ( 'video' === $mv_key ) {
				$video_json = self::extract_video( $results );
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

			if ( in_array( $mv_key, [ 'notes', 'instructions' ], true ) ) {
				$formatted[ $mv_key ] = wpautop( $value );
				continue;
			}

			$formatted[ $mv_key ] = html_entity_decode( $value );
		}

		$formatted['nutrition'] = self::parse_nutrition( $results );

		if ( ! empty( $results['_thumbnail_id'] ) ) {
			$formatted['thumbnail_id'] = $results['_thumbnail_id'][0];
		}

		return $formatted;
	}

	/**
	 * Import missing reviews when a reimport function is performed
	 *
	 * @param array $api_data
	 *
	 * @return false|array[?] False if no data or missing param. True or array if comments are found
	 */
	public static function import_missed_reviews( $api_data ) {
		global $wpdb;
		if ( empty( $api_data['creation_id'] ) ) {
			return false;
		}

		$creation_id = $api_data['creation_id'];

		$sql = "SELECT
					comments.*,
					comment_meta.meta_key,
					comment_meta.meta_value as rating
					FROM `{$wpdb->prefix}mv_creations` creations
						INNER JOIN `{$wpdb->comments}` comments ON creations.canonical_post_id=comments.comment_post_ID
						INNER JOIN `{$wpdb->commentmeta}` comment_meta ON comment_meta.comment_id=comments.comment_ID
						LEFT JOIN `{$wpdb->prefix}mv_reviews` reviews ON reviews.author_email=comments.comment_author_email
					WHERE creations.id=%d AND reviews.review_title IS NULL AND comment_meta.meta_key='ERRating'
					GROUP BY comments.comment_ID, comment_meta.meta_value ORDER BY creations.id";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$prepared_sql = $wpdb->prepare(
			$sql, [
				$creation_id,
			]
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
		$reviews_to_import = $wpdb->get_results( $prepared_sql );

		if ( empty( $reviews_to_import ) ) {
			return [
				'creation' => $creation_id,
				'message'  => __( 'No reviews to import', 'mediavine-create' ),
			];
		}

		$reviews_inserted = [];
		/**
		 * @var \Comment[] $reviews_to_import stdObject for PhpDoc to pick up the correct properties returned by $wpdb
		 */
		foreach ( $reviews_to_import as $review ) {
			$review_data = [
				'review_title'   => 'Review from ' . esc_sql( $review->comment_author ),
				'creation'       => (int) $creation_id,
				'review_content' => esc_sql( $review->comment_content ),
				'author_email'   => esc_sql( $review->comment_author_email ),
				'author_name'    => esc_sql( $review->comment_author ),
				'rating'         => esc_sql( $review->rating ),
				'created'        => esc_sql( $review->comment_date ),
				'modified'       => esc_sql( $review->comment_date ),
			];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- importer false positive: SQL identifiers trusted/core tables; values bound via prepare()
			$is_inserted        = $wpdb->insert( $wpdb->prefix . 'mv_reviews', $review_data );
			$reviews_inserted[] = [
				'success'     => (bool) $is_inserted,
				'review_id'   => $is_inserted,
				'mysql_error' => $wpdb->last_error,
				'data'        => $review_data,
			];
		}

		return $reviews_inserted;
	}

	private static function convert_video_time( $duration = 0 ) {
		return 'PT' . intval( $duration ) . 'S';
	}

	public static function extract_video( $recipe ) {
		if ( empty( $recipe['video_url_response'][0] ) ) {
			return '';
		}
		$source_video = $recipe['video_url_response'][0];
		$video_url    = $recipe['video_url'][0];
		$fields       = [
			'name'         => [ 'title', '' ],
			'description'  => [ 'description', '' ],
			'uploadDate'   => [ 'upload_date', '' ],
			'thumbnailUrl' => [ 'thumbnail_url', '' ],
			'duration'     => [ static::class, 'convert_video_time', 'duration' ],
			'contentUrl'   => [ 'video_url', '' ],
			'embedUrl'     => '',
			'source'       => [ MV_Recipe_Importer::class, 'extract_video_source_from_video_url', 'video_url' ],
			'id'           => [ MV_Recipe_Importer::class, 'extract_video_id_from_video_url', 'video_url' ],
			'display'      => true,
		];
		if ( ! empty( $source_video ) ) {
			$tasty_video = Safe_Unserialize::maybe( $source_video );
			if ( ! is_object( $tasty_video ) ) {
				$tasty_video = new \stdClass();
			}
			$tasty_video->video_url = $video_url;
			$video                  = [];
			$source                 = '';
			if ( Str::contains( $video_url, 'mediavine' ) ) {
				$source = MV_Recipe_Importer::extract_video_source_from_video_url( $video_url );
			}
			if ( 'MEDIAVINE' === $source ) {
				$slug  = MV_Recipe_Importer::extract_video_id_from_video_url( $video_url );
				$video = MV_Recipe_Importer::get_mcp_video( $slug );
				if ( ! $video ) {
					return '';
				}
				$video['source'] = $source;
			} else {
				foreach ( $fields as $field => $default ) {
					// gets the source from either the contentUrl or embedUrl, depending on which is not empty
					if ( is_array( $default ) && count( $default ) > 2 && is_callable( [ $default[0], $default[1] ] ) ) {
						$args = [];
						if ( isset( $tasty_video->{$default[2]} ) ) {
							$args[] = $tasty_video->{$default[2]};
						}
						$video[ $field ] = call_user_func_array( [ $default[0], $default[1] ], $args );
						continue;
					}
					if ( ! is_array( $default ) ) {
						$video[ $field ] = isset( $tasty_video->{$field} ) ? $tasty_video->{$field} : $default;
						continue;
					}
					$video[ $field ] = isset( $tasty_video->{$default[0]} ) ? $tasty_video->{$default[0]} : $default[1];
				}
			}
		}
		if ( ! Str::is( [ 'MEDIAVINE', 'YOUTUBE', 'VIMEO' ], $video['source'] ) ) {
			return '';
		}
		$video['imported'] = [
			'importer'         => 'tasty',
			'imported_on'      => gmdate( 'Y-m-d H:i:s' ),
			'importer_version' => Plugin::VERSION,
		];
		return wp_json_encode( $video );
	}

	public static function get_ratings( $tasty_recipe_id, $mv_recipe_id ) {
		$recipe_posts = self::get_canonical_post_ids( $tasty_recipe_id );
		$ratings      = [];
		if ( $recipe_posts ) {
			$importer = new MV_Recipe_Importer;
			foreach ( $recipe_posts as $post ) {
				$post_ratings = $importer->get_ratings_from_comments( $post['id'], 'ERRating', $mv_recipe_id );
				if ( $post_ratings ) {
					foreach ( $post_ratings as $rating ) {
						$ratings[] = $rating;
					}
				}
			}
		}
		return $ratings;
	}

	private static function parse_nutrition( $recipe ) {
		$nutrition = [];

		if ( ! empty( $recipe['serving_size'] ) ) {
			$nutrition['serving_size'] = $recipe['serving_size'][0];
		}

		if ( ! empty( $recipe['servings'] ) ) {
			$nutrition['number_of_servings'] = $recipe['servings'][0];
		}

		if ( ! empty( $recipe['calories'] ) ) {
			$nutrition['calories'] = MV_Recipe_Importer::extract_floats( $recipe['calories'][0] );
		}

		if ( ! empty( $recipe['fat'] ) ) {
			$nutrition['total_fat'] = MV_Recipe_Importer::extract_floats( $recipe['fat'][0] );
		}

		if ( ! empty( $recipe['saturated_fat'] ) ) {
			$nutrition['saturated_fat'] = MV_Recipe_Importer::extract_floats( $recipe['saturated_fat'][0] );
		}

		if ( ! empty( $recipe['trans_fat'] ) ) {
			$nutrition['trans_fat'] = MV_Recipe_Importer::extract_floats( $recipe['trans_fat'][0] );
		}

		if ( ! empty( $recipe['unsaturated_fat'] ) ) {
			$nutrition['unsaturated_fat'] = MV_Recipe_Importer::extract_floats( $recipe['unsaturated_fat'][0] );
		}

		if ( ! empty( $recipe['cholesterol'] ) ) {
			$nutrition['cholesterol'] = MV_Recipe_Importer::extract_floats( $recipe['cholesterol'][0] );
		}

		if ( ! empty( $recipe['sodium'] ) ) {
			$nutrition['sodium'] = MV_Recipe_Importer::extract_floats( $recipe['sodium'][0] );
		}

		if ( ! empty( $recipe['carbohydrates'] ) ) {
			$nutrition['carbohydrates'] = MV_Recipe_Importer::extract_floats( $recipe['carbohydrates'][0] );
		}

		if ( ! empty( $recipe['fiber'] ) ) {
			$nutrition['fiber'] = MV_Recipe_Importer::extract_floats( $recipe['fiber'][0] );
		}

		if ( ! empty( $recipe['sugar'] ) ) {
			$nutrition['sugar'] = MV_Recipe_Importer::extract_floats( $recipe['sugar'][0] );
		}

		if ( ! empty( $recipe['protein'] ) ) {
			$nutrition['protein'] = MV_Recipe_Importer::extract_floats( $recipe['protein'][0] );
		}

		return $nutrition;
	}

	public static function replace( $api_data ) {
		global $wpdb;
		$api_data['error'] = null;
		$error_message     = __( 'Failed to process shortcode replacement', 'mediavine-create' );

		$models       = \Mediavine\MV_DBI::get_models( [ 'posts', 'mv_creations' ] );
		$post_model   = $models->posts;
		$recipe_model = $models->mv_creations;

		if ( empty( $api_data['original_id'] ) ) {
			$api_data['error'] = $error_message;
			return $api_data;
		}

		$recipe = $recipe_model->find_one( $api_data['id'] );

		// original_id is always a numeric recipe ID; coerce to int so it cannot
		// break out of the LIKE literals below and inject SQL.
		$tasty_original_id = absint( $api_data['original_id'] );
		$tasty_shortcode   = '[tasty-recipe id="' . $tasty_original_id . '"]';
		$tasty_block_code  = '<!-- wp:wp-tasty/tasty-recipe {"id":' . $tasty_original_id;
		// https://regex101.com/r/XwAjkk/2/
		$tasty_gutenberg_regex = '/<!-- wp:wp-tasty\/tasty-recipe.*?"id":(' . $tasty_original_id . ').*?\/-->/m';

		$thumbnail    = wp_get_attachment_url( $recipe->thumbnail_id );
		$mv_shortcode = '[mv_create key="' . $recipe->id . '" title="' . $recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

		$statement = "SELECT ID AS post_id,
						ID AS original_post_id,
						ID AS canonical_post_id,
						post_content AS original_content
						FROM {$models->posts->table_name}
						WHERE (post_content
						LIKE '%{$tasty_shortcode}%'
						OR post_content
						LIKE '%{$tasty_block_code}%')
						AND post_type
						NOT IN ('revision', 'attachment', 'nav_menu_item')";
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
			$recipe_update = [
				'id'                => $api_data['id'],
				'original_post_id'  => $post->post_id,
				'canonical_post_id' => $post->post_id,
			];

			$post->updated_content = str_replace( $tasty_shortcode, $mv_shortcode, $post->original_content );
			$post->updated_content = preg_replace( $tasty_gutenberg_regex, $mv_shortcode, $post->updated_content );
			if ( ! Str::contains( $post->updated_content, $mv_shortcode ) ) {
				$api_data['error'] = $error_message;
				continue;
			}

			$updated_post_id = wp_update_post(
				[
					'ID'           => $post->post_id,
					'post_content' => $post->updated_content,
				], true
			);

			if ( is_wp_error( $updated_post_id ) ) {
				$api_data['error'] = $error_message;
				continue;
			}

			$recipe_model->update( $recipe_update );
			$api_data['error'] = null;
			\Mediavine\Create\Creations::publish_creation( $api_data['id'] );
		}

		return $api_data;

	}

}
