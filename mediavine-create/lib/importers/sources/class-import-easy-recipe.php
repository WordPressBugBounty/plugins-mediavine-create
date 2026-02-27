<?php

namespace Mediavine\Create\Importers\Sources;

use Mediavine\Create\Helpers\Str;

class Import_Easy_Recipe {

	/**
	 * Check for existence of EZ Recipe
	 *
	 * @param  string $content HTML String possibly containing an easy recipe
	 * @return boolean
	 */
	public static function has_ez_recipe( $content ) {
		$find_easy_recipe = '/<div\s+class\s*=\s*["\'](?:[^>]*\s+)?easyrecipe[ \'"]/si';
		return preg_match( $find_easy_recipe, $content );
	}

	public static function process_replacement( $content, $shortcode, $mv_recipe ) {
		if ( ! Str::contains( 'endeasyrecipe', $content ) ) {
			$re = '/<div[^>]+class=[\'"][^\'"]*ERNutrition[^\'"]*[\'"][^>]*>(.*)<\/div>/Us';
			preg_match_all( $re, $content, $matches );
			if ( ! empty( $matches[0] ) ) {
				$content = Str::replace( $matches[0][0], $matches[0][0] . "\n" . '<div class="endeasyrecipe">0.0.1</div>', $content );
			}
		}

		$re = '/<div[^>]+class=[\'"][^\'"]*easyrecipe[^\'"]*[\'"][^>]*>(.+)<div class="endeasyrecipe"[^>]*>[\d\.]+<\/div>.{0,2}<\/div>/Us';

		preg_match_all( $re, $content, $matches, PREG_OFFSET_CAPTURE, 0 );

		$change = false;

		foreach ( $matches[0] as $match ) {
			$position = strpos( $match[0], trim( $mv_recipe->title ) );
			if ( false !== $position ) {
				$content = str_replace( trim( $match[0] ), $shortcode, $content );
				$change  = true;
			}
		}
		return [
			'content' => $content,
			'change'  => $change,
		];
	}

	public static function replace( $recipe, $mv_recipe ) {

		$post      = get_post( $recipe['original_id'] );
		$content   = $post->post_content;
		$thumbnail = wp_get_attachment_url( $mv_recipe->thumbnail_id );
		$shortcode = '[mv_create key="' . $mv_recipe->id . '" title="' . $mv_recipe->title . '" thumbnail="' . $thumbnail . '" type="recipe"]';

		$and = self::process_replacement( $content, $shortcode, $mv_recipe );

		$change          = $and['change'];
		$recipe['error'] = __( 'Failed to process shortcode replacement', 'mediavine' );

		if ( $change ) {
			$updated_post_id = wp_update_post(
				[
					'ID'           => $recipe['original_id'],
					'post_content' => $and['content'],
				], true
			);

			if ( is_wp_error( $updated_post_id ) ) {
				$errors = $updated_post_id->get_error_messages();
				return $recipe;
			}
			unset( $recipe['post_content'] );
			$recipe['error'] = null;

			\Mediavine\Create\Creations::publish_creation( $mv_recipe->id );
		}

		return $recipe;

	}

	public function serializer( $post_id ) {

		$post = get_post( $post_id );

		$ez_recipes           = $this->extract_recipe_from_content( $post->post_content );
		$processed_ez_recipes = [];

		if ( empty( $ez_recipes ) ) {
			return false;
		}

		foreach ( $ez_recipes as $recipe_data ) {
			$formatted = $recipe_data;
			if ( isset( $recipe_data['photo_url'] ) ) {
				$attachment_id = \Mediavine\Create\Images::get_attachment_id_from_url( $recipe_data['photo_url'] );
				if ( $attachment_id ) {
					$formatted['thumbnail_id'] = $attachment_id;
				}
			}
			$processed_ez_recipes[] = $formatted;
		}

		return $processed_ez_recipes;
	}

		/**
	 * [extract_recipe_from_content description]
	 * @param  string $content String of HTML content
	 * @return array           Extracted Recipe data
	 */

	public function extract_recipe_from_content( $content ) {

		if ( ! $this->has_ez_recipe( $content ) ) {
			return false;
		}

		$recipe_dom = new EasyRecipeDocument( $content );

		$ez_recipes = $recipe_dom->get_recipes();

		$extracted_recipes = [];

		foreach ( $ez_recipes as $recipe ) {
			$extracted_recipes[] = $recipe_dom->extractData( $recipe, $recipe_dom );
		}

		return $extracted_recipes;
	}

	public static function find_recipes( $post_id = null ) {
		global $wpdb;
		$result = [];
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( self::has_ez_recipe( $post->post_content ) ) {
				$title = self::extract_title( $post->post_content, $post->post_title );
				return [
					[
						'original_id' => $post->ID,
						'title'       => $title,
					],
				];
			}
			return [];
		}

		$statement = "SELECT id AS original_id, post_title AS title, post_content, id AS canonical_post_id FROM {$wpdb->posts} WHERE post_content LIKE '%easyrecipe%' AND post_content LIKE '%ERName%' AND post_type = 'post' AND post_status IN ('publish', 'draft');";
		$data      = $wpdb->get_results( $statement );
		if ( $data ) {
			foreach ( $data as $recipe ) {
				$result[] = [
					'original_id'       => $recipe->original_id,
					'title'             => self::extract_title( $recipe->post_content, $recipe->title ),
					'canonical_post_id' => $recipe->canonical_post_id,
				];
			}
		}

		return $result;
	}

	static function extract_title( $content, $title = '' ) {
		// https://regex101.com/r/zSDdh9/1
		$re = '/<div[^>]+class=[\'"][^\'"]*ERName[^\'"]*[\'"][^>]*>(.*)<\/div>/Us';
		preg_match_all( $re, $content, $matches );
		if ( isset( $matches[1][0] ) ) {
			$title = $matches[1][0];
		}
		return $title;
	}

}
